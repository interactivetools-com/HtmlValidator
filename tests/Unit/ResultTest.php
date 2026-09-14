<?php
declare(strict_types=1);

namespace Itools\HtmlValidator\Tests\Unit;

use Itools\HtmlValidator\HtmlValidator;
use Itools\HtmlValidator\Result;
use Itools\HtmlValidator\Tests\Support\HtmlValidatorTestCase;
use Itools\HtmlValidator\Violation;

/**
 * The shape of what check() returns: ok, document order, one violation per distinct problem,
 * the error cap, the detail length, and what rules() reports.
 */
final class ResultTest extends HtmlValidatorTestCase
{
    public function testOkResult(): void
    {
        $result = HtmlValidator::check('<p>Hello</p>');
        $this->assertInstanceOf(Result::class, $result);
        $this->assertTrue($result->ok);
        $this->assertSame([], $result->errors);
    }

    public function testErrorsInDocumentOrder(): void
    {
        $result = HtmlValidator::check('<p onclick="a">x</p><script></script><a href="javascript:b">y</a>');
        $this->assertFalse($result->ok);
        $this->assertSame(
            ['event-handler: onclick', 'element-not-allowed: <script>', 'url-scheme-not-allowed: href="javascript:b"'],
            array_map(fn(Violation $v) => "$v->code: $v->detail", $result->errors),
        );
    }

    public function testSameProblemReportsOnce(): void
    {
        $result = HtmlValidator::check('<p onclick="a">x</p><p onclick="b">y</p><script></script><script></script>');
        $this->assertSame(['event-handler: onclick', 'element-not-allowed: <script>'], array_map(fn(Violation $v) => "$v->code: $v->detail", $result->errors));
    }

    public function testOneTagCanBreakSeveralRules(): void
    {
        $result = HtmlValidator::check('<a href="javascript:x" onclick="y" style="width: expression(1)" srcdoc="z">a</a>');
        $this->assertSame(
            ['url-scheme-not-allowed', 'event-handler', 'css-not-allowed', 'attribute-not-allowed'],
            array_map(fn(Violation $v) => $v->code, $result->errors),
        );
    }

    public function testViolationFields(): void
    {
        $violation = HtmlValidator::check('<script src="x">')->errors[0];
        $this->assertSame('element-not-allowed', $violation->code);
        $this->assertSame('<script src="x">', $violation->detail);
        $this->assertSame('%s is not allowed', $violation->template);
        $this->assertSame('<script src="x"> is not allowed', $violation->message);
        $this->assertSame($violation->message, sprintf($violation->template, $violation->detail));
    }

    public function testEveryTemplateHasOnePlaceholder(): void
    {
        foreach (Violation::TEMPLATES as $code => $template) {
            $this->assertSame(1, substr_count($template, '%s'), "template for $code");
            $this->assertStringNotContainsString('%%', $template, "template for $code");
        }
    }

    public function testLimits(): void
    {
        $this->withSettings(['maxErrors' => 3, 'maxDetailLength' => 12], function () {
            $html = '';
            foreach (range(1, 5) as $n) {
                $html .= "<badelement$n attr=\"x\"></badelement$n>";   // the number is inside the cut, so the details stay distinct
            }
            $result = HtmlValidator::check($html);
            $this->assertCount(3, $result->errors);
            $this->assertSame('<badelement1...', $result->errors[0]->detail);
        });
    }

    public function testDetailIsCutOnACharacterBoundary(): void
    {
        $this->withSettings(['maxDetailLength' => 9], function () {
            $violation = HtmlValidator::check('<script>日本語</script>')->errors[0];   // <script> is exactly 8 characters
            $this->assertSame('<script>', $violation->detail);
            $violation = HtmlValidator::check('<script 日本語="x">')->errors[0];
            $this->assertSame('<script 日...', $violation->detail);
        });
    }

    public function testDetailIsOneLine(): void
    {
        $violation = HtmlValidator::check("<script\nsrc=\"x\">")->errors[0];
        $this->assertSame('<script\nsrc="x">', $violation->detail);
        $violation = HtmlValidator::check("<script\r\nsrc=\"x\">")->errors[0];
        $this->assertSame('<script\nsrc="x">', $violation->detail);   // CRLF is LF to the tokenizer, and the detail follows it
    }

    public function testRules(): void
    {
        $rules = HtmlValidator::rules();
        $this->assertSame(
            ['elements', 'formElements', 'attributesRefused', 'formAttributes', 'urlSchemes', 'scriptSchemes', 'cssUrlSchemes', 'urlAttributes', 'allowForms', 'allowStyles', 'allowEmbeds', 'iframeHosts'],
            array_keys($rules),
        );
        $this->assertContains('p', $rules['elements']);
        $this->assertNotContains('script', $rules['elements']);
        $this->assertFalse($rules['allowForms']);
        $this->withSettings(['allowForms' => true], fn() => $this->assertTrue(HtmlValidator::rules()['allowForms']));
    }

    public function testEmptyInput(): void
    {
        $this->assertAccepts('');
        $this->assertAccepts('   ');
    }
}

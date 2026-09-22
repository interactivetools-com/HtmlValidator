<?php
declare(strict_types=1);

namespace Itools\HtmlValidator\Tests\Unit;

use Itools\HtmlValidator\HtmlValidator;
use Itools\HtmlValidator\Tests\Support\HtmlValidatorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The markup inside <!--[if ...]> ... <![endif]-->. A comment to every browser since IE 10, but the IE engine
 * inside old Windows programs and Outlook's Word engine read it as markup, so it gets the same rules as the
 * content around it. Email templates rely on it for Outlook-only tables and VML buttons.
 */
final class ConditionalCommentsTest extends HtmlValidatorTestCase
{
    #[DataProvider('acceptedProvider')]
    public function testSafeMarkupInsidePasses(string $html): void
    {
        $this->assertAccepts($html);
    }

    public static function acceptedProvider(): array
    {
        return [
            'outlook table wrapper' => ['<!--[if mso]><table width="600" align="center"><tr><td><![endif]-->x<!--[if mso]></td></tr></table><![endif]-->'],
            'vml button'            => ['<!--[if mso]><v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" href="https://example.com/" style="height:40px" fillcolor="#0066cc"><w:anchorlock/><center>Go</center></v:roundrect><![endif]-->'],
            'word list marker'      => ['<p><!--[if !supportLists]><span style="mso-list:Ignore">1.<span>&nbsp;</span></span><![endif]-->x</p>'],
            'text only'             => ['<!--[if gte mso 9]>Outlook only<![endif]-->'],
            'empty'                 => ['<!--[if mso]><![endif]-->'],
            'no condition close'    => ['<!--[if mso <script>x</script>-->'],   // no ]>, so no engine reads a condition: a plain comment
        ];
    }

    #[DataProvider('refusedProvider')]
    public function testUnsafeMarkupInsideRejects(string $html, string $code, string $detail): void
    {
        $this->assertRejects($html, $code, $detail);
    }

    public static function refusedProvider(): array
    {
        return [
            'script'          => ['<!--[if IE]><script>alert(1)</script><![endif]-->', 'element-not-allowed', '<script>'],
            'event handler'   => ['<!--[if mso]><img src="x" onerror="alert(1)"><![endif]-->', 'event-handler', 'onerror'],
            'javascript url'  => ['<!--[if mso]><a href="javascript:alert(1)">x</a><![endif]-->', 'url-scheme-not-allowed', 'href="javascript:alert(1)"'],
            'uppercase if'    => ['<!--[IF IE]><script>alert(1)</script><![endif]-->', 'element-not-allowed', '<script>'],
            'no endif'        => ['<!--[if IE]><script>alert(1)</script>-->', 'element-not-allowed', '<script>'],
            'nested raw text' => ['<!--[if mso]><style>p{} <b></style><![endif]-->', 'less-than-in-text', '<b>'],
        ];
    }

    public function testErrorsInsideAndOutsideAreReportedTogether(): void
    {
        $result = HtmlValidator::check('<!--[if IE]><script>a</script><![endif]--><object></object>');
        $this->assertSame(['<script>', '<object>'], array_column($result->errors, 'detail'));
    }

    public function testMaxErrorsHoldsAcrossTheComment(): void
    {
        $this->withSettings(['maxErrors' => 2], function () {
            $result = HtmlValidator::check('<!--[if IE]><script>a</script><object></object><embed><![endif]-->');
            $this->assertCount(2, $result->errors);
        });
    }
}

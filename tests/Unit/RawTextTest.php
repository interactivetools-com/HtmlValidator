<?php
declare(strict_types=1);

namespace Itools\HtmlValidator\Tests\Unit;

use Itools\HtmlValidator\HtmlValidator;
use Itools\HtmlValidator\Tests\Support\HtmlValidatorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A < inside text a browser never reads as markup: the content of style, iframe and textarea, and a
 * comment that is not <!-- -->. Text to a browser, but a tag to strip_tags() with an allow list or to
 * an HTML4-era DOM, which is what re-parses stored content downstream.
 *
 * Code: less-than-in-text.
 */
final class RawTextTest extends HtmlValidatorTestCase
{
    #[DataProvider('refusedProvider')]
    public function testALessThanIsRefused(string $html, string $detail): void
    {
        $this->withSettings(['allowForms' => true], function () use ($html, $detail) {
            $violation = $this->assertRejects($html, 'less-than-in-text', $detail);
            $this->assertSame("A < where a browser reads text, not tags: $detail", $violation->message);
        });
    }

    public static function refusedProvider(): array
    {
        $img = '<img src=x onerror=alert(1)>';
        return [
            'style'                   => ["<style>p{color:red}$img</style>", $img],
            'iframe'                  => ["<iframe src=\"https://www.youtube.com/embed/x\">$img</iframe>", $img],
            'textarea'                => ["<textarea>$img</textarea>", $img],
            'textarea, a lone <'      => ['<textarea>a < b</textarea>', '< b'],
            'detail runs to the end'  => ['<textarea>a <b> c</textarea>', '<b> c'],
            'bogus comment <?'        => ["<? $img", $img],
            'markup declaration <!x'  => ["<!x $img", $img],
            'end tag with a space'    => ["</ x $img", $img],
            'cdata'                   => ['<![CDATA[<b>]]>', '<b>'],
        ];
    }

    #[DataProvider('acceptedProvider')]
    public function testTextWithoutALessThanPasses(string $html): void
    {
        $this->withSettings(['allowForms' => true], fn() => $this->assertAccepts($html));
    }

    public static function acceptedProvider(): array
    {
        return [
            'encoded in a textarea'    => ['<textarea>&lt;b&gt; and &#60;</textarea>'],
            'child selector in css'    => ['<style>ul > li {color: red}</style>'],
            'iframe fallback text'     => ['<iframe src="https://www.youtube.com/embed/x">Your browser has no frames</iframe>'],
            'word namespace comment'   => ['<?xml:namespace prefix = o ns = "urn:schemas-microsoft-com:office:office" /><p>x</p>'],
            'php block'                => ['<?php echo 1; ?>'],
            'real comment holds a tag' => ['<!-- <img src=x onerror=alert(1)> -->'],
            'conditional comment'      => ['<!--[if IE]><p>x</p><![endif]-->'],
            'lone < in text'           => ['<p>a < b</p>'],
        ];
    }

    public function testStyleContentIsStillCheckedAsCss(): void
    {
        $result = HtmlValidator::check('<style>p{width: expression(1)} <b></style>');
        $this->assertSame(['less-than-in-text', 'css-not-allowed'], array_column($result->errors, 'code'));
    }
}

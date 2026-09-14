<?php
declare(strict_types=1);

namespace Itools\HtmlValidator\Tests\Unit;

use Itools\HtmlValidator\Tests\Support\HtmlValidatorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CSS in the style attribute and the <style> element: the constructs that ran script in
 * some browser, backslash escapes that hide them, and the schemes url() may use.
 *
 * Code: css-not-allowed.
 */
final class CssTest extends HtmlValidatorTestCase
{
    //region Allowed

    #[DataProvider('allowedCssProvider')]
    public function testAllowedCss(string $css): void
    {
        $this->assertAccepts("<p style=\"$css\">x</p>");
        $this->assertAccepts("<style>p { $css }</style>");
    }

    public static function allowedCssProvider(): array
    {
        return [
            'plain'                 => ['color: red; font-size: 12px'],
            'relative url'          => ['background: url(images/bg.png)'],
            'root url'              => ["background: url('/images/bg.png')"],
            'https url'             => ['background: url("https://cdn.example.com/bg.png")'],
            'http url'              => ['background: url(http://example.com/bg.png)'],
            'protocol relative url' => ['background: url(//cdn.example.com/bg.png)'],
            'fragment url'          => ['clip-path: url(#clip)'],
            'font family quotes'    => ["font-family: 'Open Sans', Arial"],
            'word paste'            => ['mso-bidi-font-weight: normal; tab-stops: 36.0pt'],
            'position fixed'        => ['position: fixed; top: 0; z-index: 9999'],   // a phishing overlay, not script: a known non-goal
            'empty'                 => [''],
        ];
    }

    public function testStyleElementContentIsCheckedAsCss(): void
    {
        $this->assertAccepts('<style>@media print { p { display: none } } .a::before { content: "<script>" }</style>');
    }

    public function testTextAfterStyleIsNotCss(): void
    {
        $this->assertAccepts('<style>p { color: red }</style><p>expression( is just text here</p>');
    }

    //endregion
    //region Refused

    #[DataProvider('refusedCssProvider')]
    public function testRefusedCss(string $css, string $detail): void
    {
        $violation = $this->assertRejects("<p style=\"$css\">x</p>", 'css-not-allowed', $detail);
        $this->assertSame("CSS containing $detail is not allowed", $violation->message);
        $this->assertRejects("<style>p { $css }</style>", 'css-not-allowed', $detail);
    }

    public static function refusedCssProvider(): array
    {
        return [
            'expression'            => ['width: expression(alert(1))', 'expression('],
            'expression uppercase'  => ['width: EXPRESSION(alert(1))', 'EXPRESSION('],
            'behavior'              => ['behavior: url(x.htc)', 'behavior:'],
            'behavior with space'   => ['behavior : url(x.htc)', 'behavior :'],
            'moz-binding'           => ['-moz-binding: url(x.xml#a)', '-moz-binding'],
            'import'                => ['@import url(https://example.com/x.css)', '@import'],
            'charset'               => ['@charset "UTF-7"', '@charset'],
            'backslash escape'      => ['width: e\\78 pression(alert(1))', '\\'],
            'image function'        => ['background: image(x.png)', 'image('],
            'image-set function'    => ['background: image-set(x.png 1x)', 'image-set('],
            'src function'          => ['background: src(x.png)', 'src('],
            'javascript url'        => ['background: url(javascript:x)', 'url(javascript:x)'],
            'javascript url quoted' => ["background: url('javascript:x')", 'url(javascript:x)'],
            'javascript url spaced' => ['background: url( javascript:x )', 'url(javascript:x)'],
            'url with parens'       => ['background: url(javascript:alert(1))', 'url(javascript:alert(1)'],   // an unquoted url() ends at the first ), as in CSS
            'data url'              => ['background: url(data:image/svg+xml,<svg/>)', 'url(data:image/svg+xml,<svg/>)'],
            'vbscript url'          => ['background: url(vbscript:x)', 'url(vbscript:x)'],
            'unknown scheme url'    => ['background: url(ftp://example.com/x.png)', 'url(ftp://example.com/x.png)'],
            'mailto url'            => ['background: url(mailto:a@b.c)', 'url(mailto:a@b.c)'],
            'url uppercase'         => ['background: URL(JAVASCRIPT:x)', 'url(JAVASCRIPT:x)'],
        ];
    }

    public function testEntitiesInStyleAttributeDecodeBeforeTheCheck(): void
    {
        $this->assertRejects('<p style="width: expression&#40;alert(1))">x</p>', 'css-not-allowed', 'expression(');
    }

    public function testStyleElementContentIsNotEntityDecoded(): void
    {
        $this->assertAccepts('<style>p::before { content: "expression&#40;" }</style>');   // raw text to a browser, so the entity stays
    }

    //endregion
}

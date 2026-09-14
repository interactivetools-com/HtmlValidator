<?php
declare(strict_types=1);

namespace Itools\HtmlValidator\Tests\Unit;

use Itools\HtmlValidator\HtmlValidator;
use Itools\HtmlValidator\Tests\Support\HtmlValidatorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * URL schemes in attribute values: the allowed schemes on URL attributes, javascript:
 * refused in every attribute, and the ways browsers let a scheme hide (entities, whitespace,
 * control characters, case).
 *
 * Code: url-scheme-not-allowed.
 */
final class UrlsTest extends HtmlValidatorTestCase
{
    //region Allowed

    #[DataProvider('allowedUrlProvider')]
    public function testAllowedUrl(string $value): void
    {
        $this->assertAccepts("<a href=\"$value\">x</a>");
        $this->assertAccepts("<img src=\"$value\">");
    }

    public static function allowedUrlProvider(): array
    {
        return [
            'https'             => ['https://example.com/a?b=1#c'],
            'http'              => ['http://example.com/'],
            'mailto'            => ['mailto:a@example.com?subject=Hi'],
            'tel'               => ['tel:+1-555-0100'],
            'uppercase scheme'  => ['HTTPS://EXAMPLE.COM/'],
            'relative path'     => ['about/team.html'],
            'root path'         => ['/about/'],
            'protocol relative' => ['//cdn.example.com/x.png'],
            'fragment'          => ['#section-2'],
            'query'             => ['?page=2'],
            'empty'             => [''],
            'colon in the path' => ['/time/12:30'],
            'colon in query'    => ['?when=12:30'],
            'spaces'            => ['my file.pdf'],
        ];
    }

    #[DataProvider('urlAttributeProvider')]
    public function testEveryUrlAttributeIsChecked(string $attribute): void
    {
        $this->assertAccepts("<a $attribute=\"https://example.com/\">x</a>");
        $this->assertRejects("<a $attribute=\"javascript:alert(1)\">x</a>", 'url-scheme-not-allowed', "$attribute=\"javascript:alert(1)\"");
        $this->assertRejects("<a $attribute=\"ftp://example.com/\">x</a>", 'url-scheme-not-allowed', "$attribute=\"ftp://example.com/\"");
    }

    public static function urlAttributeProvider(): array
    {
        $cases = [];
        foreach (HtmlValidator::rules()['urlAttributes'] as $attribute) {
            if ($attribute !== 'formaction') {   // refused outright while forms are off; AttributesTest covers it with forms on
                $cases[$attribute] = [$attribute];
            }
        }
        return $cases;
    }

    //endregion
    //region Refused

    #[DataProvider('refusedUrlProvider')]
    public function testRefusedUrl(string $html): void
    {
        $this->assertRejects($html, 'url-scheme-not-allowed');
    }

    public static function refusedUrlProvider(): array
    {
        return [
            'javascript'                 => ['<a href="javascript:alert(1)">x</a>'],
            'uppercase'                  => ['<a href="JAVASCRIPT:alert(1)">x</a>'],
            'mixed case'                 => ['<a href="JaVaScRiPt:alert(1)">x</a>'],
            'vbscript'                   => ['<a href="vbscript:MsgBox(1)">x</a>'],
            'data'                       => ['<a href="data:text/html,<script>alert(1)</script>">x</a>'],
            'data image on img'          => ['<img src="data:image/png;base64,iVBORw0KGgo=">'],
            'unknown scheme on href'     => ['<a href="ftp://example.com/">x</a>'],
            'file scheme'                => ['<a href="file:///etc/passwd">x</a>'],
            'leading spaces'             => ['<a href="   javascript:alert(1)">x</a>'],
            'leading tab and newline'    => ["<a href=\"\t\njavascript:alert(1)\">x</a>"],
            'newline inside the scheme'  => ["<a href=\"java\nscript:alert(1)\">x</a>"],
            'tab inside the scheme'      => ["<a href=\"jav\tascript:alert(1)\">x</a>"],
            'named entity for colon'     => ['<a href="javascript&colon;alert(1)">x</a>'],
            'named entity for tab'       => ['<a href="jav&Tab;ascript:alert(1)">x</a>'],
            'named entity for newline'   => ['<a href="jav&NewLine;ascript:alert(1)">x</a>'],
            'decimal entities'           => ['<a href="&#106;&#97;&#118;&#97;&#115;&#99;&#114;&#105;&#112;&#116;&#58;alert(1)">x</a>'],
            'hex entities'               => ['<a href="&#x6A;avascript:alert(1)">x</a>'],
            'entity without semicolon'   => ['<a href="&#106avascript:alert(1)">x</a>'],
            'unquoted value'             => ['<a href=javascript:alert(1)>x</a>'],
            'single quoted value'        => ["<a href='javascript:alert(1)'>x</a>"],
            'src on img'                 => ['<img src="javascript:alert(1)">'],
            'poster on video'            => ['<video poster="javascript:alert(1)"></video>'],
            'background on table'        => ['<table background="javascript:alert(1)"></table>'],
            'ping'                       => ['<a href="/x" ping="javascript:alert(1)">x</a>'],
            'srcset'                     => ['<img srcset="javascript:alert(1) 1x">'],
            'custom element'             => ['<my-link href="javascript:alert(1)">x</my-link>'],
            'unknown attribute'          => ['<p title="javascript:alert(1)">x</p>'],
            'data attribute'             => ['<tr data-href="javascript:alert(1)">'],   // a clickable-row script would set location to it
            'custom element attribute'   => ['<my-link url="javascript:alert(1)">x</my-link>'],
            'xlink:href'                 => ['<a xlink:href="javascript:alert(1)">x</a>'],
        ];
    }

    public function testOtherAttributesOnlyRefuseJavascript(): void
    {
        $this->assertAccepts('<p title="Note: see below" alt="Warning: hot" data-time="noon:sharp" content="width=device-width">x</p>');
        $this->assertAccepts('<a href="/x" title="ftp://example.com/">x</a>');
        $this->assertAccepts('<p title="vbscript:x" data-src="data:image/png;base64,iVBORw0KGgo=">x</p>');   // no current browser runs either as the page
        $this->assertAccepts('<my-chart data="Note: x" xmlns:o="urn:schemas-microsoft-com:office:office">x</my-chart>');   // URLs only on <object> and <svg>, both refused
        $this->assertRejects('<p data-href="javascript:alert(1)">x</p>', 'url-scheme-not-allowed', 'data-href="javascript:alert(1)"');
    }

    public function testDetailQuotesTheAttributeAsDecoded(): void
    {
        $violation = $this->assertRejects('<a href="javascript&colon;alert(1)">x</a>', 'url-scheme-not-allowed', 'href="javascript:alert(1)"');
        $this->assertSame('The URL in href="javascript:alert(1)" must start with http:, https:, mailto:, tel:, a relative path, or #', $violation->message);
    }

    //endregion
}

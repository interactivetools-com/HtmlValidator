<?php
declare(strict_types=1);

namespace Itools\HtmlValidator\Tests\Unit;

use Itools\HtmlValidator\HtmlValidator;
use Itools\HtmlValidator\Tests\Support\HtmlValidatorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Attribute names: the allowlist and its prefixes, xmlns:* declarations, event handlers,
 * formaction and style under their switches, and the tokenizer facts that decide what counts
 * as an attribute at all.
 *
 * Codes: event-handler, attribute-not-allowed. Values are covered in UrlsTest and CssTest.
 */
final class AttributesTest extends HtmlValidatorTestCase
{
    //region Event Handlers

    #[DataProvider('eventHandlerProvider')]
    public function testEventHandler(string $html, string $name): void
    {
        $violation = $this->assertRejects($html, 'event-handler', $name);
        $this->assertSame("$name= event handler attributes are not allowed", $violation->message);
    }

    public static function eventHandlerProvider(): array
    {
        return [
            'onclick'                    => ['<p onclick="alert(1)">x</p>', 'onclick'],
            'uppercase'                  => ['<p ONCLICK="alert(1)">x</p>', 'onclick'],
            'new handler names'          => ['<p oncommand="x">x</p>', 'oncommand'],
            'no value'                   => ['<p onclick>x</p>', 'onclick'],
            'slash as separator'         => ['<p/onmouseover=alert(1)>x</p>', 'onmouseover'],
            'newline before ='           => ["<img src=x onerror\n=alert(1)>", 'onerror'],
            'no space after quote'       => ['<a href="x"onclick="y">x</a>', 'onclick'],
            'on with anything after'     => ['<p onfoo="x">x</p>', 'onfoo'],
            'second of two attributes'   => ['<p class="a" onclick="x">x</p>', 'onclick'],
        ];
    }

    public function testEntityInAttributeNameIsStillRefused(): void
    {
        // the name is on&#99;lick to a browser too, so it is harmless, but the rule is the prefix and has no exceptions
        $this->assertRejects('<p on&#99;lick="x">x</p>', 'event-handler', 'on&#99;lick');
    }

    public function testAttributeNamedOnAlone(): void
    {
        $this->assertRejects('<p on="x">x</p>', 'event-handler', 'on');   // harmless today, and not worth a special case
    }

    //endregion
    //region Allowlist

    #[DataProvider('listedAttributeProvider')]
    public function testListedAttribute(string $attribute): void
    {
        $this->assertAccepts("<div $attribute=\"1\">x</div>");
    }

    public static function listedAttributeProvider(): array
    {
        $cases = [];
        foreach (HtmlValidator::rules()['attributes'] as $attribute) {
            $cases[$attribute] = [$attribute];
        }
        return $cases;
    }

    public function testListedPrefixes(): void
    {
        $this->assertAccepts('<p aria-label="Close" aria-hidden="true">x</p>');
        $this->assertAccepts('<v:shape o:spid="_x0000_s1026" v:ext="edit" w:rsidr="00A1">x</v:shape>');
        $this->assertAccepts('<img src="/x.png" v:shapes="Picture_x0020_1">');
    }

    #[DataProvider('unlistedAttributeProvider')]
    public function testUnlistedAttribute(string $html, string $name): void
    {
        $violation = $this->assertRejects($html, 'attribute-not-allowed', $name);
        $this->assertSame("The $name attribute is not allowed", $violation->message);
    }

    public static function unlistedAttributeProvider(): array
    {
        return [
            'srcdoc'               => ['<iframe src="https://www.youtube.com/embed/x" srcdoc="<script>alert(1)</script>"></iframe>', 'srcdoc'],
            'data attribute'       => ['<p data-id="7">x</p>', 'data-id'],
            'stimulus action'      => ['<p data-action="click->x#y">x</p>', 'data-action'],
            'alpine directive'     => ['<div x-data x-init="alert(1)">x</div>', 'x-init'],
            'alpine shorthand'     => ['<p @click="alert(1)">x</p>', '@click'],
            'vue binding'          => ['<a :href="x">x</a>', ':href'],
            'htmx request'         => ['<p hx-get="/delete">x</p>', 'hx-get'],
            'angularjs'            => ['<p ng-click="x()">x</p>', 'ng-click'],
            'hyperscript'          => ['<p _="on click call alert(1)">x</p>', '_'],
            'hyperscript long'     => ['<p script="on click call alert(1)">x</p>', 'script'],
            'customized built-in'  => ['<p is="fancy-p">x</p>', 'is'],
            'xlink:href'           => ['<a xlink:href="https://example.com/">x</a>', 'xlink:href'],
            'mailchimp marker'     => ['<td mc:edit="body">x</td>', 'mc:edit'],
            'bare xmlns'           => ['<p xmlns="http://www.w3.org/1999/xhtml">x</p>', 'xmlns'],
            'made-up name'         => ['<p sku="A-100">x</p>', 'sku'],
            'unlisted word name'   => ['<v:imagedata src="/x.png" cropbottom="5f"></v:imagedata>', 'cropbottom'],
            'on a word element'    => ['<o:p x-init="alert(1)">x</o:p>', 'x-init'],
            'prefix inside a name' => ['<p x-o:spid="1">x</p>', 'x-o:spid'],
        ];
    }

    /**
     * PHP stores a name of digits only as an int array key; it is refused like any other unlisted name
     */
    public function testAttributeNamedWithDigitsOnly(): void
    {
        $this->assertRejects('<p 1="x">x</p>', 'attribute-not-allowed', '1');
    }

    public function testListedNamesAreCaseInsensitive(): void
    {
        $this->assertAccepts('<P CLASS="x" ARIA-LABEL="y" O:SPID="z">x</P>');
    }

    //endregion
    //region Namespace Declarations

    public function testOfficeNamespacesPass(): void
    {
        $this->assertAccepts('<v:roundrect xmlns:v="urn:schemas-microsoft-com:vml" xmlns:o="urn:schemas-microsoft-com:office:office">x</v:roundrect>');
        $this->assertAccepts('<p xmlns:m="http://schemas.microsoft.com/office/2004/12/omml">x</p>');
    }

    /**
     * In a page served as XHTML, a prefix bound to the XHTML namespace makes <o:script> a script
     */
    public function testOtherNamespacesAreRefused(): void
    {
        $violation = $this->assertRejects('<o:script xmlns:o="http://www.w3.org/1999/xhtml">x</o:script>', 'attribute-not-allowed', 'xmlns:o="http://www.w3.org/1999/xhtml"');
        $this->assertSame('The xmlns:o="http://www.w3.org/1999/xhtml" attribute is not allowed', $violation->message);
        $this->assertRejects('<p xmlns:s="http://www.w3.org/2000/svg">x</p>', 'attribute-not-allowed', 'xmlns:s="http://www.w3.org/2000/svg"');
        $this->assertRejects('<p xmlns:o="URN:SCHEMAS-MICROSOFT-COM:office:office">x</p>', 'attribute-not-allowed');   // namespace names are case-sensitive
    }

    //endregion
    //region Switches

    public function testFormactionFollowsAllowForms(): void
    {
        $this->withSettings(['allowForms' => true], function () {
            $this->assertAccepts('<button formaction="/save">x</button>');
            $this->assertRejects('<button formaction="javascript:alert(1)">x</button>', 'url-scheme-not-allowed');
        });
        $this->withSettings(['allowForms' => false], function () {
            $this->assertRejects('<p formaction="/save">x</p>', 'attribute-not-allowed', 'formaction');
        });
    }

    public function testStyleAttributeFollowsAllowStyles(): void
    {
        $this->assertAccepts('<p style="color: red">x</p>');
        $this->withSettings(['allowStyles' => false], function () {
            $this->assertRejects('<p style="color: red">x</p>', 'attribute-not-allowed', 'style');
        });
    }

    //endregion
    //region Duplicates

    public function testFirstDuplicateAttributeWins(): void
    {
        $this->assertAccepts('<a href="https://example.com" href="javascript:alert(1)">x</a>');
        $this->assertRejects('<a href="javascript:alert(1)" href="https://example.com">x</a>', 'url-scheme-not-allowed');
    }

    //endregion
}

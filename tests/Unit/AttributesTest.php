<?php
declare(strict_types=1);

namespace Itools\HtmlValidator\Tests\Unit;

use Itools\HtmlValidator\Tests\Support\HtmlValidatorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Attribute names: event handlers, srcdoc, formaction and style under their switches, and
 * the tokenizer facts that decide what counts as an attribute at all.
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
            'custom element'             => ['<my-widget onclick="x">x</my-widget>', 'onclick'],
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
    //region Refused Names

    /** PHP stores a name of digits only as an int array key; the check runs on it all the same */
    public function testAttributeNamedWithDigitsOnly(): void
    {
        $this->assertAccepts('<p 1="x">x</p>');
        $this->assertRejects('<p 1="javascript:x">x</p>', 'url-scheme-not-allowed');
    }

    public function testSrcdoc(): void
    {
        $violation = $this->assertRejects('<iframe src="https://www.youtube.com/embed/x" srcdoc="<script>alert(1)</script>"></iframe>', 'attribute-not-allowed', 'srcdoc');
        $this->assertSame('The srcdoc attribute is not allowed', $violation->message);
    }

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

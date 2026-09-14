<?php
declare(strict_types=1);

namespace Itools\HtmlValidator\Tests\Unit;

use Itools\HtmlValidator\CharacterReferences;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CharacterReferencesTest extends TestCase
{
    #[DataProvider('providerText')]
    public function testText(string $input, string $expected): void
    {
        $this->assertSame($expected, CharacterReferences::decode($input, false));
    }

    public static function providerText(): array
    {
        return [
            'no ampersand, untouched'    => ['plain', 'plain'],
            'named'                      => ['&amp; &lt; &gt; &quot;', '& < > "'],
            'html5-only names'           => ['&colon; &Tab; &NewLine; &lpar;', ": \t \n ("],
            'unknown name stays'         => ['&bogus; &x;', '&bogus; &x;'],
            'legacy without semicolon'   => ['&amp &copy &lt', "& \u{A9} <"],
            'legacy prefix of a longer run' => ['&notit; &noti', "\u{AC}it; \u{AC}i"],
            'non-legacy without semicolon stays' => ['&colon &Tab', '&colon &Tab'],
            'decimal and hex'            => ['&#65; &#x41; &#X41;', 'A A A'],
            'missing semicolon on numeric still decodes' => ['&#65x &#x41x', 'Ax Ax'],
            'zero padded'                => ['&#0000065; &#x0000041;', 'A A'],
            'bare &# stays'              => ['&# &#x &#xg;', '&# &#x &#xg;'],
            'nul becomes U+FFFD'         => ['&#0; &#x0;', "\u{FFFD} \u{FFFD}"],
            'surrogates become U+FFFD'   => ['&#xD800; &#xDFFF;', "\u{FFFD} \u{FFFD}"],
            'out of range becomes U+FFFD' => ['&#x110000; &#99999999999;', "\u{FFFD} \u{FFFD}"],
            'C1 range maps to windows-1252' => ['&#x80; &#x92; &#x9F;', "\u{20AC} \u{2019} \u{178}"],
            'unmapped C1 stays'          => ['&#x81;', "\u{81}"],
            'decoding is one pass'       => ['&amp;lt; &amp;#60;', '&lt; &#60;'],
        ];
    }

    #[DataProvider('providerAttribute')]
    public function testAttribute(string $input, string $expected): void
    {
        $this->assertSame($expected, CharacterReferences::decode($input, true));
    }

    public static function providerAttribute(): array
    {
        return [
            'semicolon forms decode as in text'  => ['javascript&colon;x &#106;s', 'javascript:x js'],
            'legacy followed by = stays'         => ['?a=1&copy=2', '?a=1&copy=2'],
            'legacy followed by letter stays'    => ['&ampx &copyright', '&ampx &copyright'],
            'legacy followed by digit stays'     => ['&amp1', '&amp1'],
            'legacy followed by other decodes'   => ['&amp. &copy 2026 &lt', "&. \u{A9} 2026 <"],
            'legacy at the end decodes'          => ['a&amp', 'a&'],
        ];
    }
}

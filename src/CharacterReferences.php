<?php
declare(strict_types=1);

namespace Itools\HtmlValidator;

// import built-ins so calls resolve at compile time instead of per-call lookups; NamespacedCallsTest keeps this list exact
use function chr;
use function ctype_alnum;
use function hexdec;
use function html_entity_decode;
use function ltrim;
use function min;
use function preg_replace_callback;
use function str_contains;
use function strlen;
use function substr;

use const ENT_HTML5;
use const ENT_QUOTES;
use const PREG_OFFSET_CAPTURE;

/**
 * Decodes HTML character references (&amp; &#65; &#x41;) the way the HTML5 tokenizer does.
 *
 * Used on text and attribute values as the tokenizer produces them. The rules that differ
 * from html_entity_decode(): the 106 legacy names decode without a semicolon (&amp &copy
 * &lt), a legacy name followed by = or a letter inside an attribute value is left alone,
 * &#0; surrogates and out-of-range numbers become U+FFFD, and &#x80;-&#x9F; map through
 * windows-1252 (&#x80; is the euro sign). Unknown names stay as written.
 *
 * @internal The validator's own parser, not part of the public API; it can change between releases.
 */
final class CharacterReferences
{
    //region Tables

    // The names browsers decode with no trailing semicolon, from the WHATWG entities.json
    private const LEGACY = [
        'AElig' => "\u{C6}", 'AMP' => "&", 'Aacute' => "\u{C1}", 'Acirc' => "\u{C2}", 'Agrave' => "\u{C0}",
        'Aring' => "\u{C5}", 'Atilde' => "\u{C3}", 'Auml' => "\u{C4}", 'COPY' => "\u{A9}",
        'Ccedil' => "\u{C7}", 'ETH' => "\u{D0}", 'Eacute' => "\u{C9}", 'Ecirc' => "\u{CA}",
        'Egrave' => "\u{C8}", 'Euml' => "\u{CB}", 'GT' => ">", 'Iacute' => "\u{CD}", 'Icirc' => "\u{CE}",
        'Igrave' => "\u{CC}", 'Iuml' => "\u{CF}", 'LT' => "<", 'Ntilde' => "\u{D1}", 'Oacute' => "\u{D3}",
        'Ocirc' => "\u{D4}", 'Ograve' => "\u{D2}", 'Oslash' => "\u{D8}", 'Otilde' => "\u{D5}",
        'Ouml' => "\u{D6}", 'QUOT' => "\"", 'REG' => "\u{AE}", 'THORN' => "\u{DE}", 'Uacute' => "\u{DA}",
        'Ucirc' => "\u{DB}", 'Ugrave' => "\u{D9}", 'Uuml' => "\u{DC}", 'Yacute' => "\u{DD}",
        'aacute' => "\u{E1}", 'acirc' => "\u{E2}", 'acute' => "\u{B4}", 'aelig' => "\u{E6}",
        'agrave' => "\u{E0}", 'amp' => "&", 'aring' => "\u{E5}", 'atilde' => "\u{E3}", 'auml' => "\u{E4}",
        'brvbar' => "\u{A6}", 'ccedil' => "\u{E7}", 'cedil' => "\u{B8}", 'cent' => "\u{A2}",
        'copy' => "\u{A9}", 'curren' => "\u{A4}", 'deg' => "\u{B0}", 'divide' => "\u{F7}",
        'eacute' => "\u{E9}", 'ecirc' => "\u{EA}", 'egrave' => "\u{E8}", 'eth' => "\u{F0}", 'euml' => "\u{EB}",
        'frac12' => "\u{BD}", 'frac14' => "\u{BC}", 'frac34' => "\u{BE}", 'gt' => ">", 'iacute' => "\u{ED}",
        'icirc' => "\u{EE}", 'iexcl' => "\u{A1}", 'igrave' => "\u{EC}", 'iquest' => "\u{BF}",
        'iuml' => "\u{EF}", 'laquo' => "\u{AB}", 'lt' => "<", 'macr' => "\u{AF}", 'micro' => "\u{B5}",
        'middot' => "\u{B7}", 'nbsp' => "\u{A0}", 'not' => "\u{AC}", 'ntilde' => "\u{F1}",
        'oacute' => "\u{F3}", 'ocirc' => "\u{F4}", 'ograve' => "\u{F2}", 'ordf' => "\u{AA}",
        'ordm' => "\u{BA}", 'oslash' => "\u{F8}", 'otilde' => "\u{F5}", 'ouml' => "\u{F6}", 'para' => "\u{B6}",
        'plusmn' => "\u{B1}", 'pound' => "\u{A3}", 'quot' => "\"", 'raquo' => "\u{BB}", 'reg' => "\u{AE}",
        'sect' => "\u{A7}", 'shy' => "\u{AD}", 'sup1' => "\u{B9}", 'sup2' => "\u{B2}", 'sup3' => "\u{B3}",
        'szlig' => "\u{DF}", 'thorn' => "\u{FE}", 'times' => "\u{D7}", 'uacute' => "\u{FA}",
        'ucirc' => "\u{FB}", 'ugrave' => "\u{F9}", 'uml' => "\u{A8}", 'uuml' => "\u{FC}", 'yacute' => "\u{FD}",
        'yen' => "\u{A5}", 'yuml' => "\u{FF}",
    ];

    // &#x80;-&#x9F; are C1 controls in Unicode, browsers decode them as windows-1252 instead
    private const WINDOWS_1252 = [
        0x80 => 0x20AC, 0x82 => 0x201A, 0x83 => 0x0192, 0x84 => 0x201E, 0x85 => 0x2026, 0x86 => 0x2020,
        0x87 => 0x2021, 0x88 => 0x02C6, 0x89 => 0x2030, 0x8A => 0x0160, 0x8B => 0x2039, 0x8C => 0x0152,
        0x8E => 0x017D, 0x91 => 0x2018, 0x92 => 0x2019, 0x93 => 0x201C, 0x94 => 0x201D, 0x95 => 0x2022,
        0x96 => 0x2013, 0x97 => 0x2014, 0x98 => 0x02DC, 0x99 => 0x2122, 0x9A => 0x0161, 0x9B => 0x203A,
        0x9C => 0x0153, 0x9E => 0x017E, 0x9F => 0x0178,
    ];

    // &#hex or &#decimal or &name, each with an optional semicolon; a bare &# or &#x matches nothing and stays as text
    private const REFERENCE = '/&(?:#[xX]([0-9a-fA-F]+)|#([0-9]+)|([A-Za-z0-9]+));?/';

    //endregion
    //region Public API

    public static function decode(string $text, bool $inAttribute): string
    {
        if (!str_contains($text, '&')) {
            return $text;
        }
        return preg_replace_callback(
            self::REFERENCE,
            static fn(array $m): string => self::replace($m, $text, $inAttribute),
            $text,
            flags: PREG_OFFSET_CAPTURE,
        );
    }

    //endregion
    //region Decoding

    /**
     * @param array<int, array{string, int}> $m match with offsets: [whole, hex, decimal, name]
     */
    private static function replace(array $m, string $text, bool $inAttribute): string
    {
        [$whole, $offset] = $m[0];

        // numeric
        if (($m[1][1] ?? -1) >= 0 || ($m[2][1] ?? -1) >= 0) {
            $codepoint = ($m[1][1] ?? -1) >= 0 ? self::hexToInt($m[1][0]) : self::decToInt($m[2][0]);
            return self::codepointToUtf8($codepoint);
        }

        // named, with the semicolon: any of the 2231 names
        $name = $m[3][0];
        if ($whole[-1] === ';') {
            $decoded = html_entity_decode($whole, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded !== $whole) {
                return $decoded;
            }
        }

        // legacy name with no semicolon: the longest one that starts the run wins (&notit; is &not + "it;").
        // Legacy names are two to six letters, so only the first six of a longer run are looked up
        for ($len = min(strlen($name), 6); $len >= 2; $len--) {
            $legacy = self::LEGACY[substr($name, 0, $len)] ?? null;
            if ($legacy === null) {
                continue;
            }
            $rest = substr($whole, 1 + $len);                          // what follows the matched name, inside the match
            $next = $rest !== '' ? $rest[0] : ($text[$offset + strlen($whole)] ?? '');
            if ($inAttribute && ($next === '=' || ctype_alnum($next))) {
                return $whole;                                         // &copy=1 in a URL stays as written
            }
            return $legacy . $rest;
        }
        return $whole;
    }

    private static function hexToInt(string $hex): int
    {
        $hex = ltrim($hex, '0');
        return strlen($hex) > 6 ? 0x110000 : (int)hexdec($hex);      // more than 6 digits is past U+10FFFF anyway
    }

    private static function decToInt(string $dec): int
    {
        $dec = ltrim($dec, '0');
        return strlen($dec) > 7 ? 0x110000 : (int)$dec;
    }

    private static function codepointToUtf8(int $cp): string
    {
        if ($cp === 0 || $cp > 0x10FFFF || ($cp >= 0xD800 && $cp <= 0xDFFF)) {
            return "\u{FFFD}";
        }
        $cp = self::WINDOWS_1252[$cp] ?? $cp;
        return match (true) {
            $cp < 0x80    => chr($cp),
            $cp < 0x800   => chr(0xC0 | $cp >> 6) . chr(0x80 | $cp & 0x3F),
            $cp < 0x10000 => chr(0xE0 | $cp >> 12) . chr(0x80 | $cp >> 6 & 0x3F) . chr(0x80 | $cp & 0x3F),
            default       => chr(0xF0 | $cp >> 18) . chr(0x80 | $cp >> 12 & 0x3F) . chr(0x80 | $cp >> 6 & 0x3F) . chr(0x80 | $cp & 0x3F),
        };
    }

    //endregion
}

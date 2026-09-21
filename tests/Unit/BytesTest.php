<?php
declare(strict_types=1);

namespace Itools\HtmlValidator\Tests\Unit;

use Itools\HtmlValidator\HtmlValidator;
use Itools\HtmlValidator\Tests\Support\HtmlValidatorTestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The byte checks that run before tokenizing: invalid UTF-8 and control characters. When
 * one fails nothing else is reported, since the tokenizer never runs.
 *
 * Codes: not-utf8, control-character.
 */
final class BytesTest extends HtmlValidatorTestCase
{
    #[DataProvider('validUtf8Provider')]
    public function testValidUtf8(string $html): void
    {
        $this->assertAccepts($html);
    }

    public static function validUtf8Provider(): array
    {
        return [
            'ascii'             => ['<p>Hello</p>'],
            'two byte'          => ['<p>café</p>'],
            'three byte'        => ['<p>日本語</p>'],
            'four byte'         => ['<p>😀</p>'],
            'tab lf cr'         => ["<p>\ta\nb\rc\r\n</p>"],
            'empty'             => [''],
            'nbsp and ellipsis' => ["<p>a\u{A0}b\u{2026}</p>"],
        ];
    }

    #[DataProvider('invalidUtf8Provider')]
    public function testInvalidUtf8(string $html, string $detail): void
    {
        $violation = $this->assertRejects($html, 'not-utf8', $detail);
        $this->assertSame("Content must be UTF-8, this is not: $detail", $violation->message);
    }

    public static function invalidUtf8Provider(): array
    {
        return [
            'latin1 byte'          => ["<p>caf\xE9</p>", '\351</p>'],
            'lone continuation'    => ["<p>\x80</p>", '\200</p>'],
            'overlong encoding'    => ["<p>\xC0\xAF</p>", '\300\257</p>'],
            'surrogate'            => ["<p>\xED\xA0\x80</p>", '\355\240\200</p>'],
            'above U+10FFFF'       => ["<p>\xF4\x90\x80\x80</p>", '\364\220\200\200</p>'],
            'truncated sequence'   => ["<p>\xE2\x82", '\342\202'],
            'detail is cut short'  => ["<p>\xFF" . str_repeat('x', 40), '\377xxxxxxxxxxxxxxx...'],
        ];
    }

    /** The prefix pattern over the whole string reaches pcre.backtrack_limit at a megabyte without the JIT, and check() threw a TypeError */
    public function testInvalidUtf8AfterMegabytesOfValidText(): void
    {
        $this->assertRejects('<p>' . str_repeat('a', 2097152) . "\xFF</p>", 'not-utf8', '\377</p>');
    }

    public function testInvalidUtf8StopsEveryOtherCheck(): void
    {
        $result = HtmlValidator::check("<script>\xFF</script>");
        $this->assertCount(1, $result->errors);
        $this->assertSame('not-utf8', $result->errors[0]->code);
    }

    #[DataProvider('controlCharacterProvider')]
    public function testControlCharacter(string $html, string $detail): void
    {
        $violation = $this->assertRejects($html, 'control-character', $detail);
        $this->assertSame("Content contains a control character: $detail", $violation->message);
    }

    public static function controlCharacterProvider(): array
    {
        return [
            'nul in text'        => ["<p>a\0b</p>", '\000 at byte 4'],
            'nul in a tag name'  => ["<scr\0ipt>", '\000 at byte 4'],
            'nul in a value'     => ["<a href=\"java\0script:x\">", '\000 at byte 13'],
            'escape'             => ["<p>\x1B[31m</p>", '\033 at byte 3'],
            'form feed'          => ["<p>\x0C</p>", '\f at byte 3'],
            'vertical tab'       => ["<p>\x0B</p>", '\v at byte 3'],
            'first one reported' => ["\x01\x02", '\001 at byte 0'],
        ];
    }

    public function testControlCharacterStopsEveryOtherCheck(): void
    {
        $result = HtmlValidator::check("<script>\x01</script>");
        $this->assertCount(1, $result->errors);
        $this->assertSame('control-character', $result->errors[0]->code);
    }

    public function testDeleteAndC1ControlsPass(): void
    {
        $this->assertAccepts("<p>a\x7Fb\u{85}c</p>");   // not C0, and harmless to the tokenizer
    }
}

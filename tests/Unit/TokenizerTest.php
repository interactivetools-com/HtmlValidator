<?php
declare(strict_types=1);

namespace Itools\HtmlValidator\Tests\Unit;

use Itools\HtmlValidator\Token;
use Itools\HtmlValidator\Tokenizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The parsing facts a validator depends on, each one a bypass if the tokenizer got it wrong.
 * The html5lib suite covers the tokenizer in general; these are the cases that matter here.
 */
final class TokenizerTest extends TestCase
{
    #[DataProvider('providerStartTags')]
    public function testStartTagFacts(string $html, string $name, array $attributes, bool $selfClosing = false): void
    {
        $tokens = $this->tokens($html);
        $this->assertSame(Token::START_TAG, $tokens[0]->type);
        $this->assertSame($name, $tokens[0]->name);
        $this->assertSame($attributes, $tokens[0]->attributes);
        $this->assertSame($selfClosing, $tokens[0]->selfClosing);
    }

    public static function providerStartTags(): array
    {
        return [
            'names are lowercased'              => ['<ScRiPt SRC=x>', 'script', ['src' => 'x']],
            'slash is a separator'              => ['<p/onclick="x()">', 'p', ['onclick' => 'x()']],
            'first duplicate attribute wins'    => ['<p ONCLICK="a" onClick="b">', 'p', ['onclick' => 'a']],
            'unquoted value ends at whitespace' => ['<img src=x onerror=alert(1)>', 'img', ['src' => 'x', 'onerror' => 'alert(1)']],
            'newline before ='                  => ["<img src=x onerror\n=alert(1)>", 'img', ['src' => 'x', 'onerror' => 'alert(1)']],
            'no space between attributes'       => ['<a href="x"onclick="y">', 'a', ['href' => 'x', 'onclick' => 'y']],
            'entity in value decodes'           => ['<a href="javascript&colon;alert(1)">', 'a', ['href' => 'javascript:alert(1)']],
            'numeric entity in value decodes'   => ['<a href="&#106;avascript:alert(1)">', 'a', ['href' => 'javascript:alert(1)']],
            'tab entity in value decodes'       => ['<a href="jav&Tab;ascript:x">', 'a', ['href' => "jav\tascript:x"]],
            'entity in attribute name does not' => ['<p on&#99;lick="x">', 'p', ['on&#99;lick' => 'x']],
            'legacy entity before = stays'      => ['<a href="?a=1&copy=2">', 'a', ['href' => '?a=1&copy=2']],
            'legacy entity elsewhere decodes'   => ['<a title="&copy 2026">', 'a', ['title' => "\u{A9} 2026"]],
            'NUL in name becomes U+FFFD'        => ["<scr\0ipt>", "scr\u{FFFD}ipt", []],
            'tag name can contain <'            => ['<a<b href=x>', 'a<b', ['href' => 'x']],
            'self-closing flag'                 => ['<br/>', 'br', [], true],
            'attribute with no value'           => ['<input disabled>', 'input', ['disabled' => '']],
            'leading = starts the name'         => ['<p =x>', 'p', ['=x' => '']],
        ];
    }

    public function testEntityInTextIsText(): void
    {
        $tokens = $this->tokens('&lt;script&gt;alert(1)&lt;/script&gt;');
        $this->assertCount(1, $tokens);
        $this->assertSame(Token::TEXT, $tokens[0]->type);
        $this->assertSame('<script>alert(1)</script>', $tokens[0]->data);
    }

    public function testCommentClosedByDashDashBang(): void
    {
        $tokens = $this->tokens('<!-- a --!><img src=x onerror=alert(1)> -->');
        $this->assertSame([Token::COMMENT, Token::START_TAG, Token::TEXT], array_column($tokens, 'type'));
        $this->assertSame('img', $tokens[1]->name);
    }

    public function testEmptyCommentForms(): void
    {
        foreach (['<!-->', '<!--->', '<!---->'] as $html) {
            $tokens = $this->tokens($html . 'x');
            $this->assertSame(Token::COMMENT, $tokens[0]->type, $html);
            $this->assertSame('', $tokens[0]->data, $html);
            $this->assertSame('x', $tokens[1]->data, $html);
        }
    }

    public function testProcessingInstructionIsABogusComment(): void
    {
        $tokens = $this->tokens('<?php echo 1; ?><b>');
        $this->assertSame(Token::COMMENT, $tokens[0]->type);
        $this->assertSame('?php echo 1; ?', $tokens[0]->data);
        $this->assertSame('b', $tokens[1]->name);
    }

    public function testCdataOutsideForeignContentIsABogusComment(): void
    {
        $tokens = $this->tokens('<![CDATA[<img src=x onerror=alert(1)>]]>');
        $this->assertSame(Token::COMMENT, $tokens[0]->type);
        $this->assertSame('[CDATA[<img src=x onerror=alert(1)', $tokens[0]->data);
    }

    public function testEndTagForms(): void
    {
        $this->assertSame([], $this->tokens('</>'));
        $this->assertSame('</', $this->tokens('</')[0]->data);
        $this->assertSame(Token::COMMENT, $this->tokens('</ x>')[0]->type);
        $this->assertSame(Token::COMMENT, $this->tokens('</3>')[0]->type);
        $end = $this->tokens('</DIV class=x/>')[0];
        $this->assertSame(Token::END_TAG, $end->type);
        $this->assertSame('div', $end->name);
        $this->assertSame([], $end->attributes);
    }

    #[DataProvider('providerUnclosed')]
    public function testEndOfInputInsideMarkupEndsWithAnUnclosedToken(string $html, array $typesBefore, string $name, int $start): void
    {
        $tokens = $this->tokens($html);
        $last   = array_pop($tokens);
        $this->assertSame($typesBefore, array_column($tokens, 'type'));
        $this->assertSame(Token::UNCLOSED, $last->type);
        $this->assertSame($name, $last->name);
        $this->assertSame([$start, strlen($html)], [$last->start, $last->end]);
    }

    public static function providerUnclosed(): array
    {
        return [
            'tag'                     => ['<img src=x onerror=alert(1)', [], 'img', 0],
            'quoted value'            => ['a<a href="x', [Token::TEXT], 'a', 1],
            'attribute name'          => ['<p class', [], 'p', 0],
            'end tag'                 => ['<p>x</p', [Token::START_TAG, Token::TEXT], 'p', 4],
            'comment'                 => ['<!-- x --', [Token::COMMENT], '', 0],
            'bogus comment'           => ['<?php x', [Token::COMMENT], '', 0],
            'doctype'                 => ['<!DOCTYPE html', [Token::DOCTYPE], '', 0],
            'raw text'                => ['<b><style>p { }', [Token::START_TAG, Token::START_TAG, Token::TEXT], 'style', 3],
            'raw text with no text'   => ['<textarea>', [Token::START_TAG], 'textarea', 0],
            'plaintext'               => ['<plaintext>x', [Token::START_TAG, Token::TEXT], 'plaintext', 0],
        ];
    }

    public function testEndOfInputElsewhereIsNotUnclosed(): void
    {
        $this->assertSame('<', $this->tokens('<')[0]->data);
        $this->assertSame('</', $this->tokens('</')[0]->data);
        $this->assertSame([Token::START_TAG, Token::TEXT, Token::END_TAG], array_column($this->tokens('<p>x</p>'), 'type'));
        $this->assertSame([Token::COMMENT], array_column($this->tokens('<!-- x -->'), 'type'));
        $this->assertSame([Token::START_TAG, Token::TEXT, Token::END_TAG], array_column($this->tokens('<style>p { }</style>'), 'type'));
    }

    public function testRawTextElementsSwallowTags(): void
    {
        $tokens = $this->tokens('<style><img src=x onerror=alert(1)></style><b>');
        $this->assertSame([Token::START_TAG, Token::TEXT, Token::END_TAG, Token::START_TAG], array_column($tokens, 'type'));
        $this->assertSame('<img src=x onerror=alert(1)>', $tokens[1]->data);
        $this->assertSame('b', $tokens[3]->name);
    }

    public function testNoscriptIsRawText(): void
    {
        $html   = '<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>';
        $tokens = $this->tokens($html);
        // the browser (scripting on) ends noscript at the first </noscript>, so the img is live
        $this->assertSame('<p title="', $tokens[1]->data);
        $this->assertSame('img', $tokens[3]->name);
    }

    public function testRcdataDecodesEntitiesButNotTags(): void
    {
        $tokens = $this->tokens('<textarea>&lt;b&gt; <b></textarea>');
        $this->assertSame('<b> <b>', $tokens[1]->data);
        $this->assertSame(Token::END_TAG, $tokens[2]->type);
    }

    public function testRawTextEndTagMustMatch(): void
    {
        $tokens = $this->tokens('<style></styles></div></style>');
        $this->assertSame('</styles></div>', $tokens[1]->data);
    }

    public function testStateSwitchingCanBeTurnedOff(): void
    {
        $tokens = iterator_to_array((new Tokenizer('<title><b>', switchOnElements: false))->tokens(), false);
        $this->assertSame('b', $tokens[1]->name);
    }

    public function testPlaintextTakesTheRest(): void
    {
        $tokens = $this->tokens('<plaintext><b></plaintext>');
        $this->assertCount(3, $tokens);
        $this->assertSame('<b></plaintext>', $tokens[1]->data);
        $this->assertSame(Token::UNCLOSED, $tokens[2]->type);
    }

    public function testOffsetsCoverTheSource(): void
    {
        $html   = "a<b class=x>c<!-- d --></b>";
        $tokens = $this->tokens($html);
        $this->assertSame(['a', '<b class=x>', 'c', '<!-- d -->', '</b>'], array_map(
            static fn(Token $t): string => substr($html, $t->start, $t->end - $t->start),
            $tokens,
        ));
    }

    public function testCarriageReturnsBecomeNewlines(): void
    {
        $tokens = $this->tokens("a\r\nb\rc");
        $this->assertSame("a\nb\nc", $tokens[0]->data);
    }

    public function testDoctypeKeepsRawText(): void
    {
        $token = $this->tokens('<!DOCTYPE html PUBLIC "x"><p>')[0];
        $this->assertSame(Token::DOCTYPE, $token->type);
        $this->assertSame(' html PUBLIC "x"', $token->data);
    }

    /** @return Token[] */
    private function tokens(string $html): array
    {
        return iterator_to_array((new Tokenizer($html))->tokens(), false);
    }
}

<?php
declare(strict_types=1);

namespace Itools\HtmlValidator\Tests\Unit;

use Itools\HtmlValidator\HtmlValidator;
use Itools\HtmlValidator\Result;
use Itools\HtmlValidator\Tests\Support\HtmlValidatorTestCase;
use Itools\HtmlValidator\Token;
use Itools\HtmlValidator\Tokenizer;
use Itools\HtmlValidator\Violation;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * The fast path is a regex for markup the rules can never refuse, which the tokenizer steps
 * over without producing tokens. It may only skip what the full check would pass, so:
 *
 * - every run it skips passes the check on its own with the fast path off, and tokenizes to
 *   text, start tags and end tags only, the tokens the check never refuses
 * - the near miss of every rule, placed first in the content, is never matched
 * - the check reports the same violations with the fast path on and off, over the fixtures,
 *   every html5lib tokenizer input, and the downloaded corpus when it is present
 *
 * Both values of $allowStyles are covered, since the regex changes with it.
 */
final class FastPathTest extends HtmlValidatorTestCase
{
    private const FIXTURES = __DIR__ . '/../Support/fixtures';
    private const CORPUS   = __DIR__ . '/../../corpus';

    /** Content as an editor writes it, with every attribute form the fast path accepts. Each is skipped whole. */
    private const EDITOR_CONTENT = [
        'text and inline tags' => '<p>Plain <strong>bold</strong> and <em>italic</em> text.</p>',
        'links'                => '<p class="lead">A <a href="https://example.com/a/?x=1&amp;y=2" target="_blank" rel="noopener">link</a>, <a href="/relative/">another</a>, <a href="mailto:a@example.com">mail</a>, <a href="tel:+1604">tel</a>.</p>',
        'images'               => '<p><img src="/uploads/photo.jpg" alt="A photo" width="800" height="533" /><br><br/></p>',
        'inline styles'        => '<table style="width: 100%; border-collapse: collapse;" cellpadding="0"><tbody><tr><td style="padding: 20px; font-family: Arial, sans-serif; color: #333;" valign="top">cell</td></tr></tbody></table>',
        'blocks'               => '<div class="hero"><h1>Title</h1><ul><li>one</li><li>two</li></ul><blockquote><p>quote</p></blockquote></div>',
        'whitespace in tags'   => "<p>tabs\tand\nnewlines <span\tclass=\"x\"\ndata-id=\"7\">in tags</span></p>",
        'upper case'           => '<P CLASS="Upper">Upper-case <A HREF="HTTPS://EXAMPLE.COM/">tags</A></P>',
        'scroll-behavior'      => '<p style="scroll-behavior: smooth; margin: 0">a property name that ends in behavior</p>',
        'text with references' => '<p>text with &amp; &nbsp; &eacute; references, 1 < 2, a lone < and 3 > 2</p>',
    ];

    /** Content the fast path skips in part: the rest falls through to the rules. */
    private const MIXED_CONTENT = [
        'colon in a plain value'   => '<p title="Note: a colon" data-x="1">falls through and passes</p>',
        'javascript in data-href'  => '<p data-href="javascript:x">falls through and is refused</p>',
        'style element'            => '<p>before</p><style>p { color: red }</style><p>after</p>',
        'script element'           => '<p>before</p><script>alert(1)</script><p>after</p>',
        'custom element'           => '<p>before</p><my-widget data-x="1">custom</my-widget><p>after</p>',
        'unclosed tag at the end'  => '<p>before</p><img src="x',
        'comment'                  => '<p>before</p><!-- a comment --><p>after</p>',
        'single-quoted value'      => "<p class='x'>falls through and passes</p>",
    ];

    //region Skipped Runs

    /** @return iterable<string, array{string}> */
    public static function contentProvider(): iterable
    {
        foreach ([...self::EDITOR_CONTENT, ...self::MIXED_CONTENT] as $name => $html) {
            yield $name => [$html];
        }
        foreach (glob(self::FIXTURES . '/{accept,reject,tinymce4}/*.html', GLOB_BRACE) as $path) {
            yield basename($path) => [file_get_contents($path)];
        }
    }

    /** @return iterable<string, array{string}> the content the tokenizer gets to see: what passes the byte checks, which run first and alone */
    public static function tokenizedContentProvider(): iterable
    {
        foreach (self::contentProvider() as $name => $case) {
            $code = HtmlValidator::check($case[0])->errors[0]->code ?? '';
            if ($code !== 'not-utf8' && $code !== 'control-character') {
                yield $name => $case;
            }
        }
    }

    #[DataProvider('tokenizedContentProvider')]
    public function testSkippedRunsPassOnTheirOwn(string $html): void
    {
        foreach ([true, false] as $allowStyles) {
            $this->withSettings(['allowStyles' => $allowStyles, 'fastPath' => false], function () use ($html) {
                foreach (self::skippedRuns($html) as $run) {
                    $this->assertAccepts($run);
                    foreach ((new Tokenizer($run))->tokens() as $token) {
                        $this->assertContains($token->type, [Token::TEXT, Token::START_TAG, Token::END_TAG], "the fast path skipped a $token->type token in: $run");
                    }
                }
            });
        }
    }

    /** @return iterable<string, array{string}> */
    public static function editorContentProvider(): iterable
    {
        foreach (self::EDITOR_CONTENT as $name => $html) {
            yield $name => [$html];
        }
    }

    /** The regex covers what an editor writes, so the tokenizer sees none of it */
    #[DataProvider('editorContentProvider')]
    public function testEditorContentIsSkippedWhole(string $html): void
    {
        $this->assertSame([$html], self::skippedRuns($html));
    }

    public function testSkippedRunsProduceNoTokens(): void
    {
        $tokens = self::tokens('<p>x</p><script>y</script><p>z</p>', self::pattern());
        $this->assertSame([[Token::START_TAG, 'script'], [Token::TEXT, 'y'], [Token::END_TAG, 'script']], $tokens);
    }

    /** A skip regex that hits pcre.backtrack_limit returns false; the tokenizer then reads the content as usual */
    public function testPastAPcreLimitTheTokenizerCarriesOn(): void
    {
        $html = str_repeat('a', 64) . '<p>x</p>b';   // (?:a+)+b on this backtracks past the limit, with the JIT on or off
        $this->assertSame(self::tokens($html, null), self::tokens($html, '/(?:a+)+b/A'));
        $this->assertCount(5, self::tokens($html, null));
    }

    /**
     * Past the limit the regex runs over 64 KB windows. A < on a window's last byte is not text, whatever the window
     * shows after it: with </b> tags the windows align, so the text puts the < of <script> exactly on an edge
     */
    public function testATagOnAWindowEdgeIsNotSkipped(): void
    {
        $limit = ini_set('pcre.backtrack_limit', '1000000');   // the default, so the run of end tags reaches it with the JIT on or off
        try {
            $html   = str_repeat('</b>', 16384 * 96) . str_repeat('x', 65535) . '<script>alert(1)</script>';   // 96 full windows, then a window of text ending on <
            $result = HtmlValidator::check($html);
        } finally {
            ini_set('pcre.backtrack_limit', $limit);
        }
        $this->assertSame(['element-not-allowed'], array_map(fn(Violation $violation): string => $violation->code, $result->errors));
    }

    //endregion
    //region Near Misses

    /** @return array<string, array{string}> */
    public static function nearMissProvider(): array
    {
        return [
            'handler'                 => ['<p onclick="x">'],
            'handler after attribute' => ['<p class="x"onclick="y">'],
            'slash separator'         => ['<p/onclick="x">'],
            'javascript href'         => ['<a href="javascript:x">'],
            'data href'               => ['<a href="data:x">'],
            'colon in a plain value'  => ['<p title="javascript:x">'],
            'reference in a value'    => ['<a href="&#106;avascript:x">'],
            'srcdoc'                  => ['<p srcdoc="x">'],
            'formaction'              => ['<p formaction="https://x">'],
            'style with a function'   => ['<p style="width: expression(1)">'],
            'style with an escape'    => ['<p style="x: \61">'],
            'style with behavior'     => ['<p style="behavior: x">'],
            'style with a reference'  => ['<p style="x: &#40;">'],
            'style with an at-rule'   => ['<p style="@import x">'],
            'unquoted value'          => ['<p class=x>'],
            'single-quoted value'     => ["<p class='x'>"],
            'style element'           => ['<style>'],
            'iframe'                  => ['<iframe src="https://www.youtube.com/embed/x">'],
            'script'                  => ['<script>'],
            'textarea'                => ['<textarea>'],
            'custom element'          => ['<my-widget>'],
            'form'                    => ['<form>'],
            'unknown element'         => ['<pa>'],
            'comment'                 => ['<!-- x -->'],
            'bogus comment'           => ['<?x'],
            'markup declaration'      => ['<!x>'],
            'end tag with a space'    => ['</ x>'],
            'doctype'                 => ['<!DOCTYPE html>'],
            'unclosed tag'            => ['<img src="x"'],
            'unclosed end tag'        => ['</p'],
            'end tag with attributes' => ['</p class="x">'],
        ];
    }

    #[DataProvider('nearMissProvider')]
    public function testNearMissIsNotSkipped(string $html): void
    {
        foreach ([true, false] as $allowStyles) {
            $this->withSettings(['allowStyles' => $allowStyles], function () use ($html) {
                $this->assertSame(0, preg_match(self::pattern(), $html), "the fast path matched: $html");
            });
        }
    }

    //endregion
    //region Same Result On and Off

    #[DataProvider('contentProvider')]
    public function testSameResultOnAndOff(string $html): void
    {
        $this->assertSameOnAndOff($html);
    }

    #[DataProvider('nearMissProvider')]
    public function testSameResultOnAndOffAfterANearMiss(string $html): void
    {
        $this->assertSameOnAndOff("<p>before</p>$html<p>after</p>");
    }

    public function testSameResultOnAndOffForEveryHtml5libInput(): void
    {
        $count = 0;
        foreach (glob(self::FIXTURES . '/html5lib/*.test') as $file) {
            if (str_ends_with($file, 'xmlViolation.test')) {
                continue;   // an XML-compatibility mode, not the HTML tokenizer, and keyed differently
            }
            $doc = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            foreach ($doc['tests'] as $test) {
                $input = ($test['doubleEscaped'] ?? false) ? json_decode('"' . $test['input'] . '"') : $test['input'];
                if (is_string($input)) {
                    $this->assertSameOnAndOff($input);
                    $count++;
                }
            }
        }
        $this->assertGreaterThan(1000, $count);
    }

    public function testSameResultOnAndOffForTheCorpus(): void
    {
        if (!is_dir(self::CORPUS)) {
            $this->markTestSkipped('run php tools/fetch-corpus.php to check the corpus too');
        }
        $count = 0;
        foreach (glob(self::CORPUS . '/*/{*,*/*}.html', GLOB_BRACE) as $path) {
            $this->assertSameOnAndOff(file_get_contents($path));
            $count++;
        }
        $this->assertGreaterThan(1000, $count);
    }

    //endregion
    //region Helpers

    private function assertSameOnAndOff(string $html): void
    {
        foreach ([true, false] as $allowStyles) {
            $on  = $this->withSettings(['allowStyles' => $allowStyles, 'fastPath' => true], fn() => HtmlValidator::check($html));
            $off = $this->withSettings(['allowStyles' => $allowStyles, 'fastPath' => false], fn() => HtmlValidator::check($html));
            $this->assertSame(self::violations($off), self::violations($on), 'fast path on and off disagree on: ' . json_encode($html, JSON_UNESCAPED_UNICODE));
        }
    }

    /** @return array<int, array{string, string}> */
    private static function violations(Result $result): array
    {
        return array_map(fn(Violation $violation) => [$violation->code, $violation->detail], $result->errors);
    }

    /** The runs the fast path steps over in $html: the gaps between the tokens the tokenizer produces with the regex */
    private static function skippedRuns(string $html): array
    {
        $html = str_replace(["\r\n", "\r"], "\n", $html);   // offsets are in the string after the tokenizer's line-ending step
        $runs = [];
        $pos  = 0;
        foreach ((new Tokenizer($html, skip: self::pattern()))->tokens() as $token) {
            if ($token->start > $pos) {
                $runs[] = substr($html, $pos, $token->start - $pos);
            }
            $pos = max($pos, $token->end);
        }
        if ($pos < strlen($html)) {
            $runs[] = substr($html, $pos);
        }
        return $runs;
    }

    private static function pattern(): string
    {
        return (new ReflectionMethod(HtmlValidator::class, 'knownSafePattern'))->invoke(null);
    }

    /** @return array<int, array{string, string}> type and name or text of every token */
    private static function tokens(string $html, ?string $skip): array
    {
        $tokens = [];
        foreach ((new Tokenizer($html, skip: $skip))->tokens() as $token) {
            $tokens[] = [$token->type, $token->type === Token::TEXT ? $token->data : $token->name];
        }
        return $tokens;
    }

    //endregion
}

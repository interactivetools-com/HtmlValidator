<?php
declare(strict_types=1);

namespace Itools\HtmlValidator\Tests\Integration;

use Itools\HtmlValidator\Token;
use Itools\HtmlValidator\Tokenizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Runs the html5lib tokenizer test suite (tests/Support/fixtures/html5lib, MIT, from
 * github.com/html5lib/html5lib-tests) against Tokenizer with element state switching off,
 * the way the suite expects a bare tokenizer to behave.
 *
 * Skipped on purpose, counted in providerSkips():
 * - initial states the tokenizer does not implement: script data (script content is read as
 *   raw text), CDATA section (foreign content only)
 * - tests that expect a parsed DOCTYPE (name, public and system ids); Tokenizer keeps the raw text
 * - inputs with lone surrogates, which are not valid UTF-8
 * - xmlViolation.test, which describes an XML-compatibility mode, not the HTML tokenizer
 */
final class Html5libTokenizerTest extends TestCase
{
    private const FIXTURES = __DIR__ . '/../Support/fixtures/html5lib';

    private const STATES = [
        'Data state'      => Tokenizer::STATE_DATA,
        'RCDATA state'    => Tokenizer::STATE_RCDATA,
        'RAWTEXT state'   => Tokenizer::STATE_RAWTEXT,
        'PLAINTEXT state' => Tokenizer::STATE_PLAINTEXT,
    ];

    /** @var array<string, int> */
    private static array $skips = [];

    #[DataProvider('providerCases')]
    public function testMatchesHtml5lib(string $input, string $state, string $lastStartTag, array $expected): void
    {
        $tokenizer = new Tokenizer($input, switchOnElements: false, state: $state, lastStartTag: $lastStartTag);
        $actual    = [];
        foreach ($tokenizer->tokens() as $token) {
            if ($token->type === Token::UNCLOSED) {
                continue;   // the suite tokenizes whole documents, where nothing follows the unfinished markup
            }
            self::append($actual, self::toHtml5libForm($token));
        }
        $this->assertSame($expected, $actual, "input: " . json_encode($input, JSON_UNESCAPED_UNICODE));
    }

    public function testSkipCountsAreAsDocumented(): void
    {
        // Loading the provider populates the skip counts; changes here mean a fixture update or a new tokenizer feature
        iterator_to_array(self::providerCases());
        $this->assertEquals([
            'doctype internals'   => 855,
            'Script data state'   => 89,
            'CDATA section state' => 56,
            'invalid utf-8 input' => 4,
        ], self::$skips);
    }

    /** @return iterable<string, array{string, string, string, array}> */
    public static function providerCases(): iterable
    {
        self::$skips = [];
        foreach (glob(self::FIXTURES . '/*.test') as $file) {
            if (str_ends_with($file, 'xmlViolation.test')) {
                continue;
            }
            $doc = json_decode(file_get_contents($file), true, flags: JSON_THROW_ON_ERROR);
            foreach ($doc['tests'] as $i => $test) {
                $unescape = static fn(mixed $s): mixed => ($test['doubleEscaped'] ?? false) && is_string($s) ? json_decode('"' . $s . '"') : $s;
                $input    = $unescape($test['input']);
                if ($input === null || !mb_check_encoding($input, 'UTF-8')) {
                    self::skip('invalid utf-8 input');
                    continue;
                }

                $expected   = [];
                $hasDoctype = false;
                foreach ($test['output'] as $token) {
                    $token = array_map($unescape, $token);
                    if ($token[0] === 'DOCTYPE') {
                        $hasDoctype = true;
                    } elseif ($token[0] === 'StartTag') {
                        $token[2] = array_map($unescape, (array)$token[2]);
                    }
                    self::append($expected, $token);
                }

                foreach ($test['initialStates'] ?? ['Data state'] as $stateName) {
                    if (!isset(self::STATES[$stateName])) {
                        self::skip($stateName);
                        continue;
                    }
                    if ($hasDoctype) {
                        self::skip('doctype internals');
                        continue;
                    }
                    if (preg_match('/<script/i', $input)) {
                        self::skip('Script data state');
                        continue;
                    }
                    $key = basename($file, '.test') . " #$i [$stateName] " . ($test['description'] ?? '');
                    yield $key => [$input, self::STATES[$stateName], $test['lastStartTag'] ?? '', $expected];
                }
            }
        }
    }

    private static function skip(string $reason): void
    {
        self::$skips[$reason] = (self::$skips[$reason] ?? 0) + 1;
    }

    /** Adjacent character tokens compare as one, as the suite allows */
    private static function append(array &$tokens, array $token): void
    {
        if ($token[0] === 'Character' && $tokens !== [] && $tokens[array_key_last($tokens)][0] === 'Character') {
            $tokens[array_key_last($tokens)][1] .= $token[1];
            return;
        }
        $tokens[] = $token;
    }

    private static function toHtml5libForm(Token $token): array
    {
        return match ($token->type) {
            Token::START_TAG => $token->selfClosing
                ? ['StartTag', $token->name, $token->attributes, true]
                : ['StartTag', $token->name, $token->attributes],
            Token::END_TAG   => ['EndTag', $token->name],
            Token::COMMENT   => ['Comment', $token->data],
            Token::DOCTYPE   => ['DOCTYPE'],
            Token::TEXT      => ['Character', $token->data],
        };
    }
}

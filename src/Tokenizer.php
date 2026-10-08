<?php
declare(strict_types=1);

namespace Itools\HtmlValidator;

use Generator;

// import built-ins so calls resolve at compile time instead of per-call lookups; NamespacedCallsTest keeps this list exact
use function preg_match;
use function preg_quote;
use function preg_replace;
use function str_replace;
use function strlen;
use function strncasecmp;
use function strpos;
use function strtolower;
use function substr;

use const PREG_OFFSET_CAPTURE;

/**
 * Streams HTML5 tokens from a string without building a tree.
 *
 *     foreach ((new Tokenizer($html))->tokens() as $token) {
 *         // Token::START_TAG, END_TAG, TEXT, COMMENT, DOCTYPE
 *     }
 *
 * Follows the tokenizer section of the HTML Standard (13.2.5), so tag names, attributes,
 * comments and character references come out the way browsers read them: names are
 * lowercased and never entity-decoded, the first of two same-named attributes wins, a slash
 * inside a tag is a separator, <!-- x --!> closes a comment, <? ... > is a comment.
 *
 * Memory is the input string plus the current token. There is no DOM, no nesting limit
 * and nothing retained between tokens.
 *
 * The content of <title>, <textarea>, <style>, <iframe>, <noembed>, <noframes>, <xmp>,
 * <noscript> and <script> is text up to the matching end tag, as in a browser with scripting
 * on. Pass $switchOnElements = false to turn that off and tokenize as if no tree builder were
 * listening, which is what the html5lib suite expects.
 *
 * Pass $skip, an anchored regex, to step over markup the caller has no use for: in the data
 * state whatever it matches produces no tokens. HtmlValidator passes one for the text and
 * tags its rules can never refuse.
 *
 * Two deliberate differences from a browser:
 *
 * - <script> content is read with the plain raw-text rule instead of the script-data rule.
 *   The two differ only in where "</script>" ends inside a <!-- --> comment in script
 *   source, and a script tag was seen either way.
 * - <svg> and <math> content is tokenized as HTML. Browsers switch to foreign-content rules
 *   there, which depend on the tree, so callers that care must refuse those elements.
 *
 * Doctype tokens carry the raw text after "<!DOCTYPE" with no further parsing.
 *
 * @internal The validator's own parser, not part of the public API; it can change between releases.
 */
final class Tokenizer
{
    //region States

    public const STATE_DATA      = 'data';
    public const STATE_RCDATA    = 'rcdata';       // <title>, <textarea>: text with character references
    public const STATE_RAWTEXT   = 'rawtext';      // <style>, <script>, <iframe>, ...: text as written
    public const STATE_PLAINTEXT = 'plaintext';    // <plaintext>: everything to the end is text

    private const ELEMENT_STATES = [
        'title'    => self::STATE_RCDATA,
        'textarea' => self::STATE_RCDATA,
        'style'    => self::STATE_RAWTEXT,
        'script'   => self::STATE_RAWTEXT,
        'iframe'   => self::STATE_RAWTEXT,
        'noembed'  => self::STATE_RAWTEXT,
        'noframes' => self::STATE_RAWTEXT,
        'noscript' => self::STATE_RAWTEXT,
        'xmp'      => self::STATE_RAWTEXT,
        'plaintext' => self::STATE_PLAINTEXT,
    ];

    //endregion
    //region Data State

    private readonly string $html;
    private readonly int    $length;
    private int             $pos          = 0;
    private int             $rawTextStart = 0;      // where the open raw-text element's start tag began
    private ?Token          $unclosed     = null;   // set when the input ends inside markup, yielded last
    private int             $skipWindow   = 0;      // bytes the skip regex sees per call: the whole input, or SKIP_WINDOW after a PCRE limit

    /**
     * After a PCRE limit the skip regex runs over this many bytes at a time: enough tags per call to keep
     * the speed, too few to reach the limit again
     */
    private const SKIP_WINDOW = 65536;

    /**
     * The letters that can start a tag name. ASCII only, so a tag reads the same under any setlocale()
     */
    private const ASCII_LETTERS = [
        'a' => true, 'b' => true, 'c' => true, 'd' => true, 'e' => true, 'f' => true, 'g' => true, 'h' => true, 'i' => true,
        'j' => true, 'k' => true, 'l' => true, 'm' => true, 'n' => true, 'o' => true, 'p' => true, 'q' => true, 'r' => true,
        's' => true, 't' => true, 'u' => true, 'v' => true, 'w' => true, 'x' => true, 'y' => true, 'z' => true,
        'A' => true, 'B' => true, 'C' => true, 'D' => true, 'E' => true, 'F' => true, 'G' => true, 'H' => true, 'I' => true,
        'J' => true, 'K' => true, 'L' => true, 'M' => true, 'N' => true, 'O' => true, 'P' => true, 'Q' => true, 'R' => true,
        'S' => true, 'T' => true, 'U' => true, 'V' => true, 'W' => true, 'X' => true, 'Y' => true, 'Z' => true,
    ];

    /**
     * @param string  $html             the document or fragment; must be valid UTF-8
     * @param bool    $switchOnElements read raw-text and RCDATA element content as text (what a browser does)
     * @param string  $state            state to start in, one of the STATE_ constants
     * @param string  $lastStartTag     with STATE_RCDATA or STATE_RAWTEXT, the element whose end tag returns to data
     * @param ?string $skip             an anchored regex (the A modifier); in the data state whatever it matches is stepped over without producing
     *                                  tokens. After a PCRE limit it runs over SKIP_WINDOW bytes at a time, so it must not accept the last byte of
     *                                  its subject on the strength of nothing following it: a window can end anywhere, even between < and a tag name
     */
    public function __construct(
        string $html,
        private readonly bool $switchOnElements = true,
        private string $state = self::STATE_DATA,
        private string $lastStartTag = '',
        private ?string $skip = null,
    ) {
        $this->html   = str_replace(["\r\n", "\r"], "\n", $html);   // the input-stream preprocessing step
        $this->length = strlen($this->html);
    }

    /**
     * @return Generator<int, Token>
     */
    public function tokens(): Generator
    {
        $html = $this->html;
        $len  = $this->length;

        while ($this->pos < $len) {
            if ($this->state !== self::STATE_DATA) {
                yield from $this->rawText();
                continue;
            }

            $pos = $this->pos;

            if ($this->skip !== null) {
                $skipped = $this->skipWindow === 0
                    ? preg_match($this->skip, $html, $m, 0, $pos)
                    : preg_match($this->skip, substr($html, $pos, $this->skipWindow), $m);
                if ($skipped === 1) {
                    $this->pos = $pos + strlen($m[0]);
                    continue;
                }
                if ($skipped === false && $this->skipWindow === 0) {
                    $this->skipWindow = self::SKIP_WINDOW;   // one call over megabytes of tags reaches pcre.backtrack_limit; a window at a time does not
                    continue;
                }
                if ($skipped === false) {
                    $this->skip = null;   // a window reached the limit too (the interpreter on tags late in the element list); trying again at every token would pay it each time
                }
                $this->skipWindow = 0;   // nothing to skip here; whole-input calls copy nothing, so content the regex cannot skip never pays for windows
            }

            // text up to the next <
            if ($html[$pos] !== '<') {
                preg_match('/[^<]+/A', $html, $m, 0, $pos);
                $this->pos = $pos + strlen($m[0]);
                yield new Token(Token::TEXT, '', [], false, CharacterReferences::decode($m[0], false), $pos, $this->pos);
                continue;
            }

            // tag open
            $next = $html[$pos + 1] ?? '';
            if ($next === '!') {
                yield from $this->markupDeclaration($pos);
            } elseif ($next === '/') {
                yield from $this->endTagOpen($pos);
            } elseif (isset(self::ASCII_LETTERS[$next])) {
                $token = $this->tag($pos, false);
                if ($token === null) {
                    break;                                      // EOF inside the tag: the token is dropped
                }
                yield $token;
                if ($this->switchOnElements && isset(self::ELEMENT_STATES[$token->name])) {
                    $this->state        = self::ELEMENT_STATES[$token->name];
                    $this->lastStartTag = $token->name;
                    $this->rawTextStart = $token->start;
                }
            } elseif ($next === '?') {
                yield $this->bogusComment($pos, $pos + 1);      // the ? is part of the comment
            } else {
                $this->pos = $pos + 1;                          // a lone < is text; the character after it starts a new run
                yield new Token(Token::TEXT, '', [], false, '<', $pos, $this->pos);
            }
        }

        if ($this->state !== self::STATE_DATA) {                    // a raw-text element with no end tag
            $this->unclosed = new Token(Token::UNCLOSED, $this->lastStartTag, [], false, '', $this->rawTextStart, $len);
        }
        if ($this->unclosed !== null) {
            yield $this->unclosed;
        }
    }

    //endregion
    //region Raw Text States

    /**
     * RCDATA, RAWTEXT and PLAINTEXT: text up to the matching end tag, or to the end of input
     */
    private function rawText(): Generator
    {
        $html  = $this->html;
        $start = $this->pos;

        $found = $this->state !== self::STATE_PLAINTEXT
            && preg_match('/<\/' . preg_quote($this->lastStartTag, '/') . '(?=[\t\n\f \/>])/i', $html, $m, PREG_OFFSET_CAPTURE, $start);
        $end = $found ? $m[0][1] : $this->length;

        if ($end > $start) {
            $text = str_replace("\0", "\u{FFFD}", substr($html, $start, $end - $start));
            if ($this->state === self::STATE_RCDATA) {
                $text = CharacterReferences::decode($text, false);
            }
            yield new Token(Token::TEXT, '', [], false, $text, $start, $end);
        }
        $this->pos = $end;
        if (!$found) {
            return;
        }

        $this->state = self::STATE_DATA;
        $token = $this->tag($end, true);
        if ($token !== null) {
            yield $token;
        }
    }

    //endregion
    //region Tag States

    /**
     * At "</": an end tag, nothing (</>), text (</ at the end), or a bogus comment
     */
    private function endTagOpen(int $pos): Generator
    {
        $html = $this->html;
        $next = $html[$pos + 2] ?? '';
        if (isset(self::ASCII_LETTERS[$next])) {
            $token = $this->tag($pos, true);
            if ($token !== null) {
                yield $token;
            }
        } elseif ($next === '>') {
            $this->pos = $pos + 3;
        } elseif ($next === '') {
            $this->pos = $pos + 2;
            yield new Token(Token::TEXT, '', [], false, '</', $pos, $this->pos);
        } else {
            yield $this->bogusComment($pos, $pos + 2);
        }
    }

    /**
     * A start or end tag from "<" or "</" through ">". Returns null when the input ends inside
     * the tag, which drops the token as browsers do and queues the UNCLOSED token. End tags
     * keep no attributes.
     */
    private function tag(int $pos, bool $isEnd): ?Token
    {
        $html = $this->html;
        $len  = $this->length;
        $p    = $pos + ($isEnd ? 2 : 1);

        preg_match('/[^\t\n\f \/>]+/A', $html, $m, 0, $p);
        $name = strtolower(str_replace("\0", "\u{FFFD}", $m[0]));
        $p   += strlen($m[0]);

        $attributes  = [];
        $selfClosing = false;

        while (true) {
            // before attribute name
            preg_match('/[\t\n\f ]*/A', $html, $m, 0, $p);
            $p += strlen($m[0]);
            if ($p >= $len) {
                return $this->eofInTag($pos, $name);
            }
            $c = $html[$p];
            if ($c === '>') {
                $p++;
                break;
            }
            if ($c === '/') {
                if (($html[$p + 1] ?? '') === '>') {
                    $selfClosing = true;
                    $p += 2;
                    break;
                }
                $p++;                                           // a stray slash is a separator
                continue;
            }

            // attribute name: a leading = is part of the name, as in browsers
            preg_match('/=?[^\t\n\f \/>=]*/A', $html, $m, 0, $p);
            $attrName = strtolower(str_replace("\0", "\u{FFFD}", $m[0]));
            $p       += strlen($m[0]);

            // after attribute name
            preg_match('/[\t\n\f ]*/A', $html, $m, 0, $p);
            $p += strlen($m[0]);
            if ($p >= $len) {
                return $this->eofInTag($pos, $name);
            }
            if ($html[$p] !== '=') {
                $attributes[$attrName] ??= '';                  // no value; > / or the next name is handled by the loop
                continue;
            }
            $p++;

            // before attribute value
            preg_match('/[\t\n\f ]*/A', $html, $m, 0, $p);
            $p += strlen($m[0]);
            if ($p >= $len) {
                return $this->eofInTag($pos, $name);
            }
            $c = $html[$p];
            if ($c === '"' || $c === "'") {
                $close = strpos($html, $c, $p + 1);
                if ($close === false) {
                    return $this->eofInTag($pos, $name);
                }
                $raw = substr($html, $p + 1, $close - $p - 1);
                $p   = $close + 1;
            } elseif ($c === '>') {
                $attributes[$attrName] ??= '';
                $p++;
                break;
            } else {
                preg_match('/[^\t\n\f >]+/A', $html, $m, 0, $p);
                $raw = $m[0];
                $p  += strlen($raw);
                if ($p >= $len) {
                    return $this->eofInTag($pos, $name);
                }
            }
            $attributes[$attrName] ??= CharacterReferences::decode(str_replace("\0", "\u{FFFD}", $raw), true);
        }

        $this->pos = $p;
        if ($isEnd) {
            return new Token(Token::END_TAG, $name, [], false, '', $pos, $p);
        }
        return new Token(Token::START_TAG, $name, $attributes, $selfClosing, '', $pos, $p);
    }

    /**
     * The input ended inside a tag: browsers drop the token and stop, so move to the end
     */
    private function eofInTag(int $pos, string $name): ?Token
    {
        $this->pos      = $this->length;
        $this->unclosed = new Token(Token::UNCLOSED, $name, [], false, '', $pos, $this->length);
        return null;
    }

    //endregion
    //region Comment and Doctype States

    /**
     * At "<!": a comment, a doctype, or a bogus comment
     */
    private function markupDeclaration(int $pos): Generator
    {
        $html = $this->html;
        if (substr($html, $pos + 2, 2) === '--') {
            yield $this->comment($pos);
        } elseif (strncasecmp(substr($html, $pos + 2, 7), 'DOCTYPE', 7) === 0) {
            $close     = strpos($html, '>', $pos + 9);
            $this->pos = $close === false ? $this->length : $close + 1;
            $data      = substr($html, $pos + 9, ($close === false ? $this->length : $close) - $pos - 9);
            yield new Token(Token::DOCTYPE, '', [], false, $data, $pos, $this->pos);
            if ($close === false) {
                $this->unclosed = new Token(Token::UNCLOSED, '', [], false, '', $pos, $this->pos);
            }
        } else {
            yield $this->bogusComment($pos, $pos + 2);          // includes <![CDATA[ outside foreign content
        }
    }

    /**
     * Comment body from after "<!--" to "-->" or "--!>", with the empty forms <!--> and <!--->
     */
    private function comment(int $pos): Token
    {
        $html      = $this->html;
        $dataStart = $pos + 4;

        if (($html[$dataStart] ?? '') === '>') {
            $this->pos = $dataStart + 1;
            return new Token(Token::COMMENT, '', [], false, '', $pos, $this->pos);
        }
        if (substr($html, $dataStart, 2) === '->') {
            $this->pos = $dataStart + 2;
            return new Token(Token::COMMENT, '', [], false, '', $pos, $this->pos);
        }

        // --> or --!> closes the comment, whichever comes first; one forward scan, so many short comments stay linear
        $found     = preg_match('/-->|--!>/', $html, $m, PREG_OFFSET_CAPTURE, $dataStart) === 1;
        $close     = $found ? $m[0][1] : false;
        $dataEnd   = $found ? $close : $this->length;
        $this->pos = $found ? $close + strlen($m[0][0]) : $this->length;
        $data      = str_replace("\0", "\u{FFFD}", substr($html, $dataStart, $dataEnd - $dataStart));
        if ($close === false) {
            $data           = preg_replace('/(?:--!|--|-)\z/', '', $data);    // at end of input a partial close (-, --, --!) is dropped
            $this->unclosed = new Token(Token::UNCLOSED, '', [], false, '', $pos, $this->pos);
        }
        return new Token(Token::COMMENT, '', [], false, $data, $pos, $this->pos);
    }

    /**
     * Everything from $dataStart to the next ">" is the comment
     */
    private function bogusComment(int $pos, int $dataStart): Token
    {
        $html      = $this->html;
        $close     = strpos($html, '>', $dataStart);
        $dataEnd   = $close === false ? $this->length : $close;
        $this->pos = $close === false ? $this->length : $close + 1;
        $data      = str_replace("\0", "\u{FFFD}", substr($html, $dataStart, $dataEnd - $dataStart));
        if ($close === false) {
            $this->unclosed = new Token(Token::UNCLOSED, '', [], false, '', $pos, $this->pos);
        }
        return new Token(Token::COMMENT, '', [], false, $data, $pos, $this->pos);
    }

    //endregion
}

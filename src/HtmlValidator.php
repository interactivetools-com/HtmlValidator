<?php
declare(strict_types=1);

namespace Itools\HtmlValidator;

// import built-ins so calls resolve at compile time instead of per-call lookups; NamespacedCallsTest keeps this list exact
use function addcslashes, array_map, array_values, count, implode, in_array, preg_match, preg_match_all, preg_quote, preg_replace, str_replace, str_starts_with, strlen, strtolower, substr;

use const PREG_OFFSET_CAPTURE;

/**
 * Checks rich-text HTML for anything that could run script in a browser and reports every
 * problem it finds. The content is never modified: what passes is what gets stored.
 *
 *     $result = HtmlValidator::check($html);
 *     if (!$result->ok) {
 *         foreach ($result->errors as $violation) {
 *             echo htmlspecialchars($violation->message), "<br>";
 *         }
 *     }
 *
 *     HtmlValidator::$allowForms = true;            // switches are class properties, set once at startup
 *     HtmlValidator::$iframeHosts[] = 'example.com';
 *     $rules = HtmlValidator::rules();              // the tables and current switches, for docs and settings pages
 *
 * Elements are an allowlist: the HTML Standard's element index minus everything that runs
 * script, loads a plugin, belongs in <head>, or changes how a browser reads the bytes after
 * it, plus custom elements (any name with a hyphen). Attributes are pattern-based: on* and
 * srcdoc are refused, URL attributes may only use http, https, mailto and tel, no attribute
 * may start with javascript:, vbscript: or data:, and CSS is checked for the constructs that
 * once ran script. Unknown attributes pass.
 *
 * The check runs on the HTML5 token stream and never builds a tree, so it sees the same
 * tags and attributes a browser does, in one pass, with memory that does not grow with
 * nesting.
 */
final class HtmlValidator
{
    //region Rules

    /**
     * Elements that pass. The HTML Standard's element index, minus script and plugin elements
     * (script, noscript, template, svg, math, object, embed, applet), page-level elements
     * (html, head, body, title, meta, base, link, frameset, frame), and the elements that switch
     * the tokenizer into a mode a validator cannot follow (plaintext, xmp, noembed, noframes).
     * Includes the obsolete presentational elements old content still holds (font, center,
     * strike, big, tt, marquee and friends), since browsers render them without script.
     * Elements with a switch (style, iframe, the form elements) are listed with their group.
     */
    public const ELEMENTS = [
        'a', 'abbr', 'acronym', 'address', 'area', 'article', 'aside', 'audio',
        'b', 'bdi', 'bdo', 'big', 'blink', 'blockquote', 'br',
        'canvas', 'caption', 'center', 'cite', 'code', 'col', 'colgroup',
        'data', 'dd', 'del', 'details', 'dfn', 'dialog', 'dir', 'div', 'dl', 'dt',
        'em', 'figcaption', 'figure', 'font', 'footer',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'header', 'hgroup', 'hr',
        'i', 'img', 'ins', 'kbd', 'li', 'listing', 'main', 'map', 'mark', 'marquee', 'menu', 'meter', 'multicol',
        'nav', 'nobr', 'ol', 'p', 'param', 'picture', 'pre', 'progress', 'q',
        'rb', 'rp', 'rt', 'rtc', 'ruby', 's', 'samp', 'search', 'section', 'slot', 'small', 'source', 'spacer', 'span', 'strike', 'strong', 'sub', 'summary', 'sup',
        'table', 'tbody', 'td', 'tfoot', 'th', 'thead', 'time', 'tr', 'track', 'tt',
        'u', 'ul', 'var', 'video', 'wbr',
    ];

    /** Pass only when $allowForms is set. Forms submit data and can imitate a login box, but they run no script. */
    public const FORM_ELEMENTS = ['form', 'input', 'button', 'select', 'selectedcontent', 'option', 'optgroup', 'datalist', 'textarea', 'label', 'fieldset', 'legend', 'output'];

    /** Refused whatever the element. on* is matched by prefix, so new event handlers are covered. */
    public const ATTRIBUTES_REFUSED = ['srcdoc'];

    /** Attribute names that pass only when $allowForms is set. */
    public const FORM_ATTRIBUTES = ['formaction'];

    /** URL schemes a URL attribute may start with. Anything else with a scheme is refused; values with no scheme pass. */
    public const URL_SCHEMES = ['http', 'https', 'mailto', 'tel'];

    /** Schemes that run script or load a document, refused in every attribute, URL or not. */
    public const SCRIPT_SCHEMES = ['javascript', 'vbscript', 'data'];

    /** URL schemes allowed inside CSS url(). */
    public const CSS_URL_SCHEMES = ['http', 'https'];

    /**
     * Attributes browsers read as URLs. Their values must have no scheme or one in URL_SCHEMES.
     * Every other attribute value only has to avoid SCRIPT_SCHEMES, so title="Note: x" passes
     * and a URL-bearing attribute added to HTML later still cannot carry javascript:.
     */
    public const URL_ATTRIBUTES = ['href', 'src', 'action', 'formaction', 'poster', 'data', 'background', 'cite', 'longdesc', 'ping', 'xlink:href', 'manifest', 'dynsrc', 'lowsrc', 'srcset'];

    //endregion
    //region Patterns and Limits

    // a custom element name: a letter, then letters, digits, dots or underscores, with at least one hyphen
    private const CUSTOM_ELEMENT = '/^[a-z][a-z0-9._]*-[a-z0-9._-]*$/';

    // CSS that ran script in some browser, hides what the rest of the stylesheet says, or leaks page data:
    // backslash escapes (\6a avascript), @import and @charset, the image()/image-set()/src() URL functions,
    // IE expression() and behavior:, Firefox -moz-binding, and the two selectors that fire on page data
    // so a url() can report it: [attr^=value] with ^= $= *= and @font-face unicode-range
    private const CSS_FORBIDDEN = '/\\\\|@import|@charset|image\(|image-set\(|src\(|expression\(|-moz-binding|behavior\s*:|\[[^\]=]*[\^$*]=|unicode-range/i';

    // every url( in CSS, capturing what is inside up to the closing quote, paren or whitespace
    private const CSS_URL = '/url\(\s*+["\']?+\s*+([^"\')\s]*)/i';

    // bytes that cannot start or continue a UTF-8 character; a match gives the length of the valid prefix
    private const UTF8_PREFIX = '/\A(?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})*+/';

    // C0 control characters other than tab, LF and CR
    private const CONTROL_CHARACTER = '/[\x00-\x08\x0B\x0C\x0E-\x1F]/';

    // Switches. Set once at startup; every check() call reads them.
    public static bool $allowForms   = false;   // form elements and formaction
    public static bool $allowStyles  = true;    // the style attribute and the <style> element, both through the CSS check
    public static bool $allowEmbeds  = true;    // <iframe> whose src host is in $iframeHosts

    /** @var string[] hosts an <iframe src> may point at, matched exactly and case-insensitively */
    public static array $iframeHosts = ['www.youtube.com', 'www.youtube-nocookie.com', 'player.vimeo.com', 'www.google.com'];

    // Limits
    public static int $maxErrors       = 50;    // distinct errors reported per check
    public static int $maxDetailLength = 80;    // characters of content quoted in an error message before "..."

    //endregion
    //region Public API

    /**
     * Checks an HTML string. Never throws; every problem is a Violation in the Result.
     *
     *     $result = HtmlValidator::check('<p onclick="x()">Hi</p>');
     *     $result->ok;                   // false
     *     $result->errors[0]->message;   // 'onclick= event handler attributes are not allowed'
     */
    public static function check(string $html): Result
    {
        return (new self())->run($html);
    }

    /**
     * Returns the rule tables and the current switches, keyed by name: elements, formElements,
     * attributesRefused, formAttributes, urlSchemes, scriptSchemes, cssUrlSchemes, urlAttributes, allowForms,
     * allowStyles, allowEmbeds, iframeHosts. For documentation, debugging and settings pages.
     *
     * @return array<string, mixed>
     */
    public static function rules(): array
    {
        return [
            'elements'          => self::ELEMENTS,
            'formElements'      => self::FORM_ELEMENTS,
            'attributesRefused' => self::ATTRIBUTES_REFUSED,
            'formAttributes'    => self::FORM_ATTRIBUTES,
            'urlSchemes'        => self::URL_SCHEMES,
            'scriptSchemes'     => self::SCRIPT_SCHEMES,
            'cssUrlSchemes'     => self::CSS_URL_SCHEMES,
            'urlAttributes'     => self::URL_ATTRIBUTES,
            'allowForms'        => self::$allowForms,
            'allowStyles'       => self::$allowStyles,
            'allowEmbeds'       => self::$allowEmbeds,
            'iframeHosts'       => self::$iframeHosts,
        ];
    }

    //endregion
    //region Check Flow

    /** @var array<string, Violation> keyed by code and detail, so the same problem reports once */
    private array $errors = [];

    private string $html = '';

    private function __construct()
    {
    }

    private function run(string $html): Result
    {
        if ($this->bytesAllowTokenizing($html)) {
            $this->html = str_replace(["\r\n", "\r"], "\n", $html);   // so token offsets match the string the details are cut from
            $this->walk();
        }
        return new Result(array_values($this->errors));
    }

    /** Invalid UTF-8 and control characters are refused before tokenizing, and nothing else is checked when they are */
    private function bytesAllowTokenizing(string $html): bool
    {
        preg_match(self::UTF8_PREFIX, $html, $match);
        $validLength = strlen($match[0]);
        if ($validLength < strlen($html)) {
            $bad = substr($html, $validLength, 16);   // a fixed cut: the bytes are not UTF-8, so excerpt() cannot count characters
            $this->fail('not-utf8', $bad . (strlen($html) - $validLength > 16 ? '...' : ''));
            return false;
        }
        if (preg_match(self::CONTROL_CHARACTER, $html, $match, PREG_OFFSET_CAPTURE)) {
            $this->fail('control-character', $match[0][0] . ' at byte ' . $match[0][1]);
            return false;
        }
        return true;
    }

    private function walk(): void
    {
        $inStyle = false;
        foreach ((new Tokenizer($this->html))->tokens() as $token) {
            if (count($this->errors) >= self::$maxErrors) {
                return;
            }
            if ($token->type === Token::TEXT) {
                if ($inStyle) {
                    $this->checkCss($token->data);
                }
                continue;
            }
            $inStyle = false;
            if ($token->type === Token::START_TAG) {
                $this->checkStartTag($token);
                $inStyle = $token->name === 'style';
            } elseif ($token->type === Token::DOCTYPE) {
                $this->fail('element-not-allowed', self::excerpt($this->source($token)));
            } elseif ($token->type === Token::UNCLOSED) {
                $this->fail('unclosed-markup', self::excerpt($this->source($token)));   // the page this is printed into would be read as part of it
            }
        }
    }

    private function fail(string $code, string $detail): void
    {
        if (count($this->errors) >= self::$maxErrors) {
            return;   // the walk stops at the cap too, but one tag can add several errors before it checks
        }
        // one line of valid UTF-8 whatever the content held, so a log line or an error page can show it as is
        $detail = addcslashes($detail, "\0..\37\177");
        if (!preg_match('//u', $detail)) {
            $detail = addcslashes($detail, "\200..\377");
        }
        $this->errors["$code\0$detail"] ??= new Violation($code, $detail);
    }

    //endregion
    //region Token Rules

    /** A refused element reports once and its attributes are not checked, so <script onload> gives one message */
    private function checkStartTag(Token $token): void
    {
        $name = $token->name;
        if (!$this->elementAllowed($name)) {
            $this->fail('element-not-allowed', self::excerpt($this->source($token)));
            return;
        }
        if ($name === 'iframe') {
            $this->checkIframe($token);
        }
        foreach ($token->attributes as $attribute => $value) {
            $this->checkAttribute($attribute, $value);
        }
    }

    private function elementAllowed(string $name): bool
    {
        if (in_array($name, self::ELEMENTS, true)) {
            return true;
        }
        return match ($name) {
            'style'  => self::$allowStyles,
            'iframe' => self::$allowEmbeds,
            default  => (self::$allowForms && in_array($name, self::FORM_ELEMENTS, true))
                        || preg_match(self::CUSTOM_ELEMENT, $name) === 1,
        };
    }

    private function checkAttribute(string $attribute, string $value): void
    {
        if (str_starts_with($attribute, 'on')) {
            $this->fail('event-handler', $attribute);
            return;
        }
        if (in_array($attribute, self::ATTRIBUTES_REFUSED, true)
            || (!self::$allowForms && in_array($attribute, self::FORM_ATTRIBUTES, true))
            || (!self::$allowStyles && $attribute === 'style')
        ) {
            $this->fail('attribute-not-allowed', $attribute);
            return;
        }
        if ($attribute === 'style') {
            $this->checkCss($value);
            return;
        }
        $scheme = self::scheme($value);
        if ($scheme === null) {
            return;
        }
        $allowed = in_array($attribute, self::URL_ATTRIBUTES, true)
            ? in_array($scheme, self::URL_SCHEMES, true)
            : !in_array($scheme, self::SCRIPT_SCHEMES, true);
        if (!$allowed) {
            $this->fail('url-scheme-not-allowed', self::excerpt("$attribute=\"$value\""));
        }
    }

    /** src must be an absolute http, https or protocol-relative URL on a listed host; no src is refused too */
    private function checkIframe(Token $token): void
    {
        $src   = self::compact($token->attributes['src'] ?? '');
        $hosts = implode('|', array_map(fn(string $host) => preg_quote(strtolower($host), '/'), self::$iframeHosts));
        // http://, https:// or // then a listed host, then the end of the host part (a slash, ?, # or nothing)
        if ($hosts === '' || !preg_match("/^(?:https?:)?\/\/(?:$hosts)(?=[\/?#]|$)/i", $src)) {
            $this->fail('iframe-host', $src === '' ? '(no src)' : self::excerpt($src));
        }
    }

    //endregion
    //region Value Rules

    private function checkCss(string $css): void
    {
        if (preg_match(self::CSS_FORBIDDEN, $css, $match)) {
            $this->fail('css-not-allowed', $match[0]);
        }
        preg_match_all(self::CSS_URL, $css, $matches);
        foreach ($matches[1] as $url) {
            $scheme = self::scheme($url);
            if ($scheme !== null && !in_array($scheme, self::CSS_URL_SCHEMES, true)) {
                $this->fail('css-not-allowed', self::excerpt("url($url)"));
            }
        }
    }

    /**
     * The lowercased scheme a browser would read from a URL value, or null when the value has
     * none (a relative path, #id, ?query, //host, or plain text). Browsers drop ASCII whitespace
     * and control characters from a URL before reading it, so "java\nscript:" is javascript.
     */
    private static function scheme(string $value): ?string
    {
        $value = self::compact($value);
        // a scheme is a letter followed by letters, digits, + - or . up to the first colon
        if (!preg_match('/^([a-z][a-z0-9+.\-]*):/i', $value, $match)) {
            return null;
        }
        return strtolower($match[1]);
    }

    /** The value with ASCII whitespace and control characters removed, as a browser reads a URL */
    private static function compact(string $value): string
    {
        return preg_replace('/[\x00-\x20\x7F]+/', '', $value);
    }

    //endregion
    //region Helpers

    /** The tag as written in the content, after CR and CRLF became LF */
    private function source(Token $token): string
    {
        return substr($this->html, $token->start, $token->end - $token->start);
    }

    /** The first $maxDetailLength characters of a value for an error message, cut on a UTF-8 boundary. */
    private static function excerpt(string $value): string
    {
        preg_match('/^.{0,' . self::$maxDetailLength . '}/us', $value, $match);
        $start = $match[0] ?? substr($value, 0, self::$maxDetailLength);
        return strlen($start) < strlen($value) ? "$start..." : $start;
    }

    //endregion
}

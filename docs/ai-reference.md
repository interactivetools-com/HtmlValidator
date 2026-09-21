# HtmlValidator AI Reference

This is a consolidated reference for AI coding assistants: the complete API and every
rejection rule in one file, covering HtmlValidator 1.0. For human-friendly docs, see the
[README](https://github.com/interactivetools-com/HtmlValidator).

Contents:

- [What HtmlValidator Is](#what-htmlvalidator-is)
- [API](#api) - check(), rules(), the switches, Result, Violation
- [Usage Pattern](#usage-pattern)
- [Error Codes](#error-codes)
- [How a Check Runs](#how-a-check-runs)
- [Rules: Bytes](#rules-bytes)
- [Rules: Elements](#rules-elements)
- [Rules: Attributes](#rules-attributes)
- [Rules: URLs](#rules-urls)
- [Rules: Iframes](#rules-iframes)
- [Rules: CSS](#rules-css)
- [Rules: Unclosed Markup](#rules-unclosed-markup)
- [What the Tokenizer Sees](#what-the-tokenizer-sees)
- [Rule Tables](#rule-tables)
- [Limits](#limits)
- [Constraints and Gotchas](#constraints-and-gotchas)

---

## What HtmlValidator Is

HtmlValidator checks rich-text HTML (WYSIWYG output, HTML fields, API input) and rejects it if
it could run script in the page it is printed into, **with a list of reasons**. The content is
**never modified**: there is no cleaned output, and the caller stores the original string or
refuses the save.

It runs the HTML5 tokenizer on the content and checks each start tag as it comes out. No tree
is built, so the tags and attributes it checks are the ones a browser reads from the same
bytes. Elements are an allowlist. Attributes are checked by pattern (`on*`, a short refused
list, URL schemes, CSS), so attributes it has never seen pass.

```php
use Itools\HtmlValidator\HtmlValidator;

$result = HtmlValidator::check($_POST['body']);
$result->ok;       // true when the content passed
$result->errors;   // Violation[] - empty when ok, otherwise one per distinct problem
```

Requirements: PHP 8.1+. No extensions, no dependencies.

## API

Three public classes in the `Itools\HtmlValidator` namespace. Nothing throws for bad content:
a hostile or broken string is expected input and comes back as a rejected `Result`.

```php
HtmlValidator::check(string $html): Result   // one pass over the content; memory is a copy of the string plus the current token
HtmlValidator::rules(): array                // the rule tables and the current switch values, for settings pages and debugging
```

`rules()` returns an array with the keys `elements`, `formElements`, `attributesRefused`,
`formAttributes`, `urlSchemes`, `scriptSchemes`, `cssUrlSchemes`, `urlAttributes`,
`allowForms`, `allowStyles`, `allowEmbeds` and `iframeHosts`. See [Rule Tables](#rule-tables).
The lists are constants; changing the returned array changes nothing.

### Switches

Public static properties, read by every `check()` call. Set them once at startup.

```php
HtmlValidator::$allowForms   = false;   // form elements (FORM_ELEMENTS) and formaction pass
HtmlValidator::$allowStyles  = true;    // the style attribute and the <style> element pass, after the CSS check
HtmlValidator::$allowEmbeds  = true;    // <iframe> passes when its src host is in $iframeHosts
HtmlValidator::$iframeHosts  = ['www.youtube.com', 'www.youtube-nocookie.com', 'player.vimeo.com', 'www.google.com'];
HtmlValidator::$maxErrors       = 50;   // distinct errors reported per check
HtmlValidator::$maxDetailLength = 80;   // characters of content quoted in a detail before "..."
HtmlValidator::$fastPath        = true; // text and tags the rules can never refuse are stepped over in one regex match; false sends every byte through the tokenizer, same result, slower
```

`$iframeHosts` entries are host names only, matched whole and case-insensitively: no scheme,
no port, no path, no wildcard. `player.vimeo.com` does not match `vimeo.com` or the other way
round.

### Result

```php
final class Result
{
    public readonly bool  $ok;       // true when $errors is empty
    public readonly array $errors;   // Violation[], in document order, at most $maxErrors
}
```

`errors` holds one `Violation` per distinct problem (deduplicated by code and detail), in the
order found. `not-utf8` and `control-character` stop the check before tokenizing, so either
arrives alone. `unclosed-markup`, when present, is always the last entry.

### Violation

```php
$violation->code;       // 'event-handler' - stable across releases
$violation->detail;     // 'onclick' - plain text taken from the content, at most 80 characters plus '...'
$violation->template;   // '%s= event handler attributes are not allowed'
$violation->message;    // 'onclick= event handler attributes are not allowed'
Violation::TEMPLATES;   // every message template keyed by code, one %s each
```

All four properties are readonly strings.

`detail` and `message` contain text from the content. HTML-encode them before output.

For translation, `template` goes through the translation function and `detail` goes back in
with `sprintf()`. Details hold literal `<`, `>` and quotes, so the whole result is encoded:

```php
echo htmlspecialchars(sprintf(t($violation->template), $violation->detail));
```

`Violation::TEMPLATES` lists every template by code so a translation system can register
them all up front.

## Usage Pattern

```php
use Itools\HtmlValidator\HtmlValidator;

$result = HtmlValidator::check($_POST['body']);

if (!$result->ok) {
    foreach ($result->errors as $violation) {
        echo '<p>', htmlspecialchars($violation->message), '</p>';
    }
    exit;
}
$statement = $mysqli->prepare('UPDATE articles SET body = ? WHERE id = ?');
$statement->bind_param('si', $_POST['body'], $_POST['id']);   // the original string, unchanged
$statement->execute();
```

HTML sanitizers return a cleaned string to store. HtmlValidator does not; store the original.

```php
// WRONG - check() returns a Result, not cleaned HTML
$clean = HtmlValidator::check($html);
$statement->bind_param('si', $clean, $id);

// RIGHT - check, then store the original string only when ok
if (HtmlValidator::check($html)->ok) {
    $statement->bind_param('si', $html, $id);
}
```

Switch on `code`, not on `message`, when the application reacts to a specific rule. Codes
never change once released; message wording can.

## Error Codes

Every code, its template, and what `detail` holds. Templates are `Violation::TEMPLATES`.

| Code                     | Template                                                                                        | `detail`                                                                                                                                                  |
|--------------------------|-------------------------------------------------------------------------------------------------|-----------------------------------------------------------------------------------------------------------------------------------------------------------|
| `not-utf8`               | `Content must be UTF-8, this is not: %s`                                                        | the 16 bytes from the first invalid byte, non-UTF-8 bytes escaped as octal (`\351`), then `...` when more follows                                         |
| `control-character`      | `Content contains a control character: %s`                                                      | the character escaped (`\000`, `\033`, `\f`, `\v`) and ` at byte N`, for the first one found                                                             |
| `element-not-allowed`    | `%s is not allowed`                                                                             | the start tag as written, from `<` to `>` (`<script src="x">`), or the doctype (`<!DOCTYPE html>`)                                                        |
| `event-handler`          | `%s= event handler attributes are not allowed`                                                  | the attribute name, lowercased (`onclick`)                                                                                                                |
| `attribute-not-allowed`  | `The %s attribute is not allowed`                                                               | `srcdoc`, or `formaction` while `$allowForms` is off, or `style` while `$allowStyles` is off                                                               |
| `url-scheme-not-allowed` | `The URL in %s must start with http:, https:, mailto:, tel:, a relative path, or #`             | the attribute name and its decoded value (`href="javascript:alert(1)"`)                                                                                   |
| `iframe-host`            | `Embedding frames from %s is not allowed`                                                       | the `src` value with whitespace removed (`https://evil.example/x`), or `(no src)`                                                                         |
| `css-not-allowed`        | `CSS containing %s is not allowed`                                                              | the banned token as matched (`expression(`, `\`, `[value^=`, `unicode-range`) or the `url()` with its target (`url(javascript:x)`), or the first 80 characters of a block past the PCRE limit (megabytes of `x*|` pairs inside one `[`) |
| `less-than-in-text`      | `A < where a browser reads text, not tags: %s`                                                  | the text from that `<` to the end of the raw text or comment (`<img src=x onerror=alert(1)>`, `<b> c`)                                                    |
| `unclosed-markup`        | `%s is not closed`                                                                              | the unfinished markup from where it opened to the end of the content (`<img src="//evil.example/?`, `<!-- hidden`, `<style>p { }`)                        |

Details longer than 80 characters are cut at 80 and end with `...`. Newlines and other
control characters inside a detail are escaped (`\n`), so a detail is always one line.

## How a Check Runs

1. **Bytes.** The whole string is checked for invalid UTF-8, then for C0 control characters
   other than tab, LF and CR. Either failure is the only error and nothing else runs.
2. **Line endings.** CR and CRLF become LF, as the HTML parser's input step does. Details
   quote the content after this step.
3. **Tokens.** The content is tokenized as HTML5 and every start tag is checked as it is
   produced. Text, comments and end tags are never checked, with two exceptions: the text
   inside `<style>` is checked as CSS, and a `<` inside the text of `<style>`, `<iframe>` or
   `<textarea>`, or inside a comment that is not `<!-- -->`, reports `less-than-in-text`. Runs
   of text and of tags whose every attribute a regex
   proves safe (a listed element, double-quoted values with no character reference but
   `&amp;`, no scheme but a listed one, no CSS construct the CSS check reads) are stepped over
   without being tokenized; a run the regex does not match is tokenized and checked as usual,
   so the result is the same with `$fastPath` off.
4. **Per start tag**, in this order: the element must be allowed, or the tag reports
   `element-not-allowed` and its attributes are skipped. An `<iframe>` then has its `src` host
   checked. Then each attribute: an `on*` name reports `event-handler`; a refused name reports
   `attribute-not-allowed`; `style` is checked as CSS; every other value has its URL scheme
   checked.
5. **Unclosed markup.** If the content ended inside a tag, a comment, a doctype or a raw-text
   element, `unclosed-markup` is added last.
6. **Cap.** The check stops after `$maxErrors` distinct errors.

## Rules: Bytes

- **`not-utf8`**: any byte sequence that is not well-formed UTF-8, including overlong forms,
  surrogates (`\xED\xA0\x80`) and code points above U+10FFFF. A UTF-8 byte order mark is a
  valid character and passes.
- **`control-character`**: any of U+0000 to U+001F except tab (U+0009), LF (U+000A) and CR
  (U+000D), anywhere in the content, including inside a tag name or attribute value. Delete
  (U+007F), the C1 range (U+0080 to U+009F) and Unicode line separators pass. A browser would
  turn a NUL inside `<scr\0ipt>` into `<scr�ipt>`, a harmless tag; the rule is stricter and
  refuses the byte wherever it is.

## Rules: Elements

For every start tag, by its lowercased name:

- In `ELEMENTS` (the [elements table](#rule-tables)): passes.
- `style`: passes when `$allowStyles` is set. Its text content is then checked as CSS.
- `iframe`: passes when `$allowEmbeds` is set, then its `src` is checked, see
  [Iframes](#rules-iframes).
- In `FORM_ELEMENTS`: passes when `$allowForms` is set.
- A custom element name (a letter, then letters, digits, `.` or `_`, then a hyphen, then any
  of those and hyphens: `my-widget`, `x-1.0_b-c`, `widget-`): passes.
- Anything else: `element-not-allowed` with the tag as written. The tag's attributes are not
  checked, so `<script onload="x" src="javascript:1">` is one error.

A `<!DOCTYPE ...>` anywhere reports `element-not-allowed` with the doctype as written.

Not allowed on purpose, so never add them to content to "fix" a rejection:

- Script and script surfaces: `script`, `noscript`, `template`, `svg`, `math`.
- Plugins: `object`, `embed`, `applet`.
- Page-level: `html`, `head`, `body`, `title`, `meta`, `base`, `link`, `frameset`, `frame`.
- Elements whose content is text to a browser and has no use in content: `plaintext`, `xmp`,
  `noembed`, `noframes`.
- Names with a namespace prefix (`o:p`, `v:shape`, `svg:rect`): the colon is not a custom
  element character, so they are unknown.

End tags are never checked: `</script>` with no start tag is fine. Text is never checked:
`&lt;script&gt;` is text to a browser and to the validator. Comments are never checked:
`<!-- <script>alert(1)</script> -->` passes, and so does `<!--[if IE]>...<![endif]-->`.

## Rules: Attributes

For every attribute on an allowed element, by its lowercased name, in this order:

1. A name starting with `on` (`onclick`, `oncommand`, `onfoo`, `on`): `event-handler`. No
   allowed attribute starts with `on`, and the prefix covers handlers added in future
   browsers. The name is checked as written: `on&#99;lick` is refused although a browser
   would not decode it either.
2. A name in `ATTRIBUTES_REFUSED` (`srcdoc`): `attribute-not-allowed`. It is a whole
   document with script allowed.
3. A name in `FORM_ATTRIBUTES` (`formaction`) while `$allowForms` is off:
   `attribute-not-allowed`. With forms on it is a URL attribute.
4. `style` while `$allowStyles` is off: `attribute-not-allowed`. With styles on its value is
   checked as CSS, see [CSS](#rules-css).
5. Every other attribute: the value's URL scheme is checked, see [URLs](#rules-urls).

Any attribute name not covered above passes whatever the element: `class`, `id`, `data-*`,
`aria-*`, `role`, `contenteditable`, `target`, `xmlns:o`, and names nothing defines.

Duplicate attributes: the first wins and the rest are dropped, as in browsers. So
`<a href="https://x" href="javascript:y">` passes and the reverse order rejects.

## Rules: URLs

The scheme of a value is read the way a browser reads a URL: ASCII whitespace and control
characters are removed from the whole value first (so `java\nscript:` and `jav&Tab;ascript:`
are `javascript:`), then the value must start with a letter followed by letters, digits,
`+`, `-` or `.` and a colon. Attribute values arrive entity-decoded, so `javascript&colon;`
and `&#106;avascript:` are `javascript:` too. A value with no scheme (a relative path,
`/root`, `//host`, `#id`, `?query`, an empty string, `/time/12:30`) has nothing to check.

- On the attributes in `URL_ATTRIBUTES` (`href`, `src`, `action`, `formaction`, `poster`,
  `ping`, `srcset`, `cite`, `longdesc`, `background`), the scheme must be in `URL_SCHEMES`:
  `http`, `https`, `mailto`, `tel`. Case-insensitive. `data:`, `vbscript:`, `ftp:`, `file:`
  and every other scheme reject with `url-scheme-not-allowed`, including `data:image/...` on
  `<img src>`.
- On every other attribute, only the schemes in `SCRIPT_SCHEMES` reject: `javascript`. A
  browser ignores `javascript:` in `title=` or `data-href=`, but a page script that copies the
  value into a link or into `location` runs it. `vbscript:` and `data:` pass there because
  no current browser runs either as the page; `title="Note: x"`, `data-time="noon:sharp"` and
  a custom element's `data="Note: x"` pass because they are not URLs.

The detail is the attribute name and the decoded value: `href="javascript:alert(1)"`.

`srcset` is checked as one value: `javascript:alert(1) 1x` starts with `javascript:` and
rejects. A second URL after a comma is not read separately.

## Rules: Iframes

An `<iframe>` passes only when `$allowEmbeds` is set (else `element-not-allowed`) and its
`src`, after whitespace is removed, matches `https://`, `http://` or `//`, then a host in
`$iframeHosts` (case-insensitive, whole host), then the end of the value or `/`, `?` or `#`.
Anything else reports `iframe-host` with the `src` as the detail, or `(no src)` when the
attribute is missing or empty.

So `https://www.youtube.com/embed/x`, `//www.youtube.com/embed/x` and
`https://WWW.YOUTUBE.COM` pass with the default list. `https://www.youtube.com:8080/x`,
`https://www.youtube.com@evil.example/`, `https://evil.www.youtube.com/`,
`https://www.youtube.com.evil.example/`, `/uploads/page.html`, `javascript:x`, `data:text/html,x`
and an `<iframe>` with only `srcdoc` all reject. An empty `$iframeHosts` rejects every
`<iframe>`.

The other attributes on the `<iframe>` are still checked (`onload` rejects, `srcdoc`
rejects). Its content is raw text to a browser, so `<iframe src="..."><script>x</script></iframe>`
holds no script tag.

## Rules: CSS

Applies to every `style` attribute value (entity-decoded) and to the text inside every
`<style>` element (as written, no entity decoding, as in a browser). Comments and quoted
strings are removed first (see below), then two checks run, case-insensitive:

1. Any of these tokens rejects with `css-not-allowed` and the token as the detail: a
   backslash `\`, `@import`, `@charset`, `image(`, `image-set(`, `src(`, `expression(`,
   `-moz-binding`, `behavior:` as a property name (whitespace before the colon allowed;
   `scroll-behavior:` and `overscroll-behavior:` pass), an attribute selector using `^=`,
   `$=` or `*=` (`[value^=`, `[ value ^=`), and `unicode-range`.
2. Every `url(` is read up to the closing quote, `)` or whitespace, and its scheme, read as in
   [URLs](#rules-urls), must be absent or in `CSS_URL_SCHEMES`: `http`, `https`. So
   `url(images/bg.png)`, `url(/x.png)`, `url(//cdn.example/x.png)`, `url(#clip)` and
   `url("https://cdn.example/x.png")` pass; `url(javascript:x)`, `url(data:image/svg+xml,...)`,
   `url(vbscript:x)`, `url(mailto:x)` and `url(ftp://x)` reject, with the `url(` and its
   target as the detail. This check reads the text as written, comments and strings included, so a quoted
   target is still checked. A backslash inside a quoted `url()` argument also rejects, since
   an escape there still spells a scheme: `url('\6a avascript:x')` reports `url('\`.

The backslash ban is what makes the regex enough: with no escape syntax there is no way to
spell `expression(` or `url(` that the regex reads differently from a browser. The two
selectors and `unicode-range` are the CSS features that fire on page data: `[value^="a"]` tests
an input's value one character at a time, and a font with `unicode-range` loads only when a
character is on the page, so either plus a `url()` to another host reports what the page
holds. Exact-match selectors (`[type=text]`, `[href="https://x/"]`), `~=`, `|=`, presence
selectors, `@font-face` without `unicode-range`, `@media`, `@keyframes`, `:hover`,
`!important`, `position: fixed` and vendor properties (`mso-*`) are not checked.

Removal reads left to right as a CSS tokenizer does: a comment runs from `/*` to `*/` or
the end of the text, and a string from its quote to the matching quote or a bare newline.
Inside a string `\` escapes the next character, or up to six hex digits and one whitespace
after them, newline included, so `"\a` and a newline do not end the string. An unquoted
`url()` runs to its closing `)` before either is looked for, so a `/*` or a quote inside it
is part of the URL, not the start of a comment or string. So a backslash in a comment
banner, `content: '\a0'` and a font name like `'\@Yu Mincho'` pass, and `content: '/*'`
cannot hide what follows it. A comment is dropped without leaving a space, as a browser
drops it, so `[value/**/^=` still rejects and reports `[value^=`. Past the PCRE backtrack
limit (megabytes inside one comment or string) removal is skipped and the text is checked
as written, which is stricter. A block the token scan cannot finish within the limit
(megabytes of `x*|` pairs inside one `[`) rejects with `css-not-allowed` and its first 80
characters as the detail; it is never passed unchecked.

## Rules: Text That Is Not Markup

The content of `<style>`, `<iframe>` and `<textarea>`, and a comment that is not `<!-- -->`
(`<?php ... ?>`, `<!x ...>`, `</ x>`, `<![CDATA[...]]>`, all ending at the first `>`), is
text to a browser. A `<` inside any of them reports `less-than-in-text`, with the text from
that `<` to the end of the block as the detail. The bytes as written are checked, so `&lt;`
in a `<textarea>` passes, and so does `<?xml:namespace prefix = o />` from a Word paste.
Ordinary text and everything inside `<!-- -->` are never checked.

The reason is what happens after the check: `strip_tags()` with an allow list and HTML4-era
DOM parsers read a tag inside these as live. Content that passed the check stays safe
through them only if no tag can appear where the check saw text.

## Rules: Unclosed Markup

If the content ends inside a start tag, an end tag, a quoted attribute value, a comment
(`<!-- x`, `<!-- x --`), a bogus comment (`<?php x`), a doctype, or the text of a raw-text
element (`<style>`, `<iframe>`, `<textarea>`, `<plaintext>`) with no matching end tag,
`unclosed-markup` is reported with the unfinished markup as the detail, from where it
opened to the end of the content. It is always the last error.

A browser drops an unfinished tag at the end of a document. A fragment printed into a page
is not at the end: the page up to the next quote becomes the attribute value (the URL that
gets fetched, for `src`), an `onerror=` on the open tag runs once the page's next `>` closes
it, and the page up to `-->` or `</style>` disappears or becomes CSS.

Not unclosed: a lone `<` or `</` at the end (text), `<p>` with no `</p>` (a tag, closed with
its `>`), `3 > 2` in text, a self-closing `<img/>`, an empty string.

## What the Tokenizer Sees

The check runs on the HTML5 token stream, so the facts below are what a browser does too.
Each one is a test in the suite, and the html5lib tokenizer test suite runs against the
tokenizer with element state switching off.

- Tag and attribute names are ASCII-lowercased and never entity-decoded: `<ScRiPt>` is
  `script`; `on&#99;lick` is an attribute named `on&#99;lick`.
- Attribute values are entity-decoded with the HTML5 table (all named references, legacy
  names without a semicolon, numeric references, `&#x80;` to `&#x9F;` through windows-1252),
  so the URL and CSS checks see what the browser sees. Text and `<style>` content are not
  relevant to decoding for the rules except as noted under CSS.
- `/` inside a tag is a separator: `<p/onclick=x>` is a `p` with `onclick`.
- Unquoted values end at whitespace or `>`: `<img src=x onerror=alert(1)>` has two attributes.
- `<a<b href=x>` is a tag named `a<b`, rejected as unknown.
- `<!-- x --!>` closes a comment; `<!-->` and `<!--->` are empty comments; `<?php ... ?>` and
  `<![CDATA[...]]>` are comments ending at the first `>`; `</ x>` and `</3>` are comments;
  `</>` is dropped.
- `<style>`, `<iframe>`, `<noembed>`, `<noframes>`, `<xmp>`, `<noscript>` and `<script>` hold
  raw text up to their matching end tag (case-insensitive, the same name); `<title>` and
  `<textarea>` the same but entity-decoded. A `<img onerror>` inside `<style>` is text, and
  its `<` is refused (see [Rules: Text That Is Not Markup](#rules-text-that-is-not-markup)).
  `<noscript>` is raw text because scripting is on in every browser a person uses.
- `<svg>` and `<math>` content would be tokenized as HTML, not as foreign content; both
  elements are refused so this never matters.
- A NUL in a tag or attribute name becomes U+FFFD in a browser; here the byte check refuses
  it first.
- Nesting depth is irrelevant: there is no tree, so there is no depth.

## Rule Tables

The lists as `rules()` returns them, plus the current switch values. Generated from the code
by `tools/rules-doc.php`.

**`elements`** (pass with no switch):

<!-- rules:elements -->
```text
a abbr acronym address area article aside audio b bdi bdo big blink blockquote br canvas caption
center cite code col colgroup data dd del details dfn dialog dir div dl dt em figcaption figure font
footer h1 h2 h3 h4 h5 h6 header hgroup hr i img ins kbd li listing main map mark marquee menu meter
multicol nav nobr ol p param picture pre progress q rb rp rt rtc ruby s samp search section slot
small source spacer span strike strong sub summary sup table tbody td tfoot th thead time tr track
tt u ul var video wbr
```
<!-- /rules:elements -->

**`formElements`** (pass when `allowForms` is set):

<!-- rules:formElements -->
```text
form input button select selectedcontent option optgroup datalist textarea label fieldset legend
output
```
<!-- /rules:formElements -->

**`attributesRefused`** (always `attribute-not-allowed`): <!-- rules:attributesRefused -->
`srcdoc`
<!-- /rules:attributesRefused -->

**`formAttributes`** (`attribute-not-allowed` unless `allowForms` is set): <!-- rules:formAttributes -->
`formaction`
<!-- /rules:formAttributes -->

**`urlSchemes`** (allowed on a URL attribute): <!-- rules:urlSchemes -->
`http`, `https`, `mailto`, `tel`
<!-- /rules:urlSchemes -->

**`scriptSchemes`** (refused on every attribute): <!-- rules:scriptSchemes -->
`javascript`
<!-- /rules:scriptSchemes -->

**`cssUrlSchemes`** (allowed in a CSS `url()`): <!-- rules:cssUrlSchemes -->
`http`, `https`
<!-- /rules:cssUrlSchemes -->

**`urlAttributes`** (values must use `urlSchemes` or no scheme): <!-- rules:urlAttributes -->
`href`, `src`, `action`, `formaction`, `poster`, `ping`, `srcset`, `cite`, `longdesc`, `background`
<!-- /rules:urlAttributes -->

**`allowForms`** default: <!-- rules:allowForms -->
`false`
<!-- /rules:allowForms -->

**`allowStyles`** default: <!-- rules:allowStyles -->
`true`
<!-- /rules:allowStyles -->

**`allowEmbeds`** default: <!-- rules:allowEmbeds -->
`true`
<!-- /rules:allowEmbeds -->

**`iframeHosts`** default: <!-- rules:iframeHosts -->
`www.youtube.com`, `www.youtube-nocookie.com`, `player.vimeo.com`, `www.google.com`
<!-- /rules:iframeHosts -->

## Limits

Public static properties, set before the check. The rules themselves have three switches and
a host list, see [Switches](#switches).

| Limit                          | Value         | Source              |
|--------------------------------|---------------|---------------------|
| Errors reported per check      | 50            | `$maxErrors`        |
| Value length in `detail`       | 80 characters | `$maxDetailLength`  |
| Content size                   | none          | one pass, no tree   |
| Nesting depth                  | none          | no tree             |

Memory is a copy of the input (line endings normalized) plus the current token. A text run
with no tags is one token, so a 10 MB string of text costs about 20 MB. Cap the size at the
form or the API before the check.

## Constraints and Gotchas

- **No cleaned output.** The result is accept or reject. Store the original string or refuse.
- **No line numbers.** `Violation` has `code` and `detail` only. The detail quotes the tag or
  attribute, which is enough to find it in a field.
- **Same problem once.** Five `<script>` tags report one error; five different `on*`
  attributes report five; two `<script>` tags with different attributes report two, since the
  detail is the whole tag.
- **50 errors and the check stops.** Nothing past the fiftieth distinct problem is reported.
- **A byte error arrives alone.** `not-utf8` and `control-character` stop the check before
  tokenizing.
- **`detail` and `message` are unencoded text from the content.** Always one line of valid
  UTF-8 (control characters and bytes that are not UTF-8 are escaped, as `\n` or `\351`), but
  they can hold `</script>`, quotes and backticks. HTML-encode for a page. A JSON response
  with `Content-Type: application/json` needs nothing extra. Inside a `<script>` block:

  ```php
  $json = json_encode($violation->message, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
  ```
- **Names are lowercased before matching**, so element and attribute rules are
  case-insensitive; URL schemes, hosts and CSS tokens are matched case-insensitively too.
- **The check assumes the content is printed as HTML body content.** Printed inside a
  `<script>`, a `<style>`, an attribute value or a `<textarea>`, any text is something else,
  and the rules do not apply.
- **Not checked:** whether the HTML is valid or well nested, `id` and `name` (DOM
  clobbering), `target`, external images and other tracking loads, `position: fixed` and other
  layout overlays, content length, and what the text says.
- **Custom elements pass with any attributes** except `on*`, `srcdoc` and scheme-bearing
  values. `<my-link url="javascript:x">` rejects; `<my-link url="https://x">` passes.
- **`data:` images are refused on `<img src>`** with the rest of `data:`. Upload the image
  and link the file.
- **Switches are process-wide.** They are static properties, so a change in one request
  handler affects every later `check()` in the same process. Set them at startup.
- **`Tokenizer`, `Token` and `CharacterReferences` are internal.** They are the validator's
  own parser, marked `@internal`, and can change between releases. The public API is
  `HtmlValidator`, `Result` and `Violation`.

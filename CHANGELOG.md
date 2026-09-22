# HtmlValidator Changelog

> Tagged releases roll up every change since the previous tag. Versions bundled
> with CMS Builder are marked on their sections. Error codes are never renamed
> once released; message wording can change, so match on the code.

## [UNRELEASED]

First release. `HtmlValidator::check()` runs the HTML5 tokenizer over rich-text content and
rejects anything that could run script in the page it is printed into, with a `Result` of
`Violation`s that quote the tag or attribute at fault. The content is never rewritten.

### Added

- **`HtmlValidator::check()`** returns a `Result` with `ok` and `errors`, one `Violation`
  per distinct problem, in document order, capped at 50. Nothing throws.
- **Element allowlist**: the HTML Standard's element index plus the obsolete presentational
  elements, hyphenated custom elements, and Word's `o:`, `v:` and `w:` prefixed names. Script,
  plugin, head-level and tokenizer-switching elements are refused with no switch.
- **Attribute patterns**: `on*` and `srcdoc` refused; `href`, `src` and the other URL
  attributes limited to `http:`, `https:`, `mailto:`, `tel:` or no scheme, plus
  `data:image/...` on `<img src>`; `javascript:` refused at the start of every attribute
  value; unknown attributes pass.
- **CSS check** on the `style` attribute and the `<style>` element: the constructs that ran
  script in some browser, `url()` limited to `http:`, `https:` or relative, and the two
  selectors that leak page data (`[attr^=]` and friends, `unicode-range`).
- **Switches** as static properties: `$allowForms` (off), `$allowStyles` (on),
  `$allowEmbeds` (on) with `$iframeHosts` for the `<iframe>` hosts that pass, and
  `$urlSchemes` for what a URL attribute may start with (`javascript` stays refused
  whatever it holds).
- **Unclosed markup** (`<img src="`, `<!-- x`, `<style>` with no end tag) is refused, since a
  fragment printed into a page keeps reading the page as part of it.
- **A `<` inside raw text is refused** (`less-than-in-text`): the content of `<style>`, `<iframe>`
  and `<textarea>`, and comments that are not `<!-- -->`, are text to a browser but tags to
  `strip_tags()` with an allow list or an HTML4-era parser, so a fragment that passed the check
  stays safe through them.
- **Old IE's markup**: the markup inside a `<!--[if ...]>` conditional comment gets the same
  rules as the rest, for the IE engine inside old Windows programs and Outlook's Word engine,
  so email templates' `<!--[if mso]>` tables pass and a script inside one rejects.
  `<?import ...>`, which bound a behavior to a prefix in IE 5.5 to 9, is refused.
- **Byte checks**: invalid UTF-8 and C0 control characters are refused before tokenizing.
- **`HtmlValidator::rules()`** returns the rule tables and current switch values for
  settings pages and docs. `Violation::TEMPLATES` holds every message for translation.
- **A fast path**: text and tags the rules can never refuse (a listed element, double-quoted
  attributes with nothing a rule looks at) are stepped over in one regex match instead of one
  token at a time. `HtmlValidator::$fastPath = false` sends every byte through the tokenizer:
  the same result, slower.
- **An HTML5 tokenizer** written from the HTML Standard's state list, run against the
  html5lib tokenizer test suite in CI. Internal: the public API is `HtmlValidator`, `Result`
  and `Violation`.

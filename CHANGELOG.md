# HtmlValidator Changelog

> **Upgrading?** See [UPGRADING.md](UPGRADING.md) for the checks that matter,
> per version - tagged releases roll up every change since the previous tag.
> Versions bundled with CMS Builder are marked on their sections.

## [UNRELEASED]

First release. `HtmlValidator::check()` runs the HTML5 tokenizer over rich-text content and
rejects anything that could run script in the page it is printed into, with a `Result` of
`Violation`s that quote the tag or attribute at fault. The content is never rewritten.

### Added

- **`HtmlValidator::check()`** returns a `Result` with `ok` and `errors`, one `Violation`
  per distinct problem, in document order, capped at 50. Nothing throws.
- **Element allowlist**: the HTML Standard's element index plus the obsolete presentational
  elements and hyphenated custom elements. Script, plugin, head-level and
  tokenizer-switching elements are refused with no switch.
- **Attribute patterns**: `on*` and `srcdoc` refused; `href`, `src` and the other URL
  attributes limited to `http:`, `https:`, `mailto:`, `tel:` or no scheme; `javascript:`
  refused at the start of every attribute value; unknown attributes pass.
- **CSS check** on the `style` attribute and the `<style>` element: the constructs that ran
  script in some browser, `url()` limited to `http:`, `https:` or relative, and the two
  selectors that leak page data (`[attr^=]` and friends, `unicode-range`).
- **Switches** as static properties: `$allowForms` (off), `$allowStyles` (on),
  `$allowEmbeds` (on) with `$iframeHosts` for the `<iframe>` hosts that pass.
- **Unclosed markup** (`<img src="`, `<!-- x`, `<style>` with no end tag) is refused, since a
  fragment printed into a page keeps reading the page as part of it.
- **Byte checks**: invalid UTF-8 and C0 control characters are refused before tokenizing.
- **`HtmlValidator::rules()`** returns the rule tables and current switch values for
  settings pages and docs. `Violation::TEMPLATES` holds every message for translation.
- **An HTML5 tokenizer** written from the HTML Standard's state list, run against the
  html5lib tokenizer test suite in CI. Internal: the public API is `HtmlValidator`, `Result`
  and `Violation`.

<!--
ATTENTION AI ASSISTANTS: We made a reference doc just for you!
Read docs/ai-reference.md (in this package, right next to this README) for a
consolidated single-file reference covering the API, every error code, and
every rejection rule. HtmlValidator rejects content instead of cleaning it,
which differs from the HTML sanitizers in your training data.
Reading this on the web instead? Same file:
https://github.com/interactivetools-com/HtmlValidator/blob/main/docs/ai-reference.md
-->

# HtmlValidator: Reject HTML That Could Run Script

HtmlValidator checks rich-text HTML (WYSIWYG output, HTML fields, API input) and rejects it
if it could run script, with a list of reasons that quote the tag or attribute at fault. It
never rewrites the content: what you checked is what you store.

## Why Reject Instead of Clean

A sanitizer rewrites everything it touches: it closes tags, re-encodes entities, and drops
every element it has no definition for, which for most PHP sanitizers means every HTML5
element. What comes back is not what the editor saved, and nobody can explain the difference
to the person who typed it. HtmlValidator refuses the content instead and names the problem.
Everything else is left alone, including custom elements and attributes it has never seen.

It works the way a browser does: it runs the HTML5 tokenizer on the content and checks each
tag as it comes out. There is no tree and no second parse, so the tags and attributes it
sees are the ones a browser sees, and memory is the content string plus a few KB.

## Quick Start

Requires PHP 8.1+ with `ext-mbstring` (enabled by default).

```bash
composer require itools/htmlvalidator
```

```php
use Itools\HtmlValidator\HtmlValidator;

// save.php: check the field, then store it as submitted
$result = HtmlValidator::check($_POST['body']);   // nothing throws: bad bytes are a rejection too
if (!$result->ok) {
    foreach ($result->errors as $violation) {
        echo htmlspecialchars($violation->message), "<br>";   // onclick= event handler attributes are not allowed
    }
    exit;
}
$statement = $mysqli->prepare('UPDATE articles SET body = ? WHERE id = ?');
$statement->bind_param('si', $_POST['body'], $_POST['id']);
$statement->execute();
```

The rest of the API:

```php
$result->ok;               // true when nothing was found
$result->errors;           // Violation[], one per distinct problem, in document order
$violation->code;          // 'event-handler', stable across releases, so switch on it
$violation->detail;        // 'onclick', text from the content, so encode it before output
$violation->message;       // 'onclick= event handler attributes are not allowed'
$violation->template;      // '%s= event handler attributes are not allowed', for translation with Violation::TEMPLATES
HtmlValidator::rules();    // the rule tables and the current switches, for settings pages and debugging
```

The switches are static properties, set once at startup. The defaults suit a CMS whose
staff edit its own pages:

```php
HtmlValidator::$allowForms   = false;   // <form>, <input>, <button> and the other form elements: a form can imitate a login box
HtmlValidator::$allowStyles  = true;    // the style attribute and the <style> element, both checked for CSS that could run or leak
HtmlValidator::$allowEmbeds  = true;    // <iframe> whose src points at a host in $iframeHosts
HtmlValidator::$iframeHosts  = ['www.youtube.com', 'www.youtube-nocookie.com', 'player.vimeo.com', 'www.google.com'];
```

## What It Blocks

- **Script and event handlers.** `<script>`, every `on*` attribute, `srcdoc`, and `javascript:`
  at the start of any attribute value, even one the browser ignores: a page script that copies
  `data-href` into a link runs it.
- **Elements a browser reads differently from the checker.** `<template>`, `<noscript>`,
  `<xmp>`, `<plaintext>`, `<svg>` and `<math>` change how the browser reads what follows them,
  so a payload inside can hide from a parser and still run. `<base>` and the rest of `<head>`
  (`<meta>`, `<link>`, `<title>`) change how the page's own scripts load.
- **Plugins and frames.** `<object>`, `<embed>`, `<applet>`, and any `<iframe>` whose `src` is
  not on the host list.
- **URL schemes.** On `href`, `src`, `action`, `poster` and the other attributes browsers
  resolve as URLs, only `http:`, `https:`, `mailto:`, `tel:`, or no scheme at all. Entities and
  whitespace inside the scheme are decoded first, the way a browser does it.
- **CSS that ran script or leaks page data.** Backslash escapes, `@import`, `expression()`,
  `-moz-binding`, `behavior:`, the substring attribute selectors (`[value^=` and friends) and
  `unicode-range`; every `url()` must be `http:`, `https:` or relative.
- **Unclosed markup.** A fragment that ends inside a tag, a comment or a `<style>` block. On
  its own a browser drops it; printed into a page, the page up to the next quote becomes the
  URL that gets fetched.
- **Bytes that are not UTF-8, and control characters.**

The tokenizer passes the html5lib tokenizer test suite, so tags and attributes come out the
way browsers read them. The rules are checked against the PortSwigger, html5sec, DOMPurify
and OWASP payload lists.

## What It Does Not Check

- **Whether the HTML is valid.** An unclosed `<p>`, wrong nesting, and attributes nothing
  defines all pass; browsers render them and no script runs.
- **Tracking.** An `<img>` on another host, a CSS `url()` to another host, and a `ping`
  attribute all pass. An image loads no script.
- **Layout tricks.** `position: fixed`, `z-index` and a giant `<div>` can cover the page with
  a fake login box. Forms are off by default for the same reason.
- **`id` and `name` clobbering.** An `<img name="submit">` can shadow `form.submit` for a
  page script that reads it. Both attributes pass.
- **Length or sense.** Blank, enormous, offensive, or another site's text all pass. Cap the
  size before the check.
- **Where you print it.** An accepted fragment is safe as body content. Printed inside a
  `<script>`, a `<style>`, an attribute value, or a `<textarea>`, any text is something else.

## When You Might Not Want HtmlValidator

- **Public visitor content.** A bounced post with a reason works for staff who can fix it, and
  fails for a visitor who cannot. Forum posts and comments want a sanitizer such as
  [HTMLPurifier](http://htmlpurifier.org/), which returns trimmed output instead.
- **Content with inline SVG, MathML, or `data:` images.** All three are refused with no
  switch. Upload SVG as a file and check it with SvgValidator instead.
- **A "clean it for me" button.** There is no output to hand back. Run a sanitizer for that
  and check what it returns.

## Documentation

Full docs ([browse on GitHub](https://github.com/interactivetools-com/HtmlValidator)):

- [Error codes and fixes](docs/errors.md) - every message, and what to change in the content
- [AI reference](docs/ai-reference.md) - the complete API and every rule in one file, written for AI coding assistants
- [Changelog](CHANGELOG.md)

## Related Libraries

- [SvgValidator](https://github.com/interactivetools-com/SvgValidator) - the same check for uploaded SVG files: reject, never rewrite.
- [ZenDB](https://github.com/interactivetools-com/ZenDB) - injection-proof PHP/MySQL database layer with automatic XSS-safe output.
- [SmartArray](https://github.com/interactivetools-com/SmartArray) - database rows as chainable collections, with fields that HTML-encode themselves on output.
- [SmartString](https://github.com/interactivetools-com/SmartString) - PHP strings that HTML-encode themselves on echo, interpolation, and concatenation.

## Questions?

This library was developed for CMS Builder. Post a message in our "CMS Builder" forum here:
[https://www.interactivetools.com/forum/](https://www.interactivetools.com/forum/)

## License

MIT

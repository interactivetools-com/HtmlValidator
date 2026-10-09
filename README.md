<!--
AI ASSISTANTS: Before writing code that uses HtmlValidator, read docs/ai-reference.md. It covers
the complete API and every rejection rule in one file and matches this installed version.
HtmlValidator rejects content instead of cleaning it, which differs from the HTML sanitizers in
training data, so trust that file over habit.
-->

# HtmlValidator: Reject HTML That Could Run Script

HtmlValidator checks HTML from any source (an editor, a web form, an email, an import, an API)
and rejects it if it could run script, with a list of reasons that quote the tag or attribute
at fault. It never rewrites the content: what passes is what you store and what you show.

## Why Reject Instead of Clean

A sanitizer rewrites everything it touches: it closes tags, re-encodes entities, and drops
every element it has no definition for, which for most PHP sanitizers means every HTML5
element. What comes back is not what the editor saved, and nobody can explain the difference
to the person who typed it. HtmlValidator refuses the content instead and names the problem.
Everything else passes as it was saved. The elements and attributes it accepts come from the
HTML Standard's own lists.

It works the way a browser does: it runs the HTML5 tokenizer on the content and checks each
tag as it comes out. There is no tree and no second parse, so the tags and attributes it
sees are the ones a browser sees, and memory is a copy of the content plus the current
token, about twice the content for ordinary markup.

## Quick Start

Requires PHP 8.1+. No extensions, no dependencies.

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

// show.php: content that arrived by import, email, a feed or an older save was never checked, so check it before printing
$article = $mysqli->query('SELECT body FROM articles WHERE id = 1')->fetch_assoc();
$result  = HtmlValidator::check($article['body']);
echo $result->ok ? $article['body'] : '<p>This article is hidden until its HTML is fixed.</p>';
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
HtmlValidator::$urlSchemes   = ['http', 'https', 'mailto', 'tel'];   // what a link may start with; add sms or an app link if your visitors' machines should open it
```

## What It Blocks

HTML reaches a stored field from many directions: a form a visitor filled in, an incoming
email, an import, a feed or a supplier's data, a plugin's own query, a restored backup, an API
call, and a staff member typing in the editor. The check does not know or care which. It takes
a string and answers one question: printed into the body of a page on your own site, where the
page's own scripts run, could this run script? That page may be public, or the admin page where
staff read what a form or an email brought in. The attack is stored XSS: one `<script>` in a
field runs for every visitor and every admin who opens the page, with their session.

So there are two places to call it: at the save, to refuse the content with a reason, and on
stored content before an editor or a page shows it, since most of those paths never pass
through a save handler. The check refuses everything that runs script in a current browser or
through the page's own scripts, and everything that would let a payload hide from the check
itself.

- **Anything that runs script.** `<script>`, every `on*` attribute, `srcdoc`, and the plugin
  elements `<object>`, `<embed>` and `<applet>`, which load a document that runs script of its
  own. On the attributes browsers read as URLs (`href`, `src`, `action`, `poster` and the rest),
  only `http:`, `https:`, `mailto:`, `tel:` or no scheme at all: `data:` is a whole document,
  and an unknown scheme asks the visitor's machine to open whatever program is registered for
  it. The one exception is `data:image/...` on `<img src>`, which a browser shows as a picture
  and nothing else. `javascript:` is refused at the start of every attribute value, even one
  the browser ignores, because a page script that copies the value into a link runs it.
- **Anything the page's own scripts could run.** Alpine runs `x-init`, HTMX sends `hx-get`,
  Stimulus reads `data-action`, and a page can define `<my-widget>` with code of its own. So
  every element and attribute must be on a list from the HTML Standard, plus `aria-*` and what
  Word adds to a paste. `data-*` and custom elements are refused.
- **Anything that could hide a payload from the check.** The check reads the content the way a
  browser does, tag by tag, so it refuses every construct that a browser and another parser
  read differently: `<template>`, `<noscript>`, `<xmp>`, `<plaintext>`, `<svg>`, `<math>`,
  bytes that are not UTF-8, control characters, backslash escapes in CSS, and a `<` inside
  `<style>`, `<iframe>`, `<textarea>` or a `<?...>` comment, which is text to a browser but a
  tag to `strip_tags()` with an allow list. The markup inside a `<!--[if ...]>` conditional
  comment gets the same rules as the rest, because the IE engine inside old Windows programs
  and Outlook still read it. A tag, comment or `<style>` that the content ends
  inside of is refused for the same reason: on its own a browser drops it, but printed into a
  page, the page up to the next quote becomes the URL that gets fetched.
- **CSS that reports what the page shows.** `[value^=` and the other substring selectors,
  `unicode-range`, `@import`, and `url()` to anything but `http:`, `https:` or a relative
  path. None of these run script. Each lets a stylesheet send what is on the page, a CSRF
  token or a prefilled email address, to another host one character at a time. `expression()`,
  `behavior:` and `-moz-binding` ran script in browsers nobody runs now; refusing them costs
  nothing.
- **Markup that belongs to the page, not the content.** `<html>`, `<head>`, `<body>`, `<meta>`,
  `<link>`, `<title>` and `<base>`. A `<meta http-equiv="refresh">` in a field redirects every
  visitor, a `<link rel="stylesheet">` loads CSS the check never saw, and `<base>` changes
  where the page's own relative `<script src>` paths load from.
- **Things a switch decides.** Forms are off by default: a form runs no script, but it can
  imitate a login box. Frames are on, from the hosts in `$iframeHosts` only, because a frame
  from anywhere can show anything. Styles are on, through the CSS check.

The tokenizer passes the html5lib tokenizer test suite, all but the cases that need a parser
rather than a tokenizer (a parsed DOCTYPE, the script-data and CDATA states) and four
lone-surrogate inputs, so tags and attributes come out the way browsers read them. The rules
are checked against the PortSwigger, html5sec, DOMPurify and OWASP payload lists.

## How the Rules Are Built

Every rule is an allowlist except the CSS check.

- **Elements and attributes.** A name passes only if it is on the list: the HTML Standard's
  names minus the ones above, plus `aria-*` and the names Word adds to a paste.
- **URLs.** A URL passes only with a scheme from `$urlSchemes`, or no scheme, plus
  `data:image/` on `<img src>`.
- **CSS.** A list of the safe parts would be most of CSS, so the check refuses the tokens
  named above instead. `url()` is the exception: `http:`, `https:` or a relative path only.

## What It Does Not Check

Everything here is real, and none of it runs script. It is left to the page, or to a rule of
your own, because a check for it would refuse ordinary content for no gain in safety.

- **Where you print it.** An accepted fragment is safe as body content. Printed inside a
  `<script>`, a `<style>`, an attribute value, or a `<textarea>`, any text is something else.
- **Whether the HTML is valid.** An unclosed `<p>`, wrong nesting, and an attribute on the
  wrong element (`<p href>`) all pass; browsers render them and no script runs.
- **Tracking.** An `<img>` on another host, a CSS `url()` to another host, and a `ping`
  attribute all pass. An image loads no script.
- **Layout tricks.** `position: fixed`, `z-index` and a giant `<div>` can cover the page with
  a fake login box. Forms are off by default for the same reason.
- **`id` and `name` clobbering.** An `<img name="submit">` can shadow `form.submit` for a
  page script that reads it. Both attributes pass.
- **Length or sense.** Blank, enormous, offensive, or another site's text all pass. Cap the
  size before the check. A check's time grows with the size of the content and never faster,
  so the size cap is also a cap on what a check can cost. The hostile inputs table in
  [benchmarks/results.md](benchmarks/results.md) shows what a megabyte of the worst shapes takes.

## When You Might Not Want HtmlValidator

- **Visitor content you publish as HTML.** A bounced post with a reason works for staff who can fix it, and
  fails for a visitor who cannot. Forum posts and comments want a sanitizer such as
  [HTMLPurifier](http://htmlpurifier.org/), which returns trimmed output instead.
- **Content with inline SVG or MathML.** Both are refused with no switch. Upload SVG as a
  file, check it with SvgValidator, and link it from `<img src>`.
- **Markup for a front-end framework.** `data-*` attributes and custom elements are refused
  with no switch, so markup for Alpine, HTMX, Stimulus or web components does not pass.
- **A "clean it for me" button.** There is no output to hand back. Run a sanitizer for that
  and check what it returns.

A check costs less than receiving the form post did; the measurements are in
[benchmarks/results.md](benchmarks/results.md).

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

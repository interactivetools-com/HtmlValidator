# Error Codes

Every code HtmlValidator reports, its message, and what to change so the content passes. The
message is the `template` with the `detail` filled in at `%s`. Codes never change once
released; message wording can, so match on `code`.

| Code                     | Message                                                                             | What to do                                                                                                                                                                                                                                                                                                                                                                                              |
|--------------------------|-------------------------------------------------------------------------------------|---------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `not-utf8`               | `Content must be UTF-8, this is not: %s`                                            | Convert the text to UTF-8 before the check. A Latin-1 `é` from an old export is the usual cause: `mb_convert_encoding($html, 'UTF-8', 'Windows-1252')` when the source encoding is known. Text typed into a form is already UTF-8.                                                                                                                                                                        |
| `control-character`      | `Content contains a control character: %s`                                          | Remove the character; the detail names its byte offset. A NUL comes from a cut-off binary paste or a bad conversion, an escape (`\033`) from terminal output pasted in. Tab, LF and CR are fine.                                                                                                                                                                                                       |
| `element-not-allowed`    | `%s is not allowed`                                                                 | Remove the tag. `<script>`: put the script in the page template. `<o:p>`, `<v:shape>`: a Word paste, so paste as text or use the editor's paste-from-Word. `<form>`: set `HtmlValidator::$allowForms`. `<style>`: `$allowStyles`. `<iframe>`: `$allowEmbeds`. `<svg>`: upload the file and use `<img>`. `<!DOCTYPE>`, `<html>`, `<head>`, `<body>`, `<meta>`, `<title>`: paste only the body content. |
| `event-handler`          | `%s= event handler attributes are not allowed`                                      | Remove the attribute. Put the behavior in the page's own script and select the element by a class or `data-*` attribute.                                                                                                                                                                                                                                                                                |
| `attribute-not-allowed`  | `The %s attribute is not allowed`                                                   | `srcdoc`: use `src` with a listed host instead. `formaction`: set `HtmlValidator::$allowForms`. `style`: set `$allowStyles`, or move the styles to the site's stylesheet.                                                                                                                                                                                                                              |
| `url-scheme-not-allowed` | `The URL in %s must start with http:, https:, mailto:, tel:, a relative path, or #` | Use an `http:`, `https:`, `mailto:` or `tel:` URL, or a relative path. A `data:` image: upload the image and link the file. A `javascript:` link: use `href="#"` and a page script. The value is quoted as a browser decodes it, so `&colon;` shows as `:`.                                                                                                                                             |
| `iframe-host`            | `Embedding frames from %s is not allowed`                                           | Add the host to `HtmlValidator::$iframeHosts`, spelled as it appears in the embed code (`player.vimeo.com`, not `vimeo.com`), with no scheme, port or path. An `<iframe>` with no `src` has nothing to allow: remove it.                                                                                                                                                                              |
| `css-not-allowed`        | `CSS containing %s is not allowed`                                                  | Remove the construct. `expression(`, `behavior:`, `-moz-binding`: old IE and Firefox only, nothing renders them now. `@import`: link the stylesheet from the page template. `[value^=`, `[href$=`, `[name*=`: use an exact match (`[value=`) or a class. `unicode-range`: drop it from `@font-face`. A backslash outside a comment or quoted string, or inside a quoted `url()`: write the character itself. `url(data:...)`: upload the image and use its path.       |
| `unclosed-markup`        | `%s is not closed`                                                                  | Close it: add the missing `>`, closing quote, `-->` or `</style>`. The detail starts where the open piece began, so the fix is at its end. A cut-off paste is the usual cause.                                                                                                                                                                                                                       |

To translate a message, run `template` through your translation function and put `detail`
back with `sprintf()`, then encode the whole result, since the detail holds `<`, `>` and
quotes: `echo htmlspecialchars(sprintf(t($violation->template), $violation->detail));`.
`Violation::TEMPLATES` holds every template keyed by code, so a translation system can
register them all up front.

Three things to know when reading a result:

- **A byte error arrives alone.** `not-utf8` and `control-character` stop the check before
  the content is read as HTML. Fix the bytes and check again; the other problems are
  reported then.
- **A refused tag reports once.** `<script onload="x" src="javascript:1">` is one
  `element-not-allowed`, not three errors. Its attributes are not checked, since the tag is
  going away.
- **The same problem on five tags is reported once.** Errors are deduplicated by code and
  detail: five `<script>` tags are one error, five different `on*` attributes are five. The
  list stops at `HtmlValidator::$maxErrors` distinct problems (50 by default), and
  `unclosed-markup` is always last.

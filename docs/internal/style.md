# Documentation Style Guide

The shared writing standards for all InteractiveTools libraries are in the team's
[internal docs repo](https://github.com/itools-internal/docs/tree/main/open-source)
under open-source/ (private, team access only). This file holds HtmlValidator-specific
additions only.

- **Reader assumption:** a working PHP programmer who stores what a WYSIWYG editor or an
  HTML field submits and prints it into a page. They know what a tag is; they have not read
  the HTML parsing spec. Explain a tokenizer fact the first time a page uses it, in half a
  sentence.
- **"Rejected" and "accepted"** are the two outcomes of a check; a rule "refuses" or
  "allows" a thing, and content that meets every rule "passes". "Sanitize", "clean", "strip"
  and "remove" name what the library does not do; use them only when contrasting with
  sanitizers.
- **"Content" or "the content"** is the string being checked. Not "the document" (it is a
  fragment printed into one) and not "the file".
- **Elements in code font with angle brackets, attributes bare:** `<script>`, `<iframe>`,
  `<my-widget>`; `href`, `onclick`, `srcdoc`. The event handler family is written `on*`. In
  a table cell that lists elements without brackets, say so once above the table.
- **Error codes always in backticks:** `element-not-allowed`. Codes are the API:
  applications switch on them, so a page never paraphrases one.
- **Switches are named as code:** `$allowForms`, `$iframeHosts`, or with the class when the
  reader might not know it (`HtmlValidator::$allowForms`). "The forms switch" is fine after
  the code name has appeared on the page.
- **"Script" means JavaScript running in the page** where the content is printed. Say
  "script" for the outcome and "JavaScript" only when naming the language.
- **"The page" is the page the content is printed into.** Every rule is argued from there:
  what a browser does with the fragment inside a full page, not with the fragment on its
  own. Where the two differ (unclosed markup), say so.
- **"Browsers" alone means Chrome, Firefox and Safari agree.** When they differ, or when a
  rule exists only for a browser nobody runs (`expression()`), say which.
- **`docs/errors.md` rows carry the fix only.** The message cell is the template from
  `Violation::TEMPLATES`, byte for byte (a test checks it), and the third column says what
  to change. What happened is what the message already says; do not restate it.
- **Public pages carry no counts.** No error-code totals, corpus tallies, test-suite counts,
  or dated figures in the README, errors.md or ai-reference.md. Counts are fine in
  `docs/internal/` where they are evidence for a decision.
- **The product is "the CMS".** The library was built for CMS Builder, which the README
  names once in Questions. Everywhere else in shipped text, "the CMS" or "a CMS", and
  nothing about its internals.

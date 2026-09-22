# Corpus watch log

One entry per run of `tools/corpus-watch.md`, newest last. The date of the last entry is the
"since" date for the next run.

## 2026-09-14

Baseline. All 18 sources downloaded for the first time; a sha256 per file or the git tree hash
per folder is recorded in each `corpus/<name>/SOURCE.json`. 3168 payloads. Tally: 730 accepted,
2438 rejected, no surprises after review, 6 open questions. `tools/corpus-known.json` holds 240
reviewed cases and the by-design and open rules. No advisory search yet; the next run covers
everything published after this date.

- Every must-reject payload the validator accepts was reviewed by hand: old-browser tricks (IE
  `expression()`, backtick quoting, conditional comments, Opera `-o-link`, Netscape `&{}`),
  known non-goals (DOM clobbering, tracking images, overlays), payloads that parse as text or
  comments per HTML5, Unicode spaces before `javascript:` that no current browser strips, and
  fragments cut from longer vectors. None runs in a current browser.
- Three false positives in the CSS check, each with a failing fixture under
  tests/Support/fixtures/accept/ until it is fixed: `scroll-behavior` and `overscroll-behavior`
  match the substring `behavior:`; backslashes inside CSS comments (43 of 44 Mailchimp
  templates); escapes inside quoted strings (`content:"\a0"` on Wikipedia, `"\@Yu Mincho"` in
  Word pastes).
- Three judgment calls, marked open in the known file:
  `div[style*="margin: 16px 0"]` in every email template; `data:` images that CKEditor keeps
  from Word pastes (21 of 85 normalized pastes); `<image>`, which browsers rewrite to `img`.
- From reading the spec, not the corpus: `<style>` inside `<select>` was ignored by the old "in
  select" insertion mode, so a spec tokenizer and such a browser disagree. Only matters when
  `allowForms` is on. To check in Firefox and Safari.
- Raw Word clipboard captures (89, kept with no expectation): 1 passes. `<o:p>` in 76, the CSS
  backslash in 48, the html and head wrapper, `file:` and `blob:` image sources. What TinyMCE's
  paste handling leaves is not in the corpus; a round trip through the CMS editor would settle
  the Word tags question.

## 2026-09-14 (source research, not a scheduled run)

Fourteen candidate sources rated. Added beyond the first four: PayloadsAllTheThings, CKEditor 5
XSS templates, bluemonday, OWASP Java HTML Sanitizer and AntiSamy, html5lib sanitizer, WPT
sanitizer-api, bleach, TinyMCE sanitization tests, CKEditor 5 paste-from-office captures,
Mailchimp and Cerberus email templates, WordPress theme test posts, MDN pages, Wikipedia
articles. Not added, with reasons, in tools/corpus-watch.md section 1.

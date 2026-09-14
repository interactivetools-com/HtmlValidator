# TinyMCE 4 round trip, measured 2026-09-13

`input.html` was pasted into a CMS Builder WYSIWYG field (TinyMCE 4, `verify_html: false`,
`entity_encoding: raw`, `allow_script_urls` default false) in source view and saved.
`output.html` is what came back from the database. The editor removed only script-capable
URL schemes and the head-only `<meta>` and `<base>`; everything else survived, including
the browser-normalized forms of the `<noscript>` and `--!>` tricks.

Both files are reject fixtures for the validator. The "Baseline" and "HTML5 tags" sections
of `output.html`, minus the payloads, are the seed of the pass corpus.

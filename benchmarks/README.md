# Benchmarks

`check-speed.php` times `HtmlValidator::check()` on generated content of known sizes, on a
set of hostile inputs built to cost a tokenizer time, and on the real files in `corpus/` and
`tests/Support/fixtures/`. `results.md` is the raw output of one run.

Run it through `run.sh`, which turns opcache on and xdebug off and caps the memory and
process count of everything it starts:

```bash
benchmarks/run.sh                                                 # generated and hostile inputs
benchmarks/run.sh --corpus=corpus                                 # add the corpus and fixture tables (php tools/fetch-corpus.php first)
benchmarks/run.sh --sanitizer=/path/to/vendor/autoload.php        # add HTMLPurifier columns for scale
PHP=/opt/plesk/php/8.5/bin/php benchmarks/run.sh                  # a PHP binary that is not on the PATH
```

Every time is the fastest of several runs of one `check()` call on a string already in
memory, so reading the file is not counted.

## The tables

**Generated content** is three shapes of markup at four sizes: paragraphs of plain text, the
same text with an inline tag every few words (links, bold, spans, line breaks), and what Word
pastes into an editor (`MsoNormal` paragraphs, long `style` attributes, bordered tables).
Every generated input passes the check. Throughput is the input size divided by the check
time, so the three shapes show how much the cost depends on tag density rather than on
bytes.

**Hostile inputs** are built to cost a tokenizer time or memory: a long run of `<`, an
attribute value that never closes, thousands of attributes on one tag, very deep nesting, a
large `<style>` block, and an attribute value that is one long chain of character
references. Most of them pass, since nothing in them runs script; the Result column says
which are refused and with which error code. The table shows what reading each one costs
either way.

Both of those tables are timed in a fresh PHP process per input, which also reports how
much its peak memory grew during the timed calls. That growth includes PCRE's own
allocations, which `memory_get_peak_usage()` cannot see.

**Corpus and fixtures** is every `.html` file under `corpus/` (the XSS payload lists
`tools/fetch-corpus.php` downloads) and under the `accept`, `reject` and `tinymce4` folders
of `tests/Support/fixtures/`, timed in the main process, three passes, fastest time per
file. The first table groups the files by size and the second by source, so payload lists
and our own must-pass samples can be told apart. The html5lib folder holds JSON test files,
not HTML, so it is not timed.

## The HTMLPurifier comparison

The sanitizer comparison needs `ezyang/htmlpurifier` installed somewhere outside this
repository. It is LGPL licensed: timing it for a comparison on your own machine is a use
the license allows, but it is never a dependency here and none of its code ships with this
MIT library. Point `--sanitizer` at that install's `vendor/autoload.php`.

The columns appear on the generated content table only. HTMLPurifier rewrites content
rather than accepting or refusing it, so on the hostile inputs the two tools would not be
doing the same job. The purifier is built once outside the timed call, the way an
application would hold one, and its definition cache is left at the default location inside
its own install folder.

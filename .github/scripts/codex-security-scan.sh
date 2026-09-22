#!/usr/bin/env bash
# Scan library code with Codex Security. Maintainer tooling: runs locally, not
# in CI, and needs the codex-security CLI installed. Results go to the CLI's
# state dir; view them with: codex-security scans list
# Uses the CLI's default model, reasoning effort and scan limits. Additional CLI
# flags can be passed as arguments. Run scans one repo at a time: concurrent scans
# share a sandbox dir in /tmp and kill each other's workers.
# Full pre-release scan:
#   .github/scripts/codex-security-scan.sh --mode deep
# Pick a model (the default is gpt-5.6-sol):
#   .github/scripts/codex-security-scan.sh --mode deep --model gpt-6-astra
set -euo pipefail
cd "$(dirname "$0")/../.."

# There's no exclude flag, so build the path list here: everything except tests,
# gitignored files (vendor, corpus, caches, .idea) and __* scratch notes. Skipping
# those keeps the scan on shipped code; corpus/ is thousands of third-party HTML
# files, and the scratch notes get quoted back as evidence, which we don't want
# steering the results.
shopt -s dotglob
paths=()
for entry in *; do
    if [[ $entry == .git || $entry == tests || $entry == __* ]] || git check-ignore -q "$entry"; then
        continue
    fi
    paths+=(--path "$entry")
done

# The scan prompt lives here (written to a temp file at runtime) so the repo
# needs no scratch file. It says what the library promises and what it leaves
# out on purpose; without that, the scanner reports the documented boundaries
# (tracking loads, layout overlays, unencoded detail, no size cap) as findings
# instead of looking for a fragment that gets past the rules. It states the
# intent (what an accepted fragment may do in a page) and asks for the browser
# behavior behind a gap, not a working demonstration: workers turn attack
# vocabulary into attack payloads, and OpenAI's content check refuses those
# workers mid-scan.
prompt_file=$(mktemp)
trap 'rm -f "$prompt_file"' EXIT
cat > "$prompt_file" <<'PROMPT'
This is a whole-library pre-release scan of HtmlValidator, a PHP 8.1+ library
that checks an HTML fragment (editor output, an HTML field, an email, API
input) and rejects it when it could run script in the page it is printed
into. It validates and never rewrites: there is no cleaned output. tests/,
vendor/ and corpus/ are excluded on purpose. Review runtime source,
documentation examples, and maintainer tooling (tools/, benchmarks/,
.github/) in their actual contexts; the tools download and tally third-party
test files on a developer machine and never run in a deployed web
application. Assess the current checkout for release readiness. Calibrate
severity to the evidence; not every finding is a release blocker.

What the library promises

An accepted fragment, printed as body HTML into a page on the site's own
origin by a template the site controls, cannot run script in current Chrome,
Firefox or Safari, cannot hide a payload from the check by being read
differently by the browser's parser than by the tokenizer that checked it,
and cannot send what is on the page to another host through CSS. The check
runs the HTML5 tokenizer on the content and checks every start tag as it is
produced; there is no tree. docs/ai-reference.md lists every rule, allowlist
and switch and is the specification; docs/internal/design-decisions.md
records the threat model and what was left out on purpose.

What to look for

A fragment the library accepts that a browser treats differently from what
the rules assume. The likely places:

- An allowed element or attribute that a browser can make run script, or
  load a document that runs script of its own. Name the browser and the
  behavior.
- Something the tokenizer reads one way and a browser another: the raw text
  and RCDATA element states, character references in attribute values, line
  ending normalization, NUL and control bytes, invalid UTF-8, the
  unclosed-markup rules, and the fast path: a text run or tag its regex steps
  over that the tokenizer would have refused, since the two must give the
  same result.
- A URL that the scheme check reads as safe and a browser reads as a scheme:
  whitespace, control characters, character references, case, or an
  attribute a browser reads as a URL that the URL rule does not cover.
- A CSS construct the CSS check misses: an escape the comment and string
  blanking does not see, a comment or string boundary read differently from
  a browser's CSS tokenizer, a selector that reports page data in a way the
  substring selector rule does not catch, or a function or at-rule that
  fetches.
- Unbounded work on attacker-controlled text: a loop whose pass count
  depends on input syntax, or a pattern where preg_match() returns false
  past the PCRE limits and the false lets the content through.

For each, give the rule that should have applied, why it did not, the
browser behavior with its source (a spec section or a web-platform test),
and the smallest fragment that shows the gap with a harmless marker, such
as a paragraph that turns red when the gap is real. A working demonstration
is not wanted and not needed.

Do not report any of these, not as a finding, a note, or a hardening
suggestion. They are documented decisions, and time spent on them is
wasted:

- Violation detail and message are copied from the content, always one line
  of valid UTF-8; the docs say to encode them for the output. The one thing
  to flag is a documentation example that puts them into HTML or JavaScript
  unencoded; the library returning them is correct.
- Content length, nesting depth and what the text says are not checked; the
  docs make the size cap the form's or the API's job.
- Tracking loads (an <img> on another host, a CSS url() to another host,
  ping, srcset), layout overlays (position: fixed, z-index, a full-page div,
  and a form when the forms switch is on), DOM clobbering through id and
  name, target="_blank" without rel="noopener", and an exact-match attribute
  selector that reports one yes or no per guess are documented non-goals.
- Whether the HTML is valid or well nested.
- Where the fragment is printed: the check assumes body HTML. Printed inside
  a <script>, a <style>, an attribute value or a <textarea>, any text is
  something else, and the docs say so.
- The switches are process-wide static properties by design.
- Rejecting more than a browser would run (<template>, <noscript>, <svg>,
  <math>, unclosed markup, substring selectors, unicode-range, a custom
  element attribute with a refused scheme) is by design.
- The library's own time and memory: the check is one pass, and memory is
  the content plus the current token.
- Tokenizer, Token and CharacterReferences are marked @internal; their
  public methods are not a public API.

Use prior findings as leads, not proof. Verify the current implementation,
consolidate one root cause reported several ways, and calibrate severity to
what an accepted fragment can actually do.
PROMPT

codex-security scan . "${paths[@]}" \
    --knowledge-base docs/ai-reference.md \
    --knowledge-base docs/errors.md \
    --knowledge-base docs/internal/design-decisions.md \
    --scan-prompt-file "$prompt_file" \
    "$@"

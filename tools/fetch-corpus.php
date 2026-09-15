#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Downloads the XSS payload lists, sanitizer test suites and real-content samples in
 * sources() into corpus/ (gitignored) for tools/tally.php.
 *
 *     php tools/fetch-corpus.php                  # every source not downloaded yet
 *     php tools/fetch-corpus.php html5sec owasp   # named sources, downloaded again
 *
 * A source is either a list of URLs or one folder of a GitHub repo (listed through the API,
 * which allows 60 calls an hour without a token; set GITHUB_TOKEN, for example from
 * `gh auth token`, for more). URL downloads are kept as corpus/<name>/source.<file>; repo
 * files are not kept twice. Each payload is written on its own to corpus/<name>/NNNN.html
 * with nothing added, and cases.json gives each file its title and what the validator should
 * do with it: reject, accept, or none when the file is only there to be looked at (a raw
 * Word paste, which no editor stores as is). A payload with no "<" in it is a value for one
 * attribute or one script string, which the validator never sees on its own, so those are
 * left out, and so is a payload the same source already listed. LICENSE holds the license
 * text or the copyright note, and SOURCE.json records where the files came from, when, the
 * sha256 of each download or the git tree hash of the folder (so tools/corpus-watch.md can
 * tell whether a source changed), and what is expected of the set: reject for a payload
 * list, accept for real content, mixed for a sanitizer's own test suite.
 *
 * Real-content sources that are whole documents (email templates, Word pastes) are cut to
 * what a CMS field would hold: the body, with the style elements from the head in front of
 * it. That is bodyWithStyles() below.
 *
 * A sanitizer's test suite pairs each input with the output it wants. When the output kept
 * every tag and attribute (only quoting or entities changed), the input is something that
 * sanitizer allows, so we expect to accept it; when a tag or attribute is gone, we expect to
 * reject it. That reading is expectFromPair() below. Where it disagrees with our rules by
 * design (inline svg, javascript: in any attribute) the tally shows it and the reason goes
 * in tools/corpus-known.json.
 *
 * Nothing under corpus/ is ever copied into tests/: the PortSwigger page is copyrighted,
 * the OWASP sheet, Wikipedia, MDN and the Mailchimp templates are CC BY-SA, the editor
 * suites and paste captures are GPL. Running the validator over the files on a developer's
 * machine is a use every one of those licenses allows. Our own must-pass samples go in
 * tests/Support/fixtures/accept/, written in our own words from what the corpus surfaced.
 */

const CORPUS_DIR = __DIR__ . '/../corpus';
const RAW        = 'https://raw.githubusercontent.com';

function sources(): array
{
    return [
        'portswigger' => [
            'urls'    => ['https://portswigger.net/web-security/cross-site-scripting/cheat-sheet'],
            'license' => 'Copyright PortSwigger Ltd. Downloaded for local testing only, not for redistribution.',
            'spdx'    => 'proprietary',
            'expect'  => 'reject',
            'extract' => 'extractPortSwigger',
        ],
        'html5sec' => [
            'urls'    => [RAW . '/cure53/H5SC/master/items.js', RAW . '/cure53/H5SC/master/payloads.js'],
            'license' => RAW . '/cure53/H5SC/master/LICENSE',
            'spdx'    => 'MPL-2.0',
            'expect'  => 'reject',
            'extract' => 'extractHtml5sec',
        ],
        'dompurify' => [
            'urls'    => [RAW . '/cure53/DOMPurify/main/test/fixtures/expect.mjs'],
            'license' => RAW . '/cure53/DOMPurify/main/LICENSE',
            'spdx'    => 'Apache-2.0 OR MPL-2.0',
            'expect'  => 'reject',
            'extract' => 'extractDomPurify',
        ],
        'owasp' => [
            'urls'    => [RAW . '/OWASP/CheatSheetSeries/master/cheatsheets/XSS_Filter_Evasion_Cheat_Sheet.md'],
            'license' => RAW . '/OWASP/CheatSheetSeries/master/LICENSE.md',
            'spdx'    => 'CC-BY-SA-4.0',
            'expect'  => 'reject',
            'extract' => 'extractMarkdownBlocks',
        ],
        'payloads' => [
            'urls'    => [
                RAW . '/swisskyrepo/PayloadsAllTheThings/master/XSS%20Injection/README.md',
                RAW . '/swisskyrepo/PayloadsAllTheThings/master/XSS%20Injection/1%20-%20XSS%20Filter%20Bypass.md',
                RAW . '/swisskyrepo/PayloadsAllTheThings/master/XSS%20Injection/Intruders/xss_alert.txt',
                RAW . '/swisskyrepo/PayloadsAllTheThings/master/XSS%20Injection/Intruders/IntrudersXSS.txt',
                RAW . '/swisskyrepo/PayloadsAllTheThings/master/XSS%20Injection/Intruders/JHADDIX_XSS.txt',
                RAW . '/swisskyrepo/PayloadsAllTheThings/master/XSS%20Injection/Intruders/XSSDetection.txt',
                RAW . '/swisskyrepo/PayloadsAllTheThings/master/XSS%20Injection/Intruders/xss_payloads_quick.txt',
                RAW . '/swisskyrepo/PayloadsAllTheThings/master/XSS%20Injection/Intruders/XSS_Polyglots.txt',
            ],
            'license' => RAW . '/swisskyrepo/PayloadsAllTheThings/master/LICENSE',
            'spdx'    => 'MIT',
            'expect'  => 'reject',
            'extract' => 'extractPayloadsAllTheThings',
        ],
        'wpt' => [
            'urls'    => [
                RAW . '/web-platform-tests/wpt/master/sanitizer-api/sethtml-safety.sub.dat',
                RAW . '/web-platform-tests/wpt/master/sanitizer-api/sethtml-unsafety.sub.dat',
                RAW . '/web-platform-tests/wpt/master/sanitizer-api/sanitizer-javascript-url.html',
            ],
            'license' => RAW . '/web-platform-tests/wpt/master/LICENSE.md',
            'spdx'    => 'BSD-3-Clause',
            'expect'  => 'mixed',
            'extract' => 'extractWpt',
        ],
        'html5lib' => [
            'urls'    => [RAW . '/html5lib/html5lib-python/master/html5lib/tests/sanitizer-testdata/tests1.dat'],
            'license' => RAW . '/html5lib/html5lib-python/master/LICENSE',
            'spdx'    => 'MIT',
            'expect'  => 'mixed',
            'extract' => 'extractHtml5lib',
        ],
        'bleach' => [
            'urls'    => array_map(static fn(int $i) => RAW . "/mozilla/bleach/main/tests/data/$i.test", range(1, 20)),
            'license' => RAW . '/mozilla/bleach/main/LICENSE',
            'spdx'    => 'Apache-2.0',
            'expect'  => 'mixed',
            'extract' => 'extractBleach',
        ],
        'bluemonday' => [
            'urls'    => [RAW . '/microcosm-cc/bluemonday/main/sanitize_test.go'],
            'license' => RAW . '/microcosm-cc/bluemonday/main/LICENSE.md',
            'spdx'    => 'BSD-3-Clause',
            'expect'  => 'mixed',
            'extract' => 'extractBluemonday',
        ],
        'owasp-java' => [
            'urls'    => [
                RAW . '/OWASP/java-html-sanitizer/main/owasp-java-html-sanitizer/src/test/java/org/owasp/html/HtmlSanitizerTest.java',
                RAW . '/OWASP/java-html-sanitizer/main/owasp-java-html-sanitizer/src/test/java/org/owasp/html/AntiSamyTest.java',
            ],
            'license' => RAW . '/OWASP/java-html-sanitizer/main/COPYING',
            'spdx'    => 'Apache-2.0 OR BSD-2-Clause',
            'expect'  => 'mixed',
            'extract' => 'extractOwaspJava',
        ],
        'ckeditor' => [
            'urls'    => [RAW . '/ckeditor/ckeditor5/master/packages/ckeditor5-engine/tests/dataprocessor/_utils/xsstemplates.js'],
            'license' => RAW . '/ckeditor/ckeditor5/master/packages/ckeditor5/LICENSE.md',
            'spdx'    => 'GPL-2.0-or-later',
            'expect'  => 'reject',
            'extract' => 'extractCkeditor',
        ],
        'tinymce' => [
            'urls'    => [RAW . '/tinymce/tinymce/main/modules/tinymce/src/core/test/ts/browser/html/SanitizationTest.ts'],
            'license' => RAW . '/tinymce/tinymce/main/LICENSE.md',
            'spdx'    => 'GPL-2.0-or-later',
            'expect'  => 'mixed',
            'extract' => 'extractTinymce',
        ],

        // Real content, expected to pass. A rejection here is a missing allowlist entry or a real problem in the sample.
        'ckeditor-paste' => [
            'repo'    => 'ckeditor/ckeditor5',
            'ref'     => 'master',
            'path'    => 'packages/ckeditor5-paste-from-office/tests/_data',
            'keep'    => '/\\/(input|normalized)\\.[^\\/]+\\.html$/',   // model.* files are the editor's model, not HTML
            'license' => RAW . '/ckeditor/ckeditor5/master/packages/ckeditor5/LICENSE.md',
            'spdx'    => 'GPL-2.0-or-later',
            'expect'  => 'mixed',
            'extract' => 'extractCkeditorPaste',
        ],
        'cerberus' => [
            'urls'    => [RAW . '/TedGoas/Cerberus/main/cerberus-fluid.html', RAW . '/TedGoas/Cerberus/main/cerberus-hybrid.html', RAW . '/TedGoas/Cerberus/main/cerberus-responsive.html'],
            'license' => RAW . '/TedGoas/Cerberus/main/LICENSE',
            'spdx'    => 'MIT',
            'expect'  => 'accept',
            'extract' => 'extractHtmlFiles',
        ],
        'mailchimp' => [
            'repo'    => 'mailchimp/email-blueprints',
            'ref'     => 'master',
            'path'    => '',
            'keep'    => '/\\.html$/',
            'license' => 'Creative Commons Attribution-ShareAlike 3.0 Unported, as stated in the README of https://github.com/mailchimp/email-blueprints (the repo has no LICENSE file). Full text: https://creativecommons.org/licenses/by-sa/3.0/legalcode',
            'spdx'    => 'CC-BY-SA-3.0',
            'expect'  => 'accept',
            'extract' => 'extractHtmlFiles',
        ],
        'wordpress' => [
            'urls'    => [RAW . '/WordPress/theme-test-data/master/themeunittestdata.wordpress.xml', RAW . '/WordPress/theme-test-data/master/64-block-test-data.xml'],
            'license' => 'No license file in the repo. The data is published by the WordPress project for theme testing: https://github.com/WordPress/theme-test-data',
            'spdx'    => 'none stated',
            'expect'  => 'accept',
            'extract' => 'extractWordPress',
        ],
        'wikipedia' => [
            'urls'    => array_map(static fn(string $page) => "https://en.wikipedia.org/w/api.php?action=parse&page=$page&prop=text|title&format=json&formatversion=2", ['HTML', 'Cascading_Style_Sheets', 'PHP', 'Email', 'Vancouver', 'Canada']),
            'license' => 'https://creativecommons.org/licenses/by-sa/4.0/legalcode.txt',
            'spdx'    => 'CC-BY-SA-4.0',
            'expect'  => 'accept',
            'extract' => 'extractWikipedia',
        ],
        'mdn' => [
            'urls'    => array_map(static fn(string $page) => "https://developer.mozilla.org/en-US/docs/$page/index.json", ['Web/HTML/Reference/Elements/table', 'Web/HTML/Reference/Elements/a', 'Web/HTML/Reference/Elements/img', 'Web/HTML/Reference/Elements/video', 'Web/HTML/Reference/Elements/form', 'Web/CSS/Reference/Properties/background-image', 'Learn_web_development/Core/Structuring_content/HTML_table_basics']),
            'license' => RAW . '/mdn/content/main/LICENSE.md',
            'spdx'    => 'CC-BY-SA-2.5',
            'expect'  => 'accept',
            'extract' => 'extractMdn',
        ],
    ];
}

$requested = array_slice($argv, 1);
foreach ($requested as $name) {
    if (!isset(sources()[$name])) {
        fwrite(STDERR, "Unknown source '$name'. Known: " . implode(', ', array_keys(sources())) . "\n");
        exit(1);
    }
}

foreach (sources() as $name => $source) {
    $dir = CORPUS_DIR . "/$name";
    if ($requested === [] && is_file("$dir/SOURCE.json")) {
        echo str_pad($name, 16), "already downloaded, skipping (name it to refresh)\n";
        continue;
    }
    if ($requested !== [] && !in_array($name, $requested, true)) {
        continue;
    }
    fetchSource($name, $source, $dir);
}
echo "Done. Run: php tools/tally.php\n";

function fetchSource(string $name, array $source, string $dir): void
{
    $raws    = [];   // file name (or path inside the repo folder) => body
    $version = [];   // what SOURCE.json records so a later run can tell whether the source changed
    if (isset($source['repo'])) {
        [$files, $tree] = githubFolder($source);
        echo str_pad($name, 16), 'downloading ', count($files), " files from $source[repo] ... ";
        foreach ($files as $path => $url) {
            $raws[$path] = httpGet($url);
        }
        $version = ['repo' => $source['repo'], 'path' => $source['path'], 'tree' => $tree];
    } else {
        echo str_pad($name, 16), 'downloading ', count($source['urls']), ' file(s) ... ';
        foreach ($source['urls'] as $url) {
            $key = rawurldecode(basename(parse_url($url, PHP_URL_PATH)));   // the file name; the whole URL when several share one (index.json, api.php)
            $raws[isset($raws[$key]) || count(array_keys($source['urls'], $url)) > 1 || preg_match('/^(index\.\w+|api\.php)$/', $key) ? $url : $key] = httpGet($url);
        }
        $version = ['urls' => $source['urls'], 'sha256' => array_map(static fn(string $raw) => hash('sha256', $raw), $raws)];
    }

    $cases = [];   // payload => [title, expect]; keyed by payload so a source lists each one once
    foreach ($source['extract']($raws) as [$title, $html, $expect]) {
        if (str_contains($html, '<')) {
            $cases[$html] ??= [$title, $expect ?? $source['expect']];
        }
    }
    echo count($cases), " payloads\n";

    if (is_dir($dir)) {
        clearDirectory($dir);
    } else {
        mkdir($dir, 0755, true);
    }
    if (!isset($source['repo'])) {
        foreach ($raws as $file => $raw) {
            file_put_contents("$dir/source." . preg_replace('/[^\w.-]+/', '_', $file), $raw);
        }
    }
    file_put_contents("$dir/LICENSE", str_starts_with($source['license'], 'https://') ? httpGet($source['license']) : "$source[license]\n");

    $index = [];
    $i     = 0;
    foreach ($cases as $html => [$title, $expect]) {
        $file = sprintf('%04d.html', ++$i);
        file_put_contents("$dir/$file", $html);
        $index[$file] = ['title' => $title, 'expect' => $expect];
    }
    file_put_contents("$dir/cases.json", json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    file_put_contents("$dir/SOURCE.json", json_encode([
        'license' => $source['spdx'],
        'expect'  => $source['expect'],
        'fetched' => date('c'),
        'files'   => count($cases),
        ...$version,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

//region Extractors: each returns a list of [title, html, expect or null for the source default]

/**
 * Every vector on the page links to a live demo at portswigger-labs.net with the payload in
 * the x= query parameter. Those with an HTML context (or none) are HTML fragments; the rest
 * are JavaScript strings or attribute values on their own, which the validator never sees.
 */
function extractPortSwigger(array $raws): array
{
    $cases = [];
    preg_match_all('/<h[23][^>]*>([^<]+)<\/h[23]>|<details id="([^"]*)"|href="https:\/\/portswigger-labs\.net\/xss\/xss\.php\?([^"]*)"/', reset($raws), $matches, PREG_SET_ORDER);
    $title = '';
    foreach ($matches as $match) {
        if (!isset($match[3])) {
            $title = $match[2] ?? $match[1];   // the section heading, or the event handler's <details id>, names every link until the next one
            continue;
        }
        parse_str(html_entity_decode($match[3]), $query);
        $context = $query['context'] ?? 'html';   // "html#..." is an html context with a URL fragment after it
        if (str_starts_with($context, 'html')) {
            $cases[] = [$title, $query['x'] ?? '', null];
        }
    }
    return $cases;
}

/**
 * items.js is a JavaScript array; each item's 'data' is a single-quoted string holding the
 * vector with %placeholders% that payloads.js expands (js_uri_alert = javascript:alert(1)).
 * The title is the English name of the item.
 */
function extractHtml5sec(array $raws): array
{
    $payloads = [];
    preg_match_all("/^\s*'([a-z0-9_]+)'\s*:\s*'((?:[^'\\\\]|\\\\.)*)'/m", $raws['payloads.js'], $matches, PREG_SET_ORDER);
    foreach ($matches as [, $key, $value]) {
        $payloads["%$key%"] = jsUnquote($value);
    }

    $cases = [];
    preg_match_all("/'name'\s*:\s*\{\s*'en'\s*:\s*'((?:[^'\\\\]|\\\\.)*)'.*?'data'\s*:\s*'((?:[^'\\\\]|\\\\.)*)'/s", $raws['items.js'], $matches, PREG_SET_ORDER);
    foreach ($matches as [, $name, $data]) {
        $cases[] = [jsUnquote($name), strtr(jsUnquote($data), $payloads), null];
    }
    return $cases;
}

/**
 * expect.mjs is a JSON array behind "export default", with trailing commas. Each entry has
 * the payload DOMPurify was given and what it returned; entries where nothing was removed
 * are regression tests for over-stripping, not attacks, so they are left out.
 */
function extractDomPurify(array $raws): array
{
    $json    = preg_replace(['/^\s*export default\s*/', '/;\s*$/', '/,(\s*[\]}])/'], ['', '', '$1'], reset($raws));
    $entries = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $cases   = [];
    foreach ($entries as $entry) {
        $expected = (array)$entry['expected'];
        if (!in_array($entry['payload'], $expected, true)) {
            $cases[] = [$entry['title'] ?? '', $entry['payload'], null];
        }
    }
    return $cases;
}

/**
 * A Markdown sheet where the vectors are fenced code blocks. Blocks marked js, php, sh,
 * log and the like are commentary. The nearest heading above a block is its title. A block
 * whose every line holds a tag is a list of one-line vectors and is split into them.
 */
function extractMarkdownBlocks(array $raws, array $languages = ['', 'html']): array
{
    $cases = [];
    foreach ($raws as $file => $markdown) {
        $title = '';
        preg_match_all('/^(#{2,4}) ([^\n]+)$|^```(\w*)\n(.*?)^```$/ms', $markdown, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            if (isset($match[2]) && $match[2] !== '') {
                $title = trim($match[2]);
            } elseif (isset($match[4]) && in_array($match[3], $languages, true)) {
                $block = rtrim($match[4], "\n");
                $lines = array_filter(explode("\n", $block), static fn(string $line) => trim($line) !== '');
                $vectors = array_filter($lines, static fn(string $line) => str_contains($line, '<')) === $lines ? $lines : [$block];
                foreach ($vectors as $vector) {
                    $cases[] = [$title, $vector, null];
                }
            }
        }
    }
    return $cases;
}

/**
 * The XSS Injection folder: two Markdown pages where every code block is a vector whatever
 * language it is marked with, and Intruder word lists with one vector per line. xss_alert.txt
 * writes bytes as \xNN escapes, which are resolved so the tokenizer sees the real bytes.
 */
function extractPayloadsAllTheThings(array $raws): array
{
    $cases = [];
    foreach ($raws as $file => $raw) {
        if (str_ends_with($file, '.md')) {
            $cases = [...$cases, ...extractMarkdownBlocks([$file => $raw], ['', 'html', 'javascript', 'js', 'xml'])];
            continue;
        }
        foreach (explode("\n", $raw) as $line) {
            if ($file === 'xss_alert.txt') {
                $line = preg_replace_callback('/\\\\x([0-9a-fA-F]{2})/', static fn(array $m) => chr((int)hexdec($m[1])), $line);
            }
            $cases[] = [$file, rtrim($line, "\r"), null];
        }
    }
    return $cases;
}

/**
 * The Sanitizer API tests. sethtml-safety and sethtml-unsafety hold the same inputs, in the
 * same order, with the tree setHTML() and setHTMLUnsafe() build from each: equal trees mean
 * the sanitizer kept everything. sanitizer-javascript-url.html holds html5lib-style cases in
 * script blocks, each with the tree the safe method builds, read with expectFromPair().
 * Cases with a #document-fragment context are skipped: the validator never knows the
 * element a field is printed into.
 */
function extractWpt(array $raws): array
{
    $cases  = [];
    $safe   = parseDat($raws['sethtml-safety.sub.dat']);
    $unsafe = parseDat($raws['sethtml-unsafety.sub.dat']);
    foreach ($safe as $i => [$data, $safeTree]) {
        $cases[] = ["sethtml-safety " . ($i + 1), $data, $safeTree === $unsafe[$i][1] ? 'accept' : 'reject'];
    }
    preg_match_all('/<script id="([^"]*)" type="html5lib-testcases">(.*?)<\/script>/s', $raws['sanitizer-javascript-url.html'], $groups, PREG_SET_ORDER);
    foreach ($groups as [, $group, $block]) {
        foreach (parseDat($block) as $i => [$data, $tree]) {
            $cases[] = ["$group " . ($i + 1), $data, expectFromPair($data, $tree)];
        }
    }
    return $cases;
}

/** html5lib .dat cases as [data, document] pairs; cases with a #document-fragment context are dropped. */
function parseDat(string $dat): array
{
    $cases = [];
    foreach (array_slice(preg_split('/^#data\n/m', "\n$dat"), 1) as $block) {
        if (str_contains($block, '#document-fragment')) {
            continue;
        }
        [$data,]  = preg_split('/^#/m', $block, 2);
        $document = preg_match('/^#document\n(.*?)(?=^#|\n\n|\z)/ms', $block, $m) ? $m[1] : '';
        $cases[]  = [rtrim($data, "\n"), trim($document)];
    }
    return $cases;
}

/** html5lib's sanitizer suite: a JSON list of {name, input, output}. */
function extractHtml5lib(array $raws): array
{
    $cases = [];
    foreach (json_decode(reset($raws), true, 512, JSON_THROW_ON_ERROR) as $entry) {
        $cases[] = [$entry['name'], $entry['input'], expectFromPair($entry['input'], $entry['output'])];
    }
    return $cases;
}

/** bleach's data files: the input, a line holding "--", then the output. */
function extractBleach(array $raws): array
{
    $cases = [];
    foreach ($raws as $file => $raw) {
        [$input, $output] = explode("\n--\n", $raw, 2);
        $cases[] = [$file, $input, expectFromPair($input, $output)];
    }
    return $cases;
}

/**
 * bluemonday's Go test file: every case is an {in: ..., expected: ...} literal, the strings
 * either raw (backticks) or interpreted (double quotes with Go escapes). The enclosing test
 * function names the case.
 */
function extractBluemonday(array $raws): array
{
    $cases = [];
    $go    = reset($raws);
    $str   = '`[^`]*`|"(?:[^"\\\\]|\\\\.)*"';
    preg_match_all("/^func (Test\w+)|\bin:\s*($str)\s*,\s*expected:\s*($str)/ms", $go, $matches, PREG_SET_ORDER);
    $title = '';
    foreach ($matches as $match) {
        if (!isset($match[2])) {
            $title = $match[1];
            continue;
        }
        $input  = goUnquote($match[2]);
        $output = goUnquote($match[3]);
        $cases[] = [$title, $input, expectFromPair($input, $output)];
    }
    return $cases;
}

/**
 * The OWASP Java HTML Sanitizer tests. HtmlSanitizerTest pairs expected output with
 * sanitize(input) in assertEquals; AntiSamyTest says what the output must not contain
 * (a vector, expected reject) or what it must equal. Java strings may be concatenated
 * with + across lines.
 */
function extractOwaspJava(array $raws): array
{
    $cases = [];
    $str   = '"(?:[^"\\\\]|\\\\.)*"(?:\s*\+\s*"(?:[^"\\\\]|\\\\.)*")*';
    preg_match_all("/assertEquals\(\s*($str)\s*,\s*(?:[\w.]+\.)?sanitize\(\s*($str)\s*[,)]/", $raws['HtmlSanitizerTest.java'], $matches, PREG_SET_ORDER);
    foreach ($matches as $i => [, $output, $input]) {
        $input  = javaUnquote($input);
        $cases[] = ['HtmlSanitizerTest ' . ($i + 1), $input, expectFromPair($input, javaUnquote($output))];
    }
    preg_match_all("/assertSanitizedDoesNotContain\(\s*($str)\s*,/", $raws['AntiSamyTest.java'], $matches, PREG_SET_ORDER);
    foreach ($matches as $i => [, $input]) {
        $cases[] = ['AntiSamyTest does-not-contain ' . ($i + 1), javaUnquote($input), 'reject'];
    }
    preg_match_all("/assertSanitized\(\s*($str)\s*,\s*($str)\s*\)/", $raws['AntiSamyTest.java'], $matches, PREG_SET_ORDER);
    foreach ($matches as $i => [, $input, $output]) {
        $input  = javaUnquote($input);
        $cases[] = ['AntiSamyTest sanitized ' . ($i + 1), $input, expectFromPair($input, javaUnquote($output))];
    }
    return $cases;
}

/** CKEditor 5's XSS templates: an object of name => markup with %xss% where the script goes. */
function extractCkeditor(array $raws): array
{
    $cases = [];
    preg_match_all("/^\s*'((?:[^'\\\\]|\\\\.)*)'\s*:\s*'((?:[^'\\\\]|\\\\.)*)'/m", reset($raws), $matches, PREG_SET_ORDER);
    foreach ($matches as [, $name, $markup]) {
        $cases[] = [jsUnquote($name), str_replace('%xss%', 'alert(1)', jsUnquote($markup)), null];
    }
    return $cases;
}

/**
 * TinyMCE's sanitizer tests: it('name', () => testX({ input: '...', expected: '...', ... }))
 * with single-quoted strings, concatenated with + across lines. Cases that turn the sanitizer
 * off (sanitize: false) say nothing about safety and are skipped.
 */
function extractTinymce(array $raws): array
{
    $cases = [];
    $str   = "'(?:[^'\\\\]|\\\\.)*'(?:\s*\+\s*'(?:[^'\\\\]|\\\\.)*')*";
    preg_match_all("/it\('((?:[^'\\\\]|\\\\.)*)'.*?input:\s*($str)\s*,\s*expected:\s*($str)(.*?)\}\)\)/s", reset($raws), $matches, PREG_SET_ORDER);
    foreach ($matches as [, $name, $input, $output, $options]) {
        if (str_contains($options, 'sanitize: false')) {
            continue;
        }
        $input   = jsUnquote(implode('', preg_split("/'\s*\+\s*'/", substr($input, 1, -1))));
        $output  = jsUnquote(implode('', preg_split("/'\s*\+\s*'/", substr($output, 1, -1))));
        $cases[] = [$name, $input, expectFromPair($input, $output)];
    }
    return $cases;
}

/**
 * CKEditor 5's paste-from-office captures. input.*.html is the clipboard HTML as Word, Google
 * Docs or a browser put it there: a whole document that no editor stores as is, kept with no
 * expectation so the tally can show what the validator says about raw pastes. normalized.*
 * is what the editor made of it, which is what gets saved, so those must pass.
 */
function extractCkeditorPaste(array $raws): array
{
    $cases = [];
    foreach ($raws as $path => $html) {
        $cases[] = [$path, bodyWithStyles($html), str_starts_with(basename($path), 'input.') ? 'none' : 'accept'];
    }
    return $cases;
}

/** Whole HTML files, one case each, cut to what a CMS field would hold. */
function extractHtmlFiles(array $raws): array
{
    $cases = [];
    foreach ($raws as $path => $html) {
        $cases[] = [$path, bodyWithStyles($html), null];
    }
    return $cases;
}

/** The WordPress export files: one case per post body (content:encoded) that has markup. */
function extractWordPress(array $raws): array
{
    $cases = [];
    foreach ($raws as $file => $xml) {
        foreach (array_slice(explode('<item>', $xml), 1) as $item) {
            $title = preg_match('/<title>(?:<!\[CDATA\[)?(.*?)(?:\]\]>)?<\/title>/s', $item, $m) ? html_entity_decode($m[1]) : '';
            if (preg_match('/<content:encoded><!\[CDATA\[(.*?)\]\]><\/content:encoded>/s', $item, $m)) {
                $cases[] = ["$file: $title", $m[1], null];
            }
        }
    }
    return $cases;
}

/** The MediaWiki parse API: the rendered article body in "text". */
function extractWikipedia(array $raws): array
{
    $cases = [];
    foreach ($raws as $json) {
        $parse   = json_decode($json, true, 512, JSON_THROW_ON_ERROR)['parse'];
        $cases[] = ["Wikipedia: $parse[title]", $parse['text'], null];
    }
    return $cases;
}

/** MDN's page JSON: one case per prose section of the article body. */
function extractMdn(array $raws): array
{
    $cases = [];
    foreach ($raws as $json) {
        $doc = json_decode($json, true, 512, JSON_THROW_ON_ERROR)['doc'];
        foreach ($doc['body'] as $section) {
            if ($section['type'] === 'prose') {
                $cases[] = ["MDN: $doc[title]: " . ($section['value']['title'] ?? 'intro'), $section['value']['content'], null];
            }
        }
    }
    return $cases;
}

/**
 * What a CMS field would hold from a whole document: the body's content with the head's
 * style elements in front of it. A fragment with no body element is returned as it is.
 */
function bodyWithStyles(string $html): string
{
    if (!preg_match('/<body[^>]*>(.*)<\/body>/is', $html, $body)) {
        return $html;
    }
    $head = preg_match('/<head[^>]*>(.*)<\/head>/is', $html, $m) ? $m[1] : '';
    preg_match_all('/<style\b[^>]*>.*?<\/style>/is', $head, $styles);
    return implode("\n", $styles[0]) . ($styles[0] === [] ? '' : "\n") . trim($body[1]);
}

/**
 * What a sanitizer's own test says about a payload: 'accept' when the output kept every tag,
 * attribute and attribute value (only quoting or entities changed), 'reject' when it dropped
 * or escaped a tag, dropped an attribute, or emptied one (a style="" left behind after the
 * CSS was removed). Attributes are counted as name= after whitespace or a quote, which also
 * catches the onerror= a filter-evasion payload hides inside another attribute's value.
 */
function expectFromPair(string $input, string $output): string
{
    $counts = static fn(string $html): array => [
        preg_match_all('/<[a-z]/i', $html),
        preg_match_all('/[\s\/"\']([a-z_:-]+)\s*=/i', $html),
        preg_match_all('/[\s\/"\']([a-z_:-]+)\s*=\s*(?:"[^"]+"|\'[^\']+\'|[^\s"\'>])/i', $html),   // attributes whose value is not empty
    ];
    [$tagsIn, $attributesIn, $valuesIn]    = $counts($input);
    [$tagsOut, $attributesOut, $valuesOut] = $counts($output);
    return $tagsOut < $tagsIn || $attributesOut < $attributesIn || $valuesOut < $valuesIn ? 'reject' : 'accept';
}

/** The value of a single-quoted JavaScript string literal, escapes resolved. */
function jsUnquote(string $literal): string
{
    return preg_replace_callback('/\\\\(u[0-9a-fA-F]{4}|x[0-9a-fA-F]{2}|.)/s', static function (array $match): string {
        $escape = $match[1];
        return match ($escape[0]) {
            'u', 'x' => mb_chr((int)hexdec(substr($escape, 1)), 'UTF-8') ?: "\u{FFFD}",   // a lone surrogate has no UTF-8 form
            'n'      => "\n",
            'r'      => "\r",
            't'      => "\t",
            '0'      => "\0",
            default  => $escape,   // \' \\ \/ and any other character stands for itself
        };
    }, $literal);
}

/** The value of a Go string literal: raw between backticks, or double-quoted with the same escapes JavaScript has. */
function goUnquote(string $literal): string
{
    return $literal[0] === '`' ? substr($literal, 1, -1) : jsUnquote(substr($literal, 1, -1));
}

/** The value of one or more Java string literals joined with +, escapes resolved. */
function javaUnquote(string $literals): string
{
    preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $literals, $matches);
    return jsUnquote(implode('', $matches[1]));
}

//endregion
//region Download

/**
 * Every file in one folder of a GitHub repo that matches the source's keep pattern, as
 * [relative path => raw URL], plus the folder's git tree hash.
 */
function githubFolder(array $source): array
{
    $tree = json_decode(httpGet("https://api.github.com/repos/$source[repo]/git/trees/$source[ref]:" . rawurlencode($source['path']) . '?recursive=1'), true, 512, JSON_THROW_ON_ERROR);
    if (!empty($tree['truncated'])) {
        echo "warning: listing truncated by GitHub, some files will be missing\n";
    }
    $base  = RAW . "/$source[repo]/$source[ref]/" . ($source['path'] === '' ? '' : str_replace('%2F', '/', rawurlencode($source['path'])) . '/');
    $files = [];
    foreach ($tree['tree'] as $entry) {
        if ($entry['type'] === 'blob' && preg_match($source['keep'], $entry['path'])) {
            $files[$entry['path']] = $base . str_replace('%2F', '/', rawurlencode($entry['path']));
        }
    }
    return [$files, $tree['sha']];
}

/** One GET, with GITHUB_TOKEN on GitHub API calls when it is set. Exits on any failure. */
function httpGet(string $url): string
{
    $headers = ['User-Agent: htmlvalidator-fetch-corpus (https://github.com/interactivetools-com/HtmlValidator)'];
    $token   = getenv('GITHUB_TOKEN');
    if ($token && str_starts_with($url, 'https://api.github.com/')) {
        $headers[] = "Authorization: Bearer $token";
    }
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60, CURLOPT_HTTPHEADER => $headers]);
    $body   = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($body === false || $status !== 200) {
        fwrite(STDERR, "\nRequest failed (HTTP $status): $url\n" . substr((string)$body, 0, 300) . "\n");
        exit(1);
    }
    return $body;
}

/** Empties a source folder before it is written again. The folder itself stays: removing it on a Windows mount fails while the deletes are still settling. */
function clearDirectory(string $dir): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
}

//endregion

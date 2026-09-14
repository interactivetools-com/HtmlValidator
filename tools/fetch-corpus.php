#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Downloads the XSS payload lists in SOURCES into corpus/ (gitignored) for tools/tally.php.
 *
 *     php tools/fetch-corpus.php                  # every source not downloaded yet
 *     php tools/fetch-corpus.php html5sec owasp   # named sources, downloaded again
 *
 * Each source is one page or file that holds many payloads. The raw download is kept as
 * corpus/<name>/source.<ext>, each payload is written on its own to corpus/<name>/NNNN.html
 * with nothing added, and cases.json lists the title behind each file. A payload with no "<"
 * in it is a value for one attribute or one script string, which the validator never sees on
 * its own, so those are left out. LICENSE holds the
 * license text or the copyright note, and SOURCE.json records where the files came from,
 * when, and that every one of them is expected to be rejected.
 *
 * Nothing under corpus/ is ever copied into tests/: the PortSwigger page is copyrighted and
 * the OWASP sheet is CC BY-SA. Our own must-pass samples go in tests/Support/fixtures/accept/.
 */

const CORPUS_DIR = __DIR__ . '/../corpus';

const SOURCES = [
    'portswigger' => [
        'url'     => 'https://portswigger.net/web-security/cross-site-scripting/cheat-sheet',
        'ext'     => 'html',
        'license' => 'Copyright PortSwigger Ltd. Downloaded for local testing only, not for redistribution.',
        'extract' => 'extractPortSwigger',
    ],
    'html5sec' => [
        'url'     => 'https://raw.githubusercontent.com/cure53/H5SC/master/items.js',
        'ext'     => 'js',
        'license' => 'https://api.github.com/repos/cure53/H5SC/license',
        'extract' => 'extractHtml5sec',
    ],
    'dompurify' => [
        'url'     => 'https://raw.githubusercontent.com/cure53/DOMPurify/main/test/fixtures/expect.mjs',
        'ext'     => 'mjs',
        'license' => 'https://api.github.com/repos/cure53/DOMPurify/license',
        'extract' => 'extractDomPurify',
    ],
    'owasp' => [
        'url'     => 'https://raw.githubusercontent.com/OWASP/CheatSheetSeries/master/cheatsheets/XSS_Filter_Evasion_Cheat_Sheet.md',
        'ext'     => 'md',
        'license' => 'https://api.github.com/repos/OWASP/CheatSheetSeries/license',
        'extract' => 'extractOwasp',
    ],
];

$requested = array_slice($argv, 1);
foreach ($requested as $name) {
    if (!isset(SOURCES[$name])) {
        fwrite(STDERR, "Unknown source '$name'. Known: " . implode(', ', array_keys(SOURCES)) . "\n");
        exit(1);
    }
}

foreach (SOURCES as $name => $source) {
    $dir = CORPUS_DIR . "/$name";
    if ($requested === [] && is_file("$dir/SOURCE.json")) {
        echo str_pad($name, 14), "already downloaded, skipping (name it to refresh)\n";
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
    echo str_pad($name, 14), "downloading $source[url] ... ";
    $raw   = httpGet($source['url']);
    $cases = array_filter($source['extract']($raw), static fn(array $case) => str_contains($case[1], '<'));   // list of [title, html]
    echo count($cases), " payloads\n";

    if (is_dir($dir)) {
        deleteDirectory($dir);
    }
    mkdir($dir, 0755, true);
    file_put_contents("$dir/source.$source[ext]", $raw);
    file_put_contents("$dir/LICENSE", licenseText($source['license']));

    $index = [];
    foreach (array_values($cases) as $i => [$title, $html]) {
        $file = sprintf('%04d.html', $i + 1);
        file_put_contents("$dir/$file", $html);
        $index[$file] = $title;
    }
    file_put_contents("$dir/cases.json", json_encode($index, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    file_put_contents("$dir/SOURCE.json", json_encode([
        'url'     => $source['url'],
        'license' => str_starts_with($source['license'], 'https://') ? licenseId($source['license']) : 'proprietary',
        'expect'  => 'reject',
        'fetched' => date('c'),
        'files'   => count($cases),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}

//region Extractors

/**
 * Every vector on the page links to a live demo at portswigger-labs.net with the payload in
 * the x= query parameter. Those with an HTML context (or none) are HTML fragments; the rest
 * are JavaScript strings or attribute values on their own, which the validator never sees.
 */
function extractPortSwigger(string $page): array
{
    $cases = [];
    $seen  = [];
    preg_match_all('/<h[23][^>]*>([^<]+)<\/h[23]>|<details id="([^"]*)"|href="https:\/\/portswigger-labs\.net\/xss\/xss\.php\?([^"]*)"/', $page, $matches, PREG_SET_ORDER);
    $title = '';
    foreach ($matches as $match) {
        if (!isset($match[3])) {
            $title = $match[2] ?? $match[1];   // the section heading, or the event handler's <details id>, names every link until the next one
            continue;
        }
        parse_str(html_entity_decode($match[3]), $query);
        $context = $query['context'] ?? 'html';   // "html#..." is an html context with a URL fragment after it
        $payload = $query['x'] ?? '';
        if (!str_starts_with($context, 'html') || $payload === '' || isset($seen[$payload])) {
            continue;
        }
        $seen[$payload] = true;
        $cases[]        = [$title, $payload];
    }
    return $cases;
}

/**
 * items.js is a JavaScript array; each item's 'data' is a single-quoted string holding the
 * vector with %placeholders% that payloads.js expands (js_uri_alert = javascript:alert(1)).
 * The title is the English name of the item.
 */
function extractHtml5sec(string $items): array
{
    $payloads = [];
    preg_match_all("/^\s*'([a-z0-9_]+)'\s*:\s*'((?:[^'\\\\]|\\\\.)*)'/m", httpGet('https://raw.githubusercontent.com/cure53/H5SC/master/payloads.js'), $matches, PREG_SET_ORDER);
    foreach ($matches as [, $key, $value]) {
        $payloads["%$key%"] = jsUnquote($value);
    }

    $cases = [];
    preg_match_all("/'name'\s*:\s*\{\s*'en'\s*:\s*'((?:[^'\\\\]|\\\\.)*)'.*?'data'\s*:\s*'((?:[^'\\\\]|\\\\.)*)'/s", $items, $matches, PREG_SET_ORDER);
    foreach ($matches as [, $name, $data]) {
        $cases[] = [jsUnquote($name), strtr(jsUnquote($data), $payloads)];
    }
    return $cases;
}

/**
 * expect.mjs is a JSON array behind "export default", with trailing commas. Each entry has
 * the payload DOMPurify was given and what it returned; entries where nothing was removed
 * are regression tests for over-stripping, not attacks, so they are left out.
 */
function extractDomPurify(string $module): array
{
    $json    = preg_replace(['/^\s*export default\s*/', '/;\s*$/', '/,(\s*[\]}])/'], ['', '', '$1'], $module);
    $entries = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
    $cases   = [];
    foreach ($entries as $entry) {
        $expected = (array)$entry['expected'];
        if (!in_array($entry['payload'], $expected, true)) {
            $cases[] = [$entry['title'] ?? '', $entry['payload']];
        }
    }
    return $cases;
}

/**
 * The sheet is Markdown; the vectors are the fenced code blocks marked html or left
 * unmarked. Blocks marked js, php, sh or log are commentary. The nearest heading above a
 * block is its title.
 */
function extractOwasp(string $markdown): array
{
    $cases = [];
    $title = '';
    preg_match_all('/^(#{2,4}) ([^\n]+)$|^```(\w*)\n(.*?)^```$/ms', $markdown, $matches, PREG_SET_ORDER);
    foreach ($matches as $match) {
        if (isset($match[4])) {
            if ($match[3] === '' || $match[3] === 'html') {
                $cases[] = [$title, rtrim($match[4], "\n")];
            }
        } elseif (isset($match[2])) {
            $title = trim($match[2]);
        }
    }
    return $cases;
}

/** The value of a single-quoted JavaScript string literal, escapes resolved. */
function jsUnquote(string $literal): string
{
    return preg_replace_callback('/\\\\(u[0-9a-fA-F]{4}|x[0-9a-fA-F]{2}|.)/s', static function (array $match): string {
        $escape = $match[1];
        return match ($escape[0]) {
            'u'     => mb_chr((int)hexdec(substr($escape, 1)), 'UTF-8'),
            'x'     => mb_chr((int)hexdec(substr($escape, 1)), 'UTF-8'),
            'n'     => "\n",
            'r'     => "\r",
            't'     => "\t",
            '0'     => "\0",
            default => $escape,   // \' \\ \/ and any other character stands for itself
        };
    }, $literal);
}

//endregion
//region Download

function licenseText(string $license): string
{
    if (!str_starts_with($license, 'https://')) {
        return "$license\n";
    }
    $api = json_decode(httpGet($license), true, 512, JSON_THROW_ON_ERROR);
    return base64_decode($api['content']);
}

function licenseId(string $url): string
{
    $api = json_decode(httpGet($url), true, 512, JSON_THROW_ON_ERROR);
    return $api['license']['spdx_id'] ?? 'unknown';
}

/** One GET. Exits on any failure. */
function httpGet(string $url): string
{
    $curl = curl_init($url);
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 60, CURLOPT_USERAGENT => 'Mozilla/5.0 (htmlvalidator-fetch-corpus)']);
    $body   = curl_exec($curl);
    $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
    if ($body === false || $status !== 200) {
        fwrite(STDERR, "\nRequest failed (HTTP $status): $url\n" . substr((string)$body, 0, 300) . "\n");
        exit(1);
    }
    return $body;
}

function deleteDirectory(string $dir): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($dir);
}

//endregion

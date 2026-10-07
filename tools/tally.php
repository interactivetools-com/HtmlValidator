#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Runs HtmlValidator over every .html under corpus/ and reports, per source, how many
 * payloads were accepted and rejected, the count per error code with example files, and
 * the surprises: payloads a must-reject set got through, or files a must-accept set lost.
 *
 *     php tools/tally.php                              # every source
 *     php tools/tally.php html5sec owasp               # named sources
 *     php tools/tally.php --accepted                   # list every accepted payload, with its title
 *     php tools/tally.php owasp --code=css-not-allowed # list every file behind one code, with the message
 *
 * What each source should do comes from its SOURCE.json, written by tools/fetch-corpus.php:
 * reject for a payload list, accept for real content, mixed for a sanitizer's own test suite,
 * where cases.json says per file which it is. corpus/own/ (our own samples, added by hand)
 * has neither and counts as must-accept.
 *
 * A surprise that has been looked at and explained goes in tools/corpus-known.json under the
 * source's "cases", keyed by a hash of the payload bytes (file numbers change when a source
 * is fetched again). Every surprise line ends with its hash so the entry can be added. Known
 * ones are counted in the "known" column and left out of the surprises, so a new one always
 * shows. A known entry whose payload is gone, or now gets the expected result, is reported
 * at the end so the file stays true.
 *
 * A source can also list "rejects": rules for files it expects us to accept that we refuse
 * by design (inline SVG, forms, control characters). A rule names an error code and a regex
 * over the detail; a rejected file whose first error matches is known without a per-payload
 * entry. Rules never apply to a payload we accepted: an unexpected acceptance always shows.
 * A rule with "open": true marks a rejection that has been looked at but not decided (a
 * possible false positive). Those files count as known, and the tally ends with one line
 * per open rule so the question stays in view until a person settles it.
 *
 * An acceptance in a must-reject set is a bug until the upstream case turns out not to apply
 * to a fragment in a page: a payload that needs a full document, a charset trick, a
 * clickjacking overlay, or a vector for a browser nobody runs any more.
 */

require __DIR__ . '/../vendor/autoload.php';

use Itools\HtmlValidator\HtmlValidator;

const CORPUS_DIR = __DIR__ . '/../corpus';
const KNOWN_FILE = __DIR__ . '/corpus-known.json';
const EXAMPLES   = 3;

$codeFilter   = null;
$listAccepted = false;
$requested    = [];
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--code=')) {
        $codeFilter = substr($arg, 7);
    } elseif ($arg === '--accepted') {
        $listAccepted = true;
    } else {
        $requested[] = $arg;
    }
}

if (!is_dir(CORPUS_DIR)) {
    fwrite(STDERR, "No corpus/ folder. Run: php tools/fetch-corpus.php\n");
    exit(1);
}

$sources = array_map('basename', glob(CORPUS_DIR . '/*', GLOB_ONLYDIR));
if ($requested !== []) {
    $sources = array_intersect($sources, $requested);
}
$known = is_file(KNOWN_FILE) ? json_decode(file_get_contents(KNOWN_FILE), true, 512, JSON_THROW_ON_ERROR) : [];

$grandAccepted = 0;
$grandRejected = 0;
$surprises     = [];
$stale         = [];
$open          = [];   // "source: reason" => files, for rules marked open

foreach ($sources as $name) {
    $dir       = CORPUS_DIR . "/$name";
    $expect    = is_file("$dir/SOURCE.json") ? json_decode(file_get_contents("$dir/SOURCE.json"), true)['expect'] : 'accept';
    $cases     = is_file("$dir/cases.json") ? json_decode(file_get_contents("$dir/cases.json"), true) : [];   // file => [title, expect]
    $knownHere = $known[$name]['cases'] ?? [];     // hash => [payload excerpt, reason]; PortSwigger entries have no excerpt
    $rules     = $known[$name]['rejects'] ?? [];   // [code, detail regex, reason]
    $knownUsed = [];                    // hash => true, for the stale report
    $rulesUsed = 0;
    $files     = [];                    // hash => file, for the stale report
    $accepted  = 0;
    $rejected  = 0;
    $byCode    = [];   // code => [count, examples[]]
    $listing   = [];   // for --code and --accepted

    foreach (glob("$dir/[0-9]*.html") as $path) {   // NNNN.html only, never the source.* downloads
        $file         = basename($path);
        $html         = file_get_contents($path);
        $hash         = payloadHash($html);
        $files[$hash] = $file;
        $label        = isset($cases[$file]) ? "$file  {$cases[$file]['title']}" : $file;
        $expectHere   = $cases[$file]['expect'] ?? $expect;
        $result       = HtmlValidator::check($html);
        $surprise     = $result->ok ? $expectHere === 'reject' : $expectHere === 'accept';
        if ($surprise && isset($knownHere[$hash])) {
            $knownUsed[$hash] = true;
            $surprise         = false;
        } elseif ($surprise && !$result->ok && ($rule = matchingRule($rules, $result->errors[0])) !== null) {
            $rulesUsed++;
            $surprise = false;
            if (!empty($rule['open'])) {
                $open["$name: $rule[reason]"] = ($open["$name: $rule[reason]"] ?? 0) + 1;
            }
        }
        if ($result->ok) {
            $accepted++;
            if ($surprise) {
                $surprises[] = "$name/$label  [$hash]";
            }
            if ($listAccepted) {
                $listing[] = "  $label  [$hash]\n      " . str_replace("\n", "\n      ", trim($html))
                    . (isset($knownUsed[$hash]) ? "\n      known: {$knownHere[$hash]['reason']}" : '');
            }
            continue;
        }
        $rejected++;
        if ($surprise) {
            $surprises[] = "$name/$label: {$result->errors[0]->message}  [$hash]";
        }
        foreach ($result->errors as $violation) {
            $byCode[$violation->code] ??= [0, []];
            $byCode[$violation->code][0]++;
            if (count($byCode[$violation->code][1]) < EXAMPLES) {
                $byCode[$violation->code][1][] = $file;
            }
            if ($violation->code === $codeFilter) {
                $listing[] = "  $label: $violation->message";
            }
        }
    }

    foreach (array_diff_key($knownHere, $knownUsed) as $hash => $entry) {
        $stale[] = isset($files[$hash])
            ? "$name/$files[$hash] now gets the expected result, drop the entry  [$hash]"
            : "$name has no payload with this hash any more: $entry[payload]  [$hash]";
    }

    $grandAccepted += $accepted;
    $grandRejected += $rejected;
    ksort($byCode);
    printf("%-14s expect %-7s accepted %5d   rejected %5d   known %4d\n", $name, $expect, $accepted, $rejected, count($knownUsed) + $rulesUsed);
    foreach ($byCode as $code => [$count, $examples]) {
        printf("    %-24s %5d   e.g. %s\n", $code, $count, implode(', ', $examples));
    }
    if ($listing !== []) {
        echo implode("\n", array_unique($listing)), "\n";
    }
}

echo "\nTotal: accepted $grandAccepted, rejected $grandRejected\n";
if ($surprises !== []) {
    echo "\nSurprises (", count($surprises), "):\n  ", implode("\n  ", $surprises), "\n";
}
if ($open !== []) {
    echo "\nOpen questions (rejections reviewed but not decided, counted as known):\n";
    foreach ($open as $question => $count) {
        printf("  %4d  %s\n", $count, $question);
    }
}
if ($stale !== []) {
    echo "\nStale entries in tools/corpus-known.json (", count($stale), "):\n  ", implode("\n  ", $stale), "\n";
}

/** The first "rejects" rule in tools/corpus-known.json that covers this violation, or null. */
function matchingRule(array $rules, Itools\HtmlValidator\Violation $violation): ?array
{
    foreach ($rules as $rule) {
        if ($rule['code'] === $violation->code && preg_match('~' . $rule['detail'] . '~i', $violation->detail)) {
            return $rule;
        }
    }
    return null;
}

/** The key a payload has in tools/corpus-known.json: enough of its sha256 to never collide in a list this size. */
function payloadHash(string $html): string
{
    return substr(hash('sha256', $html), 0, 12);
}

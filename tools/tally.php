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
 * What each source should do comes from its SOURCE.json, written by tools/fetch-corpus.php,
 * and cases.json there gives each file its title. corpus/own/ (our own samples, added by
 * hand) has neither and counts as must-accept.
 *
 * An acceptance in a must-reject set is a bug until the upstream case turns out not to apply
 * to a fragment in a page: a payload that needs a full document, a charset trick, a
 * clickjacking overlay, or a vector for a browser nobody runs any more.
 */

require __DIR__ . '/../vendor/autoload.php';

use Itools\HtmlValidator\HtmlValidator;

const CORPUS_DIR = __DIR__ . '/../corpus';
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

$grandAccepted = 0;
$grandRejected = 0;
$surprises     = [];

foreach ($sources as $name) {
    $dir      = CORPUS_DIR . "/$name";
    $expect   = is_file("$dir/SOURCE.json") ? json_decode(file_get_contents("$dir/SOURCE.json"), true)['expect'] : 'accept';
    $titles   = is_file("$dir/cases.json") ? json_decode(file_get_contents("$dir/cases.json"), true) : [];
    $accepted = 0;
    $rejected = 0;
    $byCode   = [];   // code => [count, examples[]]
    $listing  = [];   // for --code and --accepted

    foreach (glob("$dir/*.html") as $path) {
        $file   = basename($path);
        $label  = isset($titles[$file]) ? "$file  $titles[$file]" : $file;
        $result = HtmlValidator::check(file_get_contents($path));
        if ($result->ok) {
            $accepted++;
            if ($expect === 'reject') {
                $surprises[] = "$name/$label";
            }
            if ($listAccepted) {
                $listing[] = "  $label\n      " . str_replace("\n", "\n      ", trim(file_get_contents($path)));
            }
            continue;
        }
        $rejected++;
        if ($expect === 'accept') {
            $surprises[] = "$name/$label: " . $result->errors[0]->message;
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

    $grandAccepted += $accepted;
    $grandRejected += $rejected;
    ksort($byCode);
    printf("%-14s expect %-7s accepted %5d   rejected %5d\n", $name, $expect, $accepted, $rejected);
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

#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Times HtmlValidator::check() on generated content of known sizes, on hostile inputs built to
 * cost a tokenizer time, and on the real files in corpus/ and tests/Support/fixtures/, and
 * prints the markdown tables in benchmarks/results.md.
 *
 *     php -d opcache.enable_cli=1 -d xdebug.mode=off benchmarks/check-speed.php
 *     php ... benchmarks/check-speed.php --corpus=corpus                            # add the corpus and fixture tables (run tools/fetch-corpus.php first)
 *     php ... benchmarks/check-speed.php --sanitizer=/path/to/vendor/autoload.php   # add ezyang/htmlpurifier columns for scale
 *
 * Loads src/ directly, so no composer install is needed. Generated content is written to the
 * system temp dir and deleted at the end. Each time is the fastest of several runs of one
 * check() call on a string already in memory. For the generated and hostile inputs the runs
 * happen in a fresh PHP process per input, and peak memory is how much that process's peak
 * resident set size (VmHWM on Linux) grew during the runs. That counts PCRE's own allocations,
 * which memory_get_peak_usage() does not see. On other systems the memory columns print n/a.
 */

namespace Itools\HtmlValidator\Benchmarks;

use Itools\HtmlValidator\HtmlValidator;
use function renderMdTable;

require __DIR__ . '/../src/Violation.php';
require __DIR__ . '/../src/Result.php';
require __DIR__ . '/../src/Token.php';
require __DIR__ . '/../src/CharacterReferences.php';
require __DIR__ . '/../src/Tokenizer.php';
require __DIR__ . '/../src/HtmlValidator.php';
require __DIR__ . '/../tools/shared-md-table.php';

const RUNS     = 7;
const SIZES    = ['1 KB' => 1024, '10 KB' => 10240, '100 KB' => 102400, '1 MB' => 1048576];
const SHAPES   = ['plain paragraphs' => 'generateParagraphs', 'a tag every few words' => 'generateInlineTags', 'Word paste' => 'generateWordPaste'];
const BUCKETS  = ['under 1 KB' => 1024, '1 KB to 10 KB' => 10240, '10 KB to 100 KB' => 102400, 'over 100 KB' => PHP_INT_MAX];
const FIXTURES = ['accept', 'reject', 'tinymce4'];   // folders under tests/Support/fixtures/ that hold .html files
const IS_LINUX = PHP_OS_FAMILY === 'Linux';
const WORDS = ['lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing', 'elit', 'sed', 'do', 'eiusmod', 'tempor', 'incididunt', 'ut', 'labore', 'et', 'dolore', 'magna', 'aliqua', 'enim', 'minim', 'veniam', 'quis', 'nostrud', 'exercitation', 'ullamco', 'laboris', 'nisi', 'aliquip', 'commodo'];

//region Options

$options   = getopt('', ['corpus:', 'sanitizer:', 'subprocess', 'file:', 'mode:']);
$corpus    = $options['corpus'] ?? null;
$sanitizer = $options['sanitizer'] ?? null;

// Every child this script starts carries HTMLVALIDATOR_BENCH_CHILD. A child that is not in
// subprocess mode would run the full benchmark and start children of its own without end.
if (getenv('HTMLVALIDATOR_BENCH_CHILD') !== false && !isset($options['subprocess'])) {
    fwrite(STDERR, "check-speed.php: refusing to run the full benchmark inside a benchmark child process\n");
    exit(1);
}

if ($sanitizer !== null) {
    require $sanitizer;
}

// Subprocess mode: time one file and print "seconds peakGrowthKb" on one line.
if (isset($options['subprocess'])) {
    $html    = (string)file_get_contents($options['file']);
    $call    = $options['mode'] === 'sanitize' ? sanitizer($html) : fn() => HtmlValidator::check($html);
    $before  = peakKb();
    $seconds = fastest($call);
    printf("%.9f %d\n", $seconds, IS_LINUX ? peakKb() - $before : 0);
    exit;
}
putenv('HTMLVALIDATOR_BENCH_CHILD=1');   // inherited by every process shell_exec() starts below

//endregion
//region Environment

$opcacheOn = (bool)ini_get('opcache.enable_cli') && extension_loaded('Zend OPcache');
$jitOn     = $opcacheOn && (bool)(opcache_get_status(false)['jit']['enabled'] ?? false);
$xdebugOn  = extension_loaded('xdebug') && (function_exists('xdebug_info') ? xdebug_info('mode') !== [] : ini_get('xdebug.mode') !== 'off');   // Xdebug 3 reports the active modes; none means off
$cpu       = IS_LINUX && preg_match('/model name\s*:\s*(.+)/', (string)file_get_contents('/proc/cpuinfo'), $m) ? trim($m[1]) : php_uname('m');

printf("PHP %s, PCRE %s, %s %s, %s\n", PHP_VERSION, PCRE_VERSION, PHP_OS_FAMILY, php_uname('r'), $cpu);
printf("opcache %s, JIT %s, xdebug %s%s\n\n", $opcacheOn ? 'on' : 'OFF', $jitOn ? 'on' : 'off', $xdebugOn ? 'LOADED' : 'off', $sanitizer !== null ? ', HTMLPurifier ' . \HTMLPurifier::VERSION : '');
if (!$opcacheOn || $xdebugOn) {
    echo "WARNING: run with -d opcache.enable_cli=1 -d xdebug.mode=off for citable numbers.\n\n";
}

//endregion
//region Generated Content

$tempDir = sys_get_temp_dir() . '/htmlvalidator-bench-' . bin2hex(random_bytes(8));   // a name nobody can create ahead of us
if (!mkdir($tempDir, 0700)) {
    fwrite(STDERR, "check-speed.php: could not create $tempDir\n");
    exit(1);
}

echo "## Generated content\n\n";
$headers = ['Shape', 'Size', 'Check time', 'Throughput', 'Peak memory added'];
if ($sanitizer !== null) {
    $headers = [...$headers, 'HTMLPurifier time', 'HTMLPurifier memory'];
}
$rows = [];
foreach (SHAPES as $shape => $generator) {
    foreach (SIZES as $label => $bytes) {
        $path = "$tempDir/generated-" . preg_replace('/\W+/', '-', $shape) . "-$bytes.html";
        file_put_contents($path, (__NAMESPACE__ . '\\' . $generator)($bytes));
        $result = HtmlValidator::check((string)file_get_contents($path));
        if (!$result->ok) {
            fwrite(STDERR, "generated '$shape' at $label was rejected: {$result->errors[0]->message}\n");
            exit(1);
        }
        [$seconds, $peakKb] = measureCheck($path);
        $row = [$shape, $label, ms($seconds), sprintf('%.0f MB/s', filesize($path) / 1048576 / $seconds), memoryCell($peakKb)];
        if ($sanitizer !== null) {
            $sanitized = measure($path, 'sanitize', $sanitizer);
            $row[]     = $sanitized === null ? 'failed' : ms($sanitized[0]);
            $row[]     = $sanitized === null ? 'failed' : memoryCell($sanitized[1]);
        }
        $rows[] = $row;
    }
}
echo renderMdTable($headers, $rows), "\n";

//endregion
//region Hostile Inputs

// Inputs built to cost a tokenizer time or memory. Most pass, since nothing in them runs script;
// the table shows what reading them costs either way.
echo "## Hostile inputs\n\n";
$rows = [];
foreach (hostileInputs() as $label => $html) {
    $path = "$tempDir/hostile-" . preg_replace('/\W+/', '-', $label) . '.html';
    file_put_contents($path, $html);
    $result = HtmlValidator::check($html);
    [$seconds, $peakKb] = measureCheck($path);
    $rows[] = [$label, humanBytes(filesize($path)), $result->ok ? 'accepted' : "`{$result->errors[0]->code}`", ms($seconds), memoryCell($peakKb)];
}
echo renderMdTable(['Input', 'Size', 'Result', 'Check time', 'Peak memory added'], $rows), "\n";

//endregion
//region Corpus

if ($corpus !== null) {
    $sources  = corpusFiles($corpus);   // path => source label
    $contents = [];
    $sizes    = [];
    foreach ($sources as $path => $source) {
        $contents[$path] = (string)file_get_contents($path);
        $sizes[$path]    = strlen($contents[$path]);
    }
    $times     = [];   // path => fastest seconds
    $passTimes = [];
    for ($run = 0; $run < 3; $run++) {
        $passStart = hrtime(true);
        foreach ($contents as $path => $html) {
            $start = hrtime(true);
            HtmlValidator::check($html);
            $seconds      = (hrtime(true) - $start) / 1e9;
            $times[$path] = min($times[$path] ?? INF, $seconds);
        }
        $passTimes[] = (hrtime(true) - $passStart) / 1e9;
    }
    $totalBytes = array_sum($sizes);
    $passBest   = min($passTimes);
    printf("## Corpus and fixtures: %s files, %.1f MB, checked in %.3f s (%s files/s, %.0f MB/s)\n\n", number_format(count($sources)), $totalBytes / 1048576, $passBest, number_format(count($sources) / $passBest), $totalBytes / 1048576 / $passBest);
    $columns = ['Files', 'Median per file', 'Mean per file', 'Slowest file'];
    $rows    = [];
    $lower   = 0;
    foreach (BUCKETS as $label => $upper) {
        $bucket = array_filter($times, fn($path) => $sizes[$path] >= $lower && $sizes[$path] < $upper, ARRAY_FILTER_USE_KEY);
        $lower  = $upper;
        if ($bucket !== []) {
            $rows[] = corpusRow($label, $bucket, $sizes);
        }
    }
    echo renderMdTable(['Size', ...$columns], $rows), "\n";

    // The same numbers per source, so payload lists and our own must-pass samples can be told apart.
    $bySource = [];
    foreach ($times as $path => $seconds) {
        $bySource[$sources[$path]][$path] = $seconds;
    }
    $rows = [];
    foreach ($bySource as $source => $bucket) {
        $rows[] = corpusRow($source, $bucket, $sizes);
    }
    echo renderMdTable(['Source', ...$columns], $rows), "\n";
}

//endregion
//region Cleanup

foreach (glob("$tempDir/*") ?: [] as $file) {
    unlink($file);
}
rmdir($tempDir);

//endregion
//region Generators

/** A sentence of 6 to 14 random words, capitalized, with a period. */
function sentence(): string
{
    $words = [];
    for ($i = mt_rand(6, 14); $i > 0; $i--) {
        $words[] = WORDS[mt_rand(0, count(WORDS) - 1)];
    }
    return ucfirst(implode(' ', $words)) . '.';
}

/** Repeats $paragraph() until the content is at least $targetBytes long. */
function fill(int $targetBytes, callable $paragraph, string $head = '', string $tail = ''): string
{
    mt_srand(42);
    $body = '';
    while (strlen($head) + strlen($body) + strlen($tail) < $targetBytes) {
        $body .= $paragraph() . "\n";
    }
    return $head . $body . $tail;
}

/** Paragraphs of plain text: what a typed article looks like. */
function generateParagraphs(int $targetBytes): string
{
    return fill($targetBytes, function (): string {
        $text = '';
        for ($i = mt_rand(3, 6); $i > 0; $i--) {
            $text .= sentence() . ' ';
        }
        return '<p>' . rtrim($text) . '</p>';
    });
}

/** The same text with an inline tag every few words: links, bold, italics, spans with a class, line breaks. */
function generateInlineTags(int $targetBytes): string
{
    $wrappers = [
        fn(string $w) => "<strong>$w</strong>",
        fn(string $w) => "<em>$w</em>",
        fn(string $w) => "<a href=\"https://example.com/" . mt_rand(1, 999) . "\">$w</a>",
        fn(string $w) => "<span class=\"hl\">$w</span>",
        fn(string $w) => "$w<br>",
        fn(string $w) => "<code>$w</code>",
    ];
    return fill($targetBytes, function () use ($wrappers): string {
        $words = [];
        for ($i = mt_rand(30, 60); $i > 0; $i--) {
            $word    = WORDS[mt_rand(0, count(WORDS) - 1)];
            $words[] = $i % 3 === 0 ? $wrappers[mt_rand(0, count($wrappers) - 1)]($word) : $word;
        }
        $tag = ['p', 'p', 'p', 'li', 'h2', 'blockquote'][mt_rand(0, 5)];
        return "<$tag>" . implode(' ', $words) . "</$tag>";
    });
}

/** What Word pastes into an editor: MsoNormal paragraphs, spans with long style attributes, and tables with borders. */
function generateWordPaste(int $targetBytes): string
{
    $fonts = ["'Calibri', sans-serif", "'Times New Roman', serif", "'Arial', sans-serif"];
    return fill($targetBytes, function () use ($fonts): string {
        $font = $fonts[mt_rand(0, 2)];
        if (mt_rand(0, 3) === 0) {
            $rows = '';
            for ($r = mt_rand(2, 4); $r > 0; $r--) {
                $rows .= '<tr style="mso-yfti-irow: ' . $r . ';">';
                for ($c = 3; $c > 0; $c--) {
                    $rows .= '<td style="border: solid windowtext 1pt; padding: 0cm 5.4pt 0cm 5.4pt; width: 150pt;" valign="top" width="200">'
                        . '<p class="MsoNormal" style="margin-bottom: 0.0001pt;"><span style="font-family: ' . $font . '; font-size: 11pt;">' . sentence() . '</span></p></td>';
                }
                $rows .= '</tr>';
            }
            return '<table class="MsoTableGrid" style="border-collapse: collapse; border: none; mso-border-alt: solid windowtext .5pt; mso-padding-alt: 0cm 5.4pt 0cm 5.4pt;" border="1" cellspacing="0" cellpadding="0"><tbody>' . $rows . '</tbody></table>';
        }
        $text = '';
        for ($i = mt_rand(2, 5); $i > 0; $i--) {
            $text .= mt_rand(0, 4) === 0 ? '<b style="mso-bidi-font-weight: normal;">' . sentence() . '</b> ' : sentence() . '&nbsp;';
        }
        return '<p class="MsoNormal" style="margin-bottom: 0.0001pt; line-height: normal; text-align: justify;">'
            . '<span lang="EN-US" style="font-size: 11pt; font-family: ' . $font . '; mso-fareast-font-family: Calibri; mso-ansi-language: EN-US; color: #1f497d;">'
            . rtrim($text, ' ') . '</span></p>';
    });
}

/** @return array<string, string> label => HTML, each built to cost a tokenizer time or memory */
function hostileInputs(): array
{
    $attributes = '';
    for ($i = 0; $i < 10000; $i++) {
        $attributes .= " d$i=\"v\"";
    }

    $style = '';
    for ($i = 0; strlen($style) < 1048576; $i++) {
        $style .= ".c$i { color: #abc; margin: 0; padding: 1px 2px; }\n";
    }

    // the three ways to write one ampersand, in turn, so both the named and the numeric paths run
    $entities = str_repeat('&amp;&#38;&#x26;', intdiv(1048576, 15));

    return [
        '1 MB of <'                            => str_repeat('<', 1048576),
        '1 MB unclosed attribute value'        => '<a title="' . str_repeat('x', 1048576),
        '10,000 attributes on one tag'         => "<p$attributes>x</p>",
        '100,000 levels of nesting'            => str_repeat('<div>', 100000) . 'x' . str_repeat('</div>', 100000),
        '1 MB <style> block'                   => "<style>\n$style</style>",
        '1 MB chain of entities in one value'  => "<a title=\"$entities\">x</a>",
    ];
}

//endregion
//region Helpers

/** Fastest of RUNS timings of one call, in seconds. */
function fastest(callable $call): float
{
    $call();   // warm up: class loading, regex compilation
    $best = INF;
    for ($i = 0; $i < RUNS; $i++) {
        $start = hrtime(true);
        $call();
        $best = min($best, (hrtime(true) - $start) / 1e9);
    }
    return $best;
}

/** This process's peak resident set size in KB (VmHWM), 0 where /proc is not available. */
function peakKb(): int
{
    preg_match('/VmHWM:\s+(\d+) kB/', (string)@file_get_contents('/proc/self/status'), $match);
    return (int)($match[1] ?? 0);
}

/**
 * Runs this script in subprocess mode on one file: a fresh PHP process with the same opcache
 * and memory_limit settings times the check (or the sanitizer) and reports how much its peak
 * memory grew. Returns [seconds, peak growth in KB or null off Linux], or null when the process
 * failed, which the sanitizer does when an input needs more than memory_limit.
 *
 * @return array{float, ?int}|null
 */
function measure(string $path, string $mode, ?string $sanitizer): ?array
{
    $command = escapeshellarg(PHP_BINARY) . ' -d xdebug.mode=off -d opcache.enable_cli=' . (int)ini_get('opcache.enable_cli')
        . ' -d memory_limit=' . escapeshellarg((string)ini_get('memory_limit')) . ' ' . escapeshellarg(__FILE__)
        . ' --subprocess --mode=' . $mode . ' --file=' . escapeshellarg($path)
        . ($sanitizer !== null ? ' --sanitizer=' . escapeshellarg($sanitizer) : '') . ' 2>&1';
    $output = trim((string)shell_exec($command));
    if (!preg_match('/^([\d.]+) (-?\d+)$/', $output, $match)) {   // "seconds peakGrowthKb"
        fwrite(STDERR, "$mode subprocess failed for " . basename($path) . ": " . strtok($output, "\n") . "\n");
        return null;
    }
    return [(float)$match[1], IS_LINUX ? (int)$match[2] : null];
}

/** measure() for the check, which is not expected to fail. @return array{float, ?int} */
function measureCheck(string $path): array
{
    $measured = measure($path, 'check', null);
    if ($measured === null) {
        exit(1);
    }
    return $measured;
}

/**
 * One HTMLPurifier call on $html, with the purifier built outside the timed call the way an
 * application would hold one. The definition cache is HTMLPurifier's default, inside its own
 * install folder, so nothing is written into this repository.
 */
function sanitizer(string $html): callable
{
    $purifier = new \HTMLPurifier(\HTMLPurifier_Config::createDefault());
    return fn() => $purifier->purify($html);
}

function memoryCell(?int $peakGrowthKb): string
{
    return $peakGrowthKb === null ? 'n/a' : humanBytes(max(0, $peakGrowthKb) * 1024);
}

/**
 * Every payload .html under the corpus folder, then every .html under the fixture folders,
 * keyed by path with the source label as value: the corpus source's folder name, or
 * fixtures/<folder>. The raw download each corpus source keeps as source.<ext> is skipped.
 *
 * @return array<string, string>
 */
function corpusFiles(string $corpusDir): array
{
    $roots = [];
    foreach (scandir($corpusDir) ?: [] as $source) {
        if ($source[0] !== '.' && is_dir("$corpusDir/$source")) {
            $roots["$corpusDir/$source"] = $source;
        }
    }
    foreach (FIXTURES as $folder) {
        $roots[__DIR__ . "/../tests/Support/fixtures/$folder"] = "fixtures/$folder";
    }

    $files = [];
    foreach ($roots as $root => $label) {
        $paths = [];
        $stack = [$root];
        while ($stack !== []) {
            $dir = array_pop($stack);
            foreach (scandir($dir) ?: [] as $entry) {
                if ($entry[0] === '.' || str_starts_with($entry, 'source.')) {
                    continue;
                }
                $path = "$dir/$entry";
                if (is_dir($path)) {
                    $stack[] = $path;
                } elseif (str_ends_with($entry, '.html')) {
                    $paths[] = $path;
                }
            }
        }
        sort($paths);
        foreach ($paths as $path) {
            $files[$path] = $label;
        }
    }
    return $files;
}

/**
 * One corpus table row: label, file count, median, mean, and the slowest file with its size.
 *
 * @param array<string, float> $bucket path => fastest seconds
 * @param array<string, int>   $sizes  path => bytes
 * @return string[]
 */
function corpusRow(string $label, array $bucket, array $sizes): array
{
    $slowest = array_search(max($bucket), $bucket, true);
    return [$label, number_format(count($bucket)), ms(median($bucket)), ms(array_sum($bucket) / count($bucket)), sprintf('%s (%s, %s)', ms($bucket[$slowest]), basename($slowest), humanBytes($sizes[$slowest]))];
}

/** @param float[] $values */
function median(array $values): float
{
    sort($values);
    $count = count($values);
    return ($count % 2) ? $values[intdiv($count, 2)] : ($values[$count / 2 - 1] + $values[$count / 2]) / 2;
}

function ms(float $seconds): string
{
    $ms = $seconds * 1000;
    return $ms < 0.1 ? sprintf('%.3f ms', $ms) : ($ms < 10 ? sprintf('%.2f ms', $ms) : sprintf('%.1f ms', $ms));
}

function humanBytes(int|float $bytes): string
{
    return match (true) {
        $bytes >= 1048576 => sprintf('%.1f MB', $bytes / 1048576),
        $bytes >= 1024    => sprintf('%.0f KB', $bytes / 1024),
        default           => sprintf('%d bytes', $bytes),
    };
}

//endregion

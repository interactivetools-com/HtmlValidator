# Benchmark Results

Raw output of `benchmarks/run.sh --corpus=corpus --sanitizer=...` on 2026-09-14, on a dedicated
server (Intel Xeon E-2386G, 12 cores, 64 GB) with nothing else running. The corpus was the
2,727 payload files `tools/fetch-corpus.php` had downloaded from its twelve sources, plus the 15
HTML fixtures under `tests/Support/fixtures/`. The sanitizer columns are ezyang/htmlpurifier
4.19.0 installed in a folder outside the repository.

## PHP 8.5

```text
PHP 8.5.10, PCRE 10.44 2024-06-07, Linux 4.18.0-553.141.2.el8_10.x86_64, Intel(R) Xeon(R) E-2386G CPU @ 3.50GHz
opcache on, JIT off, xdebug off, HTMLPurifier 4.19.0

## Generated content

| Shape                 | Size   | Check time | Throughput | Peak memory added | HTMLPurifier time | HTMLPurifier memory |
|-----------------------|--------|------------|------------|-------------------|-------------------|---------------------|
| plain paragraphs      | 1 KB   | 0.010 ms   | 115 MB/s   | 0 bytes           | 0.11 ms           | 1.5 MB              |
| plain paragraphs      | 10 KB  | 0.077 ms   | 126 MB/s   | 0 bytes           | 0.39 ms           | 972 KB              |
| plain paragraphs      | 100 KB | 0.75 ms    | 130 MB/s   | 0 bytes           | 3.25 ms           | 3.5 MB              |
| plain paragraphs      | 1 MB   | 7.63 ms    | 131 MB/s   | 2.6 MB            | 33.4 ms           | 18.9 MB             |
| a tag every few words | 1 KB   | 0.077 ms   | 16 MB/s    | 0 bytes           | 0.42 ms           | 1.3 MB              |
| a tag every few words | 10 KB  | 0.63 ms    | 16 MB/s    | 0 bytes           | 2.69 ms           | 3.3 MB              |
| a tag every few words | 100 KB | 6.20 ms    | 16 MB/s    | 0 bytes           | 25.2 ms           | 13.2 MB             |
| a tag every few words | 1 MB   | 62.4 ms    | 16 MB/s    | 2.5 MB            | 293.3 ms          | 116.3 MB            |
| Word paste            | 1 KB   | 0.020 ms   | 49 MB/s    | 0 bytes           | 0.16 ms           | 1.3 MB              |
| Word paste            | 10 KB  | 0.30 ms    | 36 MB/s    | 0 bytes           | 1.55 ms           | 3.6 MB              |
| Word paste            | 100 KB | 2.69 ms    | 37 MB/s    | 0 bytes           | 12.8 ms           | 6.5 MB              |
| Word paste            | 1 MB   | 27.1 ms    | 37 MB/s    | 2.6 MB            | 139.1 ms          | 38.7 MB             |

## Hostile inputs

| Input                               | Size   | Result            | Check time | Peak memory added |
|-------------------------------------|--------|-------------------|------------|-------------------|
| 1 MB of <                           | 1.0 MB | accepted          | 253.7 ms   | 2.6 MB            |
| 1 MB unclosed attribute value       | 1.0 MB | `unclosed-markup` | 1.02 ms    | 2.6 MB            |
| 10,000 attributes on one tag        | 97 KB  | accepted          | 5.83 ms    | 2.6 MB            |
| 100,000 levels of nesting           | 1.0 MB | accepted          | 131.1 ms   | 2.6 MB            |
| 1 MB <style> block                  | 1.0 MB | accepted          | 1.92 ms    | 2.6 MB            |
| 1 MB chain of entities in one value | 1.1 MB | accepted          | 54.2 ms    | 2.6 MB            |

## Corpus and fixtures: 2,742 files, 0.2 MB, checked in 0.016 s (170,053 files/s, 15 MB/s)

| Size          | Files | Median per file | Mean per file | Slowest file                               |
|---------------|-------|-----------------|---------------|--------------------------------------------|
| under 1 KB    | 2,728 | 0.004 ms        | 0.005 ms      | 0.061 ms (0013.html, 886 bytes)            |
| 1 KB to 10 KB | 14    | 0.044 ms        | 0.073 ms      | 0.19 ms (html5-custom-elements.html, 3 KB) |

| Source            | Files | Median per file | Mean per file | Slowest file                                     |
|-------------------|-------|-----------------|---------------|--------------------------------------------------|
| bleach            | 18    | 0.005 ms        | 0.006 ms      | 0.016 ms (0014.html, 251 bytes)                  |
| bluemonday        | 336   | 0.003 ms        | 0.004 ms      | 0.026 ms (0080.html, 348 bytes)                  |
| ckeditor          | 6     | 0.005 ms        | 0.005 ms      | 0.006 ms (0005.html, 68 bytes)                   |
| dompurify         | 208   | 0.008 ms        | 0.009 ms      | 0.061 ms (0013.html, 886 bytes)                  |
| html5lib          | 69    | 0.004 ms        | 0.004 ms      | 0.011 ms (0043.html, 243 bytes)                  |
| html5sec          | 148   | 0.007 ms        | 0.009 ms      | 0.070 ms (0144.html, 1 KB)                       |
| owasp             | 95    | 0.004 ms        | 0.004 ms      | 0.013 ms (0009.html, 151 bytes)                  |
| owasp-java        | 286   | 0.005 ms        | 0.005 ms      | 0.026 ms (0271.html, 348 bytes)                  |
| payloads          | 1,047 | 0.004 ms        | 0.005 ms      | 0.054 ms (0954.html, 972 bytes)                  |
| portswigger       | 461   | 0.004 ms        | 0.006 ms      | 0.028 ms (0030.html, 270 bytes)                  |
| tinymce           | 11    | 0.012 ms        | 0.011 ms      | 0.017 ms (0001.html, 145 bytes)                  |
| wpt               | 42    | 0.005 ms        | 0.005 ms      | 0.008 ms (0008.html, 55 bytes)                   |
| fixtures/accept   | 4     | 0.11 ms         | 0.13 ms       | 0.19 ms (html5-custom-elements.html, 3 KB)       |
| fixtures/reject   | 9     | 0.009 ms        | 0.008 ms      | 0.014 ms (element-not-allowed-1.html, 176 bytes) |
| fixtures/tinymce4 | 2     | 0.19 ms         | 0.19 ms       | 0.19 ms (output.html, 2 KB)                      |

```

## PHP 8.1

```text
PHP 8.1.34, PCRE 10.39 2021-10-29, Linux 4.18.0-553.141.2.el8_10.x86_64, Intel(R) Xeon(R) E-2386G CPU @ 3.50GHz
opcache on, JIT off, xdebug off, HTMLPurifier 4.19.0

## Generated content

| Shape                 | Size   | Check time | Throughput | Peak memory added | HTMLPurifier time | HTMLPurifier memory |
|-----------------------|--------|------------|------------|-------------------|-------------------|---------------------|
| plain paragraphs      | 1 KB   | 0.009 ms   | 127 MB/s   | 0 bytes           | 0.11 ms           | 1.1 MB              |
| plain paragraphs      | 10 KB  | 0.072 ms   | 137 MB/s   | 0 bytes           | 0.40 ms           | 972 KB              |
| plain paragraphs      | 100 KB | 0.69 ms    | 141 MB/s   | 0 bytes           | 3.31 ms           | 3.3 MB              |
| plain paragraphs      | 1 MB   | 6.92 ms    | 144 MB/s   | 2.3 MB            | 34.9 ms           | 22.8 MB             |
| a tag every few words | 1 KB   | 0.070 ms   | 18 MB/s    | 0 bytes           | 0.41 ms           | 1.0 MB              |
| a tag every few words | 10 KB  | 0.57 ms    | 18 MB/s    | 0 bytes           | 2.66 ms           | 3.2 MB              |
| a tag every few words | 100 KB | 5.63 ms    | 17 MB/s    | 0 bytes           | 25.7 ms           | 15.0 MB             |
| a tag every few words | 1 MB   | 55.8 ms    | 18 MB/s    | 2.3 MB            | 296.6 ms          | 130.3 MB            |
| Word paste            | 1 KB   | 0.021 ms   | 47 MB/s    | 0 bytes           | 0.16 ms           | 1.3 MB              |
| Word paste            | 10 KB  | 0.28 ms    | 38 MB/s    | 0 bytes           | 1.62 ms           | 1.3 MB              |
| Word paste            | 100 KB | 2.55 ms    | 39 MB/s    | 0 bytes           | 13.3 ms           | 6.1 MB              |
| Word paste            | 1 MB   | 26.4 ms    | 38 MB/s    | 2.3 MB            | 146.1 ms          | 38.7 MB             |

## Hostile inputs

| Input                               | Size   | Result            | Check time | Peak memory added |
|-------------------------------------|--------|-------------------|------------|-------------------|
| 1 MB of <                           | 1.0 MB | accepted          | 229.9 ms   | 2.3 MB            |
| 1 MB unclosed attribute value       | 1.0 MB | `unclosed-markup` | 0.95 ms    | 2.3 MB            |
| 10,000 attributes on one tag        | 97 KB  | accepted          | 5.75 ms    | 2.3 MB            |
| 100,000 levels of nesting           | 1.0 MB | accepted          | 111.8 ms   | 2.3 MB            |
| 1 MB <style> block                  | 1.0 MB | accepted          | 1.97 ms    | 2.3 MB            |
| 1 MB chain of entities in one value | 1.1 MB | accepted          | 55.0 ms    | 2.3 MB            |

## Corpus and fixtures: 2,742 files, 0.2 MB, checked in 0.016 s (170,131 files/s, 15 MB/s)

| Size          | Files | Median per file | Mean per file | Slowest file                    |
|---------------|-------|-----------------|---------------|---------------------------------|
| under 1 KB    | 2,728 | 0.004 ms        | 0.005 ms      | 0.072 ms (0013.html, 886 bytes) |
| 1 KB to 10 KB | 14    | 0.044 ms        | 0.072 ms      | 0.19 ms (input.html, 2 KB)      |

| Source            | Files | Median per file | Mean per file | Slowest file                                     |
|-------------------|-------|-----------------|---------------|--------------------------------------------------|
| bleach            | 18    | 0.005 ms        | 0.006 ms      | 0.016 ms (0014.html, 251 bytes)                  |
| bluemonday        | 336   | 0.003 ms        | 0.004 ms      | 0.027 ms (0080.html, 348 bytes)                  |
| ckeditor          | 6     | 0.005 ms        | 0.005 ms      | 0.006 ms (0005.html, 68 bytes)                   |
| dompurify         | 208   | 0.008 ms        | 0.009 ms      | 0.072 ms (0013.html, 886 bytes)                  |
| html5lib          | 69    | 0.004 ms        | 0.004 ms      | 0.011 ms (0043.html, 243 bytes)                  |
| html5sec          | 148   | 0.007 ms        | 0.009 ms      | 0.070 ms (0144.html, 1 KB)                       |
| owasp             | 95    | 0.004 ms        | 0.004 ms      | 0.013 ms (0009.html, 151 bytes)                  |
| owasp-java        | 286   | 0.005 ms        | 0.005 ms      | 0.027 ms (0271.html, 348 bytes)                  |
| payloads          | 1,047 | 0.004 ms        | 0.005 ms      | 0.053 ms (0954.html, 972 bytes)                  |
| portswigger       | 461   | 0.004 ms        | 0.006 ms      | 0.026 ms (0403.html, 879 bytes)                  |
| tinymce           | 11    | 0.012 ms        | 0.012 ms      | 0.018 ms (0001.html, 145 bytes)                  |
| wpt               | 42    | 0.005 ms        | 0.005 ms      | 0.008 ms (0008.html, 55 bytes)                   |
| fixtures/accept   | 4     | 0.11 ms         | 0.12 ms       | 0.19 ms (html5-custom-elements.html, 3 KB)       |
| fixtures/reject   | 9     | 0.010 ms        | 0.008 ms      | 0.014 ms (element-not-allowed-1.html, 176 bytes) |
| fixtures/tinymce4 | 2     | 0.19 ms         | 0.19 ms       | 0.19 ms (input.html, 2 KB)                       |

```

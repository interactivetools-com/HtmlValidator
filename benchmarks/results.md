# Benchmark Results

Raw output of `benchmarks/run.sh --corpus=corpus --sanitizer=...` on 2026-09-21, on a dedicated
server (Intel Xeon E-2386G, 12 cores, 64 GB) with nothing else running. The corpus was the
3,168 files `tools/fetch-corpus.php` had downloaded from its nineteen sources, plus the 19 HTML
fixtures under `tests/Support/fixtures/`. The sanitizer columns are ezyang/htmlpurifier 4.19.0
installed in a folder outside the repository.

## PHP 8.5

```text
PHP 8.5.10, PCRE 10.44 2024-06-07, Linux 4.18.0-553.141.2.el8_10.x86_64, Intel(R) Xeon(R) E-2386G CPU @ 3.50GHz
opcache on, JIT off, xdebug off, HTMLPurifier 4.19.0

## Generated content

| Shape                 | Size   | Check time | Fast path off | Throughput | Peak memory added | HTMLPurifier time | HTMLPurifier memory |
|-----------------------|--------|------------|---------------|------------|-------------------|-------------------|---------------------|
| plain paragraphs      | 1 KB   | 0.003 ms   | 0.010 ms      | 457 MB/s   | 564 KB            | 0.11 ms           | 708 KB              |
| plain paragraphs      | 10 KB  | 0.010 ms   | 0.077 ms      | 942 MB/s   | 564 KB            | 0.40 ms           | 1.1 MB              |
| plain paragraphs      | 100 KB | 0.090 ms   | 0.72 ms       | 1080 MB/s  | 564 KB            | 3.24 ms           | 3.3 MB              |
| plain paragraphs      | 1 MB   | 0.94 ms    | 7.48 ms       | 1066 MB/s  | 2.8 MB            | 33.6 ms           | 18.9 MB             |
| a tag every few words | 1 KB   | 0.003 ms   | 0.081 ms      | 354 MB/s   | 624 KB            | 0.42 ms           | 1.0 MB              |
| a tag every few words | 10 KB  | 0.018 ms   | 0.64 ms       | 564 MB/s   | 624 KB            | 2.71 ms           | 3.2 MB              |
| a tag every few words | 100 KB | 0.19 ms    | 6.42 ms       | 503 MB/s   | 624 KB            | 25.4 ms           | 13.3 MB             |
| a tag every few words | 1 MB   | 2.04 ms    | 63.6 ms       | 489 MB/s   | 2.8 MB            | 293.6 ms          | 116.3 MB            |
| Word paste            | 1 KB   | 0.004 ms   | 0.021 ms      | 260 MB/s   | 564 KB            | 0.16 ms           | 1.1 MB              |
| Word paste            | 10 KB  | 0.028 ms   | 0.31 ms       | 396 MB/s   | 564 KB            | 1.55 ms           | 3.3 MB              |
| Word paste            | 100 KB | 0.22 ms    | 2.81 ms       | 441 MB/s   | 564 KB            | 12.8 ms           | 6.3 MB              |
| Word paste            | 1 MB   | 2.29 ms    | 28.6 ms       | 437 MB/s   | 2.8 MB            | 138.8 ms          | 38.7 MB             |

## Typical pages

| Page                     | Size  | Tags per KB | Check time | Fast path off | Throughput | Peak memory added | HTMLPurifier time | HTMLPurifier memory |
|--------------------------|-------|-------------|------------|---------------|------------|-------------------|-------------------|---------------------|
| news item, 250 words     | 2 KB  | 12          | 0.004 ms   | 0.063 ms      | 480 MB/s   | 564 KB            | 0.34 ms           | 1.1 MB              |
| home page section        | 2 KB  | 20          | 0.005 ms   | 0.087 ms      | 407 MB/s   | 564 KB            | 0.46 ms           | 1.0 MB              |
| blog post, 1,000 words   | 8 KB  | 10          | 0.012 ms   | 0.19 ms       | 671 MB/s   | 564 KB            | 0.93 ms           | 3.4 MB              |
| article, 1,800 words     | 15 KB | 10          | 0.020 ms   | 0.33 ms       | 738 MB/s   | 564 KB            | 1.53 ms           | 3.5 MB              |
| FAQ, 40 questions        | 10 KB | 12          | 0.015 ms   | 0.29 ms       | 656 MB/s   | 564 KB            | 1.26 ms           | 3.1 MB              |
| policy page, 5,000 words | 36 KB | 5           | 0.037 ms   | 0.41 ms       | 945 MB/s   | 564 KB            | 2.00 ms           | 3.1 MB              |
| newsletter, 12 blocks    | 9 KB  | 10          | 0.024 ms   | 0.31 ms       | 387 MB/s   | 564 KB            | 1.52 ms           | 3.5 MB              |

## Hostile inputs

| Input                               | Size   | Result            | Check time | Peak memory added |
|-------------------------------------|--------|-------------------|------------|-------------------|
| 1 MB of <                           | 1.0 MB | accepted          | 2.06 ms    | 2.8 MB            |
| 1 MB unclosed attribute value       | 1.0 MB | `unclosed-markup` | 1.22 ms    | 624 KB            |
| 10,000 attributes on one tag        | 97 KB  | accepted          | 0.17 ms    | 624 KB            |
| 100,000 levels of nesting           | 1.0 MB | accepted          | 2.33 ms    | 2.0 MB            |
| 1 MB <style> block                  | 1.0 MB | accepted          | 2.14 ms    | 2.8 MB            |
| 1 MB chain of entities in one value | 1.1 MB | accepted          | 53.3 ms    | 2.0 MB            |
| 1 MB entity name in one value       | 1.0 MB | accepted          | 1.92 ms    | 6.8 MB            |
| 1 MB of short comments              | 1.0 MB | accepted          | 108.6 ms   | 564 KB            |

## Corpus and fixtures: 3,187 files, 6.9 MB, checked in 0.084 s (38,014 files/s, 82 MB/s)

| Size            | Files | Median per file | Mean per file | Slowest file                    |
|-----------------|-------|-----------------|---------------|---------------------------------|
| under 1 KB      | 2,855 | 0.005 ms        | 0.006 ms      | 0.056 ms (0954.html, 972 bytes) |
| 1 KB to 10 KB   | 229   | 0.030 ms        | 0.048 ms      | 0.25 ms (0096.html, 10 KB)      |
| 10 KB to 100 KB | 97    | 0.14 ms         | 0.19 ms       | 0.75 ms (0072.html, 29 KB)      |
| over 100 KB     | 6     | 5.87 ms         | 6.18 ms       | 12.8 ms (0006.html, 1.1 MB)     |

| Source            | Files | Median per file | Mean per file | Slowest file                                       |
|-------------------|-------|-----------------|---------------|----------------------------------------------------|
| bleach            | 18    | 0.005 ms        | 0.005 ms      | 0.008 ms (0006.html, 42 bytes)                     |
| bluemonday        | 336   | 0.002 ms        | 0.003 ms      | 0.028 ms (0080.html, 348 bytes)                    |
| cerberus          | 3     | 0.19 ms         | 0.19 ms       | 0.25 ms (0002.html, 40 KB)                         |
| ckeditor          | 6     | 0.005 ms        | 0.005 ms      | 0.006 ms (0001.html, 50 bytes)                     |
| ckeditor-paste    | 173   | 0.028 ms        | 0.070 ms      | 0.66 ms (0139.html, 34 KB)                         |
| dompurify         | 208   | 0.007 ms        | 0.009 ms      | 0.053 ms (0013.html, 886 bytes)                    |
| html5lib          | 69    | 0.005 ms        | 0.005 ms      | 0.012 ms (0043.html, 243 bytes)                    |
| html5sec          | 148   | 0.007 ms        | 0.009 ms      | 0.074 ms (0144.html, 1 KB)                         |
| mailchimp         | 44    | 0.13 ms         | 0.13 ms       | 0.34 ms (0001.html, 79 KB)                         |
| mdn               | 102   | 0.009 ms        | 0.013 ms      | 0.15 ms (0086.html, 9 KB)                          |
| owasp             | 95    | 0.004 ms        | 0.005 ms      | 0.013 ms (0009.html, 151 bytes)                    |
| owasp-java        | 286   | 0.005 ms        | 0.006 ms      | 0.028 ms (0271.html, 348 bytes)                    |
| payloads          | 1,047 | 0.005 ms        | 0.005 ms      | 0.056 ms (0954.html, 972 bytes)                    |
| portswigger       | 461   | 0.005 ms        | 0.006 ms      | 0.025 ms (0403.html, 879 bytes)                    |
| tinymce           | 11    | 0.012 ms        | 0.012 ms      | 0.022 ms (0001.html, 145 bytes)                    |
| wikipedia         | 6     | 5.87 ms         | 6.14 ms       | 12.8 ms (0006.html, 1.1 MB)                        |
| wordpress         | 113   | 0.059 ms        | 0.089 ms      | 0.75 ms (0072.html, 29 KB)                         |
| wpt               | 42    | 0.006 ms        | 0.006 ms      | 0.010 ms (0008.html, 55 bytes)                     |
| fixtures/accept   | 7     | 0.014 ms        | 0.023 ms      | 0.055 ms (html5-custom-elements.html, 3 KB)        |
| fixtures/reject   | 10    | 0.008 ms        | 0.007 ms      | 0.012 ms (attribute-not-allowed-1.html, 235 bytes) |
| fixtures/tinymce4 | 2     | 0.12 ms         | 0.12 ms       | 0.14 ms (input.html, 2 KB)                         |
```

## PHP 8.1

```text
PHP 8.1.34, PCRE 10.39 2021-10-29, Linux 4.18.0-553.141.2.el8_10.x86_64, Intel(R) Xeon(R) E-2386G CPU @ 3.50GHz
opcache on, JIT off, xdebug off, HTMLPurifier 4.19.0

## Generated content

| Shape                 | Size   | Check time | Fast path off | Throughput | Peak memory added | HTMLPurifier time | HTMLPurifier memory |
|-----------------------|--------|------------|---------------|------------|-------------------|-------------------|---------------------|
| plain paragraphs      | 1 KB   | 0.003 ms   | 0.009 ms      | 475 MB/s   | 504 KB            | 0.10 ms           | 912 KB              |
| plain paragraphs      | 10 KB  | 0.010 ms   | 0.067 ms      | 938 MB/s   | 504 KB            | 0.41 ms           | 912 KB              |
| plain paragraphs      | 100 KB | 0.087 ms   | 0.66 ms       | 1118 MB/s  | 504 KB            | 3.28 ms           | 3.4 MB              |
| plain paragraphs      | 1 MB   | 0.91 ms    | 6.78 ms       | 1095 MB/s  | 2.5 MB            | 34.9 ms           | 22.9 MB             |
| a tag every few words | 1 KB   | 0.003 ms   | 0.076 ms      | 375 MB/s   | 504 KB            | 0.41 ms           | 1.3 MB              |
| a tag every few words | 10 KB  | 0.017 ms   | 0.59 ms       | 589 MB/s   | 504 KB            | 2.68 ms           | 3.3 MB              |
| a tag every few words | 100 KB | 0.19 ms    | 5.69 ms       | 517 MB/s   | 504 KB            | 25.4 ms           | 15.1 MB             |
| a tag every few words | 1 MB   | 2.00 ms    | 58.1 ms       | 499 MB/s   | 2.5 MB            | 296.9 ms          | 130.4 MB            |
| Word paste            | 1 KB   | 0.004 ms   | 0.021 ms      | 263 MB/s   | 504 KB            | 0.16 ms           | 1.3 MB              |
| Word paste            | 10 KB  | 0.027 ms   | 0.30 ms       | 399 MB/s   | 504 KB            | 1.59 ms           | 3.5 MB              |
| Word paste            | 100 KB | 0.22 ms    | 2.71 ms       | 443 MB/s   | 504 KB            | 13.4 ms           | 6.2 MB              |
| Word paste            | 1 MB   | 2.32 ms    | 27.1 ms       | 431 MB/s   | 2.5 MB            | 145.8 ms          | 38.7 MB             |

## Typical pages

| Page                     | Size  | Tags per KB | Check time | Fast path off | Throughput | Peak memory added | HTMLPurifier time | HTMLPurifier memory |
|--------------------------|-------|-------------|------------|---------------|------------|-------------------|-------------------|---------------------|
| news item, 250 words     | 2 KB  | 12          | 0.004 ms   | 0.055 ms      | 508 MB/s   | 504 KB            | 0.35 ms           | 912 KB              |
| home page section        | 2 KB  | 20          | 0.004 ms   | 0.081 ms      | 437 MB/s   | 504 KB            | 0.45 ms           | 1.3 MB              |
| blog post, 1,000 words   | 8 KB  | 10          | 0.012 ms   | 0.18 ms       | 696 MB/s   | 504 KB            | 0.94 ms           | 3.5 MB              |
| article, 1,800 words     | 15 KB | 10          | 0.020 ms   | 0.31 ms       | 764 MB/s   | 504 KB            | 1.55 ms           | 3.4 MB              |
| FAQ, 40 questions        | 10 KB | 12          | 0.014 ms   | 0.27 ms       | 712 MB/s   | 504 KB            | 1.27 ms           | 3.2 MB              |
| policy page, 5,000 words | 36 KB | 5           | 0.035 ms   | 0.37 ms       | 1006 MB/s  | 504 KB            | 1.86 ms           | 3.4 MB              |
| newsletter, 12 blocks    | 9 KB  | 10          | 0.022 ms   | 0.29 ms       | 419 MB/s   | 504 KB            | 1.54 ms           | 3.5 MB              |

## Hostile inputs

| Input                               | Size   | Result            | Check time | Peak memory added |
|-------------------------------------|--------|-------------------|------------|-------------------|
| 1 MB of <                           | 1.0 MB | accepted          | 2.05 ms    | 2.5 MB            |
| 1 MB unclosed attribute value       | 1.0 MB | `unclosed-markup` | 1.20 ms    | 504 KB            |
| 10,000 attributes on one tag        | 97 KB  | accepted          | 0.16 ms    | 504 KB            |
| 100,000 levels of nesting           | 1.0 MB | accepted          | 2.30 ms    | 2.5 MB            |
| 1 MB <style> block                  | 1.0 MB | accepted          | 2.15 ms    | 2.5 MB            |
| 1 MB chain of entities in one value | 1.1 MB | accepted          | 53.8 ms    | 2.5 MB            |
| 1 MB entity name in one value       | 1.0 MB | accepted          | 1.80 ms    | 6.6 MB            |
| 1 MB of short comments              | 1.0 MB | accepted          | 95.9 ms    | 504 KB            |

## Corpus and fixtures: 3,187 files, 6.9 MB, checked in 0.080 s (40,002 files/s, 87 MB/s)

| Size            | Files | Median per file | Mean per file | Slowest file                    |
|-----------------|-------|-----------------|---------------|---------------------------------|
| under 1 KB      | 2,855 | 0.005 ms        | 0.006 ms      | 0.056 ms (0709.html, 968 bytes) |
| 1 KB to 10 KB   | 229   | 0.029 ms        | 0.044 ms      | 0.24 ms (0025.html, 6 KB)       |
| 10 KB to 100 KB | 97    | 0.13 ms         | 0.17 ms       | 0.61 ms (0139.html, 34 KB)      |
| over 100 KB     | 6     | 5.62 ms         | 5.91 ms       | 12.2 ms (0006.html, 1.1 MB)     |

| Source            | Files | Median per file | Mean per file | Slowest file                                       |
|-------------------|-------|-----------------|---------------|----------------------------------------------------|
| bleach            | 18    | 0.005 ms        | 0.005 ms      | 0.007 ms (0006.html, 42 bytes)                     |
| bluemonday        | 336   | 0.002 ms        | 0.003 ms      | 0.028 ms (0080.html, 348 bytes)                    |
| cerberus          | 3     | 0.18 ms         | 0.17 ms       | 0.23 ms (0002.html, 40 KB)                         |
| ckeditor          | 6     | 0.005 ms        | 0.005 ms      | 0.006 ms (0001.html, 50 bytes)                     |
| ckeditor-paste    | 173   | 0.027 ms        | 0.066 ms      | 0.61 ms (0139.html, 34 KB)                         |
| dompurify         | 208   | 0.007 ms        | 0.009 ms      | 0.050 ms (0013.html, 886 bytes)                    |
| html5lib          | 69    | 0.005 ms        | 0.005 ms      | 0.012 ms (0043.html, 243 bytes)                    |
| html5sec          | 148   | 0.007 ms        | 0.009 ms      | 0.071 ms (0144.html, 1 KB)                         |
| mailchimp         | 44    | 0.12 ms         | 0.13 ms       | 0.29 ms (0001.html, 79 KB)                         |
| mdn               | 102   | 0.008 ms        | 0.013 ms      | 0.14 ms (0086.html, 9 KB)                          |
| owasp             | 95    | 0.004 ms        | 0.005 ms      | 0.014 ms (0009.html, 151 bytes)                    |
| owasp-java        | 286   | 0.005 ms        | 0.006 ms      | 0.028 ms (0271.html, 348 bytes)                    |
| payloads          | 1,047 | 0.005 ms        | 0.005 ms      | 0.056 ms (0709.html, 968 bytes)                    |
| portswigger       | 461   | 0.005 ms        | 0.006 ms      | 0.024 ms (0403.html, 879 bytes)                    |
| tinymce           | 11    | 0.013 ms        | 0.012 ms      | 0.023 ms (0001.html, 145 bytes)                    |
| wikipedia         | 6     | 5.62 ms         | 5.87 ms       | 12.2 ms (0006.html, 1.1 MB)                        |
| wordpress         | 113   | 0.049 ms        | 0.074 ms      | 0.60 ms (0072.html, 29 KB)                         |
| wpt               | 42    | 0.005 ms        | 0.006 ms      | 0.010 ms (0008.html, 55 bytes)                     |
| fixtures/accept   | 7     | 0.013 ms        | 0.022 ms      | 0.052 ms (html5-custom-elements.html, 3 KB)        |
| fixtures/reject   | 10    | 0.008 ms        | 0.008 ms      | 0.012 ms (attribute-not-allowed-1.html, 235 bytes) |
| fixtures/tinymce4 | 2     | 0.12 ms         | 0.12 ms       | 0.13 ms (input.html, 2 KB)                         |
```

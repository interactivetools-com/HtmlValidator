# Benchmark Results

Raw output of `benchmarks/run.sh --corpus=corpus --sanitizer=...` on 2026-10-08, on a dedicated
server (Intel Xeon E-2386G, 12 cores, 64 GB) with nothing else running. The corpus was the
3,168 files `tools/fetch-corpus.php` had downloaded from its nineteen sources, plus the 19 HTML
fixtures under `tests/Support/fixtures/`. The sanitizer columns are ezyang/htmlpurifier 4.19.0
installed in a folder outside the repository.

## PHP 8.5

```text
PHP 8.5.11, PCRE 10.44 2024-06-07, Linux 4.18.0-553.141.2.el8_10.x86_64, Intel(R) Xeon(R) E-2386G CPU @ 3.50GHz
opcache on, JIT off, xdebug off, HTMLPurifier 4.19.0

## Generated content

| Shape                 | Size   | Check time | Fast path off | Throughput | Peak memory added | HTMLPurifier time | HTMLPurifier memory |
|-----------------------|--------|------------|---------------|------------|-------------------|-------------------|---------------------|
| plain paragraphs      | 1 KB   | 0.003 ms   | 0.010 ms      | 441 MB/s   | 564 KB            | 0.11 ms           | 708 KB              |
| plain paragraphs      | 10 KB  | 0.011 ms   | 0.072 ms      | 927 MB/s   | 564 KB            | 0.40 ms           | 1.0 MB              |
| plain paragraphs      | 100 KB | 0.090 ms   | 0.68 ms       | 1084 MB/s  | 504 KB            | 3.36 ms           | 3.3 MB              |
| plain paragraphs      | 1 MB   | 1.01 ms    | 6.88 ms       | 988 MB/s   | 2.8 MB            | 34.4 ms           | 18.9 MB             |
| a tag every few words | 1 KB   | 0.004 ms   | 0.077 ms      | 341 MB/s   | 564 KB            | 0.43 ms           | 1.0 MB              |
| a tag every few words | 10 KB  | 0.018 ms   | 0.61 ms       | 569 MB/s   | 564 KB            | 2.69 ms           | 3.2 MB              |
| a tag every few words | 100 KB | 0.19 ms    | 5.97 ms       | 502 MB/s   | 564 KB            | 25.9 ms           | 13.0 MB             |
| a tag every few words | 1 MB   | 2.05 ms    | 61.9 ms       | 488 MB/s   | 2.8 MB            | 301.5 ms          | 116.4 MB            |
| Word paste            | 1 KB   | 0.004 ms   | 0.021 ms      | 255 MB/s   | 504 KB            | 0.16 ms           | 1.0 MB              |
| Word paste            | 10 KB  | 0.028 ms   | 0.31 ms       | 385 MB/s   | 564 KB            | 1.56 ms           | 3.3 MB              |
| Word paste            | 100 KB | 0.24 ms    | 2.77 ms       | 411 MB/s   | 504 KB            | 13.0 ms           | 6.0 MB              |
| Word paste            | 1 MB   | 2.45 ms    | 28.3 ms       | 409 MB/s   | 2.8 MB            | 140.3 ms          | 38.7 MB             |

## Typical pages

| Page                     | Size  | Tags per KB | Check time | Fast path off | Throughput | Peak memory added | HTMLPurifier time | HTMLPurifier memory |
|--------------------------|-------|-------------|------------|---------------|------------|-------------------|-------------------|---------------------|
| news item, 250 words     | 2 KB  | 12          | 0.004 ms   | 0.058 ms      | 470 MB/s   | 504 KB            | 0.34 ms           | 1.0 MB              |
| home page section        | 2 KB  | 20          | 0.005 ms   | 0.083 ms      | 406 MB/s   | 504 KB            | 0.47 ms           | 1.1 MB              |
| blog post, 1,000 words   | 8 KB  | 10          | 0.012 ms   | 0.18 ms       | 673 MB/s   | 504 KB            | 0.94 ms           | 3.4 MB              |
| article, 1,800 words     | 15 KB | 10          | 0.020 ms   | 0.32 ms       | 749 MB/s   | 504 KB            | 1.55 ms           | 3.4 MB              |
| FAQ, 40 questions        | 10 KB | 12          | 0.015 ms   | 0.27 ms       | 686 MB/s   | 504 KB            | 1.28 ms           | 3.2 MB              |
| policy page, 5,000 words | 36 KB | 5           | 0.036 ms   | 0.39 ms       | 963 MB/s   | 564 KB            | 1.87 ms           | 3.3 MB              |
| newsletter, 12 blocks    | 9 KB  | 10          | 0.022 ms   | 0.29 ms       | 424 MB/s   | 564 KB            | 1.53 ms           | 3.5 MB              |

## Hostile inputs

| Input                               | Size   | Result                  | Check time | Peak memory added |
|-------------------------------------|--------|-------------------------|------------|-------------------|
| 1 MB of <                           | 1.0 MB | accepted                | 2.06 ms    | 2.8 MB            |
| 1 MB unclosed attribute value       | 1.0 MB | `unclosed-markup`       | 1.24 ms    | 564 KB            |
| 10,000 attributes on one tag        | 97 KB  | `attribute-not-allowed` | 5.59 ms    | 2.8 MB            |
| 100,000 levels of nesting           | 1.0 MB | accepted                | 2.37 ms    | 2.0 MB            |
| 1 MB <style> block                  | 1.0 MB | accepted                | 2.19 ms    | 2.8 MB            |
| 1 MB chain of entities in one value | 1.1 MB | accepted                | 55.4 ms    | 2.0 MB            |
| 1 MB entity name in one value       | 1.0 MB | accepted                | 1.06 ms    | 4.8 MB            |
| 1 MB of short comments              | 1.0 MB | accepted                | 111.4 ms   | 564 KB            |

## Corpus and fixtures: 3,187 files, 6.9 MB, checked in 0.092 s (34,480 files/s, 75 MB/s)

| Size            | Files | Median per file | Mean per file | Slowest file                    |
|-----------------|-------|-----------------|---------------|---------------------------------|
| under 1 KB      | 2,855 | 0.005 ms        | 0.006 ms      | 0.059 ms (0709.html, 968 bytes) |
| 1 KB to 10 KB   | 229   | 0.031 ms        | 0.049 ms      | 0.26 ms (0025.html, 6 KB)       |
| 10 KB to 100 KB | 97    | 0.15 ms         | 0.20 ms       | 0.71 ms (0072.html, 29 KB)      |
| over 100 KB     | 6     | 6.95 ms         | 7.18 ms       | 14.2 ms (0006.html, 1.1 MB)     |

| Source            | Files | Median per file | Mean per file | Slowest file                                       |
|-------------------|-------|-----------------|---------------|----------------------------------------------------|
| bleach            | 18    | 0.006 ms        | 0.006 ms      | 0.008 ms (0006.html, 42 bytes)                     |
| bluemonday        | 336   | 0.002 ms        | 0.004 ms      | 0.029 ms (0080.html, 348 bytes)                    |
| cerberus          | 3     | 0.34 ms         | 0.34 ms       | 0.47 ms (0002.html, 40 KB)                         |
| ckeditor          | 6     | 0.005 ms        | 0.005 ms      | 0.006 ms (0001.html, 50 bytes)                     |
| ckeditor-paste    | 173   | 0.029 ms        | 0.071 ms      | 0.65 ms (0139.html, 34 KB)                         |
| dompurify         | 208   | 0.008 ms        | 0.009 ms      | 0.055 ms (0013.html, 886 bytes)                    |
| html5lib          | 69    | 0.005 ms        | 0.006 ms      | 0.012 ms (0043.html, 243 bytes)                    |
| html5sec          | 148   | 0.007 ms        | 0.010 ms      | 0.075 ms (0144.html, 1 KB)                         |
| mailchimp         | 44    | 0.14 ms         | 0.14 ms       | 0.33 ms (0001.html, 79 KB)                         |
| mdn               | 102   | 0.012 ms        | 0.016 ms      | 0.15 ms (0086.html, 9 KB)                          |
| owasp             | 95    | 0.004 ms        | 0.005 ms      | 0.014 ms (0009.html, 151 bytes)                    |
| owasp-java        | 286   | 0.006 ms        | 0.006 ms      | 0.028 ms (0271.html, 348 bytes)                    |
| payloads          | 1,047 | 0.005 ms        | 0.006 ms      | 0.059 ms (0709.html, 968 bytes)                    |
| portswigger       | 461   | 0.005 ms        | 0.007 ms      | 0.028 ms (0403.html, 879 bytes)                    |
| tinymce           | 11    | 0.012 ms        | 0.012 ms      | 0.023 ms (0001.html, 145 bytes)                    |
| wikipedia         | 6     | 6.95 ms         | 7.14 ms       | 14.2 ms (0006.html, 1.1 MB)                        |
| wordpress         | 113   | 0.056 ms        | 0.089 ms      | 0.71 ms (0072.html, 29 KB)                         |
| wpt               | 42    | 0.006 ms        | 0.006 ms      | 0.010 ms (0008.html, 55 bytes)                     |
| fixtures/accept   | 7     | 0.013 ms        | 0.027 ms      | 0.064 ms (html5-elements.html, 3 KB)               |
| fixtures/reject   | 10    | 0.008 ms        | 0.008 ms      | 0.013 ms (attribute-not-allowed-1.html, 235 bytes) |
| fixtures/tinymce4 | 2     | 0.13 ms         | 0.13 ms       | 0.15 ms (input.html, 2 KB)                         |

```

## PHP 8.1

```text
PHP 8.1.34, PCRE 10.39 2021-10-29, Linux 4.18.0-553.141.2.el8_10.x86_64, Intel(R) Xeon(R) E-2386G CPU @ 3.50GHz
opcache on, JIT off, xdebug off, HTMLPurifier 4.19.0

## Generated content

| Shape                 | Size   | Check time | Fast path off | Throughput | Peak memory added | HTMLPurifier time | HTMLPurifier memory |
|-----------------------|--------|------------|---------------|------------|-------------------|-------------------|---------------------|
| plain paragraphs      | 1 KB   | 0.002 ms   | 0.009 ms      | 480 MB/s   | 504 KB            | 0.10 ms           | 912 KB              |
| plain paragraphs      | 10 KB  | 0.010 ms   | 0.069 ms      | 958 MB/s   | 504 KB            | 0.40 ms           | 912 KB              |
| plain paragraphs      | 100 KB | 0.090 ms   | 0.66 ms       | 1090 MB/s  | 504 KB            | 3.28 ms           | 3.4 MB              |
| plain paragraphs      | 1 MB   | 0.95 ms    | 6.73 ms       | 1058 MB/s  | 2.5 MB            | 34.8 ms           | 22.9 MB             |
| a tag every few words | 1 KB   | 0.003 ms   | 0.077 ms      | 380 MB/s   | 504 KB            | 0.42 ms           | 1.3 MB              |
| a tag every few words | 10 KB  | 0.016 ms   | 0.60 ms       | 611 MB/s   | 504 KB            | 2.70 ms           | 3.5 MB              |
| a tag every few words | 100 KB | 0.19 ms    | 6.06 ms       | 517 MB/s   | 504 KB            | 25.7 ms           | 15.1 MB             |
| a tag every few words | 1 MB   | 2.02 ms    | 59.7 ms       | 494 MB/s   | 2.5 MB            | 303.2 ms          | 130.3 MB            |
| Word paste            | 1 KB   | 0.004 ms   | 0.021 ms      | 276 MB/s   | 504 KB            | 0.16 ms           | 1.3 MB              |
| Word paste            | 10 KB  | 0.028 ms   | 0.31 ms       | 395 MB/s   | 504 KB            | 1.59 ms           | 3.4 MB              |
| Word paste            | 100 KB | 0.24 ms    | 2.78 ms       | 420 MB/s   | 504 KB            | 13.4 ms           | 6.3 MB              |
| Word paste            | 1 MB   | 2.35 ms    | 28.1 ms       | 426 MB/s   | 2.5 MB            | 150.3 ms          | 38.6 MB             |

## Typical pages

| Page                     | Size  | Tags per KB | Check time | Fast path off | Throughput | Peak memory added | HTMLPurifier time | HTMLPurifier memory |
|--------------------------|-------|-------------|------------|---------------|------------|-------------------|-------------------|---------------------|
| news item, 250 words     | 2 KB  | 12          | 0.005 ms   | 0.056 ms      | 450 MB/s   | 504 KB            | 0.33 ms           | 1.4 MB              |
| home page section        | 2 KB  | 20          | 0.004 ms   | 0.083 ms      | 420 MB/s   | 504 KB            | 0.46 ms           | 1.3 MB              |
| blog post, 1,000 words   | 8 KB  | 10          | 0.012 ms   | 0.18 ms       | 705 MB/s   | 504 KB            | 0.94 ms           | 3.5 MB              |
| article, 1,800 words     | 15 KB | 10          | 0.020 ms   | 0.31 ms       | 741 MB/s   | 504 KB            | 1.51 ms           | 3.5 MB              |
| FAQ, 40 questions        | 10 KB | 12          | 0.015 ms   | 0.26 ms       | 695 MB/s   | 504 KB            | 1.27 ms           | 3.2 MB              |
| policy page, 5,000 words | 36 KB | 5           | 0.036 ms   | 0.36 ms       | 986 MB/s   | 504 KB            | 1.88 ms           | 3.4 MB              |
| newsletter, 12 blocks    | 9 KB  | 10          | 0.021 ms   | 0.29 ms       | 435 MB/s   | 504 KB            | 1.57 ms           | 3.6 MB              |

## Hostile inputs

| Input                               | Size   | Result                  | Check time | Peak memory added |
|-------------------------------------|--------|-------------------------|------------|-------------------|
| 1 MB of <                           | 1.0 MB | accepted                | 2.11 ms    | 2.5 MB            |
| 1 MB unclosed attribute value       | 1.0 MB | `unclosed-markup`       | 1.21 ms    | 504 KB            |
| 10,000 attributes on one tag        | 97 KB  | `attribute-not-allowed` | 5.77 ms    | 2.5 MB            |
| 100,000 levels of nesting           | 1.0 MB | accepted                | 2.34 ms    | 2.5 MB            |
| 1 MB <style> block                  | 1.0 MB | accepted                | 2.12 ms    | 2.5 MB            |
| 1 MB chain of entities in one value | 1.1 MB | accepted                | 54.1 ms    | 2.5 MB            |
| 1 MB entity name in one value       | 1.0 MB | accepted                | 1.03 ms    | 4.6 MB            |
| 1 MB of short comments              | 1.0 MB | accepted                | 107.0 ms   | 504 KB            |

## Corpus and fixtures: 3,187 files, 6.9 MB, checked in 0.087 s (36,592 files/s, 79 MB/s)

| Size            | Files | Median per file | Mean per file | Slowest file                    |
|-----------------|-------|-----------------|---------------|---------------------------------|
| under 1 KB      | 2,855 | 0.005 ms        | 0.006 ms      | 0.056 ms (0954.html, 972 bytes) |
| 1 KB to 10 KB   | 229   | 0.029 ms        | 0.046 ms      | 0.25 ms (0025.html, 6 KB)       |
| 10 KB to 100 KB | 97    | 0.15 ms         | 0.19 ms       | 0.62 ms (0139.html, 34 KB)      |
| over 100 KB     | 6     | 6.62 ms         | 6.84 ms       | 13.5 ms (0006.html, 1.1 MB)     |

| Source            | Files | Median per file | Mean per file | Slowest file                                       |
|-------------------|-------|-----------------|---------------|----------------------------------------------------|
| bleach            | 18    | 0.005 ms        | 0.005 ms      | 0.008 ms (0006.html, 42 bytes)                     |
| bluemonday        | 336   | 0.002 ms        | 0.004 ms      | 0.028 ms (0080.html, 348 bytes)                    |
| cerberus          | 3     | 0.33 ms         | 0.31 ms       | 0.43 ms (0002.html, 40 KB)                         |
| ckeditor          | 6     | 0.005 ms        | 0.005 ms      | 0.006 ms (0001.html, 50 bytes)                     |
| ckeditor-paste    | 173   | 0.028 ms        | 0.067 ms      | 0.62 ms (0139.html, 34 KB)                         |
| dompurify         | 208   | 0.007 ms        | 0.009 ms      | 0.052 ms (0013.html, 886 bytes)                    |
| html5lib          | 69    | 0.005 ms        | 0.005 ms      | 0.012 ms (0042.html, 140 bytes)                    |
| html5sec          | 148   | 0.007 ms        | 0.009 ms      | 0.073 ms (0144.html, 1 KB)                         |
| mailchimp         | 44    | 0.13 ms         | 0.14 ms       | 0.29 ms (0001.html, 79 KB)                         |
| mdn               | 102   | 0.012 ms        | 0.015 ms      | 0.14 ms (0086.html, 9 KB)                          |
| owasp             | 95    | 0.004 ms        | 0.005 ms      | 0.014 ms (0009.html, 151 bytes)                    |
| owasp-java        | 286   | 0.005 ms        | 0.006 ms      | 0.028 ms (0271.html, 348 bytes)                    |
| payloads          | 1,047 | 0.005 ms        | 0.005 ms      | 0.056 ms (0954.html, 972 bytes)                    |
| portswigger       | 461   | 0.005 ms        | 0.006 ms      | 0.026 ms (0403.html, 879 bytes)                    |
| tinymce           | 11    | 0.012 ms        | 0.012 ms      | 0.023 ms (0001.html, 145 bytes)                    |
| wikipedia         | 6     | 6.62 ms         | 6.80 ms       | 13.5 ms (0006.html, 1.1 MB)                        |
| wordpress         | 113   | 0.052 ms        | 0.080 ms      | 0.61 ms (0072.html, 29 KB)                         |
| wpt               | 42    | 0.005 ms        | 0.006 ms      | 0.010 ms (0008.html, 55 bytes)                     |
| fixtures/accept   | 7     | 0.013 ms        | 0.026 ms      | 0.061 ms (html5-elements.html, 3 KB)               |
| fixtures/reject   | 10    | 0.008 ms        | 0.008 ms      | 0.013 ms (attribute-not-allowed-1.html, 235 bytes) |
| fixtures/tinymce4 | 2     | 0.12 ms         | 0.12 ms       | 0.14 ms (input.html, 2 KB)                         |

```

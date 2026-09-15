#!/usr/bin/env bash
# Runs check-speed.php with opcache on, xdebug off, and a memory and process cap on every
# process it starts, so a bug in the script cannot use up the machine. Arguments pass through:
#
#     benchmarks/run.sh
#     benchmarks/run.sh --corpus=corpus
#     benchmarks/run.sh --sanitizer=/path/to/vendor/autoload.php
#     PHP=/opt/plesk/php/8.5/bin/php benchmarks/run.sh   # a PHP binary that is not on the PATH
set -euo pipefail
cd "$(dirname "$0")/.."

# The process cap counts every thread this user owns, so it starts from the current total.
threads=$(ps -u "$(id -u)" -o nlwp= | awk '{s+=$1} END {print s}')
ulimit -u $((threads + 200))
ulimit -v 4000000   # 4 GB of address space per process

exec "${PHP:-php}" -d opcache.enable_cli=1 -d xdebug.mode=off -d memory_limit=1G benchmarks/check-speed.php "$@"

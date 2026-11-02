#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
php --version
find h5p-sensei-bridge tests -name '*.php' -print0 | xargs -0 -n1 php -l
php tests/progress.php
php tests/rest.php
php tests/durability.php

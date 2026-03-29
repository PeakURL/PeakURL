#!/bin/sh
set -eu

RELEASE_DIR="/app/release/peakurl"

mkdir -p "$RELEASE_DIR"

exec php -S 0.0.0.0:8080 -t "$RELEASE_DIR" /app/docker/test/router.php

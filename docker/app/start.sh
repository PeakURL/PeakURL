#!/bin/sh
set -eu

cd /app/app

CURRENT_HASH="$(
    {
        sha1sum composer.json
        sha1sum composer.lock
    } | sha1sum | awk '{print $1}'
)"
STAMP_FILE="vendor/.composer-state.hash"

if [ ! -d vendor ] || [ ! -f "$STAMP_FILE" ] || [ "$(cat "$STAMP_FILE" 2>/dev/null || true)" != "$CURRENT_HASH" ]; then
    composer install --no-interaction
    mkdir -p vendor
    printf '%s' "$CURRENT_HASH" > "$STAMP_FILE"
fi

composer dump-autoload --no-interaction >/dev/null

php bin/setup-database.php

exec php -S 0.0.0.0:8000 -t public

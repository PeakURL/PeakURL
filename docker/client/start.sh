#!/bin/sh
set -eu

cd /app

CURRENT_HASH="$(sha1sum package-lock.json | awk '{print $1}')"
STAMP_FILE="node_modules/.package-lock.hash"
ROLLDOWN_BINDING="node_modules/@rolldown/binding-linux-x64-musl"

if [ ! -d node_modules ] || [ ! -f "$STAMP_FILE" ] || [ "$(cat "$STAMP_FILE" 2>/dev/null || true)" != "$CURRENT_HASH" ] || [ ! -d "$ROLLDOWN_BINDING" ]; then
    npm ci --legacy-peer-deps
    printf '%s' "$CURRENT_HASH" > "$STAMP_FILE"
fi

exec npm run dev -- --host 0.0.0.0 --port 5173

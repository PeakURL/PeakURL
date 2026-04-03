#!/bin/sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
UI_BUILD_DIR="$ROOT_DIR/build"
RELEASE_ROOT="$ROOT_DIR/release"
RELEASE_DIR="$RELEASE_ROOT/peakurl"
VERSION=$(tr -d '\n' < "$ROOT_DIR/.version" 2>/dev/null || node -e "console.log(JSON.parse(require('fs').readFileSync('package.json', 'utf8')).version)" 2>/dev/null || printf "0.0.0")
ARCHIVE_PATH="$RELEASE_ROOT/peakurl-$VERSION.zip"
METADATA_PATH="$RELEASE_ROOT/release-metadata.json"
RESTORE_COMPOSER_DEPS=0

require_command() {
    if ! command -v "$1" >/dev/null 2>&1; then
        printf 'Missing required command: %s\n' "$1" >&2
        exit 1
    fi
}

require_command npm
require_command composer
require_command zip
require_command php

restore_composer_dependencies() {
	if [ "$RESTORE_COMPOSER_DEPS" -ne 1 ]; then
		return
	fi

	printf 'Restoring local Composer dev dependencies...\n'
	(
		cd "$ROOT_DIR/app"
		composer install --no-interaction
	)
}

trap restore_composer_dependencies EXIT HUP INT TERM

cd "$ROOT_DIR"

printf 'Building React dashboard UI...\n'
npm run build

printf 'Refreshing Composer autoload and production dependencies...\n'
RESTORE_COMPOSER_DEPS=1
(
    cd app
    composer install --no-dev --optimize-autoloader --no-interaction
)

printf 'Assembling release package...\n'
mkdir -p "$RELEASE_DIR" "$RELEASE_ROOT"
find "$RELEASE_ROOT" -maxdepth 1 \( -name 'peakurl-*.zip' -o -name 'peakurl-*.zip.sha256' \) -exec rm -f {} +
find "$RELEASE_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf {} +
mkdir -p "$RELEASE_DIR/app/bin"

cp -R site/. "$RELEASE_DIR/"
if [ -d "$ROOT_DIR/content/languages" ]; then
    mkdir -p "$RELEASE_DIR/content/languages"
    cp -R "$ROOT_DIR/content/languages/." "$RELEASE_DIR/content/languages/"
fi
cp -R "$UI_BUILD_DIR/assets" "$RELEASE_DIR/assets"
cp "$UI_BUILD_DIR/index.html" "$RELEASE_DIR/app.html"
cp "$ROOT_DIR/.version" "$RELEASE_DIR/.version"
cp "$ROOT_DIR/LICENSE" "$RELEASE_DIR/LICENSE"
cp "$ROOT_DIR/CREDITS.txt" "$RELEASE_DIR/CREDITS.txt"

cp -R app/api "$RELEASE_DIR/app/api"
cp -R app/controllers "$RELEASE_DIR/app/controllers"
cp -R app/http "$RELEASE_DIR/app/http"
cp -R app/includes "$RELEASE_DIR/app/includes"
cp -R app/public "$RELEASE_DIR/app/public"
cp -R app/database "$RELEASE_DIR/app/database"
cp -R app/services "$RELEASE_DIR/app/services"
cp -R app/traits "$RELEASE_DIR/app/traits"
cp -R app/utils "$RELEASE_DIR/app/utils"
cp -R app/vendor "$RELEASE_DIR/app/vendor"
cp app/store.php "$RELEASE_DIR/app/store.php"
cp app/bin/setup-database.php "$RELEASE_DIR/app/bin/setup-database.php"
cp app/bin/update-geoip.php "$RELEASE_DIR/app/bin/update-geoip.php"
cp app/composer.json "$RELEASE_DIR/app/composer.json"
cp app/composer.lock "$RELEASE_DIR/app/composer.lock"
cp app/.env.example "$RELEASE_DIR/app/.env.example"

rm -f "$RELEASE_DIR/app/.env"

printf 'Creating zip archive...\n'
rm -f "$ARCHIVE_PATH"
(
    cd "$RELEASE_DIR"
    zip -qr "$ARCHIVE_PATH" .
)

printf 'Writing release metadata...\n'
CHECKSUM=$(php -r '$hash = hash_file("sha256", $argv[1]); if (false === $hash) { fwrite(STDERR, "Unable to hash release archive.\n"); exit(1); } echo $hash;' "$ARCHIVE_PATH")
cat > "$METADATA_PATH" <<EOF
{
  "product": "peakurl",
  "version": "$VERSION",
  "generatedAt": "$(date -u +"%Y-%m-%dT%H:%M:%SZ")",
  "archiveName": "$(basename "$ARCHIVE_PATH")",
  "archiveChecksumSha256": "$CHECKSUM",
  "manifestUrl": "https://api.peakurl.org/v1/update",
  "downloadUrl": "https://releases.peakurl.org/latest.zip",
  "packageUrl": "https://releases.peakurl.org/package/peakurl-$VERSION.zip"
}
EOF

restore_composer_dependencies
trap - EXIT HUP INT TERM

printf 'Release package ready:\n%s\n' "$ARCHIVE_PATH"
printf 'Release metadata:\n%s\n' "$METADATA_PATH"

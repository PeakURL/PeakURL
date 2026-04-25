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

require_file() {
	file_path=$1

	if [ ! -f "$file_path" ]; then
		printf 'Missing required file: %s\n' "$file_path" >&2
		exit 1
	fi
}

require_command npm
require_command composer
require_command zip
require_command php
require_command rsync

copy_release_tree() {
	source_dir=$1
	destination_dir=$2
	shift 2

	mkdir -p "$destination_dir"
	rsync -a "$@" "$source_dir"/ "$destination_dir"/
}

copy_release_language_packs() {
	source_dir=$1
	destination_dir=$2

	if [ ! -d "$source_dir" ]; then
		return
	fi

	copy_release_tree \
		"$source_dir" \
		"$destination_dir" \
		--prune-empty-dirs \
		--include='*/' \
		--include='*.json' \
		--include='*.mo' \
		--include='*.pot' \
		--exclude='*'
}

remove_release_placeholder_files() {
	release_dir=$1

	find "$release_dir" -name '.DS_Store' -type f -delete
	find "$release_dir" -name '.gitkeep' -type f -delete
}

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

require_file "$ROOT_DIR/app/public/default-favicon.png"
require_file "$ROOT_DIR/app/public/default-site.webmanifest"

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

copy_release_tree "$ROOT_DIR/site" "$RELEASE_DIR" \
	--exclude='.DS_Store' \
	--exclude='.gitkeep'
copy_release_language_packs "$ROOT_DIR/content/languages" "$RELEASE_DIR/content/languages"

copy_release_tree "$UI_BUILD_DIR" "$RELEASE_DIR" \
	--exclude='index.html' \
	--exclude='.DS_Store'
cp "$UI_BUILD_DIR/index.html" "$RELEASE_DIR/app.html"

cp "$ROOT_DIR/.version" "$RELEASE_DIR/.version"
cp "$ROOT_DIR/LICENSE" "$RELEASE_DIR/LICENSE"
cp "$ROOT_DIR/CREDITS.txt" "$RELEASE_DIR/CREDITS.txt"

copy_release_tree "$ROOT_DIR/app" "$RELEASE_DIR/app" \
	--exclude='.DS_Store' \
	--exclude='.gitkeep' \
	--exclude='.env'

rm -f "$RELEASE_DIR/app/.env"
remove_release_placeholder_files "$RELEASE_DIR"

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

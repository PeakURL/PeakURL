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
require_command zipinfo
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

verify_release_package() {
	printf 'Verifying staged release package contents...\n'

	for req in \
		index.php \
		load.php \
		index.html \
		readme.html \
		.htaccess \
		config-sample.php \
		install.php \
		setup-config.php \
		database-error.php \
		.version \
		LICENSE \
		CREDITS.txt \
		api/index.php \
		assets/default-favicon.png \
		assets/default-site.webmanifest \
		content/index.php \
		content/landing-page.html \
		database/schema.sql \
		core \
		features \
		http \
		services \
		utils \
		vendor/autoload.php; do
		if [ ! -e "$RELEASE_DIR/$req" ]; then
			printf 'Verification failure: missing required entry: %s\n' "$req" >&2
			exit 1
		fi
	done

	for forbidden in \
		server \
		site \
		app.html \
		tests \
		phpunit.xml \
		.phpunit.cache \
		composer.json \
		composer.lock; do
		if [ -e "$RELEASE_DIR/$forbidden" ]; then
			printf 'Verification failure: forbidden entry present in release tree: %s\n' "$forbidden" >&2
			exit 1
		fi
	done

	if find "$RELEASE_DIR" -name '.env*' -o -name '.git*' | grep -q .; then
		printf 'Verification failure: development dotfiles present in release tree\n' >&2
		exit 1
	fi
}

verify_release_archive() {
	printf 'Verifying release archive entries...\n'
	ARCHIVE_ENTRIES=$(zipinfo -1 "$ARCHIVE_PATH")

	if printf '%s\n' "$ARCHIVE_ENTRIES" | grep -E '^(server/|site/)'; then
		printf 'Verification failure: archive contains forbidden server/ or site/ entries\n' >&2
		exit 1
	fi

	if printf '%s\n' "$ARCHIVE_ENTRIES" | grep -E '^(tests/|phpunit\.xml|\.phpunit|\.env|composer\.)'; then
		printf 'Verification failure: archive contains forbidden test or development entries\n' >&2
		exit 1
	fi

	if printf '%s\n' "$ARCHIVE_ENTRIES" | grep -qx 'README.html'; then
		printf 'Verification failure: archive contains uppercase README.html instead of lowercase readme.html\n' >&2
		exit 1
	fi

	if printf '%s\n' "$ARCHIVE_ENTRIES" | grep -qx 'app.html'; then
		printf 'Verification failure: archive contains legacy app.html instead of index.html\n' >&2
		exit 1
	fi

	if ! printf '%s\n' "$ARCHIVE_ENTRIES" | grep -qx 'readme.html'; then
		printf 'Verification failure: archive missing lowercase readme.html\n' >&2
		exit 1
	fi

	if ! printf '%s\n' "$ARCHIVE_ENTRIES" | grep -qx 'index.html'; then
		printf 'Verification failure: archive missing index.html\n' >&2
		exit 1
	fi

	if ! printf '%s\n' "$ARCHIVE_ENTRIES" | grep -qx 'api/index.php'; then
		printf 'Verification failure: archive missing api/index.php\n' >&2
		exit 1
	fi

	if ! printf '%s\n' "$ARCHIVE_ENTRIES" | grep -qx 'load.php'; then
		printf 'Verification failure: archive missing load.php\n' >&2
		exit 1
	fi

	if ! printf '%s\n' "$ARCHIVE_ENTRIES" | grep -qx 'assets/default-favicon.png'; then
		printf 'Verification failure: archive missing assets/default-favicon.png\n' >&2
		exit 1
	fi

	printf 'Release archive verification passed.\n'
}

restore_composer_dependencies() {
	if [ "$RESTORE_COMPOSER_DEPS" -ne 1 ]; then
		return
	fi

	printf 'Restoring local Composer dev dependencies...\n'
	(
		cd "$ROOT_DIR/server"
		composer install --no-interaction
	)
}

trap restore_composer_dependencies EXIT HUP INT TERM

cd "$ROOT_DIR"

require_file "$ROOT_DIR/server/public/default-favicon.png"
require_file "$ROOT_DIR/server/public/default-site.webmanifest"

printf 'Building React dashboard UI...\n'
npm run build

printf 'Refreshing Composer autoload and production dependencies...\n'
RESTORE_COMPOSER_DEPS=1
(
    cd server
    composer install --no-dev --optimize-autoloader --no-interaction
)

printf 'Compiling translation catalogs...\n'
npm run i18n:compile

printf 'Assembling release package...\n'
mkdir -p "$RELEASE_DIR" "$RELEASE_ROOT"
find "$RELEASE_ROOT" -maxdepth 1 \( -name 'peakurl-*.zip' -o -name 'peakurl-*.zip.sha256' \) -exec rm -f {} +
find "$RELEASE_DIR" -mindepth 1 -maxdepth 1 -exec rm -rf {} +

# 1. Production Root Entrypoints and Metadata
cp "$ROOT_DIR/index.php" "$RELEASE_DIR/index.php"
cp "$ROOT_DIR/load.php" "$RELEASE_DIR/load.php"
cp "$ROOT_DIR/.htaccess" "$RELEASE_DIR/.htaccess"
cp "$ROOT_DIR/config-sample.php" "$RELEASE_DIR/config-sample.php"
cp "$ROOT_DIR/install.php" "$RELEASE_DIR/install.php"
cp "$ROOT_DIR/setup-config.php" "$RELEASE_DIR/setup-config.php"
cp "$ROOT_DIR/database-error.php" "$RELEASE_DIR/database-error.php"
cp "$ROOT_DIR/readme.html" "$RELEASE_DIR/readme.html"
cp "$ROOT_DIR/.version" "$RELEASE_DIR/.version"
cp "$ROOT_DIR/LICENSE" "$RELEASE_DIR/LICENSE"
cp "$ROOT_DIR/CREDITS.txt" "$RELEASE_DIR/CREDITS.txt"

# 2. Client SPA Assets
cp "$UI_BUILD_DIR/index.html" "$RELEASE_DIR/index.html"
copy_release_tree "$UI_BUILD_DIR/assets" "$RELEASE_DIR/assets" \
	--exclude='.DS_Store'
cp "$ROOT_DIR/server/public/default-favicon.png" "$RELEASE_DIR/assets/default-favicon.png"
cp "$ROOT_DIR/server/public/default-site.webmanifest" "$RELEASE_DIR/assets/default-site.webmanifest"

# 3. Server Runtime Allowlist (Flattened into release root, excluding dev tests & caches)
for server_dir in api bin core database features http services templates utils vendor; do
	if [ -d "$ROOT_DIR/server/$server_dir" ]; then
		copy_release_tree "$ROOT_DIR/server/$server_dir" "$RELEASE_DIR/$server_dir" \
			--exclude='.DS_Store' \
			--exclude='.gitkeep' \
			--exclude='tests' \
			--exclude='Tests' \
			--exclude='*.cache'
	fi
done

# Copy API router entrypoint to api/index.php
cp "$ROOT_DIR/server/public/index.php" "$RELEASE_DIR/api/index.php"

# 4. Persistent Content Baseline (Safe templates & protection files)
mkdir -p "$RELEASE_DIR/content/cache" "$RELEASE_DIR/content/plugins" "$RELEASE_DIR/content/uploads/geoip"
cp "$ROOT_DIR/content/index.php" "$RELEASE_DIR/content/index.php"
cp "$ROOT_DIR/content/cache/index.php" "$RELEASE_DIR/content/cache/index.php"
cp "$ROOT_DIR/content/cache/.htaccess" "$RELEASE_DIR/content/cache/.htaccess"
cp "$ROOT_DIR/content/plugins/index.php" "$RELEASE_DIR/content/plugins/index.php"
cp "$ROOT_DIR/content/uploads/index.php" "$RELEASE_DIR/content/uploads/index.php"
cp "$ROOT_DIR/content/uploads/geoip/index.php" "$RELEASE_DIR/content/uploads/geoip/index.php"
copy_release_language_packs "$ROOT_DIR/content/languages" "$RELEASE_DIR/content/languages"
cp "$ROOT_DIR/content/landing-page.html" "$RELEASE_DIR/content/landing-page.html"

remove_release_placeholder_files "$RELEASE_DIR"
verify_release_package

printf 'Creating zip archive...\n'
rm -f "$ARCHIVE_PATH"
(
    cd "$RELEASE_DIR"
    zip -qr "$ARCHIVE_PATH" .
)

verify_release_archive

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

#!/bin/sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
CERT_DIR="$ROOT_DIR/docker/proxy/certs"
CERT_FILE="$CERT_DIR/peakurl-local.pem"
KEY_FILE="$CERT_DIR/peakurl-local-key.pem"
HOSTS_BEGIN="# >>> PeakURL local domains >>>"
HOSTS_END="# <<< PeakURL local domains <<<"
HOSTS_LINE="127.0.0.1 peakurl.dev api.peakurl.dev peakurl.test"

require_command() {
	if ! command -v "$1" >/dev/null 2>&1; then
		printf 'Missing required command: %s\n' "$1" >&2
		exit 1
	fi
}

write_hosts_file() {
	source_file=$1
	temp_file=$(mktemp)

	awk -v begin="$HOSTS_BEGIN" -v end="$HOSTS_END" '
		$0 == begin { skip = 1; next }
		$0 == end { skip = 0; next }
		skip != 1 { print }
	' "$source_file" > "$temp_file"

	{
		printf '%s\n' "$HOSTS_BEGIN"
		printf '%s\n' "$HOSTS_LINE"
		printf '%s\n' "$HOSTS_END"
	} >> "$temp_file"

	if [ "$(id -u)" -eq 0 ]; then
		cp "$temp_file" /etc/hosts
	else
		sudo cp "$temp_file" /etc/hosts
	fi

	rm -f "$temp_file"
}

require_command mkcert
require_command sudo

mkdir -p "$CERT_DIR"

printf 'Installing or refreshing the local mkcert CA...\n'
mkcert -install

printf 'Generating PeakURL local certificates...\n'
mkcert \
	-cert-file "$CERT_FILE" \
	-key-file "$KEY_FILE" \
	peakurl.dev \
	api.peakurl.dev \
	peakurl.test

printf 'Updating /etc/hosts entries for PeakURL local domains...\n'
write_hosts_file /etc/hosts

printf 'Local domain setup complete.\n'
printf 'Use these URLs after docker compose starts:\n'
printf '  UI:          https://peakurl.dev\n'
printf '  API:         https://api.peakurl.dev\n'
printf '  Release test:https://peakurl.test\n'
printf '  phpMyAdmin:  http://phpmyadmin.localhost\n'
printf '\n'
printf 'Next steps:\n'
printf '  1. docker compose up --build\n'
printf '  2. npm run release:build\n'
printf '  3. Open https://peakurl.test to test the packaged installer locally\n'
printf '\n'
printf 'Release-test database values:\n'
printf '  Host: db\n'
printf '  Name: peakurl_test\n'
printf '  Username: root\n'
printf '  Password: root\n'

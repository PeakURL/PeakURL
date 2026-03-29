#!/bin/sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
ENV_FILE="$ROOT_DIR/.env"
RELEASE_DIR="$ROOT_DIR/release/peakurl"
REMOTE_PATH="."

require_command() {
	if ! command -v "$1" >/dev/null 2>&1; then
		printf 'Missing required command: %s\n' "$1" >&2
		exit 1
	fi
}

require_env() {
	name=$1
	value=$(printenv "$name" || true)

	if [ -z "$value" ]; then
		printf 'Missing required env value: %s\n' "$name" >&2
		exit 1
	fi
}

if [ ! -f "$ENV_FILE" ]; then
	printf 'Missing .env file at %s\n' "$ENV_FILE" >&2
	exit 1
fi

if [ ! -d "$RELEASE_DIR" ]; then
	printf 'Missing release folder at %s\nRun `npm run release:build` first.\n' "$RELEASE_DIR" >&2
	exit 1
fi

if [ -f "$RELEASE_DIR/config.php" ]; then
	printf 'Refusing to upload release/peakurl/config.php.\nRemove the generated file and rebuild the release first.\n' >&2
	exit 1
fi

require_command sshpass
require_command sftp

set -a
. "$ENV_FILE"
set +a

require_env SFTP_HOST
require_env SFTP_PORT
require_env SFTP_USERNAME
require_env SFTP_PASSWORD

if [ -n "${SFTP_REMOTE_PATH:-}" ]; then
	REMOTE_PATH=$SFTP_REMOTE_PATH
fi

BATCH_FILE=$(mktemp)

cleanup() {
	rm -f "$BATCH_FILE"
}

trap cleanup EXIT HUP INT TERM

{
	printf 'cd %s\n' "$REMOTE_PATH"
	printf 'lcd %s\n' "$RELEASE_DIR"

	find "$RELEASE_DIR" -type d | LC_ALL=C awk '
		BEGIN {
			root = "";
		}
		NR == 1 {
			root = $0;
			next;
		}
		{
			relative_path = substr( $0, length( root ) + 2 );
			depth = gsub( /\//, "/", relative_path );
			printf "%06d %s\n", depth, relative_path;
		}
	' | LC_ALL=C sort -r | while IFS= read -r line; do
		relative_path=${line#* }

		case "$relative_path" in
			content|content/*|app|app/vendor)
				continue
				;;
		esac

		printf -- '-rm %s/*\n' "$relative_path"
	done

	find "$RELEASE_DIR/app" -maxdepth 1 -type f | LC_ALL=C sort | while IFS= read -r file_path; do
		relative_path=${file_path#"$RELEASE_DIR"/}
		printf -- '-rm %s\n' "$relative_path"
	done

	find "$RELEASE_DIR/app" -type d | LC_ALL=C awk '
		BEGIN {
			root = "";
		}
		NR == 1 {
			root = $0;
			next;
		}
		{
			relative_path = substr( $0, length( root ) + 2 );
			depth = gsub( /\//, "/", relative_path );
			printf "%06d %s\n", depth, relative_path;
		}
	' | LC_ALL=C sort -r | while IFS= read -r line; do
		relative_path=${line#* }
		legacy_path=${relative_path/app/core}

		case "$legacy_path" in
			core|core/vendor)
				continue
				;;
		esac

		printf -- '-rm %s/*\n' "$legacy_path"
	done

	find "$RELEASE_DIR/app" -maxdepth 1 -type f | LC_ALL=C sort | while IFS= read -r file_path; do
		relative_path=${file_path#"$RELEASE_DIR"/}
		legacy_path=${relative_path/app/core}
		printf -- '-rm %s\n' "$legacy_path"
	done

	printf -- '-rm %s\n' 'core/.env'

	find "$RELEASE_DIR/app" -type d | LC_ALL=C awk '
		BEGIN {
			root = "";
		}
		NR == 1 {
			root = $0;
			next;
		}
		{
			relative_path = substr( $0, length( root ) + 2 );
			depth = gsub( /\//, "/", relative_path );
			printf "%06d %s\n", depth, relative_path;
		}
	' | LC_ALL=C sort -r | while IFS= read -r line; do
		relative_path=${line#* }
		legacy_path=${relative_path/app/core}
		printf -- '-rmdir %s\n' "$legacy_path"
	done

	printf -- '-rmdir %s\n' 'core/vendor'
	printf -- '-rmdir %s\n' 'core'

	find "$RELEASE_DIR" -maxdepth 1 -type f | LC_ALL=C sort | while IFS= read -r file_path; do
		relative_path=${file_path#"$RELEASE_DIR"/}

		if [ "$relative_path" = "config.php" ]; then
			continue
		fi

		printf -- '-rm %s\n' "$relative_path"
	done

	find "$RELEASE_DIR" -type d | LC_ALL=C sort | while IFS= read -r directory; do
		relative_path=${directory#"$RELEASE_DIR"/}

		if [ "$directory" = "$RELEASE_DIR" ]; then
			continue
		fi

		printf -- '-mkdir %s\n' "$relative_path"
	done

	find "$RELEASE_DIR" -type f | LC_ALL=C sort | while IFS= read -r file_path; do
		relative_path=${file_path#"$RELEASE_DIR"/}
		printf 'put %s %s\n' "$relative_path" "$relative_path"
	done
} > "$BATCH_FILE"

printf 'Uploading %s to %s@%s:%s\n' "$RELEASE_DIR" "$SFTP_USERNAME" "$SFTP_HOST" "$REMOTE_PATH"

SSHPASS=$SFTP_PASSWORD sshpass -e sftp \
	-oBatchMode=no \
	-oStrictHostKeyChecking=accept-new \
	-P "$SFTP_PORT" \
	-b "$BATCH_FILE" \
	"$SFTP_USERNAME@$SFTP_HOST"

printf 'Release upload completed.\n'

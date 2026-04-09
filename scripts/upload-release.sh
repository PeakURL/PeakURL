#!/bin/sh
set -eu

ROOT_DIR=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
ENV_FILE="$ROOT_DIR/.env"
RELEASE_ROOT="$ROOT_DIR/release"
VERSION=$(tr -d '\n' < "$ROOT_DIR/.version" 2>/dev/null || node -e "console.log(JSON.parse(require('fs').readFileSync('package.json', 'utf8')).version)" 2>/dev/null || printf "0.0.0")
ARCHIVE_PATH="$RELEASE_ROOT/peakurl-$VERSION.zip"
REMOTE_ARCHIVE_NAME=".peakurl-release-$VERSION.zip"

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

validate_ssh_destination() {
	value=$1

	case "$value" in
		ssh\ *)
			printf 'SSH_DESTINATION must be the SSH alias only, for example `peakurl-user`, not `ssh peakurl-user`.\n' >&2
			exit 1
			;;
		*" "*|*"	"*)
			printf 'SSH_DESTINATION must not contain spaces. Use the SSH alias only, for example `peakurl-user`.\n' >&2
			exit 1
			;;
	esac
}

quote_for_sh() {
	printf "'%s'" "$(printf '%s' "$1" | sed "s/'/'\\\\''/g")"
}

if [ ! -f "$ENV_FILE" ]; then
	printf 'Missing .env file at %s\n' "$ENV_FILE" >&2
	exit 1
fi

if [ ! -f "$ARCHIVE_PATH" ]; then
	printf 'Missing release archive at %s\nRun `npm run release:build` first.\n' "$ARCHIVE_PATH" >&2
	exit 1
fi

require_command ssh
require_command scp

set -a
. "$ENV_FILE"
set +a

require_env SSH_DESTINATION
require_env SSH_REMOTE_PATH

validate_ssh_destination "$SSH_DESTINATION"

SSH_TARGET="$SSH_DESTINATION"
REMOTE_ARCHIVE_PATH="$SSH_REMOTE_PATH/$REMOTE_ARCHIVE_NAME"

REMOTE_PREPARE_SCRIPT=$(cat <<EOF
set -eu
mkdir -p $(quote_for_sh "$SSH_REMOTE_PATH")
EOF
)

REMOTE_DEPLOY_SCRIPT=$(cat <<EOF
set -eu
cd $(quote_for_sh "$SSH_REMOTE_PATH")

if ! command -v unzip >/dev/null 2>&1; then
	printf 'Missing required remote command: unzip\n' >&2
	exit 1
fi

find . -mindepth 1 -maxdepth 1 \\
	! -name config.php \\
	! -name content \\
	! -name $(quote_for_sh "$REMOTE_ARCHIVE_NAME") \\
	-exec rm -rf {} +

unzip -oq $(quote_for_sh "$REMOTE_ARCHIVE_NAME")
rm -f $(quote_for_sh "$REMOTE_ARCHIVE_NAME")
EOF
)

printf 'Uploading %s to %s:%s\n' "$ARCHIVE_PATH" "$SSH_TARGET" "$SSH_REMOTE_PATH"

ssh \
	-oBatchMode=yes \
	-oStrictHostKeyChecking=accept-new \
	"$SSH_TARGET" \
	"sh -lc $(quote_for_sh "$REMOTE_PREPARE_SCRIPT")"

scp \
	-oBatchMode=yes \
	-oStrictHostKeyChecking=accept-new \
	"$ARCHIVE_PATH" \
	"$SSH_TARGET:$REMOTE_ARCHIVE_PATH"

ssh \
	-oBatchMode=yes \
	-oStrictHostKeyChecking=accept-new \
	"$SSH_TARGET" \
	"sh -lc $(quote_for_sh "$REMOTE_DEPLOY_SCRIPT")"

printf 'Release upload completed.\n'

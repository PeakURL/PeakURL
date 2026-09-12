#!/bin/sh
set -eu

PEAKURL_URL="${PEAKURL_URL:-https://peakurl.dev}"
API_URL="${API_URL:-$PEAKURL_URL/api/v1}"
APP_URL="${APP_URL:-https://api.peakurl.dev/api/v1}"
IDENTIFIER="${PEAKURL_TEST_IDENTIFIER:-admin}"
PASSWORD="${PEAKURL_TEST_PASSWORD:?PEAKURL_TEST_PASSWORD is required}"

TMP_DIR="$(mktemp -d)"
COOKIE_JAR="$TMP_DIR/cookies.txt"
HEADERS_FILE="$TMP_DIR/headers.txt"
BODY_FILE="$TMP_DIR/body.txt"

cleanup() {
    rm -rf "$TMP_DIR"
}

trap cleanup EXIT

run_request() {
    method="$1"
    url="$2"
    shift 2

    rm -f "$HEADERS_FILE" "$BODY_FILE"

    curl -sS \
        -D "$HEADERS_FILE" \
        -o "$BODY_FILE" \
        -b "$COOKIE_JAR" \
        -c "$COOKIE_JAR" \
        -X "$method" \
        "$@" \
        "$url"
}

status_code() {
    awk 'BEGIN { code = "" } /^HTTP\// { code = $2 } END { print code }' "$HEADERS_FILE"
}

assert_status() {
    expected="$1"
    actual="$(status_code)"

    if [ "$actual" != "$expected" ]; then
        printf 'Expected HTTP %s, got %s\n' "$expected" "$actual" >&2
        printf '%s\n' '--- Headers ---' >&2
        cat "$HEADERS_FILE" >&2
        printf '%s\n' '--- Body ---' >&2
        cat "$BODY_FILE" >&2
        exit 1
    fi
}

assert_body_contains() {
    needle="$1"

    if ! grep -Fq "$needle" "$BODY_FILE"; then
        printf 'Response body did not contain expected text: %s\n' "$needle" >&2
        cat "$BODY_FILE" >&2
        exit 1
    fi
}

assert_header_contains() {
    needle="$1"

    if ! grep -Fiq "$needle" "$HEADERS_FILE"; then
        printf 'Response headers did not contain expected text: %s\n' "$needle" >&2
        cat "$HEADERS_FILE" >&2
        exit 1
    fi
}

printf '1. Checking unauthenticated session state...\n'
run_request GET "$API_URL/users/me"
assert_status 401

printf '2. Logging in with seeded owner account...\n'
run_request POST "$API_URL/auth/login" \
    -H "Content-Type: application/json" \
    -d "{\"identifier\":\"$IDENTIFIER\",\"password\":\"$PASSWORD\"}"
assert_status 200
assert_body_contains '"requiresTwoFactor": false'
assert_header_contains 'Set-Cookie: peakurl_session='
assert_header_contains 'HttpOnly'
assert_header_contains 'SameSite='

printf '3. Verifying authenticated session...\n'
run_request GET "$API_URL/users/me"
assert_status 200
assert_body_contains "\"username\": \"$IDENTIFIER\""

printf '4. Logging out and verifying session revocation...\n'
run_request POST "$API_URL/auth/logout"
assert_status 200
assert_body_contains '"loggedOut": true'
assert_header_contains 'Max-Age=0'

run_request GET "$API_URL/users/me"
assert_status 401

printf '5. Verifying secure-cookie behavior under HTTPS headers...\n'
run_request POST "$APP_URL/auth/login" \
    -H "X-Forwarded-Proto: https" \
    -H "Content-Type: application/json" \
    -d "{\"identifier\":\"$IDENTIFIER\",\"password\":\"$PASSWORD\"}"
assert_status 200
assert_header_contains 'Secure'

printf 'Auth smoke test passed.\n'

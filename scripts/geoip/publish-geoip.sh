#!/usr/bin/env bash
#
# GeoIP R2 database publisher and targeted Cloudflare cache purger.
#
# Enforces the strict mutable two-object sequential publishing order:
# 1. Authentic notices upload (LICENSE.txt, COPYRIGHT.txt)
# 2. Compressed archive upload (GeoLite2-City.mmdb.gz)
# 3. Archive R2 metadata and size verification
# 4. Targeted archive Cloudflare purge (verifying HTTP 200 AND JSON success: true)
# 5. Manifest upload (manifest.json)
# 6. Manifest R2 metadata and size verification
# 7. Targeted manifest Cloudflare purge (verifying HTTP 200 AND JSON success: true)
# 8. Optional post-CDN verification
#
# Fail-stop behavior: On any failure, execution terminates immediately.
# If archive purge fails, the manifest is NEVER uploaded.
# Never runs "Purge Everything" or purges application release archives.
#
# Note on CDN Propagation: Multi-PoP edge caches converge asynchronously;
# while archive upload precedes manifest upload in R2, client instances strictly
# verify SHA-256 against the manifest before activating, safely rejecting any
# transient mismatches during edge cache convergence.
#

set -euo pipefail

DIST_DIR=""
DRY_RUN=false
VERIFY_CDN=false
FORCE=false
MOCK_CMD_LOG=""
PUBLIC_BASE_URL="${PUBLIC_BASE_URL:-https://releases.peakurl.org}"

# Allow command overrides for testing and mocks.
AWS_CMD="${AWS_CMD:-aws}"
CURL_CMD="${CURL_CMD:-curl}"

print_usage() {
    cat <<EOF
Usage: scripts/geoip/publish-geoip.sh --dist-dir=<path> [options]

Required:
  --dist-dir=<path>        Directory containing prepared release files:
                           GeoLite2-City.mmdb.gz, manifest.json, LICENSE.txt, COPYRIGHT.txt

Options:
  --dry-run                Simulate the entire process without network calls
  --verify-cdn             Perform post-CDN GET checks after publication
  --force                  Force upload even if R2 already hosts an identical build
  --mock-cmd-log=<file>    Log executed command strings to file (for testing)
  --help, -h               Show this help message

Environment Variables (required for live publish):
  R2_ACCOUNT_ID            Cloudflare account ID for R2 endpoint
  R2_BUCKET                Target R2 bucket name
  AWS_ACCESS_KEY_ID        R2 S3-compatible access key ID
  AWS_SECRET_ACCESS_KEY    R2 S3-compatible secret access key
  CLOUDFLARE_API_TOKEN     Cloudflare API token with Zone.Cache Purge permission
  CLOUDFLARE_ZONE_ID       Cloudflare Zone ID for releases.peakurl.org
EOF
}

# Parse command line arguments.
for arg in "$@"; do
    case "$arg" in
        --dist-dir=*)
            DIST_DIR="${arg#*=}"
            ;;
        --dry-run)
            DRY_RUN=true
            ;;
        --verify-cdn)
            VERIFY_CDN=true
            ;;
        --force)
            FORCE=true
            ;;
        --mock-cmd-log=*)
            MOCK_CMD_LOG="${arg#*=}"
            ;;
        --help|-h)
            print_usage
            exit 0
            ;;
        *)
            echo "Error: Unknown argument: $arg" >&2
            print_usage >&2
            exit 1
            ;;
    esac
done

if [ -z "$DIST_DIR" ]; then
    echo "Error: --dist-dir=<path> is required." >&2
    print_usage >&2
    exit 1
fi

if [ "$DRY_RUN" = "false" ]; then
    if [ "$PUBLIC_BASE_URL" != "https://releases.peakurl.org" ]; then
        echo "Error: PUBLIC_BASE_URL must be 'https://releases.peakurl.org' for live production publishing." >&2
        exit 1
    fi
fi

log_cmd() {
    if [ -n "$MOCK_CMD_LOG" ]; then
        echo "$*" >> "$MOCK_CMD_LOG"
    fi
}

compute_sha256() {
    local target_file="$1"
    php -r "echo hash_file('sha256', \$argv[1]);" "$target_file"
}

# -----------------------------------------------------------------------------
# 1. Pre-flight Local File Verification
# -----------------------------------------------------------------------------

echo "=== PeakURL GeoIP R2 Publisher ==="
echo "Distribution directory: $DIST_DIR"
echo "Dry run mode:           $DRY_RUN"

ARCHIVE_FILE="$DIST_DIR/GeoLite2-City.mmdb.gz"
MANIFEST_FILE="$DIST_DIR/manifest.json"
LICENSE_FILE="$DIST_DIR/LICENSE.txt"
COPYRIGHT_FILE="$DIST_DIR/COPYRIGHT.txt"

for f in "$ARCHIVE_FILE" "$MANIFEST_FILE" "$LICENSE_FILE" "$COPYRIGHT_FILE"; do
    if [ ! -f "$f" ]; then
        echo "Error: Missing required release file: $f" >&2
        exit 1
    fi
done

LOCAL_ARCHIVE_SIZE=$(wc -c < "$ARCHIVE_FILE" | tr -d ' ')
LOCAL_ARCHIVE_SHA=$(compute_sha256 "$ARCHIVE_FILE")
LOCAL_MANIFEST_SIZE=$(wc -c < "$MANIFEST_FILE" | tr -d ' ')

# Validate manifest content against local archive.
VALIDATION_RESULT=$(php -r "
    \$manifest_path = \$argv[1];
    \$expected_size = (int) \$argv[2];
    \$expected_sha  = strtolower(trim(\$argv[3]));
    \$base_url      = rtrim(\$argv[4], '/');

    \$content = file_get_contents(\$manifest_path);
    \$data = json_decode(\$content, true);

    if (!is_array(\$data)) {
        fwrite(STDERR, 'Invalid JSON in manifest.json' . PHP_EOL);
        exit(1);
    }
    if ((\$data['schemaVersion'] ?? 0) !== 1) {
        fwrite(STDERR, 'Unsupported schemaVersion in manifest' . PHP_EOL);
        exit(1);
    }
    if ((\$data['edition'] ?? '') !== 'GeoLite2-City') {
        fwrite(STDERR, 'Unexpected edition in manifest' . PHP_EOL);
        exit(1);
    }
    if ((\$data['buildEpoch'] ?? 0) <= 0) {
        fwrite(STDERR, 'Invalid buildEpoch in manifest' . PHP_EOL);
        exit(1);
    }
    if ((\$data['sizeBytes'] ?? 0) !== \$expected_size) {
        fwrite(STDERR, 'Manifest sizeBytes (' . (\$data['sizeBytes'] ?? 0) . ') does not match local archive size (' . \$expected_size . ')' . PHP_EOL);
        exit(1);
    }
    if (strtolower(trim(\$data['checksumSha256'] ?? '')) !== \$expected_sha) {
        fwrite(STDERR, 'Manifest checksumSha256 does not match local archive SHA-256' . PHP_EOL);
        exit(1);
    }
    \$expected_download_url = \$base_url . '/geoip/GeoLite2-City.mmdb.gz';
    if ((\$data['downloadUrl'] ?? '') !== \$expected_download_url) {
        fwrite(STDERR, 'Manifest downloadUrl mismatch. Expected: ' . \$expected_download_url . ', got: ' . (\$data['downloadUrl'] ?? '') . PHP_EOL);
        exit(1);
    }
    echo json_encode([
        'edition' => \$data['edition'],
        'buildEpoch' => \$data['buildEpoch'],
        'sizeBytes' => \$data['sizeBytes'],
        'checksumSha256' => \$data['checksumSha256'],
    ]);
" "$MANIFEST_FILE" "$LOCAL_ARCHIVE_SIZE" "$LOCAL_ARCHIVE_SHA" "$PUBLIC_BASE_URL")

BUILD_EPOCH=$(php -r "echo json_decode(\$argv[1], true)['buildEpoch'];" "$VALIDATION_RESULT")

echo "Pre-flight validation passed:"
echo "  Edition:        GeoLite2-City"
echo "  Build Epoch:    $BUILD_EPOCH"
echo "  Archive Size:   $LOCAL_ARCHIVE_SIZE bytes"
echo "  SHA-256:        $LOCAL_ARCHIVE_SHA"

# Check origin marker and fail-closed against accidental synthetic artifact publication.
ORIGIN_MARKER="$DIST_DIR/.release-origin.json"
IS_SYNTHETIC=false
if [ -f "$ORIGIN_MARKER" ]; then
    ORIGIN_VAL=$(php -r "
        \$data = json_decode(@file_get_contents(\$argv[1]), true);
        echo is_array(\$data) ? (\$data['origin'] ?? 'unknown') : 'unknown';
    " "$ORIGIN_MARKER")
    if [ "$ORIGIN_VAL" = "synthetic" ]; then
        IS_SYNTHETIC=true
    fi
fi

if [ "$IS_SYNTHETIC" = "true" ] && [ "$DRY_RUN" = "false" ]; then
    echo "Error: Refusing to publish synthetic/test release artifacts to production R2." >&2
    echo "The directory $DIST_DIR was prepared in synthetic mode (.release-origin.json: synthetic)." >&2
    exit 1
fi

# -----------------------------------------------------------------------------
# 2. Environment and Secrets Verification
# -----------------------------------------------------------------------------

if [ "$DRY_RUN" = "false" ]; then
    MISSING_VARS=()
    for var_name in R2_ACCOUNT_ID R2_BUCKET AWS_ACCESS_KEY_ID AWS_SECRET_ACCESS_KEY CLOUDFLARE_API_TOKEN CLOUDFLARE_ZONE_ID; do
        if [ -z "${!var_name:-}" ]; then
            MISSING_VARS+=("$var_name")
        fi
    done

    if [ ${#MISSING_VARS[@]} -ne 0 ]; then
        echo "Error: Missing required environment variables: ${MISSING_VARS[*]}" >&2
        echo "Publisher requires valid R2 and Cloudflare credentials for live deployment." >&2
        exit 1
    fi

    R2_ENDPOINT="${R2_ENDPOINT:-https://${R2_ACCOUNT_ID}.r2.cloudflarestorage.com}"
fi

# Track completed publication stages for precise failure reporting.
LAST_COMPLETED_STAGE="preflight"

# -----------------------------------------------------------------------------
# Pre-flight Remote Check: Check Existing Release in R2
# -----------------------------------------------------------------------------
echo ""
echo "--- Pre-flight Remote Check ---"
if [ "$DRY_RUN" = "true" ]; then
    echo "[DRY RUN] Would check existing s3://<R2_BUCKET>/geoip/manifest.json for identical build"
    log_cmd "preflight_remote_check"
else
    # 1. Scoped bucket verification: Confirm the target bucket exists and credentials have access.
    log_cmd "$AWS_CMD s3api head-bucket --bucket $R2_BUCKET"
    BUCKET_ERR_FILE=$(mktemp)
    set +e
    $AWS_CMD s3api head-bucket --bucket "$R2_BUCKET" --endpoint-url "$R2_ENDPOINT" 2>"$BUCKET_ERR_FILE"
    BUCKET_CHECK_EXIT=$?
    set -e
    BUCKET_ERR=$(cat "$BUCKET_ERR_FILE" 2>/dev/null || true)
    rm -f "$BUCKET_ERR_FILE"

    if [ $BUCKET_CHECK_EXIT -ne 0 ]; then
        echo "Error: Target R2 bucket '$R2_BUCKET' is inaccessible or does not exist." >&2
        SAFE_ERR=$(php -r "
            \$err = \$argv[1];
            \$err = preg_replace('/(AWS_SECRET_ACCESS_KEY|token|secret|key|password)=[^ \n\r\t]+/i', '\$1=[REDACTED]', \$err);
            echo trim(\$err);
        " "$BUCKET_ERR")
        [ -n "$SAFE_ERR" ] && echo "AWS CLI error: $SAFE_ERR" >&2
        echo "Failure stage: remote_manifest_preflight_failed" >&2
        exit 1
    fi

    log_cmd "$AWS_CMD s3 cp s3://${R2_BUCKET}/geoip/manifest.json -"
    MANIFEST_ERR_FILE=$(mktemp)
    set +e
    REMOTE_MANIFEST=$($AWS_CMD s3 cp "s3://${R2_BUCKET}/geoip/manifest.json" - \
        --endpoint-url "$R2_ENDPOINT" 2>"$MANIFEST_ERR_FILE")
    MANIFEST_CP_EXIT=$?
    set -e

    MANIFEST_ERR=$(cat "$MANIFEST_ERR_FILE" 2>/dev/null || true)
    rm -f "$MANIFEST_ERR_FILE"

    if [ $MANIFEST_CP_EXIT -ne 0 ]; then
        # Check if the error indicates a confirmed absent manifest object.
        IS_NOT_FOUND=$(php -r "
            \$err = strtolower(\$argv[1]);
            \$is_unexpected = (
                strpos(\$err, 'nosuchbucket') !== false ||
                strpos(\$err, 'bucket does not exist') !== false ||
                strpos(\$err, 'accessdenied') !== false ||
                strpos(\$err, 'forbidden') !== false ||
                strpos(\$err, '403') !== false ||
                strpos(\$err, 'could not connect') !== false ||
                strpos(\$err, 'endpointconnectionerror') !== false
            );
            if (\$is_unexpected) {
                echo 'false';
                exit(0);
            }
            if (
                strpos(\$err, 'nosuchkey') !== false ||
                strpos(\$err, 'key not found') !== false ||
                strpos(\$err, 'specified key does not exist') !== false ||
                (strpos(\$err, '404') !== false && (strpos(\$err, 'getobject') !== false || strpos(\$err, 'headobject') !== false || strpos(\$err, 'not found') !== false))
            ) {
                echo 'true';
            } else {
                echo 'false';
            }
        " "$MANIFEST_ERR")

        if [ "$IS_NOT_FOUND" = "true" ]; then
            echo "Notice: Remote manifest not found in R2 (first release publish). Proceeding with publication."
            REMOTE_MANIFEST=""
        else
            echo "Error: Failed to inspect remote manifest in R2." >&2
            SAFE_ERR=$(php -r "
                \$err = \$argv[1];
                \$err = preg_replace('/(AWS_SECRET_ACCESS_KEY|token|secret|key|password)=[^ \n\r\t]+/i', '\$1=[REDACTED]', \$err);
                echo trim(\$err);
            " "$MANIFEST_ERR")
            [ -n "$SAFE_ERR" ] && echo "AWS CLI error: $SAFE_ERR" >&2
            echo "Failure stage: remote_manifest_preflight_failed" >&2
            exit 1
        fi
    fi

    if [ -n "$REMOTE_MANIFEST" ]; then
        REMOTE_MATCH=$(php -r "
            \$manifest = json_decode(\$argv[1], true);
            if (!is_array(\$manifest)) {
                echo 'false';
                exit(0);
            }
            \$r_epoch = (int) (\$manifest['buildEpoch'] ?? 0);
            \$r_sha   = strtolower(trim(\$manifest['checksumSha256'] ?? ''));
            \$l_epoch = (int) \$argv[2];
            \$l_sha   = strtolower(trim(\$argv[3]));
            if (\$r_epoch === \$l_epoch && \$r_sha === \$l_sha && \$r_epoch > 0 && '' !== \$r_sha) {
                echo 'true';
            } else {
                echo 'false';
            }
        " "$REMOTE_MANIFEST" "$BUILD_EPOCH" "$LOCAL_ARCHIVE_SHA")

        if [ "$REMOTE_MATCH" = "true" ]; then
            # Verify the referenced remote archive actually exists with the expected size before returning UP_TO_DATE.
            log_cmd "$AWS_CMD s3api head-object --bucket $R2_BUCKET --key geoip/GeoLite2-City.mmdb.gz"
            ARCHIVE_HEAD_ERR_FILE=$(mktemp)
            set +e
            REMOTE_ARCHIVE_META=$($AWS_CMD s3api head-object \
                --bucket "$R2_BUCKET" \
                --key "geoip/GeoLite2-City.mmdb.gz" \
                --endpoint-url "$R2_ENDPOINT" \
                --output json 2>"$ARCHIVE_HEAD_ERR_FILE")
            HEAD_ARCHIVE_STATUS=$?
            set -e

            ARCHIVE_HEAD_ERR=$(cat "$ARCHIVE_HEAD_ERR_FILE" 2>/dev/null || true)
            rm -f "$ARCHIVE_HEAD_ERR_FILE"

            if [ $HEAD_ARCHIVE_STATUS -ne 0 ]; then
                ARCHIVE_CHECK_RESULT=$(php -r "
                    \$err = strtolower(\$argv[1]);
                    \$is_unexpected = (
                        strpos(\$err, 'nosuchbucket') !== false ||
                        strpos(\$err, 'bucket does not exist') !== false ||
                        strpos(\$err, 'accessdenied') !== false ||
                        strpos(\$err, 'forbidden') !== false ||
                        strpos(\$err, '403') !== false ||
                        strpos(\$err, 'could not connect') !== false ||
                        strpos(\$err, 'endpointconnectionerror') !== false
                    );
                    if (\$is_unexpected) {
                        echo 'unexpected';
                        exit(0);
                    }
                    if (
                        strpos(\$err, '404') !== false ||
                        strpos(\$err, 'nosuchkey') !== false ||
                        strpos(\$err, 'not found') !== false ||
                        strpos(\$err, 'specified key does not exist') !== false ||
                        strpos(\$err, 'key not found') !== false
                    ) {
                        echo 'missing';
                    } else {
                        echo 'unexpected';
                    }
                " "$ARCHIVE_HEAD_ERR")

                if [ "$ARCHIVE_CHECK_RESULT" = "missing" ]; then
                    echo "Warning: Remote manifest matches current build, but remote archive is missing from R2. Proceeding with publication to repair archive."
                else
                    echo "Error: Failed to verify existing archive in R2." >&2
                    SAFE_ERR=$(php -r "
                        \$err = \$argv[1];
                        \$err = preg_replace('/(AWS_SECRET_ACCESS_KEY|token|secret|key|password)=[^ \n\r\t]+/i', '\$1=[REDACTED]', \$err);
                        echo trim(\$err);
                    " "$ARCHIVE_HEAD_ERR")
                    [ -n "$SAFE_ERR" ] && echo "AWS CLI error: $SAFE_ERR" >&2
                    echo "Failure stage: remote_archive_preflight_failed" >&2
                    exit 1
                fi
            else
                REMOTE_ARCHIVE_SIZE=$(php -r "echo json_decode(\$argv[1], true)['ContentLength'] ?? '';" "$REMOTE_ARCHIVE_META")

                if [ "$REMOTE_ARCHIVE_SIZE" != "$LOCAL_ARCHIVE_SIZE" ]; then
                    echo "Warning: Remote manifest matches current build, but remote archive size mismatch (remote: '${REMOTE_ARCHIVE_SIZE}', expected: ${LOCAL_ARCHIVE_SIZE}). Proceeding with publication to repair archive."
                elif [ "$FORCE" = "true" ]; then
                    echo "Notice: Remote R2 already hosts an identical release (epoch: $BUILD_EPOCH, SHA: $LOCAL_ARCHIVE_SHA), but --force was specified. Proceeding."
                else
                    echo "Notice: Remote R2 already hosts an identical release and verified archive (epoch: $BUILD_EPOCH, SHA: $LOCAL_ARCHIVE_SHA, size: $LOCAL_ARCHIVE_SIZE bytes)."
                    echo "Preflight status: UP_TO_DATE. Skipping redundant object overwrites and cache purges."
                    log_cmd "preflight_remote_check up_to_date"
                    exit 0
                fi
            fi
        fi
    fi
fi

# -----------------------------------------------------------------------------
# Stage 1: Upload Authentic Upstream Notices (LICENSE.txt & COPYRIGHT.txt)
# -----------------------------------------------------------------------------
echo ""
echo "--- Stage 1: Upload Authentic Notices ---"
if [ "$DRY_RUN" = "true" ]; then
    echo "[DRY RUN] Would upload $LICENSE_FILE -> s3://<R2_BUCKET>/geoip/LICENSE.txt (Content-Type: text/plain; charset=utf-8, Cache-Control: public, max-age=86400)"
    echo "[DRY RUN] Would upload $COPYRIGHT_FILE -> s3://<R2_BUCKET>/geoip/COPYRIGHT.txt (Content-Type: text/plain; charset=utf-8, Cache-Control: public, max-age=86400)"
    log_cmd "s3 cp LICENSE.txt"
    log_cmd "s3 cp COPYRIGHT.txt"
else
    log_cmd "$AWS_CMD s3 cp $LICENSE_FILE s3://${R2_BUCKET}/geoip/LICENSE.txt"
    $AWS_CMD s3 cp "$LICENSE_FILE" "s3://${R2_BUCKET}/geoip/LICENSE.txt" \
        --endpoint-url "$R2_ENDPOINT" \
        --content-type "text/plain; charset=utf-8" \
        --cache-control "public, max-age=86400"

    log_cmd "$AWS_CMD s3 cp $COPYRIGHT_FILE s3://${R2_BUCKET}/geoip/COPYRIGHT.txt"
    $AWS_CMD s3 cp "$COPYRIGHT_FILE" "s3://${R2_BUCKET}/geoip/COPYRIGHT.txt" \
        --endpoint-url "$R2_ENDPOINT" \
        --content-type "text/plain; charset=utf-8" \
        --cache-control "public, max-age=86400"
fi
LAST_COMPLETED_STAGE="notices_uploaded"
echo "Stage 1 complete: Authentic notices uploaded."

# -----------------------------------------------------------------------------
# Stage 2: Upload Compressed Archive (GeoLite2-City.mmdb.gz)
# -----------------------------------------------------------------------------
echo ""
echo "--- Stage 2: Upload Compressed Archive ---"
if [ "$DRY_RUN" = "true" ]; then
    echo "[DRY RUN] Would upload $ARCHIVE_FILE -> s3://<R2_BUCKET>/geoip/GeoLite2-City.mmdb.gz (Content-Type: application/gzip, Cache-Control: public, max-age=300, s-maxage=604800, Content-Encoding: unset)"
    log_cmd "s3 cp GeoLite2-City.mmdb.gz"
else
    log_cmd "$AWS_CMD s3 cp $ARCHIVE_FILE s3://${R2_BUCKET}/geoip/GeoLite2-City.mmdb.gz"
    # IMPORTANT: Do NOT specify --content-encoding gzip. It must arrive as precompressed raw bytes.
    if ! $AWS_CMD s3 cp "$ARCHIVE_FILE" "s3://${R2_BUCKET}/geoip/GeoLite2-City.mmdb.gz" \
        --endpoint-url "$R2_ENDPOINT" \
        --content-type "application/gzip" \
        --cache-control "public, max-age=300, s-maxage=604800"; then
        echo "Error: Failed to upload archive to R2." >&2
        echo "Failure stage: archive_upload_failed" >&2
        echo "Last completed stage: $LAST_COMPLETED_STAGE" >&2
        exit 1
    fi
fi
LAST_COMPLETED_STAGE="archive_uploaded"
echo "Stage 2 complete: Archive uploaded."

# -----------------------------------------------------------------------------
# Stage 3: Verify Archive in R2
# -----------------------------------------------------------------------------
echo ""
echo "--- Stage 3: Verify Archive in R2 ---"
if [ "$DRY_RUN" = "true" ]; then
    echo "[DRY RUN] Would verify s3://<R2_BUCKET>/geoip/GeoLite2-City.mmdb.gz size ($LOCAL_ARCHIVE_SIZE bytes), ContentType, and streaming read-back SHA-256"
    log_cmd "s3api head-object geoip/GeoLite2-City.mmdb.gz"
    log_cmd "s3 cp readback_sha geoip/GeoLite2-City.mmdb.gz"
else
    log_cmd "$AWS_CMD s3api head-object --bucket $R2_BUCKET --key geoip/GeoLite2-City.mmdb.gz"
    R2_ARCHIVE_META=$($AWS_CMD s3api head-object \
        --bucket "$R2_BUCKET" \
        --key "geoip/GeoLite2-City.mmdb.gz" \
        --endpoint-url "$R2_ENDPOINT" \
        --output json) || {
        echo "Error: Failed to head-object on uploaded archive in R2." >&2
        echo "Failure stage: archive_verification_failed" >&2
        echo "Last completed stage: $LAST_COMPLETED_STAGE" >&2
        exit 1
    }

    R2_ARCHIVE_SIZE=$(php -r "echo json_decode(\$argv[1], true)['ContentLength'] ?? '';" "$R2_ARCHIVE_META")
    R2_ARCHIVE_TYPE=$(php -r "echo json_decode(\$argv[1], true)['ContentType'] ?? '';" "$R2_ARCHIVE_META")

    if [ "$R2_ARCHIVE_SIZE" != "$LOCAL_ARCHIVE_SIZE" ]; then
        echo "Error: R2 archive size mismatch: $R2_ARCHIVE_SIZE bytes (expected: $LOCAL_ARCHIVE_SIZE bytes)." >&2
        echo "Failure stage: archive_size_mismatch" >&2
        echo "Last completed stage: $LAST_COMPLETED_STAGE" >&2
        exit 1
    fi

    if [ "$R2_ARCHIVE_TYPE" != "application/gzip" ]; then
        echo "Warning: R2 archive Content-Type is '$R2_ARCHIVE_TYPE' (expected 'application/gzip')." >&2
    fi

    # Read-back streaming SHA-256 verification from R2 before cache purge and manifest upload.
    echo "Verifying R2 archive integrity via streaming SHA-256 read-back..."
    log_cmd "$AWS_CMD s3 cp s3://${R2_BUCKET}/geoip/GeoLite2-City.mmdb.gz -"
    R2_READBACK_SHA=$($AWS_CMD s3 cp "s3://${R2_BUCKET}/geoip/GeoLite2-City.mmdb.gz" - \
        --endpoint-url "$R2_ENDPOINT" | php -r '
            $ctx = hash_init("sha256");
            hash_update_stream($ctx, STDIN);
            echo hash_final($ctx);
        ') || {
        echo "Error: Failed to stream archive from R2 for read-back verification." >&2
        echo "Failure stage: archive_readback_stream_failed" >&2
        echo "Last completed stage: $LAST_COMPLETED_STAGE" >&2
        exit 1
    }

    if [ "$R2_READBACK_SHA" != "$LOCAL_ARCHIVE_SHA" ]; then
        echo "Error: R2 read-back archive SHA-256 ($R2_READBACK_SHA) does not match local archive SHA-256 ($LOCAL_ARCHIVE_SHA)!" >&2
        echo "Failure stage: archive_readback_sha_mismatch" >&2
        echo "Last completed stage: $LAST_COMPLETED_STAGE" >&2
        exit 1
    fi
fi
LAST_COMPLETED_STAGE="archive_verified"
echo "Stage 3 complete: Archive verified in R2 ($LOCAL_ARCHIVE_SIZE bytes, SHA-256 verified)."

# -----------------------------------------------------------------------------
# Stage 4: Purge Archive in Cloudflare Cache
# -----------------------------------------------------------------------------
echo ""
echo "--- Stage 4: Purge Archive in Cloudflare Cache ---"
ARCHIVE_PURGE_URL="${PUBLIC_BASE_URL}/geoip/GeoLite2-City.mmdb.gz"

if [ "$DRY_RUN" = "true" ]; then
    echo "[DRY RUN] Would purge Cloudflare cache for URL: $ARCHIVE_PURGE_URL"
    log_cmd "purge_cache $ARCHIVE_PURGE_URL"
else
    PURGE_PAYLOAD=$(php -r "echo json_encode(['files' => [\$argv[1]]]);" "$ARCHIVE_PURGE_URL")
    log_cmd "$CURL_CMD purge $ARCHIVE_PURGE_URL"

    PURGE_RESPONSE=$($CURL_CMD --silent --show-error \
        --request POST \
        --url "https://api.cloudflare.com/client/v4/zones/${CLOUDFLARE_ZONE_ID}/purge_cache" \
        --header "Authorization: Bearer ${CLOUDFLARE_API_TOKEN}" \
        --header "Content-Type: application/json" \
        --data "$PURGE_PAYLOAD" \
        --write-out "\nHTTP_STATUS:%{http_code}") || {
        echo "Error: Cloudflare purge API network error." >&2
        echo "CRITICAL: Archive was overwritten in R2, but cache purge failed!" >&2
        echo "Manifest will NOT be published to prevent serving mismatched checksums." >&2
        echo "Failure stage: archive_purge_network_error" >&2
        echo "Last completed stage: $LAST_COMPLETED_STAGE" >&2
        exit 1
    }

    HTTP_STATUS=$(echo "$PURGE_RESPONSE" | grep "HTTP_STATUS:" | cut -d':' -f2)
    PURGE_BODY=$(echo "$PURGE_RESPONSE" | grep -v "HTTP_STATUS:")

    PURGE_SUCCESS=$(php -r "
        \$data = json_decode(\$argv[1], true);
        echo (!empty(\$data['success']) && true === \$data['success']) ? 'true' : 'false';
    " "$PURGE_BODY")

    if [ "$HTTP_STATUS" != "200" ] || [ "$PURGE_SUCCESS" != "true" ]; then
        echo "Error: Cloudflare cache purge failed for archive!" >&2
        echo "HTTP Status: $HTTP_STATUS" >&2
        echo "Response:    $PURGE_BODY" >&2
        echo "CRITICAL: Archive was overwritten in R2, but cache purge failed!" >&2
        echo "Manifest will NOT be published. Previous manifest remains in effect." >&2
        echo "Failure stage: archive_purge_failed" >&2
        echo "Last completed stage: $LAST_COMPLETED_STAGE" >&2
        exit 1
    fi
fi
LAST_COMPLETED_STAGE="archive_purged"
echo "Stage 4 complete: Cloudflare cache purged for archive."

# -----------------------------------------------------------------------------
# Stage 5: Upload Manifest (manifest.json)
# -----------------------------------------------------------------------------
echo ""
echo "--- Stage 5: Upload Manifest ---"
if [ "$DRY_RUN" = "true" ]; then
    echo "[DRY RUN] Would upload $MANIFEST_FILE -> s3://<R2_BUCKET>/geoip/manifest.json (Content-Type: application/json, Cache-Control: public, max-age=60, s-maxage=3600)"
    log_cmd "s3 cp manifest.json"
else
    log_cmd "$AWS_CMD s3 cp $MANIFEST_FILE s3://${R2_BUCKET}/geoip/manifest.json"
    if ! $AWS_CMD s3 cp "$MANIFEST_FILE" "s3://${R2_BUCKET}/geoip/manifest.json" \
        --endpoint-url "$R2_ENDPOINT" \
        --content-type "application/json" \
        --cache-control "public, max-age=60, s-maxage=3600"; then
        echo "Error: Failed to upload manifest to R2." >&2
        echo "Failure stage: manifest_upload_failed" >&2
        echo "Last completed stage: $LAST_COMPLETED_STAGE" >&2
        exit 1
    fi
fi
LAST_COMPLETED_STAGE="manifest_uploaded"
echo "Stage 5 complete: Manifest uploaded."

# -----------------------------------------------------------------------------
# Stage 6: Verify Manifest in R2
# -----------------------------------------------------------------------------
echo ""
echo "--- Stage 6: Verify Manifest in R2 ---"
if [ "$DRY_RUN" = "true" ]; then
    echo "[DRY RUN] Would verify s3://<R2_BUCKET>/geoip/manifest.json size ($LOCAL_MANIFEST_SIZE bytes) and ContentType"
    log_cmd "s3api head-object geoip/manifest.json"
else
    log_cmd "$AWS_CMD s3api head-object --bucket $R2_BUCKET --key geoip/manifest.json"
    R2_MANIFEST_META=$($AWS_CMD s3api head-object \
        --bucket "$R2_BUCKET" \
        --key "geoip/manifest.json" \
        --endpoint-url "$R2_ENDPOINT" \
        --output json) || {
        echo "Error: Failed to head-object on uploaded manifest in R2." >&2
        echo "Failure stage: manifest_verification_failed" >&2
        echo "Last completed stage: $LAST_COMPLETED_STAGE" >&2
        exit 1
    }

    R2_MANIFEST_SIZE=$(php -r "echo json_decode(\$argv[1], true)['ContentLength'] ?? '';" "$R2_MANIFEST_META")
    if [ "$R2_MANIFEST_SIZE" != "$LOCAL_MANIFEST_SIZE" ]; then
        echo "Error: R2 manifest size mismatch: $R2_MANIFEST_SIZE bytes (expected: $LOCAL_MANIFEST_SIZE bytes)." >&2
        echo "Failure stage: manifest_size_mismatch" >&2
        echo "Last completed stage: $LAST_COMPLETED_STAGE" >&2
        exit 1
    fi
fi
LAST_COMPLETED_STAGE="manifest_verified"
echo "Stage 6 complete: Manifest verified in R2 ($LOCAL_MANIFEST_SIZE bytes)."

# -----------------------------------------------------------------------------
# Stage 7: Purge Manifest in Cloudflare Cache
# -----------------------------------------------------------------------------
echo ""
echo "--- Stage 7: Purge Manifest in Cloudflare Cache ---"
MANIFEST_PURGE_URL="${PUBLIC_BASE_URL}/geoip/manifest.json"

if [ "$DRY_RUN" = "true" ]; then
    echo "[DRY RUN] Would purge Cloudflare cache for URL: $MANIFEST_PURGE_URL"
    log_cmd "purge_cache $MANIFEST_PURGE_URL"
else
    MANIFEST_PAYLOAD=$(php -r "echo json_encode(['files' => [\$argv[1]]]);" "$MANIFEST_PURGE_URL")
    log_cmd "$CURL_CMD purge $MANIFEST_PURGE_URL"

    PURGE_RESPONSE=$($CURL_CMD --silent --show-error \
        --request POST \
        --url "https://api.cloudflare.com/client/v4/zones/${CLOUDFLARE_ZONE_ID}/purge_cache" \
        --header "Authorization: Bearer ${CLOUDFLARE_API_TOKEN}" \
        --header "Content-Type: application/json" \
        --data "$MANIFEST_PAYLOAD" \
        --write-out "\nHTTP_STATUS:%{http_code}") || {
        echo "Error: Cloudflare manifest purge API network error." >&2
        echo "Failure stage: manifest_purge_network_error" >&2
        echo "Last completed stage: $LAST_COMPLETED_STAGE" >&2
        exit 1
    }

    HTTP_STATUS=$(echo "$PURGE_RESPONSE" | grep "HTTP_STATUS:" | cut -d':' -f2)
    PURGE_BODY=$(echo "$PURGE_RESPONSE" | grep -v "HTTP_STATUS:")

    PURGE_SUCCESS=$(php -r "
        \$data = json_decode(\$argv[1], true);
        echo (!empty(\$data['success']) && true === \$data['success']) ? 'true' : 'false';
    " "$PURGE_BODY")

    if [ "$HTTP_STATUS" != "200" ] || [ "$PURGE_SUCCESS" != "true" ]; then
        echo "Error: Cloudflare cache purge failed for manifest!" >&2
        echo "HTTP Status: $HTTP_STATUS" >&2
        echo "Response:    $PURGE_BODY" >&2
        echo "Failure stage: manifest_purge_failed" >&2
        echo "Last completed stage: $LAST_COMPLETED_STAGE" >&2
        exit 1
    fi
fi
LAST_COMPLETED_STAGE="manifest_purged"
echo "Stage 7 complete: Cloudflare cache purged for manifest."

# -----------------------------------------------------------------------------
# Stage 8: Post-CDN Verification (Optional)
# -----------------------------------------------------------------------------
if [ "$VERIFY_CDN" = "true" ] && [ "$DRY_RUN" = "false" ]; then
    echo ""
    echo "--- Stage 8: Post-CDN Verification ---"
    log_cmd "$CURL_CMD get $MANIFEST_PURGE_URL"

    CDN_MANIFEST=$($CURL_CMD --fail --silent --show-error "$MANIFEST_PURGE_URL") || {
        echo "Error: CDN served manifest request failed." >&2
        echo "Failure stage: cdn_manifest_fetch_failed" >&2
        exit 1
    }
    CDN_SHA=$(php -r "echo json_decode(\$argv[1], true)['checksumSha256'] ?? '';" "$CDN_MANIFEST")

    if [ "$CDN_SHA" != "$LOCAL_ARCHIVE_SHA" ]; then
        echo "Error: CDN served manifest checksum ($CDN_SHA) does not match expected ($LOCAL_ARCHIVE_SHA)." >&2
        echo "Failure stage: cdn_manifest_mismatch" >&2
        exit 1
    fi

    # Fetch archive bytes without curl transparent decompression.
    TEMP_CDN_ARCHIVE=$(mktemp)
    log_cmd "$CURL_CMD get $ARCHIVE_PURGE_URL"
    if ! $CURL_CMD --fail --silent --show-error --output "$TEMP_CDN_ARCHIVE" "$ARCHIVE_PURGE_URL"; then
        rm -f "$TEMP_CDN_ARCHIVE"
        echo "Error: CDN served archive request failed." >&2
        echo "Failure stage: cdn_archive_fetch_failed" >&2
        exit 1
    fi
    CDN_ARCHIVE_SHA=$(compute_sha256 "$TEMP_CDN_ARCHIVE")
    rm -f "$TEMP_CDN_ARCHIVE"

    if [ "$CDN_ARCHIVE_SHA" != "$LOCAL_ARCHIVE_SHA" ]; then
        echo "Error: CDN served archive checksum ($CDN_ARCHIVE_SHA) does not match expected ($LOCAL_ARCHIVE_SHA)." >&2
        echo "Failure stage: cdn_archive_mismatch" >&2
        exit 1
    fi

    LAST_COMPLETED_STAGE="cdn_verified"
    echo "Stage 8 complete: CDN verified identical archive and manifest."
fi

# -----------------------------------------------------------------------------
# Summary and Completion
# -----------------------------------------------------------------------------
echo ""
echo "=========================================================="
echo "GeoIP Release Publication Succeeded!"
echo "Edition:              GeoLite2-City"
echo "Build Epoch:          $BUILD_EPOCH"
echo "Archive Size:         $LOCAL_ARCHIVE_SIZE bytes"
echo "SHA-256 Checksum:     $LOCAL_ARCHIVE_SHA"
echo "Last Completed Stage: $LAST_COMPLETED_STAGE"
echo "Mode:                 $([ "$DRY_RUN" = "true" ] && echo "Dry Run" || echo "Live Published")"
echo "=========================================================="

if [ -n "${GITHUB_STEP_SUMMARY:-}" ]; then
    cat >> "$GITHUB_STEP_SUMMARY" <<EOF
### GeoIP R2 Publication Summary
- **Status:** $([ "$DRY_RUN" = "true" ] && echo "DRY RUN SUCCESS" || echo "PUBLISHED")
- **Edition:** GeoLite2-City
- **Build Epoch:** $BUILD_EPOCH
- **Archive Size:** $LOCAL_ARCHIVE_SIZE bytes
- **SHA-256:** \`$LOCAL_ARCHIVE_SHA\`
- **Last Stage:** $LAST_COMPLETED_STAGE
- **Objects Updated:**
  - \`geoip/LICENSE.txt\`
  - \`geoip/COPYRIGHT.txt\`
  - \`geoip/GeoLite2-City.mmdb.gz\`
  - \`geoip/manifest.json\`
- **Purged URLs:**
  - \`$ARCHIVE_PURGE_URL\`
  - \`$MANIFEST_PURGE_URL\`
EOF
fi

exit 0

#!/usr/bin/env bash
# AUD-018 (W1-6) — off-site replication of the DAM assets bucket.
#
# Bucket versioning (minio-init: `mc version enable pim-assets`) protects assets
# against an accidental/malicious overwrite or delete WITHIN one MinIO instance.
# It does NOT protect against losing the whole `minio_data` volume / instance —
# for that the bytes must live in a SECOND location. This script mirrors the
# assets bucket to a second MinIO/S3 target with `mc mirror`, the same way the
# pgBackRest repo now lives in its own bucket (separate failure domain).
#
# Design:
#   - Idempotent + incremental: `mc mirror` copies only new/changed objects and
#     (with --remove) prunes objects deleted at the source, so re-running it is
#     cheap. Safe to put on a cron / Symfony Scheduler / Kubernetes CronJob.
#   - Source and target are configured via env so the same script works for the
#     local stack (second bucket on the same MinIO — a smoke target) and for
#     production (a genuinely separate instance / region / S3 account).
#   - No destructive default: --remove (prune at target) is opt-in via
#     MIRROR_PRUNE=1 so a misconfigured target can't wipe the replica.
#
# Required env:
#   MIRROR_SOURCE_ALIAS   mc alias for the primary MinIO          (default: local)
#   MIRROR_SOURCE_BUCKET  source bucket                            (default: pim-assets)
#   MIRROR_TARGET_URL     S3/MinIO endpoint of the replica        (REQUIRED)
#   MIRROR_TARGET_KEY     access key for the replica              (REQUIRED)
#   MIRROR_TARGET_SECRET  secret key for the replica              (REQUIRED)
#   MIRROR_TARGET_BUCKET  target bucket                            (default: pim-assets-replica)
# Optional:
#   MIRROR_SOURCE_URL/KEY/SECRET  configure the source alias inline instead of
#                                 relying on a pre-existing `mc alias`.
#   MIRROR_PRUNE=1        delete target objects removed at source (default: off)
#   MIRROR_WATCH=1        run `mc mirror --watch` (long-lived) instead of one-shot
#
# Usage (one-shot, local smoke against a second bucket on the same MinIO):
#   MIRROR_TARGET_URL=http://minio:9000 \
#   MIRROR_TARGET_KEY=minioadmin MIRROR_TARGET_SECRET=minioadmin \
#   MIRROR_TARGET_BUCKET=pim-assets-replica \
#   docker run --rm --network pim_default \
#     -e MIRROR_TARGET_URL -e MIRROR_TARGET_KEY -e MIRROR_TARGET_SECRET \
#     -e MIRROR_TARGET_BUCKET -e MIRROR_SOURCE_URL=http://minio:9000 \
#     -e MIRROR_SOURCE_KEY=minioadmin -e MIRROR_SOURCE_SECRET=minioadmin \
#     --entrypoint /bin/sh -v "$PWD/scripts:/scripts:ro" minio/mc:RELEASE.2025-08-13T08-35-41Z@sha256:a7fe349ef4bd8521fb8497f55c6042871b2ae640607cf99d9bede5e9bdf11727 \
#     /scripts/minio-mirror-assets.sh
#
# In production: run this from a CronJob / cron entry against a SEPARATE MinIO
# region (see docs/runbook/disaster-recovery.md → "MinIO disaster recovery").

set -euo pipefail

SOURCE_ALIAS="${MIRROR_SOURCE_ALIAS:-local}"
SOURCE_BUCKET="${MIRROR_SOURCE_BUCKET:-pim-assets}"
TARGET_ALIAS="mirror-target"
TARGET_BUCKET="${MIRROR_TARGET_BUCKET:-pim-assets-replica}"

log() { printf '[%s] minio-mirror-assets: %s\n' "$(date -u +%FT%TZ)" "$*"; }
fail() { printf '[%s] minio-mirror-assets: ERROR: %s\n' "$(date -u +%FT%TZ)" "$*" >&2; exit 1; }

[ -n "${MIRROR_TARGET_URL:-}" ]    || fail "MIRROR_TARGET_URL is required (replica MinIO/S3 endpoint)."
[ -n "${MIRROR_TARGET_KEY:-}" ]    || fail "MIRROR_TARGET_KEY is required (replica access key)."
[ -n "${MIRROR_TARGET_SECRET:-}" ] || fail "MIRROR_TARGET_SECRET is required (replica secret key)."

# Optionally (re)configure the source alias inline — handy for a fresh mc
# container that has no persisted aliases. Otherwise we trust a pre-existing one.
if [ -n "${MIRROR_SOURCE_URL:-}" ]; then
    log "configuring source alias '${SOURCE_ALIAS}' → ${MIRROR_SOURCE_URL}"
    mc alias set "${SOURCE_ALIAS}" "${MIRROR_SOURCE_URL}" \
        "${MIRROR_SOURCE_KEY:?MIRROR_SOURCE_KEY required when MIRROR_SOURCE_URL is set}" \
        "${MIRROR_SOURCE_SECRET:?MIRROR_SOURCE_SECRET required when MIRROR_SOURCE_URL is set}" >/dev/null
fi

log "configuring target alias '${TARGET_ALIAS}' → ${MIRROR_TARGET_URL}"
mc alias set "${TARGET_ALIAS}" "${MIRROR_TARGET_URL}" "${MIRROR_TARGET_KEY}" "${MIRROR_TARGET_SECRET}" >/dev/null

# Ensure the replica bucket exists and is versioned (same protection as primary).
mc mb -p "${TARGET_ALIAS}/${TARGET_BUCKET}" 2>/dev/null || true
mc version enable "${TARGET_ALIAS}/${TARGET_BUCKET}" >/dev/null 2>&1 || true

MIRROR_ARGS="--overwrite"
[ "${MIRROR_PRUNE:-0}" = "1" ] && MIRROR_ARGS="${MIRROR_ARGS} --remove"
[ "${MIRROR_WATCH:-0}" = "1" ] && MIRROR_ARGS="${MIRROR_ARGS} --watch"

log "mirroring ${SOURCE_ALIAS}/${SOURCE_BUCKET} → ${TARGET_ALIAS}/${TARGET_BUCKET} (args: ${MIRROR_ARGS})"
# shellcheck disable=SC2086
mc mirror ${MIRROR_ARGS} "${SOURCE_ALIAS}/${SOURCE_BUCKET}" "${TARGET_ALIAS}/${TARGET_BUCKET}"

log "mirror pass complete"

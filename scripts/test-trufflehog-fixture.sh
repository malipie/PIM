#!/usr/bin/env bash
set -euo pipefail

readonly DEFAULT_IMAGE='ghcr.io/trufflesecurity/trufflehog:3.97.1@sha256:deb2af10659a488a14d262a323addcde099d99827a1cf1dc4e93c17915c39f08'
readonly IMAGE="${TRUFFLEHOG_IMAGE:-$DEFAULT_IMAGE}"

if [[ "$IMAGE" != *@sha256:* ]]; then
  echo "TruffleHog self-test requires an image pinned by digest, got: $IMAGE" >&2
  exit 2
fi

fixture_dir="$(mktemp -d)"
readonly fixture_dir
trap 'rm -rf "$fixture_dir"' EXIT

# Generate a real but ephemeral private key. It never enters Git history and
# has no external authority, while exercising a production TruffleHog detector.
openssl genpkey -quiet -algorithm RSA -pkeyopt rsa_keygen_bits:2048 \
  -out "$fixture_dir/controlled-private-key.pem"

set +e
scan_output="$(docker run --rm \
  --volume "$fixture_dir:/fixture:ro" \
  "$IMAGE" filesystem /fixture \
  --no-update \
  --no-verification \
  --results=unverified \
  --fail \
  --json 2>&1)"
scan_status=$?
set -e

if [[ $scan_status -ne 183 ]]; then
  echo "Expected TruffleHog exit 183 for the controlled fixture, got $scan_status." >&2
  echo "$scan_output" >&2
  exit 1
fi

if ! grep -q '"DetectorName":"PrivateKey"' <<<"$scan_output"; then
  echo "TruffleHog failed to report the PrivateKey detector." >&2
  echo "$scan_output" >&2
  exit 1
fi

echo "TruffleHog self-test: controlled PrivateKey fixture detected."

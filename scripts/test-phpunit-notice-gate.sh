#!/usr/bin/env bash
# Proves that phpunit.dist.xml turns a controlled PHPUnit notice into failure.
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
api_dir="$repo_root/apps/api"
probe_dir="$(mktemp -d)"
trap 'rm -rf "$probe_dir"' EXIT
probe="$probe_dir/PhpunitNoticeGateProbeTest.php"

printf '%s\n' \
    '<?php' \
    'declare(strict_types=1);' \
    'use PHPUnit\Framework\TestCase;' \
    'final class PhpunitNoticeGateProbeTest extends TestCase' \
    '{' \
    '    public function testMockWithoutExpectationIsRejected(): void' \
    '    {' \
    '        $this->createMock(Iterator::class);' \
    '        self::assertTrue(true);' \
    '    }' \
    '}' >"$probe"

set +e
output="$(
    cd "$api_dir"
    APP_ENV=test \
    APP_DEBUG=0 \
    MESSENGER_TRANSPORT_DSN='in-memory://' \
    MERCURE_JWT_SECRET='ci-mercure-key-at-least-256-bits-long' \
    JWT_PASSPHRASE=ci \
    APP_DEFAULT_TENANT_CODE=demo \
        php bin/phpunit "$probe" --no-progress 2>&1
)"
status=$?
set -e

if (( status == 0 )); then
    echo "test-phpunit-notice-gate: kontrolowany notice zakonczyl sie kodem 0." >&2
    echo "Sprawdz failOnPhpunitNotice w apps/api/phpunit.dist.xml." >&2
    exit 1
fi

if [[ "$output" != *"PHPUnit Notices: 1"* ]]; then
    echo "test-phpunit-notice-gate: przebieg byl czerwony z innego powodu:" >&2
    printf '%s\n' "$output" >&2
    exit 1
fi

echo "test-phpunit-notice-gate: controlled notice rejected. Clean."

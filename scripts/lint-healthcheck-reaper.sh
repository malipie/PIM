#!/usr/bin/env bash
# #2791 — a container whose healthcheck forks must reap what it forks.
#
# The edge Caddy ran `wget --spider https://…` every ten seconds. BusyBox wget
# forks `ssl_client` for the TLS handshake; when wget exits that helper is
# reparented to PID 1 — `caddy`, which reaps nothing. One zombie per check,
# 8 640 a day, ~12 KB of kernel slab each, all charged to the container's
# 128 MiB limit. Measured before the fix: 10 489 zombies on dev (121/128 MiB),
# and on prod a PID space so full (~9 200) that nothing could fork any more —
# so the healthcheck could not run and `docker exec` was dead for 45 hours,
# while the container carried on serving traffic. Nothing alerted.
#
# The guard: a healthcheck that runs BusyBox `wget` against an https URL must
# declare `init: true` (Docker runs tini as PID 1, and tini reaps).
#
# Why this narrow and not "any forking healthcheck": what leaks is an orphaned
# GRANDCHILD. The healthcheck process itself is reaped by the container runtime
# that exec'd it, so a `curl`/`pg_isready`/`redis-cli` check leaves nothing
# behind — measured: 0 zombies in every other container of this stack. Only
# BusyBox wget-over-TLS forks a helper that outlives it. Flagging every CMD
# healthcheck would have failed api/worker, which are demonstrably clean, and a
# guard that cries wolf gets an allowlist entry rather than a fix. The realistic
# way this returns is someone copying the edge healthcheck into a new service —
# which is exactly what this catches.
set -eu

ROOT="${ROOT:-$(cd "$(dirname "$0")/.." && pwd)}"
COMPOSE="${ROOT}/docker-compose.yml"

if [ ! -f "$COMPOSE" ]; then
    echo "lint-healthcheck-reaper: $COMPOSE not found" >&2
    exit 1
fi

failures=0

python3 - "$COMPOSE" <<'PY' || failures=1
import re
import sys

compose_path = sys.argv[1]
text = open(compose_path, encoding="utf-8").read()

# Top-level service blocks: two-space indented keys under `services:`.
services = re.split(r"\n  (?=[a-z0-9_-]+:\n)", text.split("\nservices:\n", 1)[-1])

offenders = []
for block in services:
    name = block.split(":", 1)[0].strip().lstrip("# ")
    if not name or "healthcheck:" not in block:
        continue
    test_line = re.search(r"test:.*", block)
    if not test_line:
        continue
    test = test_line.group(0)
    # BusyBox wget over TLS forks `ssl_client`, and that helper is what leaks.
    if "wget" not in test or "https" not in test:
        continue
    if re.search(r"^\s+init:\s*true", block, re.MULTILINE):
        continue
    offenders.append(name)

if offenders:
    print("lint-healthcheck-reaper: FAIL — wget-over-TLS healthcheck with no reaper:")
    for name in offenders:
        print(f"  {name}: add `init: true`")
    print()
    print("A forked healthcheck leaves a zombie per run when PID 1 does not reap.")
    print("At a 10s interval that is 8 640 zombies a day; the container dies of")
    print("PID exhaustion long before anyone reads a memory graph (#2791).")
    sys.exit(1)

print("lint-healthcheck-reaper: OK — every wget-over-TLS healthcheck has a reaper.")
PY

exit "$failures"

#!/bin/sh
# PIM api container entrypoint.
#
# Wraps the upstream FrankenPHP entrypoint with a dev-only auto-seed step
# so a fresh database (post `pim:db:reset`, post `docker compose down -v`,
# post big-rebase that wiped the volume) auto-loads the admin user before
# the operator hits the login form. Without this, every "DB empty" boot
# surfaces as a misleading "Nieprawidłowy e-mail lub hasło" toast.
#
# Decisions:
# - dev/test only. APP_ENV=prod skips entirely (safety: never touch prod
#   data from an entrypoint hook).
# - Best-effort: a seed failure logs a warning but does NOT block the
#   container from coming up. Operator still gets a working API and can
#   diagnose interactively.
# - DB readiness is gated by compose `depends_on: database:
#   service_healthy`, so the seed runs against an up DB. The Symfony
#   command also has its own retry-on-DBAL-error path.
#
# This file is COPYed into /usr/local/bin/docker-entrypoint.sh in the
# Dockerfile and made the container ENTRYPOINT.

set -e

# Mirror the upstream behaviour from /usr/local/bin/docker-php-entrypoint:
# expand `-f flag` style args into a full `frankenphp run …` invocation.
if [ "${1#-}" != "$1" ]; then
    set -- frankenphp run "$@"
fi

# Rebuild the compiled DI container on boot when running non-debug in dev/test.
#
# The worker is pinned to APP_DEBUG=0 in dev (docker-compose.yml) so it never
# pays the profiler / DebugDataHolder memory overhead the prod worker avoids.
# But non-debug Symfony SKIPS the container-freshness check: once the compiled
# container is dumped it is never regenerated on a source change. A message
# handler added during development is therefore absent from the worker's frozen
# container, and the routed message is silently acked "handled successfully"
# (Messenger `allow_no_handlers: true`, needed for handler-less domain events)
# — a dropped sync with no error in the log. The api (APP_DEBUG=1) self-refreshes
# and needs none of this.
#
# Scope: only the non-debug dev/test case. NEVER in prod (the prod image warms
# the cache at build time; clearing it at runtime would cold-start every deploy)
# and never in CI (the workflow drives cache/migrations explicitly). Best-effort:
# a failure logs a warning and the worker still starts on the existing container.
if [ "${CI:-}" != "true" ] && [ "${APP_DEBUG:-}" = "0" ] \
    && { [ "${APP_ENV:-dev}" = "dev" ] || [ "${APP_ENV:-dev}" = "test" ]; }; then
    if [ -x /app/bin/console ]; then
        # GOLIVE #2178 — a pim:db:reset FORCE drop kills this worker's DB
        # session, which restarts the container and lands us right here while
        # the reset CLI is still running. cache:clear on the SHARED /app/var
        # volume would delete the compiled DI container out from under it
        # (getXxxService.php "Failed to open stream" on the reset's final
        # steps). Wait for the reset's advisory lock to clear first.
        php /app/bin/console pim:dev:await-lifecycle-lock --no-interaction \
            || echo "[entrypoint] WARN: lifecycle-lock wait failed; continuing."
        echo "[entrypoint] Rebuilding DI container (APP_DEBUG=0 skips freshness checks; ensures new handlers register)"
        php /app/bin/console cache:clear --no-interaction \
            || echo "[entrypoint] WARN: cache:clear failed; worker will start on the existing (possibly stale) container."
    fi
fi

# Generate the JWT keypair on first boot when missing (dev/test only).
#
# A fresh clone has no config/jwt/*.pem (gitignored per the lexik bundle
# recipe) and nothing in the onboarding path created them, so the very
# first login on a new machine failed with a 500 ("An error occurred
# while trying to encode the JWT token") — GOLIVE cold-start finding L4.
# CI is unaffected (workflows generate keys themselves before boot) and
# prod is skipped entirely: production keys are provisioned explicitly
# with a real passphrase (secrets vault), never from an entrypoint hook.
#
# Gated on PIM_JWT_AUTOGEN, set by docker-compose ONLY on the api
# service: the worker shares the same bind mount and entrypoint, and two
# containers generating concurrently on first boot could interleave into
# a mismatched pair. The worker never encodes JWTs, so it needs no keys.
#
# --skip-if-exists makes the step idempotent — existing keys are never
# touched, so a restart cannot invalidate issued tokens. The follow-up
# check-config is warn-only: it catches a passphrase changed AFTER the
# keys were generated (keys load-fail) without blocking the boot.
if [ "${CI:-}" != "true" ] && [ "${PIM_JWT_AUTOGEN:-}" = "1" ] \
    && { [ "${APP_ENV:-dev}" = "dev" ] || [ "${APP_ENV:-dev}" = "test" ]; }; then
    if [ -x /app/bin/console ]; then
        echo "[entrypoint] Ensuring JWT keypair exists (lexik:jwt:generate-keypair --skip-if-exists)"
        php /app/bin/console lexik:jwt:generate-keypair --skip-if-exists --no-interaction \
            || echo "[entrypoint] WARN: JWT keypair generation failed; login will return 500 until keys exist. Run 'docker compose exec api bin/console lexik:jwt:generate-keypair' manually to diagnose."
        php /app/bin/console lexik:jwt:check-config --no-interaction \
            || echo "[entrypoint] WARN: JWT config check failed — likely JWT_PASSPHRASE no longer matches the existing keys. Regenerate with 'docker compose exec api bin/console lexik:jwt:generate-keypair --overwrite' (this invalidates all issued tokens)."
    fi
fi

# Run the seed guard only on dev / test, and never in CI — Playwright's
# workflow drives migrations + fixtures explicitly via `bin/console
# doctrine:migrations:migrate` after the container is up, and the seed
# guard's `pim:db:reset --with-fixtures` would race against that step
# (it creates the schema first, then the workflow's `migrations:migrate`
# trips "relation already exists"). GitHub Actions exports CI=true on
# every job; honour it as the universal "don't auto-mutate the DB" flag.
if [ "${CI:-}" != "true" ] && { [ "${APP_ENV:-dev}" = "dev" ] || [ "${APP_ENV:-dev}" = "test" ]; }; then
    if [ -x /app/bin/console ]; then
        echo "[entrypoint] Running pim:dev:ensure-seeded (env=${APP_ENV:-dev})"
        # AUD-002 (W1-1): the seed writes tenants/users/roles/objects under FORCE
        # ROW LEVEL SECURITY, but a CLI command never hits RlsContextListener so
        # the per-request tenant GUC is unset — as the runtime role `pim_app`
        # (NOBYPASSRLS) every insert would be denied. Run the seed on the owner
        # DSN (role `pim`, the table owner / BYPASSRLS) just like migrations.
        # DATABASE_URL_OWNER is injected by docker-compose; fall back to
        # DATABASE_URL so a single-role sandbox still seeds.
        DATABASE_URL="${DATABASE_URL_OWNER:-$DATABASE_URL}" \
            php /app/bin/console pim:dev:ensure-seeded --quiet-when-noop --no-interaction \
            || echo "[entrypoint] WARN: ensure-seeded failed; api will still start. Run 'docker compose exec api bin/console pim:dev:ensure-seeded' manually to diagnose."
    fi
fi

exec "$@"

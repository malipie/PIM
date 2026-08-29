#!/usr/bin/env bash
# Custom entrypoint wrapper for the PIM database image.
#
# Responsibilities (in order):
#   1. Register the postgres crontab via `crontab -u` and launch dcron in the
#      background so the production schedule (full/diff + weekly restore-test)
#      runs pgBackRest under the postgres user.
#   2. Launch pim-init-backup.sh in the background. It waits until postgres is
#      accepting connections, then idempotently creates the stanza and triggers
#      an initial full backup. Backgrounded so it never blocks postgres start-up.
#   3. Launch the idempotent runtime-role and pg_stat_statements bootstraps for
#      fresh and existing volumes; healthchecks enforce their final contracts.
#   4. Hand control over to the upstream `docker-entrypoint.sh postgres ...`
#      which performs initdb on first run and then exec's postgres.

set -euo pipefail

# AUD-017: dcron (dillon's cron 4.5) only loads a per-user crontab once it has
# been (re)registered through `crontab -u` — a file dropped straight into
# /etc/crontabs by the Dockerfile is synchronised for `root` but silently
# ignored for every other user, so the postgres schedule never fired and the
# backup cron was dead for ~49 days (the repo kept only the 2026-04-28 full).
# Re-install it from the baked-in source on every boot so dcron picks it up on
# a fresh volume AND a pre-existing one. Idempotent: same content each time.
CRONTAB_SRC=/etc/crontabs/postgres
if [ -f "${CRONTAB_SRC}" ]; then
    crontab -u postgres "${CRONTAB_SRC}"
fi

# dcron logs to stderr; -L /dev/stderr keeps everything visible in `docker logs`.
crond -b -L /dev/stderr

# TNT-P2-02 (#2864): sekcja stanzy w konfiguracji pgBackRest jest renderowana
# pod TĘ instancję, ZANIM ruszy cokolwiek, co czyta tę konfigurację (cron,
# init-backup, archive_command). Bez tego instancja tenanta ze stanzą
# `pim-<kod>` nie miałaby własnej sekcji i każda komenda pgBackRest padałaby.
/usr/local/bin/pim-render-pgbackrest-conf.sh || echo "[start-pim] WARN: render konfiguracji pgBackRest nie powiódł się"

# Background init: wait + stanza-create + initial backup. Survives postgres
# restarts because of the cron-based hourly schedule, but on first run we want
# the repo populated immediately so the restore test can run any time.
/usr/local/bin/pim-init-backup.sh &

# AUD-002 (W1-1): wait + idempotently create/sync the NOSUPERUSER NOBYPASSRLS
# runtime role `pim_app` so it exists (with the right password) before the api
# connects — on a fresh volume AND a pre-existing one. Backgrounded so it never
# blocks postgres start-up; the operations inside are idempotent.
/usr/local/bin/pim-init-app-role.sh &

# AUD-OBS-002 (#3026): CREATE EXTENSION is per database and the upstream
# initdb hooks do not run for existing volumes. Ensure it on every boot; the
# database healthcheck stays red until both the preload and view are usable.
/usr/local/bin/pim-init-query-stats.sh &

exec docker-entrypoint.sh "$@"

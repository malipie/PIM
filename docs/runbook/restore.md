# Database restore runbook

> Scope: PITR (point-in-time recovery) procedure for PIM's pgBackRest +
> MinIO topology. Production schedule (weekly full + daily diff +
> async WAL + automated weekly restore test) landed with #106 / 0.11.11.

## What's wired

- Custom `database` image: `postgres:16-alpine` + `pgbackrest` + `dcron`
  (`docker/postgres/Dockerfile`).
- WAL archiving: `archive_mode=on`, `archive_command='pgbackrest --stanza=pim
  archive-push %p'`. Continuous, async via `archive-async=y`.
- Repository: MinIO bucket `pim-pgbackrest` (dedicated repo bucket, separate
  from the DAM assets bucket — AUD-018/W1-6), path `/pim`. Dev credentials reuse
  `MINIO_ROOT_USER` / `MINIO_ROOT_PASSWORD` via `PGBACKREST_REPO1_S3_KEY*` env
  vars in `docker-compose.yml`; prod takes a dedicated, fail-loud
  `PGBACKREST_REPO1_S3_KEY`/`_KEY_SECRET` and can target a separate MinIO/S3
  region (see "Backup storage layout" below).
- **Schedule** (cron inside the database container, see Dockerfile):
  - Sundays 02:00 UTC — `pgbackrest --type=full backup`.
  - Mon-Sat 02:00 UTC — `pgbackrest --type=diff backup`.
  - Saturdays 03:30 UTC — automated restore test (`pim-restore-test.sh`).
  - Initial full backup triggered by `pim-init-backup.sh` on first start.
- **Retention**: 4 full + 28 differential. Older backups are pruned
  automatically. Compression: `zst` level 3.
- **Automated weekly restore test**: `pim-restore-test.sh` clones the
  latest backup into `/tmp/pim-restore-test`, starts the side cluster on
  port 55432, runs three smoke counts (tenants / users) against the
  live and side clusters, and reports `PASS` / `FAIL` to
  `/var/log/pgbackrest/restore-test.log`. The 0.11.10 dashboard widget
  reads this file to surface the last-known-good restore time.
- Restore orchestrator: `scripts/pim-backup-restore.sh` (host).
- Acceptance test: `scripts/test-pgbackrest-restore.sh` (host).

## Day-to-day operations

### Inspect the repository

```bash
docker compose exec database pgbackrest --stanza=pim info
```

Shows backup labels, sizes, WAL ranges and which type each backup is. Fresh
stack with one full backup looks like:

```
stanza: pim
    status: ok
    cipher: none

    db (current)
        wal archive min/max (16): 000000010000000000000001 / 000000010000000000000002

        full backup: 20260428-153018F
            timestamp start/stop: 2026-04-28 15:30:18+00 / 2026-04-28 15:30:23+00
            wal start/stop: 000000010000000000000002 / 000000010000000000000002
            database size: 49.4MB, database backup size: 49.4MB
            repo1: backup set size: 6.5MB, backup size: 6.5MB
```

### Trigger a manual backup

```bash
# Incremental (default, fastest after a full)
docker compose exec database \
    su -s /bin/sh postgres -c "pgbackrest --stanza=pim backup"

# Force a full backup (rotates retention)
docker compose exec database \
    su -s /bin/sh postgres -c "pgbackrest --stanza=pim --type=full backup"
```

### Tail the cron log

```bash
docker compose exec database tail -f /var/log/pgbackrest/cron.log
```

## PITR — restoring to a specific moment

> ⚠ This destroys the current `$PGDATA`. Take a manual backup first if there
> is anything you cannot afford to lose.

### Latest backup (no replay)

```bash
pnpm backup:restore -- --type latest --no-confirm
# or
scripts/pim-backup-restore.sh --type latest
```

### Stop replay as soon as the backup is consistent (fastest)

```bash
scripts/pim-backup-restore.sh --type immediate
```

### Replay WAL up to a specific timestamp (canonical PITR)

```bash
scripts/pim-backup-restore.sh \
    --type time \
    --target "2026-04-28 12:34:56+00"
```

The orchestrator will:

1. `docker compose stop api` — releases FrankenPHP's persistent connections.
2. `docker compose stop database` — clean shutdown so the WAL is consistent.
3. `docker compose run --rm --no-deps --entrypoint /bin/sh database -c …` —
   wipes `$PGDATA` and runs `pgbackrest restore` against the existing volume.
   For `--type time` the wrapper passes `--target-action=promote` so the
   cluster finishes recovery **read-write** (a bare `--type=time` leaves it
   paused in read-only hot-standby until `pg_promote()`).
4. `docker compose up -d --wait database` — postgres replays WAL from the repo.
5. **Re-grant `pim_app`** — the wrapper re-applies the runtime role's schema
   grants (idempotent). A physical restore can land the cluster missing them
   and the W1-1 migration does **not** re-run, so `pim_app` would otherwise hit
   `permission denied for schema public`. Manual equivalent:
   ```sql
   GRANT USAGE ON SCHEMA public TO pim_app;
   GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO pim_app;
   GRANT USAGE, SELECT, UPDATE ON ALL SEQUENCES IN SCHEMA public TO pim_app;
   ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO pim_app;
   ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT USAGE, SELECT, UPDATE ON SEQUENCES TO pim_app;
   ```
6. `docker compose up -d --wait api` — healthchecks block until everything is back.

> **PITR quirk (fixed #2196):** the `--target` timestamp contains a space; the
> wrapper now double-quotes each pgBackRest arg so it stays a single argument.
> A repeatable drill lives at `scripts/restore-drill.sh` (inserts before/after
> markers, restores to a point between them, asserts precision + RTO).

### Verify

```bash
# RLS bypass as owner sees the recovered rows:
docker compose exec database \
    psql -U pim -d pim -c "SELECT count(*) FROM objects;"
# app role can query (RLS shows 0 without a tenant GUC — that is expected):
docker compose exec database \
    psql -U pim_app -d pim -c "SELECT count(*) FROM objects;"

curl -k https://pim.localhost/api/products | head
```

## Sprint 0 acceptance test

```bash
pnpm backup:test
# or
scripts/test-pgbackrest-restore.sh
```

The script:

1. Reads the baseline product count via `/api/products`.
2. Inserts 3 marker products tagged with a random prefix.
3. Forces an `incr` backup + a `pg_switch_wal()` so the markers are durable in
   the repo.
4. Deletes the markers (simulated data loss).
5. Runs `scripts/pim-backup-restore.sh --type latest --no-confirm`.
6. Re-authenticates and re-counts. Pass = post-restore count equals the
   pre-delete count.

This is the test referenced in ticket 0.0.15 DoD ("restore test passing —
produkty po restore = produkty przed dropem").

## Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| `stanza-create` fails with `unable to load info file` | MinIO bucket missing | `docker compose up -d minio-init` (check `pim-pgbackrest` exists in MinIO) |
| `archive-push` warnings in postgres log | Stanza not initialised yet | Wait for `pim-init-backup.sh` to finish, or run `pgbackrest --stanza=pim stanza-create` manually |
| `pgbackrest backup` complains about WAL not archived | `archive-async` queue full | Check `/var/spool/pgbackrest`, ensure MinIO is reachable from the database container |
| Restore fails with `unable to remove path` | API still holds connections | The orchestrator stops `api` first; if running pgbackrest manually, `docker compose stop api` before wiping `$PGDATA` |
| Healthcheck never goes green after restore | WAL replay still in progress | `docker compose logs -f database` and wait — large WAL ranges take longer |

## Backup storage layout (AUD-018 / W1-6)

The pgBackRest repo lives in its OWN MinIO bucket, **`pim-pgbackrest`**, separate
from the DAM assets bucket `pim-assets`. Co-locating them was a single point of
failure: losing the asset bucket also lost every database backup. The durable
buckets (`pim-assets`, `pim-pgbackrest`, `pim-exports`) have **versioning
enabled** so an accidental delete/overwrite is recoverable
(`mc cp --version-id`). The legacy `pim-backups` bucket is retained (its
2026-04-28 objects are untouched) but no longer the active repo target; the
stanza re-creates in `pim-pgbackrest`. See the MinIO DR section of
[`disaster-recovery.md`](disaster-recovery.md) for recovery procedures.

In **production**, the repo should point at a SEPARATE MinIO/S3 instance/region
(true anti-SPOF) via the prod overlay's
`database.PGBACKREST_REPO1_S3_ENDPOINT`/`_BUCKET` env (no rebuild needed —
pgBackRest reads `PGBACKREST_*` env over the baked-in config).

## Production gaps (post-0.11.11 follow-ups)

- **Off-site asset replication** — wired as `scripts/minio-mirror-assets.sh` +
  the `mirror-assets` sidecar (prod overlay, profile `dr`). Needs a real second
  MinIO region/account provisioned; consider a native `mc replicate` rule for
  managed replication.
- **Off-site repo replication** — point `PGBACKREST_REPO1_S3_ENDPOINT` at a
  dedicated backup region (prod overlay). Provisioning of that region is the
  remaining deploy-time step.
- **Encryption at rest** for the repo (`repo1-cipher-type=aes-256-cbc` +
  `repo1-cipher-pass` from Vault). Today the repo lives on TLS-terminated
  MinIO; at-rest encryption is opt-in.
- **Hardened credentials** — the prod overlay now takes a dedicated
  `PGBACKREST_REPO1_S3_KEY`/`_KEY_SECRET` (fail-loud, no default) so the repo
  can authenticate with a bucket-scoped service account instead of MinIO root.
  Creating that scoped account on the backup store is the deploy-time step.
- **Slack/Sentry alert** when `pim-restore-test.sh` fails or when no
  successful backup landed in the last 24h. The 0.11.10 dashboard
  widget surfaces it; alerting needs a dedicated webhook in 0.11.10
  follow-up.

See `Project Plan/02-plan-projektu-pim.md` §0.11.8 and §0.11.11.

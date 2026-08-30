# Disaster Recovery Runbook

> **Audience:** the on-call operator. Each section follows a SHTF
> structure — **Symptom**, **Triage**, **Action**. Run commands as
> written. When in doubt, restore from backup; PIM data is the
> authoritative copy of customer-facing product information.

This runbook complements:

- [`docs/runbook/restore.md`](restore.md) — PITR walkthrough for pgBackRest.
- [`docs/multi-tenancy.md`](../multi-tenancy.md) — tenant isolation model.
- [`docs/rbac.md`](../rbac.md) — RBAC matrix.
- [`Project Plan/01-architektura-pim.md`](../../Project%20Plan/01-architektura-pim.md) §11 — security architecture.

## Contents

1. [PostgreSQL — point-in-time recovery (PITR)](#1-postgresql--point-in-time-recovery-pitr)
2. [JWT signing key — emergency rotation](#2-jwt-signing-key--emergency-rotation)
3. [Argon2id rehash — password / API key drift](#3-argon2id-rehash--password--api-key-drift)
4. [BYOK Anthropic — per-tenant key provisioning](#4-byok-anthropic--per-tenant-key-provisioning)
5. [Compromised API key — revocation + forensics](#5-compromised-api-key--revocation--forensics)
6. [Suspected data breach — containment + audit log forensics](#6-suspected-data-breach--containment--audit-log-forensics)
7. [Audit log — retention + manual prune](#7-audit-log--retention--manual-prune)
8. [Async worker stuck — tenant context drift](#8-async-worker-stuck--tenant-context-drift)
9. [Rate limiter — operator unblock](#9-rate-limiter--operator-unblock)
10. [MinIO — object storage disaster recovery](#10-minio--object-storage-disaster-recovery)

---

## 1. PostgreSQL — point-in-time recovery (PITR)

**Symptom:** corrupted DB / accidental drop / ransomware / region failure.

**Triage**
1. Stop write traffic — disable Caddy `/api/*` route or scale `api` to 0.
2. Identify the target time `T` (just before the destructive event).
3. Confirm pgBackRest repo is reachable: `docker compose exec database pgbackrest --stanza=pim info`.

**Action** — see full walkthrough in [`docs/runbook/restore.md`](restore.md). Short form:

```bash
# 1. Run the orchestrator with the recovery target.
PGBACKREST_TARGET_TIME='2026-04-30 14:30:00 UTC' \
  ./scripts/pim-backup-restore.sh

# 2. Validate post-restore.
docker compose exec api php bin/console pim:tenant:audit
docker compose exec api php bin/console doctrine:migrations:status

# 3. Smoke check the application surface.
curl -sk https://pim.localhost/api | jq .
```

**RPO / RTO targets** (production, after #106 / 0.11.11 lands):

| Target          | Sprint 0 baseline | Production |
|-----------------|-------------------|------------|
| RPO (data loss) | ~1 h (hourly)     | ≤5 min (WAL streaming) |
| RTO (downtime)  | manual ~30 min    | ≤15 min (runbook automated) |

---

## 2. JWT signing key — emergency rotation

**Symptom:** suspected leak of `JWT_PASSPHRASE` / private key / kernel
core dump exposing memory.

**Triage**
1. Note the time `T_leak`. Anything signed before `T_rotate` is suspect.
2. Inventory active sessions: `SELECT count(*) FROM refresh_tokens
   WHERE revoked_at IS NULL AND expires_at > now()`.

**Action**

```bash
# 1. Generate a fresh keypair.
docker compose exec api php bin/console lexik:jwt:generate-keypair --overwrite

# 2. Force-revoke every refresh token (logs everyone out).
docker compose exec api php bin/console doctrine:query:sql \
  "UPDATE refresh_tokens SET revoked_at = now() WHERE revoked_at IS NULL"

# 3. Rotate the JWT_PASSPHRASE secret in the deployment vault and roll
#    api containers so the new key + passphrase load.
#    (Specifics depend on the host orchestrator.)

# 4. Notify all active operator users (out-of-band).
```

**Verify**: any access token issued before rotation now returns 401
on `/api/me`. New login → new keypair → access works.

---

## 3. Argon2id rehash — password / API key drift

**Symptom:** PHP / libsodium upgrade pushes the recommended Argon2id
parameters past what your stored digests carry. Symptom: warnings in
logs from `password_needs_rehash($hash, PASSWORD_ARGON2ID) → true`.

**Triage** — identify drift volume:

```bash
docker compose exec api php bin/console doctrine:query:sql \
  "SELECT count(*) FROM users WHERE password LIKE '\$argon2id\$%'"
```

**Action**

User passwords rehash on next successful login (Symfony's
`PasswordAuthenticatedUserInterface` does the dance automatically).
**No bulk action needed** — let the rehash flow naturally.

API keys rehash on next successful authentication via
`Argon2idApiKeyHasher::needsRehash()` in
`ApiKeyAuthenticator::authenticate()`. **No bulk action** — the auth
hot path takes care of it.

If a user / partner is dormant for >90 days and the rehash queue is
non-empty, consider a forced password reset email.

---

## 4. BYOK Anthropic — per-tenant key provisioning

**Symptom:** enterprise tenant insists on paying their own LLM bill;
or the platform key approaches its $1000/month org cap.

**Action** — full BYOK setup lands with **#107 / 0.11.12**. Until then,
the platform key is shared (`ANTHROPIC_API_KEY` env var on `api`
container) and per-tenant cost limits live in
[`Project Plan/01-architektura-pim.md`](../../Project%20Plan/01-architektura-pim.md)
§8.5 (50 tool calls/h/user, $20/d/tenant, $300/mo/tenant).

After #107: the admin UI exposes `Settings → API keys → Anthropic`.
The submitted key is encrypted AES-256-GCM with `APP_BYOK_KEY`
(server-side master) before persistence. Runtime resolver picks
tenant-specific key when present, falls back to platform key
otherwise.

**Rotation** — operator submits a fresh key, old one is overwritten.
No pin to old keys; the previous value is unrecoverable from the DB.

---

## 5. Compromised API key — revocation + forensics

**Symptom:** partner reports leaked key / key appears in a public
paste / spike in `pim:audit:cleanup --dry-run` numbers from a single
prefix.

**Action**

```bash
# 1. Find the row by prefix (operator only sees the prefix).
docker compose exec api php bin/console doctrine:query:sql \
  "SELECT id, name, key_prefix, last_used_at FROM api_keys
   WHERE key_prefix = 'pim_live_a4f2'"

# 2. Revoke (soft-delete — keeps audit trail).
docker compose exec api php bin/console doctrine:query:sql \
  "UPDATE api_keys SET revoked_at = now() WHERE id = '<uuid>'"

# 3. Inspect what the key did.
docker compose exec api php bin/console doctrine:query:sql \
  "SELECT type, object_id, blame_user, ip, created_at, diffs
   FROM api_keys_audit
   WHERE blame_id = '<key uuid as string>'
   ORDER BY created_at DESC LIMIT 200"

# 4. Generate a replacement key for the partner via CLI.
docker compose exec api php bin/console pim:apikey:generate \
  --tenant=<tenant-code> \
  --name='Partner X — replacement post-leak' \
  --scopes=<comma-separated-profile-codes>
# Capture the raw key from stdout. Hand off to partner via secure channel.
```

The previous key's `last_used_at` and `audit log` rows give you the
incident window. Cross-reference Caddy access logs (off-line) for
the source IPs.

---

## 6. Suspected data breach — containment + audit log forensics

**Symptom:** customer data showing up on a leak site / monitoring
flag on egress traffic.

**Triage** (in this order)
1. **Contain** — disable suspect API keys + JWT signing key (sections 2 + 5).
2. **Snapshot** — `pgbackrest backup --type=full` (out-of-cycle full
   for forensic analysis) + freeze the MinIO buckets `pim-pgbackrest`
   (backup repo) and `pim-assets` (DAM). Versioning is enabled on both, so
   prior object versions are preserved for forensics even if data is altered.
3. **Inventory** — what tenants are affected? Audit logs answer:

```bash
# Per tenant: every change in the last 7 days.
docker compose exec api php bin/console doctrine:query:sql \
  "SELECT al.created_at, al.type, al.object_id, al.blame_user, al.ip
   FROM (SELECT * FROM users_audit
         UNION ALL SELECT * FROM api_keys_audit
         UNION ALL SELECT * FROM api_profiles_audit
         UNION ALL SELECT * FROM channels_audit
         UNION ALL SELECT * FROM attributes_audit
         UNION ALL SELECT * FROM object_types_audit) AS al
   WHERE al.created_at > now() - INTERVAL '7 days'
     AND al.blame_id IS NOT NULL
   ORDER BY al.created_at DESC LIMIT 1000"
```

4. **Notify** — affected tenants + DPA. GDPR clock starts at detection.
5. **Root cause** — review change log for the past 30 days, focus on
   commits touching auth / RBAC / TenantFilter.

---

## 7. Audit log — retention + manual prune

The audit log defaults to 365-day retention. Disk pressure or a one-off
purge requirement can call for ad-hoc pruning.

**Cron** (default — runs daily on prod):

```bash
docker compose exec api php bin/console pim:audit:cleanup --older-than=365d
```

**Manual short prune** (e.g. before a backup run on a hot disk):

```bash
docker compose exec api php bin/console pim:audit:cleanup --older-than=180d --dry-run
docker compose exec api php bin/console pim:audit:cleanup --older-than=180d
```

Retention windows shorter than 30 days trigger the GDPR breach
reporting clock — DO NOT prune below 30 days without legal sign-off.

---

## 8. Async worker stuck — tenant context drift

**Symptom:** worker logs `LogicException: Cannot persist X without a
current tenant` or rows show up under wrong tenant after a sync run.

**Triage**
1. Confirm the message in question carries either a `TenantStamp` or
   implements `TenantAwareMessage` (`App\Shared\Application\TenantAwareMessage`).
2. If the dispatcher omitted both — that's the bug. Fix the
   dispatcher; the middleware fails loud by design (see
   `TenantContextRebindingMiddleware`).
3. Rebind manually for a one-shot rescue:

```bash
docker compose exec api php bin/console messenger:consume async \
  --limit=1 --time-limit=30 -vv
```

The middleware writes a log line on every successful rebind — search
worker logs for `Tenant context rebound for async handler` to verify.

---

## 9. Rate limiter — operator unblock

**Symptom:** legitimate operator / NAT egress bumps the auth limiter
ceiling and is stuck on 429.

**Action**

```bash
# Single IP.
docker compose exec api php bin/console pim:security:unblock-ip 1.2.3.4

# Need to forgive many IPs at once (e.g. office NAT)?
# Loop in shell — the command is idempotent + fast.
for ip in 203.0.113.10 203.0.113.11 203.0.113.12; do
  docker compose exec api php bin/console pim:security:unblock-ip "$ip"
done
```

The command resets BOTH `auth_login` and `auth_refresh` buckets for
the supplied IP. API-key budgets aren't covered by this command —
those are per-key, not per-IP; use the rotate-secret endpoint
instead.

---

## 10. MinIO — object storage disaster recovery

> **AUD-018 / W1-6.** MinIO holds two distinct classes of data with very
> different recovery stories:
>
> - **DAM assets** (`pim-assets`) — the ONLY copy of product imagery/files.
>   Unlike Postgres there is no WAL/PITR; durability comes from bucket
>   versioning + off-site replication.
> - **pgBackRest repo** (`pim-pgbackrest`) — the database backup. It now lives
>   in its OWN bucket, separate from the assets, so an assets-storage failure
>   cannot take the database backups with it.

### Defence layers (what protects what)

| Layer | Protects against | Where configured |
|---|---|---|
| **Bucket versioning** (`pim-assets`, `pim-pgbackrest`, `pim-exports`) | Accidental / malicious object delete or overwrite | `minio-init` (`mc version enable`) — base `docker-compose.yml` |
| **Repo bucket separation** (`pim-pgbackrest` ≠ `pim-assets`) | Losing both assets and DB backups in one failure | `docker/postgres/pgbackrest.conf` (`repo1-s3-bucket`) |
| **Separate backup store** (prod: dedicated MinIO/S3 region) | Loss of the whole primary MinIO instance taking the DB repo too | prod overlay `database.PGBACKREST_REPO1_S3_ENDPOINT/_BUCKET` |
| **Asset replication** (prod: `mc mirror` / bucket-replication rule) | Loss of the primary `minio_data` volume losing all assets | `scripts/minio-mirror-assets.sh` + prod overlay `mirror-assets` (profile `dr`) |

### Symptom A — accidental object delete / overwrite (versioning recovery)

A user or a buggy job deleted or clobbered an asset/export object. The bucket is
versioned, so the previous version is still there behind a delete-marker.

**Action**

```bash
# Helper: run mc against the live MinIO (replace creds in prod).
MC() { docker compose run --rm --no-deps --entrypoint /bin/sh minio/mc:RELEASE.2025-08-13T08-35-41Z@sha256:a7fe349ef4bd8521fb8497f55c6042871b2ae640607cf99d9bede5e9bdf11727 -c \
  "mc alias set local http://minio:9000 \"$MINIO_ROOT_USER\" \"$MINIO_ROOT_PASSWORD\" >/dev/null; $*"; }

# 1. List every version of the object (delete-markers + prior PUTs).
MC 'mc ls --versions local/pim-assets/<tenant-uuid>/<asset-uuid>/original.bin'

# 2. Read the prior (non-delete-marker) version by its version-id to confirm.
MC 'mc cat --version-id <VERSION_ID> local/pim-assets/<...>/original.bin'

# 3. Restore it as the current version (copy the old version onto the key).
MC 'mc cp --version-id <VERSION_ID> local/pim-assets/<...>/original.bin \
                                    local/pim-assets/<...>/original.bin'
```

> Validated 2026-06-18 on the live dev stack: put → `mc rm` (delete-marker) →
> the prior `PUT` version survives → `mc cp --version-id` restores the bytes.

### Symptom B — total loss of the primary MinIO volume / instance

`minio_data` is gone (volume corruption, host loss, ransomware). Assets and — on
dev, where the repo shares the instance — the DB backup repo are unreachable.

**Triage**
1. Stand up a fresh MinIO (new `minio_data` volume). `minio-init` recreates the
   buckets and re-enables versioning idempotently on first boot.
2. Decide the source of truth for assets: the off-site replica (prod) or, on dev
   where no replica exists, accept asset loss (assets are non-authoritative dev
   fixtures; product DATA is authoritative and restored from Postgres).

**Action — restore assets from the replica (prod)**

```bash
# Pull the replica back into the rebuilt primary (reverse of the normal mirror).
MIRROR_SOURCE_URL=<replica-endpoint> MIRROR_SOURCE_KEY=… MIRROR_SOURCE_SECRET=… \
MIRROR_SOURCE_BUCKET=pim-assets-replica \
MIRROR_TARGET_URL=http://minio:9000 MIRROR_TARGET_KEY="$MINIO_ROOT_USER" \
MIRROR_TARGET_SECRET="$MINIO_ROOT_PASSWORD" MIRROR_TARGET_BUCKET=pim-assets \
  docker compose run --rm --no-deps -v "$PWD/scripts:/scripts:ro" \
  --entrypoint /bin/sh minio/mc:RELEASE.2025-08-13T08-35-41Z@sha256:a7fe349ef4bd8521fb8497f55c6042871b2ae640607cf99d9bede5e9bdf11727 /scripts/minio-mirror-assets.sh
```

**Action — restore the database (repo on a separate store survives)**

Because `pim-pgbackrest` is a separate bucket (prod: separate instance), the
pgBackRest repo is unaffected by an assets-only failure. Follow
[§1 PITR](#1-postgresql--point-in-time-recovery-pitr) / `restore.md` as usual.
On dev (shared instance) the repo is lost with the volume; rebuild from the
newest `backups/*.dump` + migrations per the dev DB recovery policy.

### Restore-from-zero drill (assets + database)

The end-to-end "rebuild from nothing" target for W1-6. Do NOT run by wiping
`minio_data` on a machine you care about — exercise it on a throwaway stack:

1. Fresh stack, empty volumes → `minio-init` recreates + versions buckets.
2. `mc mirror` the off-site asset replica back into `pim-assets` (Symptom B).
3. pgBackRest `stanza-create` + restore the latest backup from `pim-pgbackrest`
   (separate store) → PITR to target time.
4. Smoke: login `200`, a product read returns a signed `previewUrl`, the signed
   URL serves bytes (`200`, correct `content-type`).

### Enabling replication in production

```bash
# Provision a SECOND MinIO/S3 region with a bucket-scoped service account
# (NOT root). Then run the mirror sidecar (profile `dr`):
MIRROR_TARGET_URL=https://minio-dr.example.com \
MIRROR_TARGET_KEY=<replica-key> MIRROR_TARGET_SECRET=<replica-secret> \
  docker compose -f docker-compose.yml -f docker-compose.prod.yml \
  --profile dr up -d mirror-assets
```

Prefer a native MinIO bucket-replication rule (`mc replicate add`) for managed,
event-driven replication where available; the `mc mirror` sidecar is the
portable fallback.

---

## On-call escalation

1. **Self-resolve** — try the runbook section that matches the symptom.
2. **Snapshot** — `pgbackrest backup --type=full`, copy `var/log/`
   off-host, capture `docker compose logs --since=1h`.
3. **Notify** — operator (Marcin Lipiec) via the team's escalation
   channel.
4. **Post-incident** — append a short note to `agent/lessons.md` so
   the next incident hits the runbook faster.

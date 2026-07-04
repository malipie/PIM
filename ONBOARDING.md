# Onboarding — PIM platform

Three milestones for a developer (or a fresh Claude Code session) to land productively.

## Day 1 — environment up (≤4h)

Pre-requisites: Docker Desktop with at least 8 GiB allocated, Node 22, pnpm 10, OpenSSL.

```bash
git clone https://github.com/malipie/PIM.git
cd PIM
pnpm install
pnpm stack:up                 # docker compose up -d (api + admin + caddy + postgres + redis + meilisearch + minio + mercure)
docker compose exec -T api bin/console pim:db:reset --with-fixtures   # canonical one-shot: drop+create+migrate+audit:schema:update+fixtures
```

> `pim:db:reset` re-runs itself on the owner connection (`DATABASE_URL_OWNER`) automatically — since the W1-1 role split the runtime role `pim_app` has no DDL/BYPASSRLS, so the raw sub-commands fail under it (#2178). The drop uses `DROP DATABASE … WITH (FORCE)`, so api/worker sessions are terminated atomically — no need to stop containers first.
>
> The manual equivalent is **three** steps, not two — `audit:schema:update` is required: audit tables live outside the Doctrine migration pipeline, so without it any INSERT into an audited entity 500s with `relation "*_audit" does not exist`. Two of them need the owner DSN swap (`migrations:migrate` has its own owner connection in config):
>
> ```bash
> docker compose exec -T api bin/console doctrine:migrations:migrate --no-interaction
> docker compose exec -T api sh -c 'DATABASE_URL="$DATABASE_URL_OWNER" bin/console audit:schema:update --force'
> docker compose exec -T api sh -c 'DATABASE_URL="$DATABASE_URL_OWNER" bin/console doctrine:fixtures:load --no-interaction'
> ```

Open `https://pim.localhost` (Caddy will produce a self-signed cert — accept it once). Login with `admin@demo.localhost` / `changeme`. Navigate to **Products** — the demo dataset seeded by `App\DataFixtures\AppFixtures` should be visible.

### JWT keys

Login tokens are signed with an RSA keypair in `apps/api/config/jwt/` (gitignored). **In dev/test the api container generates it automatically on first boot** (`lexik:jwt:generate-keypair --skip-if-exists` in the entrypoint), using whatever `JWT_PASSPHRASE` resolves to — you don't need to do anything. To use your own passphrase, set `JWT_PASSPHRASE=<value>` in `apps/api/.env.local` **before** the first boot.

Manual generation (production, or running outside Docker — never auto-generated when `APP_ENV=prod`):

```bash
docker compose exec -T api bin/console lexik:jwt:generate-keypair
```

Troubleshooting — `POST /api/auth/login` returns 500 `An error occurred while trying to encode the JWT token`:

- **Keys missing** (e.g. wiped manually): restart the api container (regenerates) or run the command above.
- **Passphrase changed after the keys were generated**: `docker compose exec -T api bin/console lexik:jwt:check-config` will fail; regenerate with `lexik:jwt:generate-keypair --overwrite` (invalidates all issued tokens — everyone logs in again).

Verify your environment with the same gates CI runs:

```bash
docker compose exec -T api composer phpstan
docker compose exec -T api composer cs-check
docker compose exec -T api composer deptrac
docker compose exec -T api php bin/phpunit --testsuite=unit
pnpm --filter @pim/admin lint
pnpm --filter @pim/admin typecheck
pnpm --filter @pim/admin build
```

If any of these fail, fix before writing your first PR — the CI rejects the same matrix.

## Day 3 — first PR

Pick something contained: add a new `AttributeOption` to an existing `Attribute`, or extend `BuiltInObjectTypeSeeder`. Workflow:

1. Read [CLAUDE.md](CLAUDE.md) — operator + agent contract for this project.
2. Read [Project Plan/02-plan-projektu-pim.md](Project%20Plan/02-plan-projektu-pim.md) section for the epic you are working on.
3. Read [agent/lessons.md](agent/lessons.md) — patterns to follow / avoid.
4. Branch off main, push to GitHub, open a PR. Required CI checks are: `PHPStan max`, `PHP-CS-Fixer (dry-run)`, `Deptrac (architectural fitness)`, `PHPUnit`, `Playwright E2E`, `Biome strict`, `TypeScript noEmit`, `Vite build (smoke)`.
5. Use Conventional Commits (`feat(catalog): ...`, `refactor(asset): ...`, `fix(identity): ...`). Commit subject ≤72 chars; body in English.

## Day 10 — bigger feature

Read the architecture in this order:

1. [docs/architecture/c4-context.md](docs/architecture/c4-context.md) — what talks to what.
2. [docs/architecture/c4-container.md](docs/architecture/c4-container.md) — what runs where.
3. [docs/architecture/bounded-contexts.md](docs/architecture/bounded-contexts.md) — BC ringfence + domain event flow.
4. [docs/adr/](docs/adr/) — architectural decisions, especially ADR-0010 (top-level layout), ADR-0013 (Deptrac), ADR-0014 (Tenant in Shared), ADR-0015 (cross-BC FK policy).

Then pick a BC, follow its `Domain/Application/Infrastructure/Contracts` ring, and add the use case. New aggregates extend `Shared\Domain\AggregateRoot` and emit events; subscribers live next to the BC that reacts. Cross-BC reads go through `<BC>\Contracts\Query\*` DTOs; cross-BC writes go through events.

When in doubt: re-run the audit at `Zrodla/Zalecana_struktura_kodu/Audyt/AUDIT-CHECKLIST.md` and look at the latest report under `docs/audits/`.

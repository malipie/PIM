# Contributing to PIM

> Single source of truth for the team contract. Workflows below are enforced by
> hooks (`.husky/`) and CI (`.github/workflows/`); breaking them locally just
> moves the failure from your laptop to a PR check, so prefer fixing the issue
> over bypassing the gate.

## Quick start

```bash
pnpm install              # registers husky hooks via the prepare script
cp .env.example .env      # local secrets stay out of git
pnpm dev                  # full stack (Caddy + FrankenPHP + Postgres + …)
```

`pim.localhost` resolves natively on macOS (RFC 6761). On Linux/Windows add
`127.0.0.1 pim.localhost` to `/etc/hosts`. Caddy ships with a local CA — accept
the cert on first request or trust the CA at the OS level.

For the full lay of the land see the top-level [README.md](README.md),
[`docs/architecture/`](docs/architecture/) and the ADRs in [`docs/adr/`](docs/adr/).

## Branch naming

`<type>/<short-slug>` — keep slugs short and kebab-cased. The type prefix has
to match the matching commit `type` (see below) so reviewers can predict the
PR scope from the branch name alone.

Examples:

- `feat/sprint-0-pgbackrest`
- `fix/admin-login-redirect`
- `chore/adr-009-issue-reshape`
- `docs/api-platform-quirks`

Avoid Polish in branch names — they end up in `git log` and CI artifacts.

## Commits — Conventional Commits, English

```
<type>(<scope>): <subject>
<BLANK LINE>
<body — why, not what>
<BLANK LINE>
<footer>
```

Format rules (commitlint enforces them on every commit):

- **type** — one of `feat`, `fix`, `chore`, `docs`, `refactor`, `test`, `ci`,
  `build`, `perf`, `style`.
- **scope** — bounded context or area: `catalog`, `identity`, `infra`,
  `admin`, `messaging`, `agent`, etc. Optional but encouraged.
- **subject** — imperative mood, ≤72 chars, no trailing period. Write it as
  the next line of `git log` — "add", "fix", "remove" — not "added" / "fixes".
- **body** — explain *why*, not *what* (the diff already shows what). Wrap at
  ~72 chars. Reference the architectural decision or the prior incident that
  motivated the change so a future reader can rebuild the rationale.
- **footer** — `Refs #N` or `Closes #N` to link a GitHub issue. **No
  `Co-Authored-By` for AI tools.** Commit history stays neutral about which
  tool produced the change.

```
feat(catalog): add ObjectType entity with tenant isolation

Initial ObjectType (kind='product') with tenant_id, ObjectTypeAttribute
junction, and is_built_in flag. Doctrine ORM annotations + API Platform
ApiResource declaration. Tenant filter applied via TenantAssignmentListener.

Refs #32
```

Polish is fine in project docs, GitHub issues, PR descriptions
and review comments — that's our internal language. Code, identifiers,
comments and commit metadata are English.

## Pull requests

1. Branch off `main`, push early, open the PR while WIP if you want feedback.
2. **Definition of Done** (lessons.md "Definicja Done — automation-first"):
   - PHPStan max + Biome strict — green
   - PHPUnit ≥80% on new logic + ApiTestCase for new endpoints
   - Playwright E2E for any user-visible change (no E2E ⇒ ticket is not done)
   - `composer audit` + `pnpm audit` — no high/critical
   - Manual smoke ≥5 min on `https://pim.localhost`
3. Atomic, reviewable commits. Squash-merge is the default; keep the per-step
   commit history readable so the squash narrative writes itself.
4. PR description — at minimum a `## Summary` (1–3 bullets) and a
   `## Test plan` checklist. Reference the issue with `Closes #N`.
5. CI must be green before merge. Branch protection on `main` enforces this
   plus an up-to-date branch.

## Hook expectations

`pnpm install` registers two hooks via husky:

- `pre-commit` — `lint-staged`: Biome on JS/TS/JSON, PHP-CS-Fixer on PHP. Runs
  only against staged files; ≤2 s on a typical change.
- `commit-msg` — `commitlint` validates the Conventional Commits format.

Bypassing hooks (`git commit --no-verify`) is reserved for genuine emergencies
and should be flagged in the PR. Every Sprint-0 ticket landed without skipping
hooks — keep that streak.

## Quality gates locally

```bash
# Frontend
pnpm typecheck            # tsc --noEmit, all workspaces
pnpm lint                 # turbo run lint
pnpm build                # turbo run build

# PHP (inside the api container)
docker compose exec api composer phpstan
docker compose exec api composer cs-check
docker compose exec -e APP_ENV=test api php bin/phpunit

# E2E (host-side; alpine container can't host Playwright deps — see lessons)
pnpm --filter @pim/admin e2e
```

If any of those fails on a fresh checkout, that's a real bug — open an issue
or fix it before continuing.

## Backups, restore, and other ops

`scripts/pim-backup-restore.sh` handles PITR; `scripts/test-pgbackrest-restore.sh`
is the Sprint-0 acceptance test. Full operational guidance is in
[`docs/runbook/restore.md`](docs/runbook/restore.md). Production-grade backup
schedule lands in 0.11.11 — the current setup is a stub good enough for the
gate decision.

## Things to never commit

- `.env`, `.env.local`, anything matching `*.local` (gitignored).
- `apps/api/config/jwt/*.pem` (keys are dev-only, generated locally / per CI run).
- `.claude/` (per-developer Claude Code state — gitignored).
- Real customer data, exports, screenshots with PII. Synthetic fixtures only.
- LLM-tool footers like `Co-Authored-By: Claude …` — they belong in PR
  descriptions if anywhere, not in commit metadata.

## Where to look when stuck

- [`docs/adr/`](docs/adr/) — Architecture Decision Records (index in
  [`docs/adr/README.md`](docs/adr/README.md)).
- [`docs/architecture/`](docs/architecture/) — C4 diagrams, bounded contexts,
  concurrency matrix.
- [`docs/development/adding-a-field-or-endpoint.md`](docs/development/adding-a-field-or-endpoint.md) —
  end-to-end vertical slice walkthrough.

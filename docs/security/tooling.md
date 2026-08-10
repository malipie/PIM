# Security Tooling — Cortex PIM

> Status: MVP-Alpha. Source: [RBAC-P1-001](https://github.com/malipie/PIM/issues/640) (#640).
> ADR-013 (RBAC od dnia 1) requires multi-layer security gating on every PR.

## Tooling stack — current state

### Shipped (RBAC-P1-001 + pre-existing)

| Tool | Where | What it catches | Run locally |
|---|---|---|---|
| **PHPStan max** | CI (`quality-php.yml`) + Husky pre-commit (via `lint-staged-php.sh`) | Static analysis, type errors, null safety, undefined methods | `docker compose exec api composer phpstan` |
| **PHPUnit** | CI (`quality-php.yml`) | Unit + integration tests, schema validation via Foundry `ResetDatabase` | `docker compose exec api php bin/phpunit` |
| **Deptrac** | CI (`quality-php.yml`) | Architectural fitness — cross-bundle dependency rules per `deptrac.yaml` (Catalog/Channel/Asset/Identity/… contracts boundary) | `docker compose exec api composer deptrac` |
| **PHP-CS-Fixer** | CI (`quality-php.yml`) + Husky pre-commit | Code style, PSR-12 + project rules | `docker compose exec api composer cs-check` |
| **raw-sql-lint** | CI (`quality-php.yml`) | HARD-07 — flags raw SQL bypassing TenantFilter | `scripts/lint-raw-sql.sh` |
| **composer audit** | CI (`audit.yml`) + on every `composer install` | Known CVE in composer deps (deprecation-style notices) | `docker compose exec api composer audit` |
| **Roave Security Advisories** | composer require-dev (`roave/security-advisories: dev-latest`) | **Blocks** `composer require` of packages with known CVEs at install time (stronger than `audit` which only warns) | Automatic — composer fails install if any dependency matches a published advisory |
| **Biome strict** | CI (`quality-frontend.yml`) + Husky pre-commit | TS/JS lint + format, blocks `console.log`/`debugger`/`var` etc. | `pnpm exec biome check` |
| **TypeScript noEmit** | CI (`quality-frontend.yml`) | TS type errors across `apps/admin` | `pnpm --filter @pim/admin typecheck` |
| **Vite build smoke** | CI (`quality-frontend.yml`) | Production build succeeds without errors | `pnpm --filter @pim/admin build` |
| **Playwright E2E** | CI (`quality-frontend.yml`) | Browser-level smoke + critical path tests | `pnpm --filter @pim/admin e2e` |
| **pnpm audit** | CI (`audit.yml`) | Known CVE in npm deps | `pnpm audit` |
| **OpenAPI spec drift** | CI (`quality-php.yml`) | `docs/api-spec/v{version}.json` matches `/api/docs.jsonopenapi` output (no unintentional API surface changes) | `composer openapi-export` |
| **Commitlint (Conventional Commits)** | Husky commit-msg | Commit message format `<type>(<scope>): <subject>` per [CLAUDE.md §"Konwencje języka"](../../CLAUDE.md) | Automatic on `git commit` |
| **Husky lint-staged** | Husky pre-commit | Runs Biome + PHPStan only on staged files (fast feedback) | Automatic on `git commit` |
| **Dependabot** | `.github/dependabot.yml` | Automated PRs for npm + composer + GitHub Actions updates (daily for backend/admin, weekly for tooling/CI) | n/a — auto-runs |
| **Gitleaks** | CI (`security-secrets.yml`) | Regex-based secrets-leak scan (AWS keys, Stripe tokens, private keys, JWT secrets) on every PR + push | n/a — auto-runs in CI |
| **TruffleHog** | CI (`security-secrets.yml`) + Husky pre-commit (if binary installed locally) | High-entropy + verified-secret scan (catches structured tokens gitleaks would miss). **Defence in depth** alongside gitleaks. | `brew install trufflehog` then `trufflehog git file://. --since-commit=HEAD --only-verified` |

### Wyjątki w `pnpm audit` (`pnpm.auditConfig.ignoreGhsas`)

Bramka `audit.yml` puszcza `pnpm audit --audit-level=high`, więc każdy *high* zatrzymuje
wszystkie PR-y. `package.json` trzyma listę świadomie zignorowanych advisory — ten
rejestr wyjaśnia, dlaczego. **Każdy nowy wpis wymaga uzasadnienia tutaj**, inaczej
lista po kilku miesiącach staje się workiem, do którego nikt nie zagląda.

| GHSA | Pakiet | Dlaczego zignorowane | Kiedy zdjąć |
|---|---|---|---|
| `GHSA-fx2h-pf6j-xcff` | `vite` ≤6.4.2 (tranzytywnie przez `vitepress`) | Obejście `server.fs.deny` w **dev serverze Vite na Windows**. Ten vite wchodzi wyłącznie z `vitepress` (strona dokumentacji) i nigdy nie startuje jako dev server — `apps/docs` robi tylko `vitepress build` do statycznego HTML na linuksowym CI. `vitepress@1.6.4` (najnowszy stabilny) przypina vite 5; 2.x jest dopiero w alfie, więc nie ma ścieżki upgrade'u. | Gdy `vitepress` 2.x wyjdzie stabilnie — wtedy bump i usunięcie wpisu |

Pozostałe wpisy pochodzą sprzed tego rejestru (#2745, #2699) i czekają na uzupełnienie
uzasadnień przy najbliższym ticketcie maintenance.

### Deferred (with explicit follow-up tickets)

| Tool | Reason for deferral | Where it lands |
|---|---|---|
| **Infection (PHP mutation testing)** | 2-3h config + baseline tuning (MSI thresholds per bundle) — substantial dedicated work, not required to start Phase 2/3 RBAC implementation | Follow-up ticket `RBAC-P1-001a` or Phase 6 CI lockdown (#720 — `final CI gates — coverage thresholds + mutation testing thresholds`) |
| **Semgrep custom rules** | Phase 6 has dedicated ticket [#722](https://github.com/malipie/PIM/issues/722) `Semgrep custom rules + final tooling lockdown` (5-7h). Implementing now would duplicate work. | [#722](https://github.com/malipie/PIM/issues/722) |
| **OWASP ZAP nightly** | Requires staging deployment; MVP-Alpha is dev-only (`pim.localhost`). Staging arrives pre-MVP-Final hardening. | Post-staging ticket, target Phase 7 pentest preparation [#724](https://github.com/malipie/PIM/issues/724) |
| **PHPStan custom rules (RBAC patterns)** | Phase 1 has dedicated ticket [#649](https://github.com/malipie/PIM/issues/649) `chore(static-analysis): custom PHPStan rules — RBAC pattern enforcement` | [#649](https://github.com/malipie/PIM/issues/649) |

## How to run locally

### Pre-requisites

```bash
pnpm stack:up       # Starts docker compose stack (api + database + redis + minio + admin + caddy)
```

### Run individual security checks

```bash
# Static analysis
docker compose exec api composer phpstan

# Tests
docker compose exec api php bin/phpunit

# Architectural rules
docker compose exec api composer deptrac

# Code style
docker compose exec api composer cs-check        # dry-run
docker compose exec api composer cs-fix          # auto-fix

# Composer audit (CVE check on installed deps)
docker compose exec api composer audit

# Frontend
pnpm --filter @pim/admin lint
pnpm --filter @pim/admin typecheck
pnpm --filter @pim/admin build
pnpm --filter @pim/admin e2e

# Run everything (full local CI parity)
pnpm exec lint-staged   # Manual trigger of Husky pre-commit logic
```

### TruffleHog (local install — optional)

The CI workflow `security-secrets.yml` runs gitleaks + TruffleHog on every PR.
The Husky pre-commit hook also runs TruffleHog if the binary is on `$PATH` —
a local pre-commit catch is faster than waiting for CI feedback.

```bash
# macOS
brew install trufflehog

# Linux
curl -sSfL https://raw.githubusercontent.com/trufflesecurity/trufflehog/main/scripts/install.sh | sh -s -- -b /usr/local/bin

# Scan current branch diff
trufflehog git file://. --since-commit=main --only-verified
```

## Negative-test recipes (for verifying tooling works)

### 1. Roave Security Advisories blocks vulnerable package

```bash
docker compose exec api composer require --dev "ramsey/uuid:^3.0"
# Expected: composer aborts with "Conflicting requirements: roave/security-advisories conflicts with ramsey/uuid <4.x.y due to CVE-..."
```

### 2. Gitleaks catches AWS key pattern

Create a file with a dummy AWS key:

```bash
echo 'AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE' >> /tmp/leak.env
git add /tmp/leak.env
git commit -m "test"
git push   # gitleaks-action in CI flags the leak and fails the workflow
```

### 3. TruffleHog catches structured token

```bash
# Use an actual JWT generated locally — DO NOT paste a real example string
# into this doc; gitleaks would flag the doc itself. Generate fresh:
JWT=$(jwt encode --secret "test-secret" '{"sub":"123"}')
echo "JWT_SECRET=$JWT" >> /tmp/token
git add /tmp/token
# Local pre-commit (if trufflehog installed): blocks the commit immediately.
# Otherwise: CI catches at push time.
```

### 4. Commitlint blocks non-Conventional commits

```bash
git commit --allow-empty -m "fixed stuff"
# Expected: rejected with "type may not be empty" — must use feat(scope): ... format
```

### 5. PHP-CS-Fixer catches style violations

```bash
echo '<?php $x=1;' > apps/api/src/test.php   # missing strict_types, bad spacing
docker compose exec api composer cs-check
# Expected: dry-run shows diff; commit would be blocked by Husky pre-commit
rm apps/api/src/test.php
```

## Maintenance

Per CLAUDE.md §"Zarządzanie zależnościami":

- **Maintenance ticket co 2 epiki** (1-2h) — review Dependabot PRs, run `composer outdated` + `pnpm outdated`, merge patch updates, queue minor/major for next ticket review
- **Automerge patch only** — Dependabot opens PRs with `dependencies` label; reviewer merges patches without diff review, holds minor/major for impact analysis
- **Pin to older version requires comment** in file with concrete reason (breaking incompatibility, missing platform support, unfixed bug + link to issue)

## Cross-references

- [`CLAUDE.md`](../../CLAUDE.md) §"Zarządzanie zależnościami" — maintenance cadence, automerge rules, pinning policy
- [`Project Plan/07-rbac-implementation-plan.md`](../../Project%20Plan/07-rbac-implementation-plan.md) §3 — RBAC security tooling requirements (full scope, including deferred items)
- [`Project Plan/01-architektura-pim.md`](../../Project%20Plan/01-architektura-pim.md) §15 R-26 — stack drift risk mitigation
- [`.github/dependabot.yml`](../../.github/dependabot.yml) — automation config
- [`.github/workflows/security-secrets.yml`](../../.github/workflows/security-secrets.yml) — secrets scan CI
- [`.github/workflows/quality-php.yml`](../../.github/workflows/quality-php.yml) — PHP CI gates
- [`.github/workflows/quality-frontend.yml`](../../.github/workflows/quality-frontend.yml) — Frontend CI gates
- [`.github/workflows/audit.yml`](../../.github/workflows/audit.yml) — composer + pnpm CVE audit

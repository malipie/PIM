# PIM — Product Information Management

Agentic-first PIM platform. Konkurent PIMcore/Akeneo.

**Status:** aktualna sub-faza, epik i blokery — zawsze w [`agent/current_status.md`](agent/current_status.md) (ten plik celowo nie hardcoduje fazy, żeby nie dryfował).

## Stack (MVP)

PHP 8.4 + Symfony 7.4 LTS + API Platform 4 + FrankenPHP 2.x worker mode · PostgreSQL 16 (JSONB+ltree+RLS) · Meilisearch · Redis 7 · React 19 + Vite + Refine.dev + shadcn/ui · Mercure (SSE) · Anthropic SDK PHP · monorepo Turborepo.

## Dokumentacja

- **`CLAUDE.md`** — konstytucja projektu (system prompt dla Claude Code)
- **`CONTRIBUTING.md`** + **`ONBOARDING.md`** — onboarding nowego developera
- **`docs/development/adding-a-field-or-endpoint.md`** — jak dodać pole/endpoint (vertical slice backend↔front)
- **`apps/admin/README.md`** — panel admina (Refine + shadcn): komendy, shared-types
- **`CHANGELOG.md`** — release history per epic
- **`Project Plan/01-architektura-pim.md`** — pełna architektura, ADR, model danych
- **`Project Plan/02-plan-projektu-pim.md`** — fazy, milestones, backlog, ryzyka
- **`docs/adr/`** — Architecture Decision Records (najnowszy numer = `ls docs/adr/`)
- **`docs/runbook/restore.md`** — pgBackRest PITR walkthrough
- **`docs/runbook/disaster-recovery.md`** — incident-response playbook (key rotation, breach forensics, async drift)
- **`docs/multi-tenancy.md`** + **`docs/rbac.md`** — security model deep-dive
- **`agent/current_status.md`** — aktualna sub-faza i postęp
- **`agent/lessons.md`** — patterns to follow / avoid, package quirks

## Struktura monorepo

```
.
├── apps/
│   ├── api/        Symfony 7.4 + API Platform 4 + FrankenPHP (PHP 8.4)
│   └── admin/      Vite + React 19 + TypeScript (Refine + shadcn dochodzą w 0.1.4)
├── packages/
│   └── shared-types/   TypeScript types generowane z OpenAPI spec (codegen, nie build)
├── docker/
│   └── caddy/      Edge Caddyfile — single-origin proxy
├── docker-compose.yml  Cały stack dev: Caddy + FrankenPHP + Postgres + Redis +
│                       Meilisearch + MinIO + Mercure + Mailpit
└── Project Plan/   Architektura, plan, ADR-y
```

## Wymagania

- **Docker** (Desktop / OrbStack / colima) — daemon musi działać
- **Node** ≥22 + **pnpm** ≥10 (`npm install -g pnpm@latest` lub via corepack)
- **`pim.localhost`** — macOS rozwiązuje automatycznie (RFC 6761). Inne systemy: dodaj `127.0.0.1 pim.localhost` do `/etc/hosts`.

## Lokalny development

```bash
# 1. Sklonuj i zainstaluj zależności node (root + apps/admin + packages/*)
pnpm install

# 2. Skopiuj .env.example → .env i podmień hasła (Postgres / Mercure / Meilisearch / MinIO).
#    Tylko .env.example jest w gitcie. .env (oraz .env.dev / .env.local) są
#    ignorowane i trzymają REALNE sekrety — patrz "Pliki .env" niżej.
cp .env.example .env

# 3. Wystartuj cały stack (build kontenerów przy pierwszym uruchomieniu trwa kilka minut)
#    Przy pierwszym boocie kontener api sam generuje parę kluczy JWT
#    (config/jwt/*.pem, gitignored) — bez tego login zwracałby 500.
#    Szczegóły + troubleshooting: ONBOARDING.md sekcja "JWT keys".
pnpm dev          # foreground, Ctrl+C zatrzymuje
# albo:
pnpm stack:up     # detached, działa w tle
pnpm stack:logs   # tail logów

# 4. Zainicjalizuj bazę: drop + create + migrate + audit:schema:update + fixtures.
#    Jeden skrót (zalecane — patrz "pim:db:reset" niżej):
docker compose exec -T api php bin/console pim:db:reset --with-fixtures --force
#    Manualny odpowiednik to TRZY kroki (audit:schema:update jest wymagany —
#    tabele *_audit są poza pipeline'em migracji; bez tego INSERT do audytowanej
#    encji wywala 500 "relation '*_audit' does not exist").
#    UWAGA (#2178): po splicie ról W1-1 audit:schema:update i fixtures:load
#    wymagają roli owner — stąd swap DATABASE_URL (migrate ma własne połączenie
#    owner w konfiguracji; pim:db:reset robi ten swap sam):
#      docker compose exec -T api php bin/console doctrine:migrations:migrate --no-interaction
#      docker compose exec -T api sh -c 'DATABASE_URL="$DATABASE_URL_OWNER" php bin/console audit:schema:update --force'
#      docker compose exec -T api sh -c 'DATABASE_URL="$DATABASE_URL_OWNER" php bin/console doctrine:fixtures:load --no-interaction'

# 5. Sprawdź single-origin i zaloguj się (admin@demo.localhost / changeme)
curl -sk https://pim.localhost/api/docs.jsonld | head  # Hydra/JSON-LD API documentation
open https://pim.localhost/                            # admin (login: admin@demo.localhost / changeme)
open https://pim.localhost/api/docs                    # Swagger UI (HTML)
open https://pim.localhost/api                         # API Platform entrypoint

# 6. Restart / reset
pnpm stack:down               # stop, zachowaj dane
pnpm stack:reset              # stop + wipe volumes (USUWA dane bazy — patrz ostrzeżenie niżej)
pnpm stack:rebuild            # rebuild obrazów (po zmianie Dockerfile / composer.json)
```

Caddy ma własny lokalny CA — przy pierwszym `pnpm dev` przeglądarka wymaga zaakceptowania certyfikatu. Można też zaufać CA na hoście: skopiuj `caddy_data` volume → `~/.local/share/caddy/pki/authorities/local/root.crt` i dodaj do System Keychain (macOS).

### Pliki `.env`

- **`.env.example`** (w gitcie) — szablon z placeholderami. Skopiuj do `.env`.
- **`.env`** (ignorowany) — Twoje lokalne sekrety; nadpisuje `.env.example`.
- **`.env.dev` / `.env.local`** (ignorowane) — realne sekrety per środowisko.
  `.gitignore` celowo łapie `.env` oraz każde `.env.*` (poza allowlistą
  `.env.example`, `apps/api/.env`, `apps/api/.env.test`). Nigdy nie commituj
  pliku z realnym hasłem.

> **Uwaga (operator):** `.env.dev` jest *trackowany* na starszych branchach
> (sprzed W0-7), a *nietrackowany* na `main`. Przełączenie/`pull` może skasować
> Twój realny `.env.dev` — zrób backup do `/tmp` przed checkoutem, potem przywróć
> i zrestartuj stack.

### `pim:db:reset` — reset bazy w jednym kroku

```bash
docker compose exec -T api php bin/console pim:db:reset --with-fixtures --force
```

Robi po kolei: `doctrine:database:drop` → `create` → `migrations:migrate` →
`audit:schema:update --force` → (z `--with-fixtures`) `fixtures:load` +
`pim:search:reindex --purge`. Flagi: `--with-fixtures` (załaduj fixtures),
`--force` (pomiń pytanie potwierdzające), `--force-prod` (dopuść `APP_ENV=prod` —
domyślnie odmawia). Używaj **tylko na bazie deweloperskiej**.

Komenda **sama przełącza się na połączenie owner** (`DATABASE_URL_OWNER`,
rola `pim`) — po splicie ról W1-1 runtime'owa rola `pim_app` nie ma DDL ani
BYPASSRLS, więc drop/create/audit/fixtures pod nią padają (#2178). Nie trzeba
(i nie należy) ręcznie podmieniać env-varów. Drop wykonuje się jako
`DROP DATABASE … WITH (FORCE)` — sesje trzymane przez api/worker są zrywane
atomowo, **nie trzeba zatrzymywać kontenerów** przed resetem.

> **OSTRZEŻENIE — wipe stanu manualnego.** `pim:db:reset` (i `pnpm stack:reset`)
> kasują bazę i ładują od nowa tylko fixtures. **Nie odtwarzają** custom
> ObjectType / grup atrybutów / danych utworzonych ręcznie przez UI — ten stan
> przepada. Zrób smoke-test/eksport zanim zresetujesz, albo uprzedź operatora.

### `exec` vs `exec -T`

Komendy w kontenerze odpalasz przez `docker compose exec api …`. Flaga **`-T`
wyłącza alokację pseudo-TTY** — używaj jej w **skryptach, CI i potokach** (gdy
nie ma interaktywnego terminala), inaczej `docker compose exec` może się wywalić
na braku TTY. Interaktywnie (ręcznie w terminalu) `-T` jest opcjonalne. W tym
README komendy dokumentowane są z `-T` dla powtarzalności (kopiuj-wklej działa
też w skrypcie); ręcznie możesz pominąć `-T`.

## shared-types (kontrakt TS z OpenAPI)

`packages/shared-types` to **codegen**, nie klasyczny build: typy TypeScript są
generowane z żywego dokumentu OpenAPI API Platform do
`packages/shared-types/src/api.d.ts`. Stack musi działać:

```bash
pnpm --filter @pim/shared-types generate
# = openapi-typescript https://pim.localhost/api/docs.jsonopenapi -o src/api.d.ts
```

Regeneruj po każdej zmianie kształtu API (nowy zasób, nowe pole, zmiana grup
serializacji). Pełny przepływ „jak dodać pole/endpoint" (z regeneracją włącznie):
[`docs/development/adding-a-field-or-endpoint.md`](docs/development/adding-a-field-or-endpoint.md).

## Quality gates

```bash
pnpm typecheck    # tsc --noEmit dla apps/admin + packages/*
pnpm build        # build production wszystkich workspace'ów

# PHP gates (w kontenerze api)
docker compose exec api composer phpstan        # PHPStan max
docker compose exec api composer cs-check       # PHP-CS-Fixer (dry-run)
docker compose exec -e APP_ENV=test api php bin/phpunit --testsuite=unit
```

> **PHPUnit lokalnie = TYLKO `--testsuite=unit`** (przechodzi na świeżym klonie).
> Suity kernel/Api wymagają test-env (klucze JWT + `.env.test.local` + baza
> testowa) i pełny run ginie na limicie pamięci 256M — lokalna procedura dla
> kernel-suitów: [`docs/testing/local-kernel-suites.md`](docs/testing/local-kernel-suites.md);
> default = CI (#2183).

CI: GitHub Actions na PR + push do main — `quality-php.yml`, `quality-frontend.yml` (Biome / typecheck / Vite build / **Playwright E2E**), `audit.yml` (composer + pnpm audit).

## Running E2E tests (Playwright)

E2E używa Chromium przeciwko full stackowi (`https://pim.localhost`).

> **macOS też wymaga wpisu w `/etc/hosts`** (`127.0.0.1 pim.localhost`), mimo że
> przeglądarka rozwiązuje `*.localhost` sama (RFC 6761): helpery `apiLogin` /
> `page.request` działają w **Node**, który tego nie robi — bez wpisu ~80/122
> speców pada środowiskowo na `getaddrinfo ENOTFOUND pim.localhost` (#2182).

Przed pierwszym uruchomieniem:

```bash
pnpm stack:up                                       # Caddy + Postgres + API + admin Vite
docker compose exec api php bin/console doctrine:fixtures:load --no-interaction --env=dev
pnpm --filter @pim/admin exec playwright install chromium  # raz, host-side (Alpine container nie wspiera Playwright deps)
```

Następnie:

```bash
pnpm --filter @pim/admin e2e          # headless, pełna suite
pnpm --filter @pim/admin e2e:ui       # tryb interaktywny (debug)
```

Artefakty failure (screenshot / video / trace) lądują w `apps/admin/test-results/`. Reportowy HTML w `apps/admin/playwright-report/`. CI uploads obie ścieżki przez `actions/upload-artifact` przy failure.

## Troubleshooting (dzień pierwszy)

Znane quirki środowiska dev — symptom → heal (#2179; źródło: `agent/lessons.md`):

| Symptom | Przyczyna | Heal |
|---|---|---|
| `/api/*` zwraca **HTTP 200 z HTML** `Fatal error: … getXxxService.php: No such file` (np. login), znika menu po zalogowaniu | Korupcja dev-cache FrankenPHP — `var/cache/dev` przebudowany pod działającym workerem (zdarza się też na świeżym klonie przy pierwszym boocie) | `docker compose exec api rm -rf /app/var/cache/dev && docker compose exec api php bin/console cache:warmup && docker compose restart api worker` |
| Uploady padają 500 `SlowDownWrite` / `inconsistent drive` | MinIO w stanie degraded | `docker compose restart minio` — sprawdź storage ZANIM obwinisz kod |
| Login utyka / `429 Too Many Requests` po serii prób (curl/Playwright) | Rate-limiter `auth_login` 5/IP/15min (dev override 50) wyczerpany skumulowanymi próbami | `docker compose exec api php bin/console cache:pool:clear cache.rate_limiter` (suita E2E robi to sama w global-setup) |
| Toast „Nieprawidłowy e-mail lub hasło" na świeżej bazie | Baza pusta (po `down -v` / reset) — a NIE złe hasło | odczekaj boot api: entrypoint auto-seeduje (`pim:dev:ensure-seeded`); log `docker compose logs api \| grep seed` |
| `database "pim" is being accessed by other users` przy operacjach na bazie | Sesje trzymają api ORAZ worker (FrankenPHP + konsument Messengera) | `pim:db:reset` radzi sobie sam (`DROP … WITH (FORCE)`); dla innych operacji: `docker compose stop api worker` na czas zabiegu |

Uwaga do pierwszego symptomu: **HTTP 200 ≠ sukces** — smoke-testy muszą asertować kształt odpowiedzi (JSON z `token`), nie sam status.

## Stack components

| Service | Image | Purpose | Healthcheck |
|---|---|---|---|
| `caddy` | `caddy:2-alpine` | Edge — terminuje TLS, single-origin proxy | `wget` na `https://localhost/api` |
| `api` | `pim-api` (FrankenPHP 1 + PHP 8.4) | Symfony 7.4 + API Platform 4 worker mode | `curl http://127.0.0.1/api` |
| `admin` | `node:22-alpine` | Vite dev server (HMR przez WebSocket upgrade) | brak (process-based) |
| `database` | `pim-database:local` (postgres:16-alpine + pgbackrest 2.57 + dcron) | PostgreSQL 16 + WAL archiving + hourly backup do MinIO | `pg_isready` |
| `redis` | `redis:7-alpine` | Symfony Messenger transport + cache (AOF persistence) | `redis-cli ping` |
| `meilisearch` | `getmeili/meilisearch:v1.13` | Full-text search | `curl /health` |
| `minio` | `minio/minio:latest` | S3-compatible object storage (DAM + backups) | `curl /minio/health/live` |
| `minio-tls` | `caddy:2-alpine` | TLS terminator dla pgBackRest → MinIO (pgBackRest hard-coduje HTTPS) | brak |
| `minio-init` | `minio/mc:latest` | Init buckets `pim-assets` / `pim-backups` (one-shot, `restart: no`) | — |
| `mercure` | `dunglas/mercure:latest` | SSE hub real-time (events do admin frontend) | brak |
| `mailpit` | `axllent/mailpit:latest` | Dev mail catcher (SMTP + UI) | brak |
| `k6` | `grafana/k6:latest` | Perf load tester (`profile: ["perf"]`, nie startuje z `pnpm stack:up`) | — |

## Backup & restore

```bash
pnpm backup:run       # one-off pgBackRest backup do MinIO bucket pim-backups
pnpm backup:info      # status repo, lista backup'ów
pnpm backup:restore   # PITR — patrz docs/runbook/restore.md
pnpm backup:test      # DoD acceptance test (insert markery → backup → drop → restore)
```

Pełen runbook: [`docs/runbook/restore.md`](docs/runbook/restore.md). Production-grade schedule (full + diff + 5-min WAL + automated weekly restore test) dochodzi w 0.11.11.

## Następny krok

MVP-Alpha — epik 0.1 zamknięty. Następny: epik 0.2 (Identity & Access, [#24-#30](https://github.com/malipie/PIM/issues?q=is%3Aopen+label%3A%22epik-0.2%22)). Pełny plan w [`Project Plan/02-plan-projektu-pim.md`](Project%20Plan/02-plan-projektu-pim.md), aktualny status w [`agent/current_status.md`](agent/current_status.md).

## Licencja

Prywatne — wszystkie prawa zastrzeżone.

# @pim/admin — panel administracyjny PIM

Admin do PIM-a: **React 19 + TypeScript 5 + Vite + [Refine.dev](https://refine.dev) +
[shadcn/ui](https://ui.shadcn.com) (Radix + Tailwind)**. To frontendowy workspace
monorepo Turborepo (`apps/admin`). Konsumuje wyłącznie publiczne API z
`apps/api` (API Platform 4) — żadnych prywatnych endpointów, ten sam kontrakt co
integratorzy.

> To **nie jest** standalone aplikacja. Admin działa **za reverse-proxy Caddy w
> obrębie całego stacka** (single-origin, bez CORS) — uruchamiasz go przez
> `pnpm stack:up` z roota repo, nie samym `vite` w tym katalogu. Patrz niżej.

## Jak uruchomić (w kontekście całego stacka)

Z **roota repo** (nie z `apps/admin`):

```bash
pnpm install          # raz, z roota — instaluje cały workspace (root + apps/admin + packages/*)
pnpm stack:up         # docker compose up -d: caddy + api + admin (Vite) + postgres + redis + meili + minio + mercure + mailpit
```

Admin Vite startuje jako usługa `admin` w `docker-compose.yml` i jest serwowany
przez Caddy pod jednym originem:

- `https://pim.localhost/` → admin (HMR Vite przez WebSocket upgrade w Caddy)
- `https://pim.localhost/api/*` → API Platform (Symfony)

Login: `admin@demo.localhost` / `changeme` (po `pim:db:reset --with-fixtures` —
patrz [ONBOARDING](../../ONBOARDING.md)).

Pełna sekwencja Day-1 (migracje, fixtures, reset bazy, akceptacja certyfikatu
Caddy) jest w [`../../ONBOARDING.md`](../../ONBOARDING.md) i
[`../../README.md`](../../README.md) — nie powielamy jej tutaj.

### Standalone `vite dev` (rzadko, do izolowanego debugowania UI)

Skrypt `dev` z `package.json` (`vite --host 0.0.0.0 --port 5173`) odpala sam dev
server, ale **bez backendu** (`/api/*` zwróci 404/502, bo nie ma proxy Caddy).
Domyślnym trybem pracy jest stack przez `pnpm stack:up`.

## Komendy (skrypty `package.json`)

Uruchamiaj per-workspace z roota przez `pnpm --filter @pim/admin <skrypt>`
(nazwa pakietu to `@pim/admin`):

| Skrypt | Komenda | Do czego |
|--------|---------|----------|
| `dev` | `vite --host 0.0.0.0 --port 5173` | dev server (zwykle odpalany w kontenerze przez stack) |
| `build` | `tsc -b && vite build` | build produkcyjny (typecheck + bundle) |
| `typecheck` | `tsc -b --noEmit` | sam typecheck, bez emisji |
| `lint` | `biome check src e2e` | Biome (lint + format check) |
| `lint:fix` | `biome check --write src e2e` | Biome z autofixem |
| `format` | `biome format --write src e2e` | tylko formatowanie |
| `test` | `vitest run` | testy jednostkowe (Vitest + Testing Library) |
| `e2e` | `playwright test` | E2E Playwright przeciw `https://pim.localhost` |
| `e2e:ui` | `playwright test --ui` | Playwright w trybie UI |
| `e2e:install` | `playwright install --with-deps chromium` | instalacja Chromium dla E2E |

```bash
pnpm --filter @pim/admin typecheck
pnpm --filter @pim/admin lint
pnpm --filter @pim/admin build
pnpm --filter @pim/admin test
```

> **OOM przy typecheck / build.** `tsc` na tym projekcie potrafi przekroczyć
> domyślny limit pamięci Node i wywalić się na OOM. Ustaw
> `NODE_OPTIONS="--max-old-space-size=4096"`:
>
> ```bash
> NODE_OPTIONS="--max-old-space-size=4096" pnpm --filter @pim/admin typecheck
> NODE_OPTIONS="--max-old-space-size=4096" pnpm --filter @pim/admin build
> ```

### Turborepo (z roota, wszystkie workspace'y naraz)

```bash
pnpm typecheck     # turbo run typecheck (apps/admin + packages/*)
pnpm build         # turbo run build
pnpm lint          # turbo run lint
pnpm test          # turbo run test
```

### E2E — pierwsze uruchomienie

Alpine w kontenerze nie hostuje zależności Playwright, więc Chromium instalujesz
po stronie hosta:

```bash
pnpm stack:up
docker compose exec -T api php bin/console doctrine:fixtures:load --no-interaction --env=dev
pnpm --filter @pim/admin exec playwright install chromium   # raz, host-side
pnpm --filter @pim/admin e2e
```

> Po edycji plików tłumaczeń (`src/.../locales/pl.json` / `en.json`) zrestartuj
> kontener admina (`docker compose restart admin`) — HMR nie re-inicjalizuje
> i18next, nowe klucze renderują się jako surowe klucze.

## shared-types — kontrakt TypeScript z OpenAPI

Typy współdzielone z backendem **nie są pisane ręcznie** — są generowane z
wersjonowanego snapshotu `docs/api-spec/v0.json` do
`packages/shared-types/src/api.d.ts`:

```bash
pnpm --filter @pim/shared-types generate
# = openapi-typescript ../../docs/api-spec/v0.json -o src/api.d.ts
```

**Kiedy regenerować:** po każdej zmianie kształtu API (nowy zasób, nowe pole,
zmiana grup serializacji). To NIE jest klasyczny „build step" kompilujący kod —
to **deterministyczny codegen kontraktu offline**. Wygenerowany `api.d.ts` jest
wersjonowany, a CI odrzuca drift. Admin importuje typy przez
nazwę pakietu `@pim/shared-types` (workspace pnpm). Jak dodać pole/endpoint
end-to-end (łącznie z regeneracją): [dev-guide](../../docs/development/adding-a-field-or-endpoint.md).

> Wewnątrz `apps/admin` ścieżki własne mapuje alias `@/*` → `src/*`
> (`tsconfig.app.json`). Wywołania API idą przez warstwę `src/lib/http.ts`
> (`jsonFetch`, doklejanie `Authorization: Bearer <jwt>`) i Refine data provider
> `src/lib/data-provider.ts`.

## Stos i konwencje

- **Refine.dev** — data/auth provider + hooki (`useList`, `useOne`, `useCreate`,
  `useUpdate`, `useDelete`). Zasoby rejestrowane w `src/App.tsx`.
- **shadcn/ui na Radix** — a11y za darmo; customowe komponenty walidowane
  axe-core (jest-axe / `@axe-core/playwright`).
- **i18n** — wszystkie stringi user-facing przez `t()` (react-i18next), klucze
  angielskie, tłumaczenia w plikach `pl`/`en`. Bez literałów w JSX.
- **Single-origin, bez CORS** — wszystko przez Caddy; base URL API to `/api`
  (względny). Jeśli widzisz błąd CORS, sprawdź Caddyfile, nie dodawaj nagłówków.

## Powiązane dokumenty

- [`../../README.md`](../../README.md) — przegląd monorepo + lokalny development.
- [`../../ONBOARDING.md`](../../ONBOARDING.md) — pełna sekwencja Day-1.
- [`../../CONTRIBUTING.md`](../../CONTRIBUTING.md) — branże, commity, bramki, hooki.
- [`../../docs/development/adding-a-field-or-endpoint.md`](../../docs/development/adding-a-field-or-endpoint.md) —
  jak dodać pole/endpoint (vertical slice backend↔front).

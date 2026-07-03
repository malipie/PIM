# Cold-start handover test — raport (#2119, epik GOLIVE)

**Data:** 2026-07-03 · **Blok:** A (plan `Project Plan/15-plan-testow-przedprodukcyjnych.md`, sekcja A1)
**Scenariusz:** symulacja pierwszego dnia zewnętrznego software house'u — świeży klon z GitHuba, uruchomienie stacka i testów **wyłącznie na podstawie README/ONBOARDING/docs**, bez wiedzy plemiennej.

## Warunki testu

- Świeży `git clone https://github.com/malipie/PIM.git` do osobnego katalogu (commit = main po merge #2150).
- Zero lokalnych `.env*`, zero `config/jwt/`, świeże wolumeny Dockera (izolacja przez `COMPOSE_PROJECT_NAME=pimcold` — patrz „Odstępstwa symulacji" niżej).
- Ścieżka wykonana krok po kroku wg `README.md` → `ONBOARDING.md`.

### Odstępstwa symulacji od „naprawdę czystej maszyny" (nie są lukami docs)

| Odstępstwo | Wpływ |
|---|---|
| `COMPOSE_PROJECT_NAME=pimcold` (docker-compose.yml ma sztywne `name: pim` — na współdzielonej maszynie klon podpiąłby się pod wolumeny stacka dev) | brak; na czystej maszynie nie występuje |
| pnpm store / obrazy bazowe Dockera częściowo z cache maszyny | pomiar czasu = dolna granica |
| `/etc/hosts` i cert-store hosta odziedziczone po dev | `pim.localhost` działał od razu |

## Timeline „klon → zielono"

| Czas | Krok | Wynik |
|---|---|---|
| 23:23:41 | `git clone` | 5 s |
| 23:24:53 | `pnpm install` | 2 s (cache) |
| 23:25 | `.env` z `.env.example` + 7 sekretów | OK |
| 23:25:49 | `pnpm stack:up` (pierwszy build) | **2m58s**, 11 kontenerów up |
| 23:29 | entrypoint auto-seed: **122 migracje od zera** + audit + fixtures + reindex | ✅ automatycznie |
| 23:29:11 | `pim:db:reset` wg README krok 4 | ❌ **L5** (owner) |
| 23:30 | manual 3-step wg README | ❌ **L6** (audit:schema jako pim_app) |
| 23:31 | worker crashloop odkryty (RestartCount=10) | ❌ **L7** (messenger_messages) |
| 23:34 | login → HTML fatal (HTTP 200) — dev-cache corruption na świeżym klonie; heal `rm var/cache/dev`+warmup+restart | ⚠️ **L9** |
| 23:38 | login → 500 JWT „private key/passphrase" → `.env.local` + `lexik:jwt:generate-keypair` + restart | ❌ **L4**, po workaround **login 200** ✅ |
| 23:42–23:45 | bramki: PHPStan ✅ · cs-check 0/1687 ✅ · Deptrac 0 err ✅ · biome/tsc/vite build ✅ | zielone |
| 23:46 | PHPUnit `--testsuite=unit`: **1453 testy, 4056 asercji, OK** (6.3 s) | zielone |
| 23:47→ | Playwright E2E pełna suita | _wynik niżej_ |

**Czas „klon → działający stack z zalogowanym adminem": ~15 min** (w tym ~10 min diagnozy luk L4–L9 bez pomocy docs). Na czystej maszynie + build bez cache: szacunkowo 30–45 min, **pod warunkiem znajomości workaroundów** — bez nich nowy zespół utknie na L4/L5 (login niemożliwy, reset bazy niemożliwy).

## Luki (każda → ticket; grupowanie po root cause)

| # | Severity | Ticket | Luka | Dowód |
|---|---|---|---|---|
| L1 | LOW | [#2180](../../../issues/2180) | README „Status"/„Następny krok" drift ~2 mies. (Sprint 0 / epik 0.2; jest GOLIVE); „ADR-0000..0016" a jest do 0025 | README.md L5, L20, L208-210 |
| L2 | MEDIUM | [#2180](../../../issues/2180) | ONBOARDING Day-10 odsyła do `Zrodla/Zalecana_struktura_kodu/Audyt/AUDIT-CHECKLIST.md` — `Zrodla/` jest w `.gitignore`, nie istnieje w klonie | ONBOARDING.md L62 |
| L3 | LOW | [#2180](../../../issues/2180) | ONBOARDING Day-1: `pim:db:reset --with-fixtures` bez `--force` — z `-T` (bez TTY) confirm pada | ONBOARDING.md L14 |
| L4 | **CRITICAL** | [#2176](../../../issues/2176) | **Brak generacji pary kluczy JWT w całej ścieżce onboardingu** — świeży klon nie ma `config/jwt/`, `JWT_PASSPHRASE` placeholder, entrypoint nie generuje, README/ONBOARDING milczą → **logowanie niemożliwe** (500 „error while trying to encode the JWT token") | curl login 500; po `lexik:jwt:generate-keypair` → 200 |
| L5 | **HIGH** | [#2178](../../../issues/2178) | `pim:db:reset` (kanoniczny krok README/ONBOARDING) pada na świeżej instalacji: `must be owner of database pim` — komenda używa default connection (`pim_app`, non-owner od W1-1); entrypoint obchodzi to podmianą `DATABASE_URL=$DATABASE_URL_OWNER`, czego docs nie dokumentują | wyjście komendy |
| L6 | HIGH | [#2178](../../../issues/2178) | Manualny 3-step z README pada tak samo: `audit:schema:update` jako `pim_app` → `must be owner of table backups_audit`; `doctrine:fixtures:load` analogicznie | wyjście komend |
| L7 | **HIGH (bug, nie docs)** | [#2177](../../../issues/2177) | **`messenger_messages` nie jest tworzone przez żadną migrację** → transport `failed` (doctrine://) próbuje auto-setup jako `pim_app` → permission denied → **worker crashloop od pierwszego bootu** (RestartCount=10). Na maszynie dev tabela istnieje historycznie (auto-setup pre-W1-1) — klasa problemu „works on my machine" | logi workera; po `messenger:setup-transports` jako owner → healthy |
| L8 | MEDIUM | [#2178](../../../issues/2178) | Ostrzeżenie README „najpierw `docker compose stop api`" niekompletne: worker też trzyma sesję DB; pełna działająca sekwencja resetu (stop api → exec w worker z `DATABASE_URL_OWNER` → start api) nie jest udokumentowana nigdzie | próby resetu |
| L9 | MEDIUM | [#2179](../../../issues/2179) | Dev-cache corruption FrankenPHP występuje **także na świeżym klonie przy pierwszym bootcie** (HTML fatal `getXxxService.php` z HTTP 200 na `/api/auth/login`); heal znany operatorowi (`rm -rf var/cache/dev` + warmup + restart), ale niezapisany w żadnym README/runbooku | reprodukcja + heal |
| L10 | **HIGH (bug)** | [#2181](../../../issues/2181) | `apps/api/.env` hardkoduje `AWS_ASSETS_KEY/SECRET=minioadmin`, compose nie propaguje `MINIO_ROOT_*` do api/workera → wykonanie README krok 2 („podmień hasła MinIO") łamie **wszystkie uploady** (`InvalidAccessKeyId` 500: staged import, asset, eksport) | logi api; po fixie env → specy importowe zielone |
| L11 | MEDIUM | [#2182](../../../issues/2182) | README „macOS rozwiązuje pim.localhost automatycznie" — fałszywe dla Node (`page.request`): bez wpisu `/etc/hosts` **80/122 speców E2E pada** (`ENOTFOUND`); + `1932-feed-delivery-preview` deterministycznie czerwony lokalnie na axe AA 4.42:1 (zielony na CI, do triage) | wyniki E2E |
| L12 | MEDIUM | [#2183](../../../issues/2183) | README „Quality gates" dokumentuje pełny `bin/phpunit` — na świeżym klonie setki E (JWT test-env, setup bazy test nieopisany) + **OOM 256M** przy 98%; działający lokalny wzorzec kernel-suitów żyje tylko w `agent/lessons.md` | wyjście runu |

## Wyniki testów (stan po workaroundach)

- PHP gates: PHPStan max ✅ · PHP-CS-Fixer 0/1687 ✅ · Deptrac 0 errors (3 warnings) ✅
- FE gates: Biome lint (4 warnings) ✅ · `tsc -b --noEmit` ✅ · `vite build` ✅
- PHPUnit unit: **1453 testy / 4056 asercji — OK** (58 notices)
- Playwright E2E (pełna suita 122 speców, Chromium, świeży stack): **39 ✅ / 83 ❌**, z czego:
  - **80 faili środowiskowych** — `getaddrinfo ENOTFOUND pim.localhost` z `page.request` (Node): README twierdzi „macOS rozwiązuje automatycznie", co jest prawdą tylko dla przeglądarki → luka **L11** ([#2182](../../../issues/2182)); te specy są zielone na CI,
  - **2 realne faile fresh-install** — import wizard + structural import padały na `InvalidAccessKeyId` (S3): luka **L10** ([#2181](../../../issues/2181)); po zweryfikowanym workaroundzie (AWS_ASSETS_KEY/SECRET = MINIO_ROOT_*) oba **zielone**,
  - **1 do triage** — `1932-feed-delivery-preview` deterministycznie lokalnie czerwony na axe AA contrast (64 węzły, `#6b778a` na `#fcfcfe` = 4.42:1), zielony na CI → [#2182](../../../issues/2182).
- PHPUnit pełny (`bin/phpunit` wg README, suity kernel/Api): ❌ **niewykonalny na świeżym klonie** — setki E (JWT test-env bez kluczy/passphrase, brak setupu bazy testowej w docs), run zabity przez **OOM 256M** na `FlushWithoutClearInLoopRuleTest` przy 98% → luka **L12** ([#2183](../../../issues/2183)). ONBOARDING (tylko `--testsuite=unit`) jest wykonalny i zielony; pełne suity = CI albo wzorzec docker+worktree z `agent/lessons.md` (wiedza plemienna).

## Wnioski

1. **Ścieżka szczęśliwa istnieje, ale nie ta opisana**: entrypoint auto-seed robi całą inicjalizację bazy poprawnie i automatycznie (122 migracje od zera ✅); dokumentowane komendy ręczne (reset/audit/fixtures) są wszystkie niewykonalne na roli `pim_app`. Docs opisują świat sprzed W1-1 (split ról).
2. **Trzy twarde blokery dnia pierwszego**: L4 (JWT — login niemożliwy), L7 (worker martwy od bootu), L10 (uploady martwe, jeśli nowy zespół posłucha README i zmieni hasła MinIO). Wszystkie z klasy „works on my machine" — stan istniejący u operatora historycznie, nie re-derywowalny na świeżej maszynie, niewidoczny w CI (CI buduje środowisko innym torem).
3. **Bramki statyczne + unit są zdrowe**: PHPStan/CS/Deptrac/Biome/tsc/build/unit-suite — zielone na świeżym klonie bez żadnych korekt.
4. **Testy wyższych pięter wymagają wiedzy plemiennej**: pełny PHPUnit (L12) i większość E2E (L11) nie przechodzą wg samych docs; realna weryfikowalność „zielono" dla nowego zespołu = CI, nie lokalna maszyna.
5. **Werdykt handover-readiness (obszar środowisko/docs): NIE-GOTOWY bez naprawy L4/L5/L7/L10** — każdy z nich zatrzymuje pierwszy dzień zespołu zewnętrznego. Po naprawach ~15–45 min do działającego stacka jest osiągalne.

## Mapa ticketów

[#2176](../../../issues/2176) JWT keypair (L4) · [#2177](../../../issues/2177) messenger_messages (L7) · [#2178](../../../issues/2178) komendy DB vs owner (L5/L6/L8) · [#2179](../../../issues/2179) troubleshooting cache (L9) · [#2180](../../../issues/2180) drift README/ONBOARDING (L1/L2/L3) · [#2181](../../../issues/2181) S3 credentials (L10) · [#2182](../../../issues/2182) E2E hosts + 1932 (L11) · [#2183](../../../issues/2183) pełny PHPUnit (L12)

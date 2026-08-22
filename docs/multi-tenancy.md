# Multi-tenancy — strategia, kontrakty, RLS

PIM jest **multi-tenant ready, single-tenant deployed** (ADR-003). Każda tabela domenowa niesie `tenant_id UUID` od dnia 1, izolacja na poziomie aplikacji (Doctrine `TenantFilter`) działa od Sprintu 0. Od **W1-1 / AUD-002** druga linia obrony jest realna: aplikacja łączy się jako nie-uprzywilejowana rola `pim_app` (NOSUPERUSER, NOBYPASSRLS, nie-owner), a wszystkie tabele z `tenant_id` mają `FORCE ROW LEVEL SECURITY` + polityki izolacji. Bug w `TenantFilter` (native SQL, zapomniany `TenantScoped`, `disable('tenant')` bez re-enable) nie jest już cross-tenant leakiem — RLS odrzuca obce wiersze na poziomie bazy.

## Trzecia warstwa: instancja per tenant (epik TNT, ADR-0035)

Od epiku TNT każdy tenant produkcyjny dostaje **własną instancję aplikacji
z własnym klastrem Postgresa**. To NIE zastępuje niczego, co opisuje reszta
tego dokumentu — `TenantFilter` i RLS zostają bez zmian i nadal są granicą
**wewnątrz** instancji. Kod ma działać poprawnie także tam, gdzie w jednej
bazie siedzi wielu tenantów, bo tak wygląda dev, testy i CI.

Trzy warstwy, od najbliższej kodowi:

1. **`TenantFilter`** (Doctrine) — dokleja `tenant_id = :current_tenant` do
   zapytań po encjach `TenantScoped`.
2. **RLS** (Postgres, `FORCE`, rola `pim_app` z `NOBYPASSRLS`) — łapie to, co
   omija Doctrine: natywny SQL, zapomniany `TenantScoped`, `disable('tenant')`.
3. **Instancja** — osobne procesy, osobny klaster, osobne klucze JWT, osobne
   hasła bazy. Błąd w warstwach 1–2 nie sięga cudzych danych, bo cudze dane są
   w innym klastrze.

### Dlaczego osobny klaster, a nie osobna baza w jednym klastrze

Osobna baza (`DATABASE`) w jednym klastrze zawiera błędną migrację i `DELETE`
bez `WHERE` dokładnie tak samo. Rozstrzygnął pgBackRest: **stanza obejmuje
cały klaster**, więc odtworzenie jednego tenanta do punktu w czasie cofałoby
wszystkich pozostałych. Osobny klaster to jedyny sposób na niezależne PITR —
i przy okazji na przeniesienie klienta na inną maszynę przez skopiowanie
wolumenu. Pełne uzasadnienie i odrzucone warianty: ADR-0035.

### Gdzie granica NIE jest podwójna

Dwie rzeczy zostają współdzielone i trzeba o tym pamiętać:

| Zasób | Co rozdziela | Druga linia obrony |
|---|---|---|
| **Meilisearch** | filtr `tenantId` w `CatalogSearchService`, wspólny indeks `objects` | **brak** — osobny indeks wymagałby zmiany kodu (stała `IndexSettingsTemplate::INDEX_NAME`) |
| **MinIO** | bucket per tenant + użytkownik z polityką ograniczoną do jego bucketów | polityka po stronie MinIO (dostęp odmawiany przez serwer, nie przez aplikację) |

Wyszukiwarka jest **jedynym** miejscem w modelu, gdzie izolacja opiera się
wyłącznie na poprawności kodu aplikacji. Dlatego test przecieku wyników jest
obowiązkową częścią `scripts/pim-tenant-isolation-check.sh` (#2868),
uruchamianego po każdym dołożeniu klienta.

### Świadomy kompromis: baza w sieci wspólnej

Baza tenanta jest podłączona także do sieci stacku współdzielonego, bo
pgBackRest wypycha kopie i segmenty WAL przez sidecar `minio-tls`. Jest więc
osiągalna po nazwie kontenera z innych stacków. **Granicą są osobne hasła per
instancja**, nie brak trasy sieciowej — i dlatego skrypt sprawdzający izolację
najpierw dowodzi, że cudza baza odpowiada (własnym hasłem), a dopiero potem
wymaga odmowy dla hasła sąsiada. Odmowa bez tej kontroli mogłaby oznaczać
zwykły brak sieci i nie dowodziłaby niczego.

Operacyjnie: `docs/runbook/tenant-instances.md`.

---

## Tabele i ich tenant scope

| Tabela | `tenant_id` | Uwagi |
|---|---|---|
| `tenants` | — | sama encja Tenant; nie scopowana |
| `users` | `NOT NULL` (FK) | tenant scope, ale **bez** `TenantScoped` w PHP — login musi znaleźć usera po email zanim tenant będzie znany |
| `products` | `NOT NULL` (FK) | domain table, `TenantScoped` |
| `roles` | nullable (FK) | `NULL` = globalna rola wbudowana (super_admin / catalog_manager / integration_manager / viewer) |
| `permissions` | — | globalny katalog `(resource, action)`, intencjonalnie unscopowany |
| `role_permissions`, `user_roles` | — | M2M junction; tenant scope dziedziczony po wierszach rodziców |
| `refresh_tokens` | `NOT NULL` (denormalised UUID) | lookup po `token_hash` UNIQUE; tenant_id dla integralności + hipotetycznej RLS w fazie 2 |
| `messenger_messages` | — | infra (Symfony Messenger) |
| `doctrine_migration_versions` | — | bookkeeping Doctrine |

`pim:tenant:audit` (poniżej) sprawdza ten zestaw automatycznie — gdy w epiku 0.3 dochodzą `Object`/`Channel`/`Asset`, audit alarmuje jeśli któraś tabela zapomni `tenant_id`.

## Interfejsy

Dwa marker interface'y w `App\Identity\Application\`:

- **`TenantAware`** — *"ten obiekt umie zwrócić aktywny tenant"*. Implementuje `User`. Używany przez `CurrentTenantProvider` żeby resolver wyciągnął tenant z autoryzowanego principal'a (JWT → User → tenant).
- **`TenantScoped`** — *"ta encja niesie `tenant_id` na poziomie schematu, listener auto-stempluje + filter scopuje"*. Implementuje `Product`. Wymaga `getTenant(): ?Tenant` + `assignTenant(Tenant): void`.

Można implementować oba (User by mógł — w MVP nie potrzebuje, lookup po email globalny), ale 95% domain entities chce tylko `TenantScoped`.

## Listener — auto-stempling przy `prePersist`

[`TenantAssignmentListener`](../apps/api/src/Identity/Infrastructure/Doctrine/EventListener/TenantAssignmentListener.php) dispatchuje przez `instanceof TenantScoped`:

1. encja nie jest `TenantScoped` → ignore;
2. `getTenant()` już ustawiony → respect (caller stamped manually);
3. `TenantContext::get()` jest `null` → throw `LogicException` (clearer niż NOT NULL constraint violation z DB);
4. inaczej → `$entity->assignTenant($tenant)`.

Konsekwencje:
- nowe encje opt-in przez `implements TenantScoped` — bez modyfikacji listener'a;
- bulk path (`COPY`, raw INSERT, `RefreshTokenService`) ustawia `tenant_id` ręcznie w kodzie — bypass listener'a jest świadomy.

## Filter — auto-scope w SELECT/UPDATE/DELETE

[`TenantFilter`](../apps/api/src/Identity/Infrastructure/Doctrine/Filter/TenantFilter.php) (Doctrine `SQLFilter`) dokleja `WHERE <alias>.tenant_id = :current_tenant` do każdego query którego `targetEntity implements TenantScoped`. Parametr `current_tenant` ustawia `TenantFilterConfigurator` po resolve'owaniu tenant'a z requestu.

Co filter NIE łapie:
- native SQL przez DBAL (smoke #12 / `TenantIsolationTest::nativeSqlBypassesTheDoctrineFilterByDesign` udowadnia że granica leży na warstwie aplikacji);
- `COPY` i raw `INSERT … SELECT` (patrz "Bulk operations" niżej);
- query po encjach NIE-`TenantScoped` (User, Role, Permission, RefreshToken).

## RLS — kontrakt GUC i stan aktywacji

**Kanon nazwy GUC: `app.current_tenant`** (plus `app.is_super_admin` dla break-glass bypass). To jedyna zmienna sesyjna jaką ustawia aplikacja. Każda polityka RLS w bazie MUSI czytać tę nazwę.

**Trzy wejścia wiążą tenanta — i tylko one wolno pisać ten GUC** (#2978):

| wejście | kto wiąże | automatycznie? |
|---|---|---|
| żądanie HTTP z principalem | [`RlsContextListener`](../apps/api/src/Identity/Infrastructure/Doctrine/RlsContextListener.php) (`kernel.request`) | tak |
| worker Messengera | [`TenantRlsGucMiddleware`](../apps/api/src/Shared/Infrastructure/Messenger/TenantRlsGucMiddleware.php) | tak |
| **komenda konsolowa** i **trasa podpisana bez sesji** | [`TenantScopeBinder`](../apps/api/src/Shared/Infrastructure/Tenant/TenantScopeBinder.php) | **nie — trzeba zawołać `bind()`** |

Trzeci wiersz jest źródłem defektów. „Czyje dane" żyje w **trzech** warstwach naraz — `TenantContext` (PHP), parametr filtra Doctrine i GUC — a wejście bez principala nie ustawia żadnej z nich samo. `TenantScopeBinder::bind()` wiąże wszystkie trzy jednym wywołaniem, `release()` zwalnia. Dwa wyjątki na liście dozwolonych: [`RlsTenantGuard`](../apps/api/src/Shared/Infrastructure/Doctrine/RlsTenantGuard.php) (#2156 — re-asercja GUC-a po możliwym reconnectcie długowiecznego połączenia FrankenPHP) i [`TenantPurger`](../apps/api/src/Shared/Infrastructure/Maintenance/TenantPurger.php) (offboarding, działa poza jakimkolwiek scope'em).

> **Dlaczego to jest pilnowane bramką, a nie konwencją (#2978):** 12 z 19 komend wiążących tenanta ustawiało sam `TenantContext`, więc RLS nie widział tenanta — `pim:asset:upload` padał na `new row violates row-level security policy`, a `pim:agent:start` raportował „no active Anthropic BYOK key is configured" dla tenanta, który klucz miał skonfigurowany i włączony (wiersz był niewidoczny, nie brakujący). Pięć kolejnych komend i trzy kontrolery miały własne, ręcznie przepisane kopie pary `set_config` — i **każda kopia komendy zapomniała o `TenantFilterConfigurator::apply()`**, więc filtr Doctrine mógł wskazywać innego tenanta niż GUC. Dwie reguły PHPStan (`TenantBindingMustUseBinderRule`, `RawTenantGucRule`) zamykają obie furtki — patrz [`docs/static-analysis/custom-rules.md`](static-analysis/custom-rules.md). Przykład polityki:

```sql
CREATE POLICY tenant_isolation_select ON refresh_tokens FOR SELECT
    USING (tenant_id = current_setting('app.current_tenant', true)::uuid);
```

> **Historia driftu (AUD-027 / W1-2, naprawione [`Version20260617000000`](../apps/api/migrations/Version20260617000000.php)):** pierwsza fala RLS ([`Version20260428195217`](../apps/api/migrations/Version20260428195217.php)) seedowała `products` + `refresh_tokens` ze starą nazwą `pim.current_tenant_id`, której kod nigdy nie ustawia. Polityki `products` zniknęły przy migracji `products → objects` ([`Version20260428222056`](../apps/api/migrations/Version20260428222056.php)); `refresh_tokens` pozostał osamotniony na starym GUC. Era IMP2-2.x (api_tokens, audit_logs, import_logs, import_staged_files, import_undo_log, invitations, user_tenant_memberships) używa już `app.current_tenant`. Migracja W1-2 ujednoliciła `refresh_tokens` na `app.current_tenant` — **warunek wstępny** FORCE RLS (W1-1): pod FORCE niedopasowany GUC dawałby `tenant_id = NULL` (three-valued logic) → deny-all → zepsuty refresh-login. Migracja W1-2 sama **nie** włącza ani nie wymusza RLS — zmienia tylko nazwę GUC w politykach.

**Skala GUC — `set_config(..., false)` (session), NIE `SET LOCAL` (transaction).** Ścieżka HTTP NIE owija requestu w jedną transakcję — Doctrine/PDO działa w trybie autocommit libpq, więc każde zapytanie to osobna domyślna transakcja commitowana natychmiast. GUC transaction-local (`is_local=true`) znikałby w momencie auto-commitu statementu `set_config` i każde kolejne zapytanie (oraz INSERT do `audit_logs` na `kernel.response`) widziałoby pusty tenant → pod FORCE RLS deny-all / failed write. Dlatego [`RlsContextListener`](../apps/api/src/Identity/Infrastructure/Doctrine/RlsContextListener.php) ustawia GUC na poziomie **sesji** (`is_local=false`) na `kernel.request` i **zeruje go** na `kernel.terminate` (priorytet -256) — FrankenPHP worker mode reużywa połączenie DBAL między requestami, więc bez resetu poprzedni tenant wyciekałby do okna pre-auth kolejnego requestu. To ten sam wybór co [`TenantRlsGucMiddleware`](../apps/api/src/Shared/Infrastructure/Messenger/TenantRlsGucMiddleware.php) dla workerów.

Polityki castują przez `NULLIF(current_setting('app.current_tenant', true), '')::uuid`, nie gołe `::uuid`. GUC jest zerowany do **pustego stringa** (nie NULL), a SQL nie gwarantuje że `OR` short-circuituje przed castem — gołe `''::uuid` rzuca `invalid input syntax for type uuid: ""` i zamienia każde zapytanie w 500 zamiast pustego wyniku. `NULLIF` zamienia `''` na `NULL`, cast jest bezpieczny, a `tenant_id = NULL` → unknown → wiersz odrzucony (fail-closed dla tabel domenowych).

Stan dziś (po W1-1 / AUD-002): RLS **ENABLED + FORCED** na wszystkich 43 tabelach z `tenant_id`. Każda tabela ma `tenant_isolation_<t>` + `super_admin_bypass_<t>`. Connection user runtime to `pim_app` (NOSUPERUSER + NOBYPASSRLS + nie-owner) → RLS realnie egzekwowane. Migracje działają pod `pim` (owner, osobne połączenie DBAL `owner` / `DATABASE_URL_OWNER`, `doctrine_migrations.connection: owner`). **Dwa kształty polityki:**
- **tabele DOMENOWE** (objects, object_values, attributes, channels, assets, imports/exports, …) — polityka ścisła `tenant_id = NULLIF(current_setting('app.current_tenant', true), '')::uuid`. Pusty GUC = 0 wierszy (fail-closed; endpointy domenowe zawsze mają tenant w kontekście).
- **tabele AUTH** (users, refresh_tokens, password_reset_tokens, api_keys, api_tokens, roles, sso_providers, tenant_locales, user_tenant_memberships, invitations, **audit_logs**) — polityka pre-context-safe: `current_setting(...) IS NULL OR current_setting(...) = '' OR tenant_id = NULLIF(...)::uuid`. Te wiersze są czytane PODCZAS autentykacji (firewall priorytet 8 ładuje usera po email, waliduje hash refresh-tokena, resolwuje SSO providera, hydratuje lazy `tenant_locales`) ZANIM `RlsContextListener` (priorytet 0) ustawi GUC. Ścisła polityka odrzuciłaby te odczyty (GUC jeszcze pusty) → zepsuty login/refresh/SSO. Relaksacja działa TYLKO gdy GUC pusty (okno pre-auth); gdy tenant jest w kontekście ta sama polityka egzekwuje ścisłą izolację. Lookupy w tym oknie idą po unikalnych nieenumerowanych kluczach (email, 256-bit hash, UUID), a logika auth waliduje własność. `audit_logs` jest tu bo wiersz jest pisany na `kernel.response` z tenantem, którego GUC z `kernel.request` mógł nie mieć (login autentykuje się W TRAKCIE requestu); listener i tak ustawia `tenant_id` z principal'a, więc RLS to defence-in-depth, nie główna granica. Wiersze NULL-tenant (platform/super-admin) pozostają widoczne tylko przez `super_admin_bypass`.
- **roles, smart_filter_presets** — nullable `tenant_id` (built-iny/system-shipped mają NULL i są widoczne dla wszystkich tenantów); polityka dokleja `OR tenant_id IS NULL` (lustrzane do `TenantFilter` SystemShipped).
- **junction bez `tenant_id`** (`user_role_assignments`) — bez RLS; granicę daje FK do scopowanego rodzica + RLS rodzica.
- **infra bez `tenant_id`** (`tenants`, `permissions`, `messenger_messages`, `doctrine_migration_versions`) — poza RLS.

Migracja: [`Version20260617050000`](../apps/api/migrations/Version20260617050000.php) (idempotentny guard `CREATE ROLE pim_app` + GRANT-y DML + default privileges + ENABLE/FORCE + polityki). Round-trip up/down/up czysty. Rola `pim_app` (LOGIN + hasło) jest tworzona/synchronizowana idempotentnie przez [`docker/postgres/pim-init-app-role.sh`](../docker/postgres/pim-init-app-role.sh) na każdym starcie bazy (przed connectem api, na świeżym i istniejącym wolumenie).

### Test izolacji pod non-superuserem

[`ForceRlsTenantIsolationTest`](../apps/api/tests/Integration/Identity/ForceRlsTenantIsolationTest.php) odtwarza oba stany na tabeli domenowej (`channels`) na jednym połączeniu: RED (bez polityki, NOBYPASSRLS reader scopowany do A widzi wiersz B) → GREEN (po FORCE + polityce z migracji, B = 0, A = 1). Związany z faktyczną migracją (regresja drop'ująca tabelę z listy → fail). Pełen pen-test zewnętrznego audytora pozostaje przed SaaS go-live (Phase 7).

## Bulk operations — runbook

`COPY`, raw `INSERT … SELECT`, oraz każdy worker bulk-import używający `EntityManager::clear()` wymagają osobnej dyscypliny:

- **`COPY`** ignoruje RLS i Doctrine filter. Zawsze ustaw `tenant_id` w SELECT klauzuli źródłowej (`COPY products FROM stdin … WHERE tenant_id = '…'`) lub jako stałą kolumnę przy imporcie.
- **`INSERT … SELECT`** respektuje RLS (gdy aktywne), bo to normalny INSERT — używaj go zamiast `COPY` dla cross-tenant exportów.
- **bulk worker** (`AbstractBatchHandler` z #13) musi po każdym `clear()` zrobić `find()` na `Tenant` i `TenantContext::set()` przed kontynuacją batch'a (lekcja z #13 — bez tego `TenantAssignmentListener` rzuca na detached referencję).
- gdy aktywujemy RLS w fazie 2, runbook backup/restore (`docs/runbook/restore.md`) musi explicit:
  - przed `pgBackRest restore` → `ALTER TABLE … DISABLE ROW LEVEL SECURITY` (tylko dla supera);
  - po restore → `ALTER TABLE … ENABLE ROW LEVEL SECURITY`.
  Inaczej `pgBackRest` `COPY` na pusty target z aktywnym RLS = wszystkie wiersze deny.

## CLI — `pim:tenant:audit`

```bash
docker compose exec api bin/console pim:tenant:audit
```

Read-only audit każdej tabeli z `public` schema:

- **FAIL** — domena bez `tenant_id` (najczęściej: zapomniana migracja przy nowej encji);
- **FAIL** — domena z `tenant_id` nullable (poza `roles`);
- **WARN** — domena bez indeksu na `tenant_id` (perf concern);
- **OK** — wszystko gra.

Exit code 0/1 (success/failure) — nadaje się do CI gate'u w przyszłości. Idempotent, nieinwazyjny.

## Smoke test izolacji

[`TenantIsolationTest`](../apps/api/tests/Functional/Identity/TenantIsolationTest.php) (#12 / 0.0.12, rozszerzony przez voters w #26) tworzy dwa tenanty, dwie pary userów + produktów, autentykuje admin'em tenant'u A i sprawdza że:

- `GET /api/products` zwraca tylko produkty A (5 z 10);
- `GET /api/products/{id-z-tenant-B}` zwraca **404** (filter ukrywa istnienie wiersza, nie 403);
- `PATCH` cross-tenant zwraca **404**;
- raw SQL przez DBAL widzi obie strony — explicit, dokumentuje gdzie kończy się boundary.

W epiku 0.3 ten test rozszerzy się o `Object`/`Channel`/`Asset`. Po fazie 2 dochodzi pen-test RLS — patrz wyżej.

# Security review checklist — PR-y dotykające auth / permissions / danych

> GOLIVE A3 (#2123). Checklist code-review dla każdego PR-a dotykającego
> uwierzytelniania, autoryzacji, izolacji tenantów, sekretów lub parsowania
> nieufnego wejścia. Wiele pozycji jest egzekwowanych maszynowo (Semgrep
> `.semgrep/cortex-rbac.yml`, PHPStan rules, Deptrac, gitleaks) — checklist
> pokrywa to, co wymaga ludzkiej oceny. Model zagrożeń: `threat-model.md`.
> Warstwa agenta ma własną sekcję na końcu `threat-model.md`.

## Nowy endpoint (`#[Route]` / API Resource)

- [ ] Ma `#[RequiresPermission(module, action)]` **albo** jawne
      `#[NoPermissionRequired]` z uzasadnieniem (Semgrep
      `cortex-requires-permission-attribute-missing` to wymusza — nie obchodź).
- [ ] Kod uprawnienia istnieje w macierzy (`src/Identity/Domain/Rbac/RbacMatrix.php`,
      `docs/rbac.md`).
- [ ] Błędy w formacie RFC 7807 (problem+json), bez stack-trace w prod.
- [ ] Scoping własności: cudzy zasób → **404, nie 403** (nie ujawniaj istnienia).
- [ ] Jeśli `PUBLIC_ACCESS`: udokumentowane *dlaczego* + podpięty rate-limiter
      (patrz niżej) + brak side-effectów poza zamierzoną akcją.

## Nowy voter

- [ ] Sprawdza uprawnienie przez `isGranted(permission, subject)`, **nie**
      hardkodowany string roli (`hasRole('admin')` zbanowany —
      Semgrep `cortex-no-direct-role-string-check`).
- [ ] Row-level: weryfikuje tenant + scope (kategoria/locale/channel) subjectu.
- [ ] `abstain` vs `deny` przemyślane — brak głosu ≠ dostęp (fail-closed).
- [ ] Test: pozytyw + negatyw + cross-tenant (0 wyników).

## Nowa encja / migracja (izolacja tenantów)

- [ ] Encja implementuje `TenantScoped` (`getTenant()`/`assignTenant()`) —
      Semgrep `cortex-entity-missing-tenant-id`.
- [ ] Migracja: `tenant_id UUID NOT NULL` + FK + `ENABLE`/`FORCE ROW LEVEL
      SECURITY` + `CREATE POLICY tenant_isolation_*`.
- [ ] Polityka RLS używa `NULLIF(current_setting('app.current_tenant', true), '')::uuid`
      (nie bare `::uuid`; GUC to `app.current_tenant`, nie `pim.current_tenant_id`).
- [ ] Tabele auth (users/refresh_tokens): polityka „pre-context-safe" (dopuszcza
      brak GUC dla ścieżki logowania).
- [ ] `TenantAssignmentListener` stempluje na `prePersist` (test:
      `TenantAssignmentListenerTest`).
- [ ] Test izolacji: `ForceRlsTenantIsolationTest` cross-tenant read = 0 wierszy.

## Raw SQL / bulk / async

- [ ] Raw SQL: jawny predykat `tenant_id` + komentarz `// tenant-safe:`
      (Semgrep `cortex-raw-sql-missing-tenant-filter`; break-glass wyjątek tylko
      w plikach `SuperAdmin*`).
- [ ] Bulk DQL UPDATE/DELETE: pamiętaj że SQLFilter działa tylko na SELECT —
      dołóż jawny `tenant` predykat, RLS = backstop.
- [ ] Handler async: kontekst tenanta odtworzony (`TenantContextRebindingMiddleware`
      + `TenantRlsGucMiddleware` ustawia GUC); po `em->clear()` reattach
      run/tenant przed persistem (lekcja AUD-002 / agent P9-01).
- [ ] `flush()` w pętli batch ma `clear()` (PHPStan `FlushWithoutClearInLoopRule`).

## Token / sekret

- [ ] Sekret nigdy w response (klucze BYOK/integracji — tylko prefix do
      wyświetlenia) ani w logach.
- [ ] Sekret w Vault / env (nie w trackowanym pliku) — guard
      `lint-tracked-secrets` + gitleaks/trufflehog CI.
- [ ] JWT: algorytm pinowany (brak `alg:none`); refresh HttpOnly + rotujący +
      single-use (check-then-reset odporny na wyścig).
- [ ] Access token po stronie FE tylko w pamięci — **nie** `localStorage`.
- [ ] Nowy klucz API / webhook secret: przechowywany jako hash / zaszyfrowany;
      podpis weryfikowany timing-safe.

## Rate limiter (nowa powierzchnia publiczna)

- [ ] Endpoint `PUBLIC_ACCESS` konsumuje limiter (albo jawnie udokumentowany brak).
- [ ] Klucz limitera poprawny: per-IP (login), per-email (reset), per-key
      (api_key), per-feed (feed_pull), per-tenant (import/backup).
- [ ] 429 = problem+json + `Retry-After`; limit liczony PRZED kosztowną operacją.
- [ ] Endpoint zawsze-200 (np. password-reset) limituje ścieżkę sukcesu **i**
      porażki (brak timing oracle).

## Field-level (atrybuty)

- [ ] Read: `FieldRestrictionFilter` usuwa ograniczone atrybuty z serializacji.
- [ ] Write: `canEditAttribute()` przed akceptacją PATCH.
- [ ] Export: policy atrybutowa zastosowana przed listą kolumn (AUD-008).
- [ ] Schema route nie wycieka definicji ograniczonych atrybutów.

## Parsowanie nieufnego wejścia (import/webhook)

- [ ] XML: parser bez rozwijania external entities (XXE); XLSX: `XlsxArchiveGuard`
      (zip-bomb central-dir); ścieżki: `FolderPathGuard` (traversal).
- [ ] Eksport CSV/XLSX: neutralizacja formuł (`=`,`+`,`-`,`@` prefix).
- [ ] URL zewnętrzny (media/webhook): `NoPrivateNetworkHttpClient` (SSRF +
      redirect rebinding).
- [ ] Limity rozmiaru pliku + memory workera (bounded decompression).

## Agent (jeśli PR dotyka warstwy agenta)

Patrz osobna sekcja „Security review checklist (PRs touching auth / agent)"
w `threat-model.md` — narrowest `requiredPermission`, write przez
`pending_changes`, removability (`agent-removability` CI), audyt każdej tranzycji.

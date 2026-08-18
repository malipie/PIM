# Threat model — Agent layer (STRIDE)

> AGENT-P9-03 (#1990). Scope: the agentic admin (epik 0.7) and its interaction
> with the catalog, RBAC and BYOK. Complements the RBAC threat model in
> `Project Plan/07-rbac-implementation-plan.md` §5. Update after each red-team
> round (`docs/security/agent-red-team-checklist.md`) and after any new attack
> vector surfaces.

## Assets

- **Catalog data** (product values, schema/attributes) — integrity is the prize
  of a prompt-injection attack.
- **Tenant BYOK Anthropic key** — AES-256-GCM at rest; a leak is account
  takeover on the tenant's Anthropic account.
- **Cross-tenant isolation** — one tenant's run must never read or write
  another's data.
- **Audit trail** (`agent_runs`, `agent_tool_calls`, `audit_logs`) —
  accountability for "who approved what".
- **Spend** — the §8.5 budgets protect against runaway cost / a compromised
  loop draining the key.

## The seven defence layers (§11.5)

1. **RBAC on every tool call** — the loop has no security token; the registry
   filters tools by the initiating user's permissions (`ToolRegistry::
   availableFor`) and the executor re-checks on execute. Fine-grained
   per-attribute/locale/channel checks run inside the tools by user id.
2. **Single human approval gate** — no write tool commits; it materializes a
   `pending_changes` batch (plan == diff, ADR-0024 c). The operator sees the
   real before→after, not the model's narration.
3. **Tenant isolation** — Doctrine `TenantFilter` on SELECTs + explicit
   `tenant_id` predicates on the raw SQL bulk paths + Postgres RLS (FORCE) as
   the backstop.
4. **Per-role autonomy** (`roles.agent_autonomy`) — `off` / `read_only` /
   `propose`; write/schema stay behind approval at every level.
5. **§8.5 budgets + kill-switch** — in-run (10 calls, 100k tokens) and windowed
   (50/h/user, 500k/day/user, $20/day, $300/month per tenant); exceeding a
   window disables the agent until it rolls over.
6. **BYOK isolation** — the key is encrypted, never returned by any endpoint
   (only the display prefix), resolved per tenant at call time; no key ⇒ agent
   off (fail-closed feature guard).
7. **Append-only audit** — every tool call (allowed / forbidden / crashed) and
   every decision (start / commit / reject / cancel / rollback) lands in the DH
   Auditor with the actor and, for decisions, the approver.

## STRIDE

| Category | Vector | Mitigation | Residual / hook |
|----------|--------|------------|-----------------|
| **Spoofing** | Run attributed to the wrong user / off-request worker | Actor = run owner id carried explicitly into audit (not the request token); approver recorded separately | — |
| **Tampering** | Prompt injection in a description/schema orders a mass edit | Layers 1+2: RBAC bounds the surface, approval stops every write; red-team P9-01 proves zero writes pre-accept | Prompt classifier / tool sandbox = §13.5 hook if a vector ever defeats approval |
| **Tampering** | Compromised tool suppresses its own audit row by clearing the EM | Executor re-fetches a managed run and persists the audit row regardless (P9-01 hardening) | — |
| **Repudiation** | "The agent did it, not me" | Append-only `agent_runs` + `agent_tool_calls` + `audit_logs`; approver ≠ actor | — |
| **Information disclosure** | BYOK key exfiltrated via the model or an endpoint | Key encrypted, never in any response, resolved server-side; the model only ever sees tool *results* (minimal projections), never the key | Transparency copy in Settings lists exactly what reaches the model |
| **Information disclosure** | Cross-tenant read through a crafted filter | Layer 3 (filter + explicit predicate + RLS); red-team P9-01 cross-tenant test | — |
| **Denial of service** | Runaway loop drains the key / spend | Layer 5 in-run + windowed caps, kill-switch; one active run per user (partial unique index) | — |
| **Elevation of privilege** | Model calls a tool outside the user's permissions | Layer 1 per-call RBAC refuses + audits (`forbidden`); red-team P9-01 escalation test | — |
| **Elevation of privilege** | Schema rollback destroys data (delete-with-values) | P5-04 dataless-only boundary blocks it with a reason | — |

## Security review checklist (PRs touching auth / agent)

- [ ] New tool: declares the narrowest `requiredPermission`; write/schema kind
      routes through `pending_changes` (never commits directly).
- [ ] New cross-BC reach: goes through a `*\Contracts\*` port (Deptrac green),
      not internals.
- [ ] Raw SQL: explicit `tenant_id` predicate + `// tenant-safe:` comment.
- [ ] New endpoint: `AgentFeatureGuard::assertEnabled` (if agent) + RBAC
      attribute + RFC 7807 errors + ownership scoping (own-run ⇒ 404, not 403).
- [ ] Any new agent lifecycle transition: audited via `AgentActionAuditor`.
- [ ] Removability: agent DI stays in `services_agent.yaml`; no `App\Agent`
      reference leaks into core `src`/`config`/`tests` (CI `agent-removability`).
- [ ] Secrets: no plaintext key in responses/logs; BYOK stays encrypted.

---

# Threat model — system-wide (STRIDE)

> GOLIVE A3 (#2123). Rozszerza powyższy model agenta na całość systemu przed
> go-live. Konsoliduje wektory z audytu półrocznego (`docs/audit/2026-06/`,
> 81 findings, 5/5 CRITICAL naprawione Wave 0–3) jako punkty odniesienia.
> Fundament obrony udokumentowany osobno: `docs/multi-tenancy.md` (TenantFilter
> + RLS + GUC), `docs/rbac.md` (macierz uprawnień + voters). Aktualizować po
> każdym red-teamie (Blok B #2129/#2130) i nowym wektorze.

## Granice zaufania (trust boundaries)

1. **Nieuwierzytelniony HTTP** — 15 tras `PUBLIC_ACCESS` (login, refresh, 2FA,
   password-reset request/confirm, invitation accept/verify, SSO callback,
   asset preview HMAC, publiczny feed-pull token, docs/contexts/metrics/root).
2. **Uwierzytelniony HTTP** — powierzchnia zasobowa API Platform + custom
   `#[Route]`; granica = tenant + RBAC.
3. **Async / tło** — Messenger (`sync`/`async`/`import`/`failed`), webhooki
   wychodzące.
4. **Agent LLM** — powierzchnia narzędzi (osobny model powyżej).
5. **Parsowanie plików** — import CSV/XML/JSON/XLSX/ZIP.
6. **App → Postgres** — TenantFilter + RLS (FORCE, rola `pim_app` NOBYPASSRLS).

## Assets (poza agentem)

- **Dane katalogu wszystkich tenantów** — integralność + izolacja.
- **Sekrety** — JWT keypair, BYOK master key, credentiale integracji (AES-GCM),
  webhook secrets, feed tokeny (192-bit).
- **Konta / sesje** — JWT (15 min) + refresh (HttpOnly, rotujący), MFA.
- **Dostępność** — worker pool (FrankenPHP), pojemność DB pod 50k+ SKU.

## STRIDE — powierzchnia systemowa

| Category | Vector | Mitigation | Ref / residual |
|----------|--------|------------|----------------|
| **Spoofing** | Podszycie pod tenanta przez sfałszowany JWT (alg-confusion `alg:none`) | Lexik RS256, algorytm pinowany; brak akceptacji `none` | red-team B3 #2129 |
| **Spoofing** | Brute-force logowania / enumeracja kont | Limiter `auth_login` 5/IP/15min; password-reset zawsze-200 (`password_reset_email` 5/15min + `password_reset_ip` 10/15min) | — |
| **Spoofing** | Enumeracja/brute tokenu zaproszenia lub feedu | Token 192-bit + limiter `invitation_accept` 10/15min, `feed_pull` 120/h/feed; 404-not-403 | AUD-030 (fixed) |
| **Tampering** | Cross-tenant write przez DQL/native omijający TenantFilter | RLS FORCE backstop (`pim_app` NOBYPASSRLS) + `// tenant-safe:` raw SQL + Semgrep `cortex-raw-sql-missing-tenant-filter` | AUD-002 (fixed W1-1) |
| **Tampering** | CSV/formula injection w eksporcie, XXE/zip-bomb w imporcie | `neutraliseFormula` (XlsxStreamWriter), `XlsxArchiveGuard` central-dir, limity rozmiaru + memory workera | AUD-051 IMP2-2.8 (fixed) |
| **Tampering** | SSRF przez URL webhooka / media z URL | `NoPrivateNetworkHttpClient` (blokuje RFC1918 + redirect rebinding) | IMP2-1.12 review (fixed) |
| **Repudiation** | „Nie ja to zrobiłem" | DH Auditor append-only na encjach audytowanych + `audit_logs`; break-glass loguje `cross_tenant_access=true` | — |
| **Information disclosure** | Cross-tenant SSE (Mercure) / cross-tenant read przez filtr Meili | Topiki tenant-prefixed + `private:true`; whitelist `filterableAttributes` Meili | AUD-001/004 (fixed W0) |
| **Information disclosure** | Sekret w response/logach/trackowanym `.env` | Sekrety w Vault/env (nie w gitcie), guard `lint-tracked-secrets`, gitleaks/trufflehog CI; klucze nigdy w API (tylko prefix) | AUD-005 (fixed W0-7) |
| **Information disclosure** | Cross-tenant asset przez zgadnięty preview URL | Podpis HMAC-SHA256 + TTL (AssetPreviewUrlSigner) | AUD-006 (fixed W0-4) |
| **Information disclosure** | Field-level: atrybut ograniczony wyciekający w read/PATCH/export | `FieldRestrictionFilter` (read) + `canEditAttribute` (write) + policy w export builderze | AUD-008 (fixed W0-6) |
| **Denial of service** | OOM workera na bulk (50k) — flush-bez-clear | PHPStan `FlushWithoutClearInLoopRule` + limit pamięci kontenera + alert Prometheus 256 MiB | AUD-011/012 (fixed W1-8) |
| **Denial of service** | Zalew publicznych endpointów / kosztów agenta | Limitery per-bucket (login/reset/invite/feed/import/api_key/agent_run); §8.5 budżety agenta | — |
| **Elevation of privilege** | Endpoint bez `#[RequiresPermission]` (domyślnie dostępny) | Semgrep `cortex-requires-permission-attribute-missing` + PHPStan rule; voters + `AbstractPrdVoter` | Phase 6 retrofit (done) |
| **Elevation of privilege** | Hardkodowany `hasRole('admin')` omijający macierz | Semgrep `cortex-no-direct-role-string-check`; wszystkie decyzje przez `isGranted(permission)` | — |
| **Elevation of privilege** | Nadużycie break-glass / platform-operator jako tenant super_admin | Rozdział platform-operator ≠ tenant super_admin; break-glass audytowany + runbook | AUD-003 (fixed W0-3) |

## Ryzyko rezydualne

- **Brak zewnętrznego pentestu** przed pierwszym pilotem — kompensowane B3
  (15-punktowy red-team) + B3+ (wiel-agentowy deep audit); zewnętrzny do fazy
  SaaS. Zapisane w `06-sprint-0-findings.md`.
- **Timing-safe compare podpisu webhooka** — do jawnej weryfikacji w B3.
- **Prompt classifier / tool sandbox** (agent) — hook §13.5 jeśli wektor
  kiedykolwiek pokona approval.

---

# Threat model — provisioning instancji tenantów (STRIDE)

> TNT-P4-09 (#2910). Powierzchnia dołożona przez epik TNT: **aplikacja webowa
> pośrednio uruchamia kontenery na hoście**. Model orkiestracji i uzasadnienie
> podziału: ADR-0036. Runbook operacyjny: `docs/runbook/tenant-instances.md`.

## Dlaczego ta powierzchnia jest osobno

Reszta systemu operuje na wierszach w bazie. Ta ścieżka kończy się procesem
uruchomionym na hoście. Dostęp do gniazda demona Dockera jest **równoważny
uprawnieniom roota na hoście** — kto może wołać demona, ten może wystartować
kontener z podmontowanym `/`. Skala pomyłki jest tu inna niż wszędzie indziej
w tym dokumencie: nie wyciek danych jednego tenanta, tylko cały serwer ze
wszystkimi klientami.

## Ścieżka i granice zaufania

```
operator (przeglądarka)
   │  HTTPS, JWT + platform.tenants.manage
   ▼
┌──────────────────────────┐
│ API instancji platformowej│   ① granica: uwierzytelnienie + uprawnienie
│  (PIM_INSTANCE_ROLE=      │   ② granica: walidacja kodu i subdomeny
│   platform)               │      NIE MA dostępu do Dockera
└──────────┬───────────────┘
           │  plik `<uuid>.job.json` w katalogu kolejki
           ▼                    ③ granica: kolejka plikowa, brak portu
┌──────────────────────────┐
│ provisioner               │   ④ granica: walidacja POWTÓRZONA
│  network_mode: none       │      + allowlista projektów
└──────────┬───────────────┘
           │  gniazdo uniksowe
           ▼
      demon Dockera  ──►  stack instancji tenanta
```

Cztery decyzje, na których to stoi:

1. **Kolejka plikowa zamiast HTTP.** Komponent z uprawnieniami roota na hoście
   nie nasłuchuje na żadnym porcie. Jego powierzchnia sieciowa wynosi zero
   i `network_mode: none` to egzekwuje.
2. **Walidacja powtórzona po drugiej stronie.** Provisioner nie ufa API, choć
   API waliduje pierwsze. To osobne komponenty, a ten drugi umie uruchamiać
   kontenery — przyjęcie „wołający już sprawdził" byłoby jedyną warstwą.
3. **Procesy uruchamiane tablicą argumentów, nigdy przez powłokę.**
   `shell=False` jest decyzją bezpieczeństwa, nie stylem: kod tenanta pochodzi
   z żądania HTTP, a powłoka interpretowałaby w nim znaki specjalne.
4. **Projekt wyprowadzany z kodu, nigdy z ładunku.** `project = f"pim-{code}"`,
   a `pim` i `pim-platform` są na twardej liście projektów zakazanych. Zlecenie nie może
   wskazać, w który stack ma uderzyć.

## STRIDE — provisioning

| Category | Vector | Mitigation | Ref / residual |
|----------|--------|------------|----------------|
| **Spoofing** | Właściciel tenanta (globalny `super_admin`) podszywa się pod operatora platformy i zakłada/kasuje instancje | Bramka `platform.tenants.manage`, rozdzielona od ról tenanta; panel istnieje **tylko** na instancji platformowej (404 na routingu, nie 403) | AUD-003; test `ProvisioningNegativeSurfaceTest` — 403 **przed** kolejką, katalog zleceń pusty |
| **Tampering** | Wstrzyknięcie polecenia przez kod tenanta lub subdomenę (`acme; rm -rf /`, `$(id)`, nowa linia) | Dwie niezależne walidacje po stronie API (kod + wartość `TenantSubdomain`) + trzecia w provisionerze; wywołania tablicą argumentów bez powłoki | `ProvisioningNegativeSurfaceTest` (8 ładunków, failing-first) + `test_provisioner.py` |
| **Tampering** | Zlecenie celujące w stack współdzielony albo w instancję platformową | `PROTECTED_PROJECTS = {pim, pim-platform}` sprawdzane **przed** wykonaniem czegokolwiek | `test_provisioner.py::test_protected_projects_are_unreachable` |
| **Tampering** | Ładunek dyktuje projekt, akcję albo hasło właściciela | Endpoint wybiera akcję; projekt wyprowadzany z kodu; hasło tymczasowe losuje kolejka | `ProvisioningNegativeSurfaceTest::anAttackerCannotNameTheProjectTheJobWillTouch` |
| **Tampering** | Operacja Compose'a bez jawnego projektu trafia w stack z katalogu roboczego | Każde wywołanie ma `-p` i `--env-file`; kształt przypięty testem | Incydent #2929 (odtworzenie cudzej bazy) — `test_provisioner.py::ComposeCallsAreAlwaysScopedToOneInstance` |
| **Repudiation** | „Nie zlecałem usunięcia tego klienta" | `requested_by` w każdym zleceniu + log audytowy provisionera (linia JSON na zdarzenie, na stdout → `docker logs`, przeżywa czyszczenie kolejki) | — |
| **Information disclosure** | Odczyt dowolnego pliku przez identyfikator zlecenia w ścieżce (`../`) | Kształt UUID sprawdzany **zanim** cokolwiek zostanie sklejone, w kolejce i w wymaganiach trasy | `ProvisioningNegativeSurfaceTest::theProgressEndpointCannotBeUsedToReadArbitraryFiles` |
| **Information disclosure** | Hasło tymczasowe właściciela w liście procesów hosta | Hasło idzie zmienną środowiskową pojedynczego wywołania, nie argumentem | — |
| **Information disclosure** | Postęp provisioningu ujawnia istnienie klientów osobie bez uprawnień | Ten sam kod uprawnienia co reszta panelu | `ProvisioningNegativeSurfaceTest::aTenantOwnerCannotReadProvisioningProgress` |
| **Denial of service** | Zlecenie wieszające provisionera i blokujące kolejkę dla pozostałych | `PROVISIONER_STEP_TIMEOUT` (1800 s) na krok, limit czasu na oczekiwanie na zdrowie instancji (180 s), pętla nie umiera na wyjątku | — |
| **Denial of service** | Instancja jednego klienta zjada zasoby sąsiadów | Twarde limity pamięci i CPU per usługa w `docker-compose.tenant.yml` | ADR-0035 |
| **Elevation of privilege** | Gniazdo Dockera dołożone do `api` „na chwilę, do debugowania" | Bramka CI `scripts/lint-docker-socket.sh` — montaż dozwolony **wyłącznie** w usłudze `provisioner` | #2910 |
| **Elevation of privilege** | Uruchomienie procesu z kodu API (nowy `shell_exec`, `Process`) | Semgrep `cortex-no-process-execution-in-api` (ERROR, blokujące) z trzema imiennymi wyjątkami | #2910 |
| **Elevation of privilege** | Kompromitacja kontenera provisionera → root na hoście | Usługa minimalna, bez sieci, bez sekretów aplikacji; **ryzyko rezydualne** — patrz niżej | ADR-0036 |

## Ryzyko rezydualne

- **Brak pośrednika przed API Dockera.** Provisioner ma pełny dostęp do demona,
  a nie zawężony zestaw operacji. Kandydat: `docker-socket-proxy` z allowlistą
  endpointów. Zapisane przy zamknięciu #2905 i nadal otwarte — obecną granicą
  jest to, że usługa jest mała, jednozadaniowa i nie ma sieci.
- **Zapis do drzewa repozytorium.** Provisioner montuje `.:/workspace` do
  **zapisu**, bo musi wygenerować `.env.tenant.<kod>`. Kto przejmie tę usługę,
  może podmienić skrypt, który sam potem wykona. Świadome ustępstwo — bez niego
  nie da się wygenerować środowiska instancji.
- **`pgbackrest` uruchamiany na skutek żądania HTTP.** `PgBackRestRunner` woła
  binarkę z workera po wiadomości wysłanej przez kontroler. Argumenty są tablicą
  stałych, a stanza pochodzi z konfiguracji, nie z żądania — ale to jest proces
  uruchamiany w reakcji na ruch z sieci. Wyłączony imiennie z reguły Semgrepa,
  żeby wyjątek był widoczny, a nie zaszyty w zakresie ścieżek.
- **Rozjazd rejestru i stanu faktycznego.** Gdy zatrzymanie kontenerów padnie,
  tenant jest zawieszony w rejestrze, a jego instancja dalej odpowiada. Wybór
  jest świadomy (fail-closed po stronie dostępu), a rozjazd trafia do logów na
  poziomie `error` — nie jest jednak nigdzie alertowany.

# Plan testów przedprodukcyjnych PIM (epik GOLIVE)

**Typ dokumentu:** Plan finalnych testów + backlog ticketów przed go-live
**Status:** Aktywny — utworzony 2026-07-03
**Label GitHub:** `epik-GOLIVE` · **Milestone:** GOLIVE — testy przedprodukcyjne
**Powiązane:** [`14-rbac-tickets-phase-7.md`](14-rbac-tickets-phase-7.md) (red-team), [`06-sprint-0-findings.md`](06-sprint-0-findings.md) (świadome odejścia), [`docs/audit/2026-06/`](../docs/audit/2026-06/) (audyt półroczny)

---

## 1. Kontekst

Projekt ~80% ukończony. W toku: epik AGENT (0.7 Cortex), następnie epik DP (drobne poprawki #2031–#2039), poprawki frontendu, potem **go-live**. Ten dokument zbiera wszystko, co musi zostać przetestowane, zanim system wpuści realne dane klientów.

**Decyzje operatora (2026-07-03):**
- **Load testing** — jednorazowa sesja pomiarowa przed startem, bez stałej infry k6 w CI.
- **Pentest** — tylko własny red-team (15-punktowa checklista RBAC Phase 7); zewnętrzny pentest odłożony do fazy multi-tenant SaaS.
- **Audyt przejmowalności** — dodany na życzenie operatora (czy projekt jest gotowy do przejęcia przez zewnętrzny software house).
- **Głęboki adwersaryjny audyt bezpieczeństwa (B3+)** — dodany: wiel-agentowe, semantyczne śledzenie przepływu danych ponad to, co łapią skanery.
- **Forma** — ten dokument + tickety GitHub z labelem `epik-GOLIVE`.

**Stan zastany (inwentaryzacja infrastruktury testowej):**

| Obszar | Status | Pokrycie |
|---|---|---|
| Testy PHPUnit | ✅ | 454 pliki (210 unit / 70 integration / 159 API / 10 architecture), 5-shardowe CI |
| Playwright E2E | ✅ | 122 specy, pełny stack, tylko Chromium |
| Benchmarki pamięci | ✅ | 4 klasy, bramka < 256 MiB przy 50k wierszy |
| CI quality gates | ✅ | PHPStan max, Deptrac, Semgrep RBAC, audyty, agent-removability |
| Audyt 2026-06 (81 findings) | ✅ | Wszystkie naprawione Wave 0–3 (5 CRITICAL zamknięte) |
| Runbooki (break-glass, secrets, rotation) | ✅ | Kompletne |
| pgBackRest backup | ✅ konfiguracja | Nigdy nie odtwarzany (brak restore-drill) |
| **Load / performance testing** | ❌ **LUKA** | p95 < 300 ms nigdy niezweryfikowane pod ruchem |
| **threat-model.md / security-checklist.md** | ❌ **LUKA** | Nie istnieją |
| **Restore-test backupu** | ❌ **LUKA** | Konfiguracja jest, próby brak |
| Monitoring / alerting | ⚠️ częściowe | Tylko RBAC + pamięć workerów |
| RBAC Phase 7 (red-team + launch) | ❌ nietknięta | 6 ticketów zaplanowanych |
| Agent live LLM smoke | ❌ blocker | Brak klucza Anthropic (BYOK) |

---

## 2. Zasada sekwencjonowania — 3 bloki

- **Blok A** — startuje **teraz, równolegle** z epikami AGENT/DP/frontend. Nie dotyka kodu aplikacji (docs/infra/audyt).
- **Blok B** — **po code freeze** (koniec AGENT + DP + frontend). Finalna regresja, load, security, smoke — nie ma sensu na ruchomym kodzie.
- **Blok C** — **przygotowanie produkcji** + soft launch.

Krytyczna ścieżka: `Blok A ‖ epiki` → **code freeze** → `Blok B` → `Blok C` → **go-live**.

---

## 3. BLOK A — równolegle z epikami (~40–55h)

### A1. Audyt przejmowalności kodu (handover-readiness) — ~12–16h
Czy projekt jest gotowy do przejęcia przez zewnętrzny software house.
- **Cold-start test** — świeży klon na czystej maszynie → uruchomienie stacka i testów **wyłącznie z README/docs**, bez wiedzy plemiennej. Pomiar czasu do „zielono"; każda luka w dokumentacji = ticket. Symuluje pierwszy dzień nowego zespołu.
- **Audyt struktury vs deklaracja** — drift między kodem a `01-architektura-pim.md` + ADR-ami (Deptrac pilnuje bounded contexts; tu chodzi o aktualność dokumentacji).
- **Inwentaryzacja długu** — dead code (deprecated `families`/`family_attributes`/`products`/`product_values` — do usunięcia przed handoverem?), skan TODO/FIXME/HACK z klasyfikacją, pliki ponad limit ADR-0021.
- **Audyt licencji** — `composer licenses` + pnpm; brak GPL blokującego użycie komercyjne.
- **Deliverable** — `docs/audit/2026-07-handover/` z oceną per obszar (dokumentacja / testy / spójność / dług) + lista braków.

### A2. Próba generalna backup/DR — ~6–8h
- Pełny restore pgBackRest na czyste środowisko + PITR, pomiar RTO.
- **Znany quirk** — po pg_restore rola `pim_app` traci granty na schema public; procedura re-grant do runbooka.
- Restore-test jako powtarzalny `scripts/restore-drill.sh` (ręcznie co miesiąc, świadomie poza CI).

### A3. Dokumenty bezpieczeństwa — ~6–8h
- `docs/security/threat-model.md` — STRIDE dla całości (rozszerzyć istniejący STRIDE agenta z commita 27a09194).
- `docs/security/security-checklist.md` — checklista code-review dla PR-ów auth/permissions.

### A4. Przygotowanie load testingu — ~6–8h
- Skrypty k6 w `scripts/load/` — lista produktów (kursor), wyszukiwarka Meili, edycja masowa, start importu, export, feed XML, login (uwaga na rate limit).
- Seed 50k SKU × 200 atrybutów × 3 locale (reuse fixtures z benchmarków `SYNC_BENCH_ROWS`).
- Sesja pomiarowa → Blok B.

### A5. Fresh-install test — ~4–6h
- `doctrine:migrations:migrate` od pustej bazy przez CAŁĄ historię (po miesiącach pracy często pęka; prod deploy i software house zaczną właśnie od tego).
- Reindex Meilisearch od zera przy 50k SKU — czas + spójność z Postgres.
- Rebuild `attributes_indexed` workerem od zera — spójność denormalizacji.

### A6. Kompletność i18n + macierz przeglądarek — ~4–6h
- Skan literałów poza `t()` + brakujących kluczy pl/en (Playwright = tylko Chromium, literały mogły przeciec).
- Manualny przegląd krytycznych widoków na Firefox + Safari (1 przejście, bez automatyzacji).

---

## 4. BLOK B — po code freeze (~50–70h z B3+)

### B1. Pełna regresja automatyczna (baseline) — ~2h + CI
Zielony przebieg całego CI na main: PHPStan, Deptrac, Semgrep, 5 shardów PHPUnit, benchmarki, 122 specy Playwright, audyty, agent-removability. **Punkt odniesienia** — dalej testujemy tylko ten commit.

### B2. Sesja load testowa (jednorazowa) — ~8–10h
- k6 z A4 na 50k SKU: weryfikacja **p95 < 300 ms** per endpoint → `docs/perf/`.
- **Endurance** — 2–4h ciągłego ruchu mieszanego, obserwacja `frankenphp_worker_memory_bytes` (wyciek Identity Map = ryzyko #1 worker mode; benchmark łapie 1 job, nie wielogodzinny bieg).
- Regresje wydajności = tickety fix przed produkcją.

### B3. Red-team + regresja bezpieczeństwa — ~10–14h
- 15-punktowa checklista RBAC Phase 7 → `docs/security/red-team-findings-2026-XX.md`.
- Jawna regresja 5 CRITICAL z audytu 2026-06 (Mercure, RLS, Meili, token_dev_only, sekrety) — probe'y z `docs/audit/2026-06/probes/` ponownie na aktualnym main.
- Krytyczne findings = **blocker go-live**.

### B3+. Głęboki adwersaryjny audyt bezpieczeństwa (multi-agent) — ~16–24h
> Wyjście poza checklistę i pattern-matching. Semgrep/PHPStan łapią wzorce, nie błędy logiczne. Wiel-agentowa orkiestracja (Workflow) na skali — świadomie kosztowna tokenowo, odpalana za zgodą operatora, w zamian za brak zewnętrznego pentestu.

- **Metoda — śledzenie taint (źródło → ujście):** dla każdego źródła danych niezaufanych prześledzić drogę do niebezpiecznego ujścia BEZ przejścia przez tenant filter / permission check / walidację / escaping.
  - **Źródła:** body HTTP, wartości JSONB, dane produktu czytane przez agenta LLM, payloady webhooków, pliki importu (IdoSell IOF XML), parametry publicznych URL-i feedów.
  - **Ujścia:** surowy SQL, DQL/native query, filtry Meili, ltree (kategorie), ścieżki plików, prompty LLM, wychodzące HTTP (SSRF), deserializacja.
- **Fan-out** per powierzchnia ataku × bounded context → agenci-weryfikatorzy próbują **OBALIĆ** każde znalezisko (kill false-positive) → synteza + ranking severity/confidence.
- **Powierzchnie priorytetowe (specyficzne dla PIM):**
  - *Izolacja tenantów poza CRUD* — native query, DQL nietriggerujący filtra, relacje ładowane przed filtrem, klucze cache bez `tenant_id`, indeks Meili nie-scope'owany, handlery Messenger bez kontekstu tenanta, publiczne feedy → weryfikacja, że RLS łapie to, co przecieka przez Doctrine.
  - *Red-team agenta LLM* — prompt injection przez dane produktu, respektowanie RBAC wykonującego usera przez tool calls, obejście approval flow, obejście limitów kosztowych, SSRF, wyciek sekretów do promptu.
  - *Błędy logiczne uprawnień* — IDOR, mass-assignment omijający field-level, per-locale/channel scope (§3.5 PRD RBAC), voter przy abstain.
  - *Pliki* — XXE / zip bomba w imporcie XML, path traversal w storage_path, scope presigned URL MinIO.
  - *Auth* — alg-confusion JWT, refresh/expiry, entropia + reuse tokenu resetu, obejście MFA, nadużycie break-glass.
- **Persony atakującego** jako driver: złośliwy user-tenant, pracownik eskalujący, zewnętrzny przez publiczny feed, zatruwacz danych pod agenta, przejęta integracja.
- **Exploit PoC dla każdego HIGH/CRITICAL** — działający failing test (curl / PHPUnit) → po naprawie zostaje jako test regresji. Bez działającego PoC finding = „suspected", nie potwierdzony.
- **Automat uzupełniająco** — `/security-review` na diffie + ewentualne nowe reguły Semgrep/CodeQL.
- **Deliverable** — `docs/security/deep-audit-2026-XX/` (findings + PoC + status fix). Krytyczne = **blocker go-live**.
- **Residual risk** — brak zewnętrznego pentestu → niezależna walidacja niewykonana; ryzyko rezydualne zapisane i zaakceptowane w `06-sprint-0-findings.md`.

### B4. Izolacja tenantów na żywo — ~3–4h
2 tenanty na żywym stacku; próby cross-tenant read/write przez REST, GraphQL, wyszukiwarkę, publiczne URL-e feedów, eksporty, asset binary — każda powierzchnia osobno.

### B5. Manualny smoke test krytycznych ścieżek — ~6–8h
Checklista per moduł wg **SMOKE TEST RULE** (DevTools Network + Console): auth (login/MFA/reset/zaproszenia), CRUD produktów + warianty, import (w tym duży plik), export, feedy + publiczny URL + SSE monitor, konfigurator API, panel RBAC, break-glass wg runbooka (przećwiczyć raz naprawdę). Wynik: tabela flow → status → dowód (HTTP code / screenshot), wzór z CLOSED-MEANS-CLOSED.

### B6. Agent — live smoke z prawdziwym LLM — ~4–6h · **bloker: klucz API**
Wymaga realnego klucza Anthropic (konto/BYOK — do rozwiązania przed Blokiem B). Test: realne tool calls, approval flow (pending_changes → accept/reject), twarde limity kosztowe (50 calls/h, $20/dzień — symulacja przekroczenia), wyłączenie feature flagiem.

### B7. Chaos dry-run + alerting — ~6–8h
- Celowy pad po kolei: Meilisearch, MinIO, Redis, Mercure → czy API degraduje się łagodnie (RFC 7807, nie 500-owy wodospad), czy alerty Prometheus strzelają.
- **Restart-all** — `docker compose down && up` całego stacka; czy wszystko wstaje w odpowiedniej kolejności (restart policies, healthchecki).
- Test „dysk pełny" na wolumenie WAL/MinIO (retencja logów).
- Znane dev-quirki (MinIO degraded, FrankenPHP cache corruption, auth rate limit) — czy mają odpowiedniki w runbookach prod.

---

## 5. BLOK C — przygotowanie produkcji + soft launch (~25–35h)

### C1. Production readiness na docelowym hostingu — ~10–14h · **bloker: wybór hostingu**
- Deploy `docker-compose.prod.yml`: TLS/DNS, sekrety z vaultu (nie .env), **usunięcie demo credentials** (admin@demo.localhost/changeme) i seedów dev.
- **SMTP deliverability** — prod mailer (nie Mailpit), SPF/DKIM/DMARC, realny test resetu hasła i zaproszeń na Gmail/Outlook (mail w spamie = zablokowany onboarding).
- Healthcheck + zewnętrzny uptime monitoring; backup cron realnie strzela na prodzie.
- **Rollback** — udokumentowany i przećwiczony powrót do poprzedniej wersji, w tym strategia migracji DB (backup przed migracją + restore drill z A2).

### C2. RODO operacyjnie + dokumenty prawne — ~6–8h
- Techniczne RODO: eksport danych użytkownika, usunięcie konta (right to erasure — polityka retencji provenance/audit log?), retencja logów.
- Polityka prywatności + regulamin + DPA (RBAC-P7-005).

### C3. UAT na realnych danych + soft launch — ~8–12h
- **Import realnego katalogu IdoSell (IOF XML)** jako test bojowy — prawdziwe dane operatora zamiast syntetycznego seeda; właściwy UAT dla PIM-a (transform XML→CSV, auto-map, kompletność).
- 5-min screencast demo (rytuał końca sub-fazy).
- Onboarding 1–2 design partnerów + kanał feedbacku, tygodniowy przegląd zgłoszeń.

---

## 6. Blokery do rozwiązania po drodze

1. **Klucz API Anthropic** (B6) — bez niego agent na produkcję za feature flagiem OFF albo czekamy.
2. **Docelowy hosting** (C1) — wybór serwera/providera przed Blokiem C.

## 7. Sumaryczna estymata

~**115–160h** w 3 blokach (w tym B3+ 16–24h). Blok A (40–55h) startuje od razu. B3+ kosztowny tokenowo — świadoma inwestycja w zamian za brak zewnętrznego pentestu.

## 8. Świadome odejścia (residual risk)

- Brak zewnętrznego pentestu przed pierwszym pilotem — kompensowane B3 + B3+ (własny + wiel-agentowy audyt). Zewnętrzny do fazy SaaS.
- Load testing jednorazowy, nie w CI — brak ochrony przed regresją wydajności między releasami po launchu.
- Restore-drill manualny (miesięczny), nie automat w CI.

---

## 9. Tickety GitHub

Label `epik-GOLIVE` · milestone `GOLIVE — testy przedprodukcyjne`. Tabela uzupełniana numerami issues przy tworzeniu:

| ID | Issue | Blok | Tytuł |
|---|---|---|---|
| A1a | [#2119](../../issues/2119) | A | Cold-start handover test — uruchomienie z czystego klonu |
| A1b | [#2120](../../issues/2120) | A | Handover — struktura vs ADR + inwentaryzacja długu |
| A1c | [#2121](../../issues/2121) | A | Handover — audyt licencji + raport końcowy |
| A2 | [#2122](../../issues/2122) | A | Restore drill pgBackRest + PITR |
| A3 | [#2123](../../issues/2123) | A | threat-model.md + security-checklist.md |
| A4 | [#2124](../../issues/2124) | A | Przygotowanie load testingu — k6 + seed 50k |
| A5 | [#2125](../../issues/2125) | A | Fresh-install — migracje/reindex/rebuild od zera |
| A6 | [#2126](../../issues/2126) | A | i18n + macierz przeglądarek |
| B1 | [#2127](../../issues/2127) | B | Pełna regresja automatyczna (baseline) |
| B2 | [#2128](../../issues/2128) | B | Sesja load testowa 50k + endurance |
| B3a | [#2129](../../issues/2129) | B | Manual red-team — 15-punktowa checklista |
| B3b | [#2130](../../issues/2130) | B | Regresja 5 CRITICAL z audytu 2026-06 |
| B3+1 | [#2131](../../issues/2131) | B | Deep audit — setup workflow + model taint |
| B3+2 | [#2132](../../issues/2132) | B | Deep audit — sweep taint + weryfikacja |
| B3+3 | [#2133](../../issues/2133) | B | Deep audit — exploit PoC + raport |
| B4 | [#2134](../../issues/2134) | B | Izolacja tenantów na żywo — wszystkie powierzchnie |
| B5 | [#2135](../../issues/2135) | B | Manualny smoke test krytycznych ścieżek |
| B6 | [#2136](../../issues/2136) | B | Agent — live LLM smoke (**bloker: klucz API**) |
| B7 | [#2137](../../issues/2137) | B | Chaos dry-run + alerting |
| C1a | [#2138](../../issues/2138) | C | Deploy prod + TLS + sekrety + usunięcie demo (**bloker: hosting**) |
| C1b | [#2139](../../issues/2139) | C | SMTP deliverability + monitoring + rollback |
| C2 | [#2140](../../issues/2140) | C | RODO operacyjnie + dokumenty prawne |
| C3 | [#2141](../../issues/2141) | C | UAT IdoSell (IOF XML) + soft launch |

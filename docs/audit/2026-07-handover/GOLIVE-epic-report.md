# Epik GOLIVE — raport końcowy (readiness przed produkcją)

> Skonsolidowany raport całego epiku testów przedprodukcyjnych.
> Plan: [`Project Plan/15-plan-testow-przedprodukcyjnych.md`](../../../Project%20Plan/15-plan-testow-przedprodukcyjnych.md) (3 bloki, 23 tickety planu + 16 ticketów findingów).
> Data raportu: 2026-07-04. Środowisko wykonania: **lokalne** (`pim.localhost`, Docker Desktop/macOS) — dyrektywa operatora „wszystko lokalnie, bez hostingu".
> Stan issue: **30 zamkniętych / 9 otwartych** (label `epik-GOLIVE`).

## 1. Werdykt go-live

**GOTOWY WARUNKOWO.** Wszystko, co dało się zweryfikować lokalnie na żywym stacku, jest zielone: architektura, bezpieczeństwo (red-team + deep audit + izolacja tenantów), backup/restore, wydajność na 50k SKU, agent AI end-to-end na realnym LLM, onboarding dnia 1. Do faktycznego wdrożenia produkcyjnego pozostają **wyłącznie zadania wymagające realnego hostingu** (deploy/TLS/SMTP-deliverability/soft-launch) oraz **decyzji operatora/prawnika** (strategia RODO) i **zaplanowania 3 findingów wydajnościowych** (nie-blokery).

Bezwzględny gate wydajności `p95 < 300 ms` jest potwierdzalny dopiero na sprzęcie prod-podobnym — lokalne pomiary to proxy (patrz §3.2).

## 2. Blok A — handover readiness (KOMPLETNY, 8/8)

Raporty: [`docs/audit/2026-07-handover/README.md`](README.md) + 6 podraportów (cold-start, structure-and-debt, restore-drill, fresh-install, i18n, critical-paths-smoke, chaos-dryrun) + [`docs/security/threat-model.md`](../../security/threat-model.md).

- **Cold-start / fresh-install (#2119, #2125):** świeży klon staje od zera. Wykryto i naprawiono **4 blokery dnia pierwszego** (klasa „works on my machine" po splicie ról W1-1, niewidoczne w CI): #2176 (JWT keypair → login 500), #2177 (`messenger_messages` poza migracjami → worker crashloop), #2181 (`AWS_ASSETS_*` nie propaguje `MINIO_ROOT_*` → upload 500), #2178 (komendy DB pod `pim_app`).
- **Restore drill (#2122):** pgBackRest + PITR. **2 krytyczne bugi narzędzia DR wykryte i naprawione** (#2196) — bez drillu wyszłyby dopiero w realnym DR. RTO ≈ 19 s, PITR precyzyjny.
- **Struktura + dług (#2120), licencje (#2121):** zero GPL/AGPL/SSPL (144 MIT + 1 LGPL htmlpurifier; JS permisywne).
- **i18n + przeglądarki (#2126):** parytet kluczy EN uzupełniony (#2188), literały PL → `t()` (#2189).

## 3. Blok B — security + load + agent (KOMPLETNY lokalnie)

### 3.1 Bezpieczeństwo (wszystkie zamknięte z proofem)
- **#2130** regresja 5 CRITICAL audytu 2026-06 → **5/5 PASS**.
- **#2129** red-team 15-pkt → **znaleziony + naprawiony realny FINDING HIGH** (bulk-actions privilege escalation: `delete` nie re-asertował `products.delete` → Marketing kasował produkty). Fix per-action permission map.
- **#2131-2133** deep audit (3-agentowy fan-out taint + adwersaryjna weryfikacja empiryczna) → **znaleziony + naprawiony webhook SSRF** (`WebhookDeliveryClient` na plain HttpClient → tenant admin mógł pivotować na 169.254.169.254/redis/minio). 2/5 „CONFIRMED" agentów **obalone** weryfikacją empiryczną (Meili addslashes false-positive, SavedView accepted MVP).
- **#2134** izolacja tenantów, 7 powierzchni → **7/7 PASS** (zero cross-tenant).
- **#2135** smoke krytycznych ścieżek → **11/11 PASS**.
- **#2137** chaos dry-run → degradacja udokumentowana, 2 luki → #2221 (naprawione), #2222 (naprawione).

### 3.2 Load 50k SKU + endurance (#2128, zamknięty)
Raport: [`docs/perf/golive-load-session-2026-07.md`](../../perf/golive-load-session-2026-07.md).
- Katalog: 50 000 produktów, 1,68 mln wartości; seeder płaski (peak 71 MiB); reindex Meili 50 118 docs.
- **p95 @5 VU** (realistyczny admin, 0% błędów): bulk-preview 71 ms ✅, search 92 ms ✅, export-preflight 95 ms ✅, **`GET /api/products` 481 ms ❌** (algorytmiczne → #2234).
- **Endurance** 15 min / 17,7k iteracji: pamięć workera **płaska 40–90 MiB, brak wycieku** (sufit 256 MiB).
- **Zastrzeżenie:** lokalne p95 = proxy; bezwzględny gate <300 ms potwierdzić na prod-podobnym HW.

### 3.3 Agent AI live (#2136, zamknięty z pełnym proofem)
Live smoke na realnym LLM (klucz BYOK operatora, Opus). **Wszystkie 4 AC udowodnione na żywo:** realne tool calls (`aggregate_count`), approval accept (approve→commit, `provenance=agent`), reject guard (409), limit kosztowy (**HTTP 429** po przekroczeniu, blokada przed wywołaniem LLM). **Test wykrył 2 krytyczne bugi niewidoczne bez realnego LLM:** #2239 (`processed_messages` permission-denied → dead-letter każdego runa), #2240 (`sku` niefilterowalny w Meili → agent odmawiał write'ów). Oba naprawione.

## 4. Blok C — produkcja + launch (hosting-gated, NIE wykonane lokalnie)

Wymaga realnego serwera / poczty / partnerów — poza zakresem „wszystko lokalnie". Części wykonalne lokalnie zrobione (patrz #2141, #2139 poniżej).

## 5. Findingi epiku — rejestr

| # | Finding | Klasa | Status |
|---|---|---|---|
| #2176/#2177/#2178/#2181 | 4 blokery dnia 1 (cold-start) | infra | ✅ naprawione |
| #2196 | 2 bugi narzędzia DR (PITR) | infra/DR | ✅ naprawione |
| #2129 | bulk-actions privilege escalation | security HIGH | ✅ naprawione |
| #2131-2133 | webhook SSRF | security HIGH | ✅ naprawione |
| #2221 | MinIO outage → 500 HTML (nie 503) | resiliency | ✅ naprawione (+ timeout S3) |
| #2222 | brak alertów na awarię zależności | monitoring | ✅ naprawione (blackbox) |
| #2225 | escaper Meili (consistency) | hardening | ✅ naprawione |
| #2239 | agent processed_messages permission | infra/privilege-split | ✅ naprawione |
| #2240 | agent search sku niefilterowalny | agent | ✅ naprawione |
| #2231 | reconcile OOM na 50k | perf | ⏳ OPEN |
| #2233 | stale S3 connection po recovery MinIO | resiliency (worker-mode) | ⏳ OPEN |
| #2234 | `GET /api/products` p95 481 ms | perf | ⏳ OPEN |

## 6. Otwarte tickety (9) — co zostało i kryteria zamknięcia

### A. Findingi wydajnościowe/resiliency z Bloku B — praca do zaplanowania (nie-blokery go-live)
- **#2234** — `GET /api/products` p95 481 ms @5 VU na 50k. Najcięższy read-path admina/integracji. Do zbadania: N+1 na atrybutach / ciężar serializacji JSON-LD. **Priorytet przed twardym gate wydajności na prod.**
- **#2231** — `pim:catalog:detect-attributes-drift --reconcile` OOM na 50k (`toIterable` buforuje pełny result-set + per-obiekt `findBy`). Workaround: `-d memory_limit=1024M`. Fix: keyset pagination + batch-load. Komenda utrzymaniowa, nie ścieżka requestowa.
- **#2233** — worker FrankenPHP trzyma martwe połączenie S3 po powrocie MinIO → 503 aż do restartu. Klasa worker-mode connection resiliency. Kandydat: retry z fresh S3Client / recykl workera po serii błędów storage.

### B. Świadomie odłożone / zaakceptowane MVP (nie-blokery)
- **#2224** — SavedView cross-user w obrębie tenanta. Zaakceptowane zachowanie MVP (per-user scoping = Faza 1). Zostawione świadomie.
- **#2192** — brak CI guardu max-lines dla PHP (`ImportRunHandler` 1913 linii). Guard dodany warunkowo; pełne włączenie czeka na refactor `ImportRunHandler` — świadome odejście.

### C. Blok C — hosting-gated (wymaga realnego serwera)
- **#2138** *(blocker)* — deploy prod + TLS/DNS + sekrety z vaultu + usunięcie demo credentials + backup cron na prodzie. **Bloker: wybór hostingu.** Compose prod (`docker-compose.prod.yml`) gotowy i zwalidowany.
- **#2139** — SMTP deliverability (SPF/DKIM/DMARC, inbox na Gmail/Outlook) + monitoring cross-domain + rollback drill. **App-side mail zweryfikowany lokalnie** (Mailpit: invitation/reset docierają); deliverability = realny mailer/hosting.
- **#2141** *(partial)* — UAT IdoSell **wykonany lokalnie**: 94 produkty, 100% kompletności, 91 obrazów, rollback zweryfikowany (raport w komentarzu issue). Pozostaje soft-launch: screencast demo + onboarding 1–2 partnerów + kanał feedbacku (human/hosting).

### D. Decyzja operatora / prawnik
- **#2140** *(partial)* — RODO. Audyt as-is + rekomendacja podziału w komentarzu issue. **Eksport danych** = bezpieczny slice do zbudowania (read-only). **Erasure** = decyzja anonymize vs hard-delete + migracje FK. **Dokumenty prawne** (privacy/regulamin/DPA) = wymagają prawnika, nie generujemy autonomicznie.

## 7. Rekomendowana ścieżka do go-live

1. **Decyzja hostingu** → odblokowuje #2138 → #2139 (deliverability) → #2141 soft-launch.
2. **Decyzja RODO** (#2140) — strategia erasure; slice eksportu można zbudować równolegle, lokalnie.
3. **Fix #2234** (products-list) przed twardym gate wydajności na prod-podobnym HW; #2231/#2233 jako hardening Fazy 1.
4. Potwierdzenie bezwzględnego gate `p95 < 300 ms` na prod-podobnym sprzęcie (re-run `scripts/load/`).
5. Residual risk zaakceptowany: brak zewnętrznego pentestu (odłożony do fazy SaaS — kompensowany deep auditem #2131-2133).

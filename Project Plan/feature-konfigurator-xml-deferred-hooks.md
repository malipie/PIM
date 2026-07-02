# Konfigurator XML — deferred hooki ([DEF] backlog)

> **Źródło:** `feature-konfigurator-xml-plan.md` §7 („Hooki — świadomie odłożone") + §4 (reuse-mapa) + §11 (open questions). Utworzony w XMLF-P6-04 (#1939).
> **Konwencja [DEF]:** brak GitHub Issue na starcie — issue z labelem `deferred` powstaje dopiero, gdy pojawi się realny pain (żądanie pilota / blocker integracji). Ten plik trzyma kontekst decyzji, żeby przyszła implementacja nie zaczynała od zera i nie ominęła istniejących klocków.
> **Estymacje:** zgrubne (rząd wielkości), do rewizji przy podjęciu.

## Indeks

| # | Hook | Pain-trigger | Est. |
|---|------|--------------|------|
| DEF-XMLF-01 | Import XML (dostawca → PIM) + guard XXE | pilot z hurtownią wystawiającą tylko XML | 20-30h |
| DEF-XMLF-02 | Push feedu do FTP/SFTP/S3 klienta | odbiorca bez crawlera (starsze ERP, marketplace push-only) | 12-18h |
| DEF-XMLF-03 | Silnik dowolnych transformacji | mapowanie wymaga logiki spoza zamkniętej listy | 25-40h |
| DEF-XMLF-04 | Allegro jako szablon predef | ≥2 tenantów buduje ten sam custom-descriptor Allegro | 8-12h |
| DEF-XMLF-05 | Feed dla ObjectType ≠ product | żądanie feedu kategorii/zasobów | 10-16h |
| DEF-XMLF-06 | AI-assisted mapping / Cmd+K | operatorzy grzęzną w ręcznym mapowaniu | 16-24h |
| DEF-XMLF-07 | Adaptive scheduling + migawka + jitter | regeneracje 50k+ SKU co godzinę bez zmian katalogu | 14-20h |
| DEF-XMLF-08 | Webhook `feed.regenerated` | konsument chce powiadomienia zamiast pollingu | 6-10h |

---

## DEF-XMLF-01 — Import XML (dostawca → PIM) z twardym guardem XXE

**Business context (kiedy pain):** pilot-tenant dostaje cenniki/stany od hurtowni wyłącznie jako XML (IOF IdoSell, custom B2B). Dziś jedyna droga to transformacja XML→CSV poza PIM-em (udokumentowana przy imporcie IdoSell — stratna na strukturach EAV).

**Co reużyć:**
- Silnik zapisu IMP2: `apps/api/src/Import/` — `ValueWriteCore`/`BatchValueWriter` + `ObjectResolver` (ten sam write-path co CSV i sync APIC, `Provenance::Import`).
- Wzorzec adaptera formatu: istniejące readery IMP2 (CSV/XLSX) — import XML to trzeci reader, NIE osobny silnik.
- Odwrócony descriptor feedu (`FeedDescriptor` VO) jako mapa „element → atrybut" — kształt JSONB już zwalidowany guardem P1-04.

**Twardy wymóg bezpieczeństwa (nienegocjowalny):** parser XML MUSI działać z guardem XXE — `libxml` bez zewnętrznych encji (`LIBXML_NONET`, brak resolvera external entities / `libxml_set_external_entity_loader(null)`), limit głębokości i rozmiaru dokumentu. Ścieżka feedów **generuje** XML i XXE jej nie dotyczy (plan §6.9 „XXE — N/A"); z chwilą PARSOWANIA cudzego XML-a ten guard staje się pierwszą linią. Suite adversarialna analogiczna do `XmlInjectionSafetyTest` (P6-01), ale w drugą stronę (billion laughs, external DTD, parameter entities).

**Dlaczego odłożone:** decyzja operatora §2.1 planu — MVP to feedy wychodzące + XML ad-hoc; import XML wymaga osobnego bezpieczeństwa (XXE) i osobnego UI mapowania źródła.

---

## DEF-XMLF-02 — Push feedu do FTP/SFTP/S3 klienta

**Business context:** część odbiorców (starsze ERP, niektóre marketplace'y, agencje) nie pulluje URL-a — oczekuje pliku wypchniętego na ich serwer po każdej regeneracji.

**Co reużyć:**
- `apps/api/src/Import/.../SsrfGuard` (#1475) — walidacja hosta docelowego (blokada RFC1918/loopback/link-local) zanim cokolwiek się połączy; push na adres wewnętrzny = ta sama klasa ataku co SSRF konektora.
- `apps/api/src/Shared/Infrastructure/Crypto/AesGcmEncryptionService.php` — szyfrowanie credentiali FTP/SFTP w spoczynku (ten sam wzorzec co hasło HTTP Basic feedu w `FeedDeliveryConfig` i credentiale połączeń APIC).
- Flysystem (już w composerze dla MinIO) — adaptery FTP/SFTP/S3 to konfiguracja, nie nowy kod transportu.
- `FeedRunHandler` — push jako opcjonalny krok „po store" istniejącego pipeline'u (query→serialize→validate→store→**push**), z retry/backoff wzorem webhooków APIC.

**Dlaczego odłożone:** decyzja §2.4 — pull cache-and-serve wystarcza crawlerom Google/Ceneo/Meta (100% odbiorców MVP); push dokłada zarządzanie cudzymi credentialami i retry-politykę.

---

## DEF-XMLF-03 — Silnik dowolnych transformacji

**Business context:** zamknięta lista transformacji (§6.4: static, default/fallback, format price/date/number, concat/template, enum-map, strip-html/CDATA) pokrywa Google/Ceneo/Meta. Pain pojawia się przy feedach custom B2B: „jeśli stock<5 → availability=preorder, chyba że kategoria=X", lookup z tabeli kursów itp.

**Co reużyć:**
- Kontrakt transformacji feedu (`FeedTransform`, P2-03) — silnik wyrażeń wchodzi jako KOLEJNY wariant transformacji, nie zamiennik listy.
- Wzorzec DSL filtrów (`filter-dsl` FE + `FilterDslResolver` BE) — walidowany, serializowalny JSONB zamiast wolnego kodu.
- Sandbox: symfony/expression-language (już w vendorach przez Symfony) z whitelistą funkcji — NIE eval, NIE Twig.

**Dlaczego odłożone:** decyzja §2.3 — świadome odejście od „1:1 bez transformacji" (APIC) ograniczone do zamkniętej listy; dowolne wyrażenia to powierzchnia błędów i bezpieczeństwa (injection przez konfigurację), która wymaga własnego ticketu SEC.

---

## DEF-XMLF-04 — Allegro jako szablon predef

**Business context:** Allegro jest dziś osiągalne custom-descriptorem (edytor P5-06 buduje strukturę od zera). Predef ma sens, gdy ≥2 tenantów powiela ten sam kształt — wtedy szablon + default mappings + enum-mapy stają się produktem.

**Co reużyć:**
- `FeedTemplateCatalog` (`Export/Feed/Domain/Template/`) — czwarty wpis obok Google/Ceneo/Meta; seeding i `create-from-template` (P2-06) działają bez zmian.
- `apps/api/src/Channel/Domain/Entity/ChannelCategoryNode.php` — `external_code` na węźle drzewa kanału + `ChannelCategoryExternalCodeResolverInterface` (P3-03) — identyczny mechanizm kategorii marketplace co Ceneo `<cat>`; drzewo kategorii Allegro wchodzi jako `ChannelCategoryNode` z kodami Allegro.
- Warianty flat + `item_group_id` (P3-03) — Allegro oferty wariantowe mapują się na ten sam plan emit-id.

**Dlaczego odłożone:** §11 open question — bez potwierdzonego pilota sprzedającego na Allegro predef byłby spekulacją co do dokładnego kształtu (oferty vs produkty, wymagane pola zmieniają się per kategoria).

---

## DEF-XMLF-05 — Feed dla innych ObjectType niż product

**Business context:** feed kategorii (nawigacja partnera), feed zasobów (DAM→CDN partnera). Model danych już to wspiera — `FeedProfile.object_type_id` jest generyczne (ADR-009: ObjectType first-class).

**Co reużyć:**
- `FeedProfile` bez zmian (trzyma `object_type_id` UUID, nie enum).
- `ExportBuilder` — czyta dowolny `object_type_id`; ścieżka wariantów/kategorii jest parametryzowana per kind.
- UI: `useProductObjectTypeId` (kreator P5-02) do zamiany na select typu — jedyna twarda blokada jest we froncie.

**Dlaczego odłożone:** §7 — MVP celuje w syndykację produktową; feed nie-produktowy nie ma jeszcze odbiorcy, a odblokowanie wymaga przemyślenia szablonów (predefy są produktowe).

---

## DEF-XMLF-06 — AI-assisted mapping / Cmd+K

**Business context:** operator z 200+ atrybutami mapuje sloty ręcznie; „zbuduj feed Google z atrybutów SEO" / „zasugeruj mapowania po nazwach" skraca onboarding feedu z godzin do minut.

**Co reużyć:**
- Wzorzec `PRD-PIM-exports.md` §10.2 (AI-assisted eksporty) — ten sam kształt: propozycja → diff → akcept człowieka (spójne z approval flow agenta, CLAUDE.md „Approval flow dla agenta").
- Katalog atrybutów mappera (P3-01, `GET /api/feeds/{id}/mapping` → `attributes[]` z labelami JSONB) — gotowy kontekst wejściowy dla LLM.
- Agent layer (epik 0.7, Faza 2) — tool `suggest_feed_mapping` wchodzi do rejestru tooli agenta zamiast osobnego endpointu.

**Dlaczego odłożone:** zależy od agent layer (Faza 2); wcześniejsza implementacja stworzyłaby drugi, konkurencyjny mechanizm LLM poza budżetami/limitami agenta (§8.5 architektury).

---

## DEF-XMLF-07 — Adaptive scheduling + migawka transakcyjna + jitter między tenantami

**Business context:** cron regeneruje feed niezależnie od tego, czy katalog się zmienił — przy 50k SKU × wielu feedach × wielu tenantach to puste koszty CPU/S3. Adaptive = regeneruj tylko gdy `EntityChanged` dotknął scope feedu od ostatniego runu.

**Co reużyć:**
- `apps/api/src/Import/Application/Service/CronExpressionParser.php` + `ScheduleDispatcher` — feed scheduling (P4-01) już na nich stoi. **Nota z §4 planu (explicit follow-up):** `CronExpressionParser`/`ScheduleDispatcher` NIE mają wbudowanego jittera — gdy wiele tenantów ma cron `0 3 * * *`, wszystkie regeneracje strzelają jednocześnie. **Jitter między tenantami (deterministyczny offset per tenant, np. hash(tenant_id) % 300s) jest udokumentowanym follow-upem tego hooka** — wzorzec: jitter schedulera APIC (P3-09/#1787).
- Lifecycle event `EntityChanged` (hook agentowy, CLAUDE.md „Hooks pod Fazę 2") — źródło sygnału „katalog się zmienił".
- `FeedPullStat.last_pulled_at` (P3-06) — drugi sygnał: nie regeneruj feedu, którego nikt nie pulluje.
- Migawka transakcyjna: `REPEATABLE READ` snapshot na czas generacji (dziś generator czyta live — przy długiej generacji item może „przeskoczyć" wersję między chunkami; akceptowalne w MVP, plan §11).

**Dlaczego odłożone:** optymalizacja kosztowa bez painu przy skali MVP (5 kanałów / 50k SKU); wymaga najpierw działającego sygnału zmian (agent hooks, Faza 2).

---

## DEF-XMLF-08 — Webhook `feed.regenerated`

**Business context:** dziś konsument feedu polluje URL (ETag/304 czyni to tanim), a UI dostaje live-progress przez Mercure. Zewnętrzny system (np. cache-warmer partnera) może chcieć powiadomienia push po regeneracji.

**Co reużyć:**
- Infrastruktura webhooków producenta APIC (P4-05/#1868, `apps/api/src/ApiConfigurator/`) — delivery + retry z backoffem + delivery-history UI; `feed.regenerated` to nowy typ eventu w ISTNIEJĄCYM systemie, nie nowy mechanizm.
- `FeedRunHandler` — emisja eventu po `markDone()` (dokładnie tam, gdzie dziś publikacja Mercure `FeedProgressPublisher`).

**Dlaczego odłożone:** §7 — pull+Mercure pokrywa 100% odbiorców MVP; webhook bez odbiorcy to martwy kod do utrzymania.

# 0023. Konfigurator XML — umiejscowienie, model serializacji i autoryzacja feedu

- **Status:** accepted
- **Date:** 2026-07-01
- **Deciders:** Marcin (operator), architekt rozwiązań (agent)

> Finalizowany w tickecie XMLF-P0-01 (epik XMLF). Streszczenie w `01-architektura-pim.md` §13. Wszystkie 7 decyzji rozstrzygnięte; otwarte kwestie przesunięte do hooków §7 planu.

## Context and Problem Statement

PIM potrzebuje feedów produktowych XML (Google Shopping / Ceneo / Meta / custom) oraz XML jako formatu eksportu ad-hoc. Silnik Export istnieje (`ExportBuilder` → `Generator<array<string,string>>`, writery CSV/XLSX), ale jest **flat** — nie ma serializacji zagnieżdżonego XML ani modelu feedu (URL, harmonogram, cache, token). Sąsiednie feature'y świadomie wykluczyły XML: API Configurator jest REST/JSON-only (`feature-api-configurator-uniwersalny-plan.md` §7), a PRD eksportów przesunął XML do „Fazy 1" (§6.3). Trzeba rozstrzygnąć: gdzie żyje silnik, jak serializować bez brudzenia CSV/XLSX, jak dostarczać feed pod crawler bez DoS-owania workera, i jak autoryzować publiczny URL.

## Decision Drivers

- **Reuse zamiast duplikacji** — feed nie może mieć własnego selektora produktów ani odczytu wartości (jak „outbound reużywa Export" w APIC §6.8).
- **Memory-safe pod FrankenPHP worker mode** — 50k SKU nie mogą budować DOM ani load-all-into-memory (CLAUDE.md §3.10).
- **Anti-DoS** — crawler pulluje feed cyklicznie; generowanie na każde uderzenie zabija workera.
- **Czystość kontraktów** — pozycyjny `RowWriter` (CSV/XLSX) nie może zostać zabrudzony logiką nazw/struktury XML.
- **Bezpieczeństwo publicznego endpointu** — nowa powierzchnia ataku (token w URL, izolacja tenantów, escaping XML).
- **Zgodność z Deptrac** — cross-BC tylko przez `*\Contracts\*`.

## Considered Options

1. **Osobny bounded context `Feed`** — pełna izolacja, ale ~6 klas domenowych + duplikacja selekcji/odczytu (odrzucony jako overhead, analog do odrzuconego Option C w ADR-0018).
2. **Silnik XML w `Export` + feedy w pod-obszarze `Export/Feed`** — reuse `ExportBuilder`, jeden silnik serializacji dla ad-hoc i feedów.
3. **Rozszerzenie pozycyjnego `RowWriter` o XML** — odrzucony: XML potrzebuje nazw elementów i struktury; wtłoczenie tego w flat row-of-strings zabrudziłoby CSV/XLSX.

## Decision Outcome

Chosen option: **Option 2 (silnik XML w Export, feedy w `Export/Feed`)**, bo feed to semantycznie „projekcja katalogu na wyjście" — ta sama domena co eksport — a reuse `ExportBuilder` trzyma złożoność w ryzach (poprawki w Export propagują się do feedów).

Rozstrzygnięcia (7 decyzji z planu §12):

1. **Silnik XML w `src/Export/`** (nie nowy BC). `ExportFormat::Xml` + `XmlWriterCore` obok istniejących writerów; feedy w pod-obszarze `src/Export/Feed/`.
2. **Nowy kontrakt `ItemWriter`** (asocjacyjny, klucz→wartość) zamiast rozszerzania pozycyjnego `RowWriter`. Oba konsumują ten sam `Generator<array<string,string>>` z `ExportBuilder`. `GenericXmlWriter` (ad-hoc) i `XmlFeedWriter` (feed, descriptor-driven) implementują `ItemWriter`.
3. **Reuse `ExportBuilder` jako źródła itemów** — feed nie duplikuje selekcji/odczytu. Cross-BC do Channel/Catalog wyłącznie przez `*\Contracts\*`; rdzeń Export przez seam `Export/Contracts` (Deptrac: `Export_Feed_Internals` → `Export_Contracts`).
4. **Feed = deklaratywny descriptor JSONB** (Airbyte-like manifest) + **zamknięta lista transformacji** (default/price/number/date/enum_map/template/strip_html/truncate). Predef (Google/Ceneo/Meta) jako seedy `is_built_in` w kodzie, klonowane do `FeedProfile.descriptor`. `FeedDescriptor` VO = jedyne źródło prawdy kształtu i reguł walidacji.
5. **Delivery pull cache-and-serve** — regeneracja (cron/manual/first_publish) → plik w MinIO → publiczny URL serwuje **cache** (nigdy nie generuje na żądanie); `ETag`/`Last-Modified`/`304`/gzip. Push do FTP/S3 = hook.
6. **Autoryzacja token-in-URL** — nieodgadywalny token (≥128-bit) hashowany wzorcem `ApiKey` (Argon2id, weryfikacja constant-time), pokazany raz + rotate/revoke. **Token ≠ klucz tenanta** (osobny lifecycle, read-only, per feed). Opcjonalny HTTP Basic (hasło szyfrowane `AesGcmEncryptionService`). Publiczny endpoint przez `PUBLIC_ACCESS` (wzorzec audytu W0-5), tenant rozwiązywany z tokenu → RLS/TenantFilter; cross-tenant miss = 404.
7. **Scope przez publication profile** (ADR-0018) — feed reużywa `?publication=<channel>` allow-listę atrybutów + `column_aliases` jako zalążek mapowania.

### Consequences

- **Positive:** czysty podział (Export = jak projektujemy na wyjście); reuse silnika Export → poprawki propagują się do feedów; jeden silnik serializacji dla ad-hoc i feedów; `RowWriter`/CSV/XLSX pozostają czyste.
- **Negative:** dwa mechanizmy sekretów współistnieją (hash token / szyfr Basic); publiczny endpoint to nowa powierzchnia ataku (obsłużona wzorcem `PUBLIC_ACCESS` + escaping + rate-limit).
- **Follow-ups (hooki §7):** import XML + guard XXE; push FTP/SFTP/S3; silnik dowolnych transformacji; Allegro predef; feed dla innych ObjectType; AI-assisted mapping; adaptive scheduling + pełna migawka transakcyjna; jitter między tenantami w cron-workerze.

## Alternatives Considered

- **Osobny BC `Feed`** — odrzucony: overhead 6 klas + duplikacja selekcji/odczytu przy zerowej korzyści izolacyjnej (feed i tak czyta Channel/Catalog przez te same Contracts).
- **Rozszerzenie `RowWriter` o XML** — odrzucony: pozycyjny kontrakt celowo flat; XML potrzebuje nazw i struktury.
- **Generowanie feedu na żądanie (bez cache)** — odrzucony: DoS na workera przy pull crawlera 50k SKU.
- **Autoryzacja kluczem tenanta** — odrzucony: klucz tenanta ma szerszy zakres; token feedu musi być read-only, per-feed, rewokowalny bez wpływu na inne integracje.

## Links

- Plan: `Project Plan/feature-konfigurator-xml-plan.md` §6 (architektura), §7 (MVP vs hooki), §12 (mini-ADR)
- Backlog: `Project Plan/feature-konfigurator-xml-tickets.md` (epik XMLF, 38 ticketów)
- Related ADRs: ADR-0018 (publication profile), ADR-0015 (bare UUID cross-BC), ADR-0020 (OpenAPI custom route), ADR-0011 (ORM XML mapping w Infrastructure), ADR-0009 (ObjectType), ADR-0022 (granica konsument/producent)
- Tickets: XMLF-P0-01 (finalizacja tego ADR)

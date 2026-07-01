# Backlog — Konfigurator XML: feedy produktowe (epik XMLF)

> **Status:** backlog do realizacji. Utworzony 2026-07-01.
> **Źródło architektury:** [`feature-konfigurator-xml-plan.md`](feature-konfigurator-xml-plan.md) (§4 as-is, §6 architektura, §7 MVP vs hooki, §8 fazy, §9 brief UI, §12 mini-ADR).
> **Decyzja architektoniczna:** ADR-0023 (`docs/adr/0023-konfigurator-xml-placement.md`) — finalizowany w XMLF-P0-01.
> **Designy UI:** `Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/Feedy.html` + `feedy/*.jsx` (hub, kreator 5-krokowy, mapper, preview + raport zdrowia, monitor + detal + drill-down).
> **Epik label:** `epik-XMLF`. Prefix ID: `XMLF`, format `XMLF-P{faza}-{nn}`.
> **Milestone'y:** M0 Fundament + ad-hoc XML · M1 Model feedu + CRUD · M2 Descriptor/szablony/silnik · M3 Mapowanie/scope/delivery · M4 Harmonogram/monitor/preview · M5 UI · M6 Hardening + launch.

Ten plik to single source of truth backlogu. GitHub Issues są lustrem (skondensowane body + link tutaj). Tracking faktyczny w Issues + milestone'ach.

38 ticketów, ~340–470h. Konfigurator XML to warstwa „szablon + mapowanie + transformacje + delivery" NAD istniejącym silnikiem Export (reuse `ExportBuilder`); feed nie duplikuje selekcji/odczytu wartości.

---

## Mapa GitHub Issues

_Uzupełniana po `gh issue create` — odwrotny indeks ID → numer._

38 issues, milestone'y #34–#40 (M0–M6). Body linkuje do sekcji backlogu; poniżej indeks ID → numer.

| ID | Issue | ID | Issue | ID | Issue | ID | Issue |
|---|---|---|---|---|---|---|---|
| XMLF-P0-01 | #1902 | XMLF-P0-02 | #1903 | XMLF-P0-03 | #1904 | XMLF-P0-04 | #1905 |
| XMLF-P0-05 | #1906 | XMLF-P0-06 | #1907 | XMLF-P1-01 | #1908 | XMLF-P1-02 | #1909 |
| XMLF-P1-03 | #1910 | XMLF-P1-04 | #1911 | XMLF-P2-01 | #1912 | XMLF-P2-02 | #1913 |
| XMLF-P2-03 | #1914 | XMLF-P2-04 | #1915 | XMLF-P2-05 | #1916 | XMLF-P2-06 | #1917 |
| XMLF-P2-07 | #1918 | XMLF-P3-01 | #1919 | XMLF-P3-02 | #1920 | XMLF-P3-03 | #1921 |
| XMLF-P3-04 | #1922 | XMLF-P3-05 | #1923 | XMLF-P3-06 | #1924 | XMLF-P4-01 | #1925 |
| XMLF-P4-02 | #1926 | XMLF-P4-03 | #1927 | XMLF-P4-04 | #1928 | XMLF-P5-01 | #1929 |
| XMLF-P5-02 | #1930 | XMLF-P5-03 | #1931 | XMLF-P5-04 | #1932 | XMLF-P5-05 | #1933 |
| XMLF-P5-06 | #1934 | XMLF-P5-07 | #1935 | XMLF-P6-01 | #1936 | XMLF-P6-02 | #1937 |
| XMLF-P6-03 | #1938 | XMLF-P6-04 | #1939 |  |  |  |  |

---

## Konwencje

- **Cls:** `BE` · `FE` · `SEC` (security-first, failing-test-first) · `DOCS`.
- **[PM]:** ticket wymaga Plan Mode — cross-context lub decyzja architektoniczna.
- **[SEC]:** ticket bezpieczeństwa, failing-test-first.
- **[DEF]:** hook §7, świadomie odłożony; w backlogu, bez issue na starcie.
- **Bounded context:** silnik XML + ad-hoc → `apps/api/src/Export/`; feedy → pod-obszar `apps/api/src/Export/Feed/`. Cross-BC do Channel/Catalog tylko przez `*\Contracts\*` (Deptrac); rdzeń Export przez `Export/Contracts`.

### Standard DoD (każdy ticket, o ile nie zaznaczono inaczej)

- [ ] Acceptance criteria spełnione.
- [ ] **PHPStan max**: 0 errors (BE).
- [ ] **Deptrac**: 0 violations (cross-BC tylko przez Contracts).
- [ ] **PHP-CS-Fixer**: czysto (BE).
- [ ] **Biome strict** + **tsc --noEmit**: 0 errors (FE).
- [ ] **PHPUnit** ≥80% nowej logiki domenowej; **ApiTestCase** dla każdego endpointu (401 + 403 + 404 + walidacja + happy path).
- [ ] **Playwright E2E**: happy path + ≥1 edge case (FE z widoczną zmianą).
- [ ] **axe-core**: 0 violations serious/critical (FE).
- [ ] **Multi-tenancy**: cross-tenant read = 0 wyników (encje TenantScoped).
- [ ] **composer audit + pnpm audit**: 0 high/critical.
- [ ] **OpenAPI snapshot** `docs/api-spec/v0.json` zaktualizowany (nowe endpointy).
- [ ] **Feed smoke:** URL feedu zwraca 200 + well-formed XML (`xmllint --noout`); manual smoke 5 min na `pim.localhost`; PR opis nie używa „działa" bez smoke testu.
- [ ] CI green; PR merged do main.

### Reuse (potwierdzone sygnatury as-is, 2026-07-01)

| Klocek | Ścieżka | Rola |
|---|---|---|
| `ExportBuilder` (Generator `array<string,string>`, caller owns chunk+`clear()` co 200) | `apps/api/src/Export/Application/Builder/ExportBuilder.php` | Rdzeń produkcji itemów feedu i ad-hoc |
| `RowWriter` (pozycyjny) / `Csv`/`XlsxStreamWriter` | `apps/api/src/Export/Infrastructure/Writer/` | Wzorzec streamingu → nowy `ItemWriter` (asocjacyjny) |
| `ExportFormat`, Sync/Async runnery (`SYNC_THRESHOLD=100`) | `apps/api/src/Export/{Domain/Enum,Application}` | `case Xml` + wpięcie GenericXmlWriter |
| `ChannelPublicationResolverInterface` + `column_aliases` (ADR-0018) | `apps/api/src/Channel/Contracts/`, `ChannelPublicationProfile` | Scope + zalążek mapowania |
| `ChannelCategoryNode.externalCode` + `ChannelCategoryNodeMapping` | `apps/api/src/Channel/Domain/Entity/` | ID kategorii marketplace |
| `CronExpressionParser` + `ScheduleDispatcherService` | `apps/api/src/Import/Application/Service/` | Harmonogram regeneracji (jitter = follow-up) |
| `FilterDslResolver` / `useFilterDslState` / `AdvancedFilterPanel` | `apps/api/src/Catalog/Application/Filter/`, `apps/admin/src/lib/filters/` | Filtr asortymentu feedu |
| `ApiKey`/`Argon2idApiKeyHasher`/rate-limit | `apps/api/src/ApiConfigurator/` | Token URL feedu (osobny lekki byt) |
| `AesGcmEncryptionService` + `EncryptedSecret` | `apps/api/src/Shared/Infrastructure/Crypto/` | Szyfrowanie hasła HTTP Basic |
| `PUBLIC_ACCESS` (audyt W0-5) + `CustomRouteOpenApiFactory` (ADR-0020) | `apps/api/config/packages/security.yaml`, `apps/api/src/Shared/OpenApi/` | Publiczny URL feedu + OpenAPI |
| Shell `KonfiguratorApiLayout` (pill-tabs) | `apps/admin/src/features/api-configurator/layout/` | Zakładki „Feedy" + „Monitor" |
| MinIO/Flysystem `{tenant}/{id}.{fmt}`, Mercure `exportSession` | `flysystem.yaml`, `MercureSubscribeTopics.php` | Cache feedu + progres `feed-runs.{id}` |

### Rozstrzygnięcia z adwersarialnej krytyki backlogu (wbudowane)

1. **Własność kształtu descriptora:** `XMLF-P2-01` (VO) = jedyne źródło prawdy; `XMLF-P1-04` = lekki guard przy zapisie, deleguje pełną walidację do VO.
2. **Twarde prereq serializacji:** `XMLF-P2-04`/`P2-05` zależą jawnie od `XMLF-P0-03`/`P0-04`.
3. **Luki dodane jako tickety:** `XMLF-P2-07`/`P5-06` (edytor struktury custom), `XMLF-P3-06` (telemetria pobrań), `XMLF-P5-07` (globalny monitor).
4. **skip_policy + KPI:** wchłonięte jako AC w `XMLF-P1-01`/`P1-03`/`P5-01`.

---


# M0 — Fundament + ad-hoc XML

### XMLF-P0-01: docs(architecture): add ADR-0023 for Konfigurator XML placement and serialization model
- **Typ:** `docs` · **Cls:** DOCS · **Milestone:** M0 · **Est:** 3-5h · **Risk:** low · `[PM]`
- **Blocked by:** — · **Blocks:** XMLF-P0-02, XMLF-P0-03
- **Po co:** Konfigurator XML dotyka >1 bounded context (Export jako gospodarz silnika + Channel/Catalog przez Contracts jako źródła danych, publiczny endpoint jako nowa powierzchnia ataku). Zanim powstanie kod, potrzebujemy jednej autorytatywnej decyzji architektonicznej, żeby kolejne tickety (M2 silnik, M3 delivery) nie renegocjowały umiejscowienia. Plan §12 zawiera szkic mini-ADR — ten ticket go finalizuje jako ADR-0023, ustalając kontrakty, na których opierają się wszystkie następne fazy. Bez zamrożonej decyzji ryzykujemy dryf: ktoś stworzy osobny BC albo rozszerzy pozycyjny RowWriter, co zabrudzi CSV/XLSX.
- **Stan obecny:** Plan feature-konfigurator-xml-plan.md §12 zawiera szkic mini-ADR z 7 proponowanymi decyzjami, ale bez sfinalizowanego pliku w docs/adr/. Najwyższy istniejący numer to 0022 (docs/adr/0022-api-configurator-consumer-producer-boundary.md), więc następny wolny numer to 0023 (potwierdzone ls docs/adr/). ExportFormat enum ma komentarz 'XML/JSON deferred to Faza 1'. RowWriter jest pozycyjny (writeHeaders/writeRow z list<string>). Deptrac ma płaską warstwę Export bez pod-warstw Feed.
- **Zakres:**
  - Utworzyć docs/adr/0023-konfigurator-xml-placement.md wg docs/adr/adr-template.md (status Accepted, data 2026-07-01).
  - Sfinalizować 7 decyzji z planu §12: (1) silnik XML w Export (nie nowy BC), feedy w pod-obszarze Export/Feed; (2) nowy asocjacyjny kontrakt ItemWriter zamiast rozszerzania pozycyjnego RowWriter — oba konsumują ten sam Generator<array<string,string>> z ExportBuilder; (3) reuse ExportBuilder jako źródła itemów, cross-BC do Channel/Catalog tylko przez *\Contracts\*; (4) feed = deklaratywny descriptor JSONB + zamknięta lista transformacji, predef jako seedy is_built_in; (5) delivery pull cache-and-serve (regeneracja → MinIO → publiczny URL serwuje cache, ETag/304/gzip), push = hook; (6) autoryzacja token-in-URL (hash wzorca ApiKey) + opcjonalny HTTP Basic (AesGcm), token ≠ klucz tenanta, publiczny endpoint przez PUBLIC_ACCESS; (7) scope przez publication profile (ADR-0018) reuse ?publication + column_aliases.
  - Udokumentować konsekwencje: reuse silnika Export → poprawki propagują się do feedów; dwa mechanizmy sekretów (hash token / szyfr Basic) współistnieją; publiczny endpoint = nowa powierzchnia ataku obsłużona wzorcem PUBLIC_ACCESS z audytu W0-5.
  - Wypisać powiązane ADR (0018 publication profile, 0015 bare UUID cross-BC, 0020 OpenAPI custom route, 0011 ORM XML mapping w Infrastructure, 0009 ObjectType, 0022 granica konsument/producent).
  - Zaktualizować sekcję ADR w agent/lessons.md jeśli decyzja zmienia którąś wcześniejszą regułę (spodziewane: brak zmian, tylko rozszerzenie).
  - Dopisać wpis do docs/adr/README.md (indeks ADR) jeśli README utrzymuje listę.
- **Poza zakresem:**
  - Implementacja jakiegokolwiek kodu (XmlWriterCore, ItemWriter, enum) — to osobne tickety XMLF-P0-03/04/05
  - Zmiany w deptrac.yaml — osobny ticket XMLF-P0-02
  - Decyzje o modelu feedu FeedProfile/FeedRun (schema tabel) — to M1, ADR-0023 tylko wskazuje kierunek, nie zamraża kolumn
  - Import XML / XXE guard (hook §7)
- **AC:**
  - [ ] Plik docs/adr/0023-konfigurator-xml-placement.md istnieje, status Accepted, zgodny z adr-template.md
  - [ ] ADR jednoznacznie stwierdza: silnik XML żyje w src/Export, feedy w src/Export/Feed, NIE w nowym bounded context
  - [ ] ADR jednoznacznie stwierdza: nowy kontrakt ItemWriter (asocjacyjny, klucz→wartość) zamiast rozszerzania pozycyjnego RowWriter, z uzasadnieniem (RowWriter celowo flat row-of-strings; XML potrzebuje nazw elementów)
  - [ ] ADR jednoznacznie stwierdza: cross-BC Export→Channel/Catalog wyłącznie przez *\Contracts\* (spójne z Deptrac)
  - [ ] ADR jednoznacznie stwierdza: delivery pull cache-and-serve; token-in-URL ≠ klucz tenanta; scope przez publication profile (ADR-0018)
  - [ ] Wszystkie 7 decyzji z planu §12 są rozstrzygnięte (nie 'proponowane'/'do potwierdzenia'), a otwarte kwestie z §11 wyraźnie oznaczone jako otwarte lub przesunięte do hooków
  - [ ] Sekcja 'Powiązane ADR' linkuje 0009/0011/0015/0018/0020/0022
- **Smoke:**
  - Otworzyć docs/adr/0023-konfigurator-xml-placement.md i zweryfikować, że wszystkie 7 decyzji są rozstrzygnięte i spójne z deptrac.yaml (Export sięga tylko *_Contracts + Shared)
  - Zweryfikować, że numer 0023 nie koliduje: ls docs/adr/ nie pokazuje istniejącego 0023
  - Sprawdzić, że linki do powiązanych ADR (0009/0011/0015/0018/0020/0022) wskazują na istniejące pliki w docs/adr/
- **Reuse:** docs/adr/adr-template.md — szablon struktury ADR (kontekst/decyzja/konsekwencje) · docs/adr/0022-api-configurator-consumer-producer-boundary.md — najwyższy istniejący numer (0022), potwierdza że 0023 jest wolny; wzorzec sąsiedniego boundary ADR · docs/adr/0018-channel-publication-profile.md — reuse jako scope feedu (?publication + column_aliases) · docs/adr/0020-openapi-custom-route-documentation.md — custom trasy feedu muszą trafić do docs/api-spec/v0.json · docs/adr/0015-cross-bc-fk-policy.md — bare UUID cross-BC dla channel_id/object_type_id feedu · apps/api/src/Export/Infrastructure/Writer/RowWriter.php — pozycyjny kontrakt, którego ADR uzasadnia NIE-rozszerzanie · apps/api/deptrac.yaml — Export ruleset (tylko *_Contracts + Shared), który ADR kodyfikuje
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §12 (mini-ADR szkic), §6.1 (umiejscowienie), §6.3 (silnik trójwarstwowy) · docs/adr/adr-template.md · ADR-0018, ADR-0015, ADR-0020, ADR-0011, ADR-0009, ADR-0022
- **DoD:** standard (docs-only — bez bramek kodowych).

### XMLF-P0-02: chore(deptrac): add Export_Feed layers reaching Export_Contracts seam
- **Typ:** `chore` · **Cls:** BE · **Milestone:** M0 · **Est:** 3-5h · **Risk:** medium
- **Blocked by:** XMLF-P0-01 · **Blocks:** XMLF-P0-04
- **Po co:** ADR-0023 ustala, że feedy żyją w pod-obszarze src/Export/Feed i sięgają Channel/Catalog wyłącznie przez *\Contracts\*. Deptrac musi egzekwować tę granicę CI-owo od dnia 1 — inaczej pierwszy ticket M1/M2 przypadkiem sięgnie do Catalog\Domain i utrwali dług jak istniejący baseline ExportBuilder→Catalog\Domain. Bez pod-warstw Feed cały kod feedu spadłby do płaskiej warstwy Export, tracąc izolację między silnikiem ad-hoc a feedami. Ten ticket kładzie szkielet architektoniczny, na którym stoją wszystkie kolejne tickety BE.
- **Stan obecny:** deptrac.yaml ma płaską warstwę Export (collector directory 'src/Export/.*') z ruleset Export → [Catalog_Contracts, Channel_Contracts, Asset_Contracts, Identity_Contracts, Shared, Vendor]. Brak pod-warstw Feed. Brak jawnej warstwy Export_Contracts — Export sięga innych BC bezpośrednio przez ich Contracts, ale nie ma własnego seamu kontraktowego. Istniejące reaches Export→Catalog\Domain (ExportBuilder, ValueSerializer, SyncExportRunner) są baselinowane w skip_violations. src/Export/ nie ma jeszcze katalogu Contracts ani Feed.
- **Zakres:**
  - Dodać do deptrac.yaml warstwy: Export_Feed_Internals (collectory directory na src/Export/Feed/{Domain,Application,Infrastructure,Presentation}/.*) oraz Export_Feed_Contracts (src/Export/Feed/Contracts/.*).
  - Dodać warstwę Export_Contracts (src/Export/Contracts/.*) jako seam, przez który Export/Feed czyta rdzeń Export (ItemWriter, XmlWriterCore contract jeśli wystawiony jako port) — zgodnie z ADR-0023.
  - Przepiąć istniejącą płaską warstwę Export tak, by NIE łapała src/Export/Feed/* ani src/Export/Contracts/* (bool must directory 'src/Export/.*' must_not directory 'src/Export/Feed/.*' + 'src/Export/Contracts/.*'), analogicznie do wzorca Integration vs Integration/Generic.
  - Ruleset: Export_Feed_Internals → [Export_Feed_Contracts, Export_Contracts, Channel_Contracts, Catalog_Contracts, Asset_Contracts, Identity_Contracts, Shared, Vendor]. Export_Feed_Contracts → [Shared, Vendor]. Export_Contracts → [Shared, Vendor].
  - Utworzyć minimalny placeholder w src/Export/Contracts/ (np. .gitkeep lub pierwszy marker interface z XMLF-P0-04) oraz w src/Export/Feed/ tylko jeśli deptrac wymaga niepustego katalogu do rozpoznania warstwy — inaczej warstwy dołożymy 'na przyszłość' bez pustych plików.
  - Uruchomić deptrac lokalnie/CI i potwierdzić 0 nowych naruszeń; nie dodawać żadnych nowych wpisów do skip_violations (jeśli trzeba — to sygnał złej granicy).
- **Poza zakresem:**
  - Implementacja klas w Export/Feed lub Export/Contracts (poza ewentualnym placeholderem) — to M1/M2
  - Usuwanie istniejących baseline skip_violations dla ExportBuilder/ValueSerializer/SyncExportRunner — to burndown #1466, niezwiązany
  - Warstwy dla FeedProfile/FeedRun encji (dojdą naturalnie pod Export_Feed_Internals gdy powstaną w M1)
- **AC:**
  - [ ] deptrac.yaml zawiera warstwy Export_Feed_Internals, Export_Feed_Contracts, Export_Contracts z poprawnymi collectorami
  - [ ] Ruleset: Export_Feed_Internals może sięgać Export_Feed_Contracts + Export_Contracts + {Channel,Catalog,Asset,Identity}_Contracts + Shared + Vendor, i NIE może sięgać Catalog_Internals/Channel_Internals
  - [ ] Płaska warstwa Export nie łapie już src/Export/Feed/* ani src/Export/Contracts/* (bool must/must_not)
  - [ ] deptrac analyse zwraca 0 violations (dokładnie 0, nie 'baseline pokrywa')
  - [ ] Żaden nowy wpis nie został dodany do skip_violations
  - [ ] Deptrac debug:unassigned nie pokazuje nowych nieprzypisanych klas w src/Export/
- **Smoke:**
  - Uruchomić vendor/bin/deptrac analyse (przez CI shard lub lokalnie jeśli dostępne) — oczekiwane 'no violations found'
  - Uruchomić vendor/bin/deptrac debug:layer Export_Feed_Internals i potwierdzić, że reguły dopuszczają tylko *_Contracts + Shared + Vendor
  - Potwierdzić, że próba (myślowa/testowa) importu App\Catalog\Domain\Entity\CatalogObject z klasy w src/Export/Feed/ dałaby violation
- **Reuse:** apps/api/deptrac.yaml — plik do rozszerzenia; wzorzec bool must/must_not z Integration vs Integration/Generic/* (linie 146-168) i Identity carve-out · apps/api/deptrac.yaml Export ruleset (linie 270-276) — istniejące dozwolenia Export → *_Contracts + Shared + Vendor do skopiowania dla warstw Feed
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.1 (Deptrac: Export/Feed sięga Export/*, cross-BC tylko przez Contracts), §12 decyzja 3 · docs/adr/0023-konfigurator-xml-placement.md (z XMLF-P0-01) · docs/adr/0013-deptrac-rollout.md — polityka Internals/Contracts split
- **DoD:** standard.

### XMLF-P0-03: feat(export): add XmlWriterCore streaming XMLWriter wrapper with always-well-formed guarantee
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M0 · **Est:** 8-12h · **Risk:** high · `[SEC]`
- **Blocked by:** XMLF-P0-01 · **Blocks:** XMLF-P0-04, XMLF-P2-04, XMLF-P2-05
- **Po co:** To jest bezpieczeństwo-krytyczny rdzeń całej funkcji: KAŻDY bajt XML (ad-hoc eksport i przyszłe feedy) przechodzi przez tę jedną klasę. Śmieciowe dane produktowe (nazwa z ']]>', opis z '<script>', znak \x0B) nie mogą złamać well-formedness — inaczej marketplace odrzuci cały feed, a w gorszym scenariuszu dojdzie do XML injection (analog lekcji CSV formula injection IMP2-2.8 #1552). XmlWriterCore to jedyne miejsce w kodzie dotykające php XMLWriter; wszystko inne buduje na jego gwarancji. Streaming (nigdy DOMDocument) jest wymagany dla feedów 50k SKU pod FrankenPHP worker mode bez OOM.
- **Stan obecny:** Brak jakiejkolwiek serializacji XML w kodzie. src/Export/Infrastructure/Writer/ zawiera tylko CsvStreamWriter i XlsxStreamWriter (OpenSpout, streaming do pliku) implementujące pozycyjny RowWriter. ExportFormat enum komentuje 'XML/JSON deferred to Faza 1'. PHP ma wbudowany XMLWriter (libxml) z auto-escapingiem, ale bez sanityzacji nielegalnych znaków sterujących XML 1.0 — to trzeba dołożyć ręcznie przed każdym zapisem.
- **Zakres:**
  - Utworzyć src/Export/Infrastructure/Writer/XmlWriterCore.php — cienka otoczka nad php XMLWriter w trybie streamingowym (openMemory z flush do zasobu / openUri do php://temp lub bezpośrednio do output stream).
  - Wystawić API rdzenia: startDocument (UTF-8 declaration), startElement/endElement, writeAttribute, text (auto-escaped), cdata (owinięte, z guardem na sekwencję ']]>' — split na ']]]]><![CDATA[>'), writeRepeated helper, close/flush.
  - Zaimplementować sanitizer nielegalnych znaków sterujących XML 1.0: usuwać/zastępować bajty spoza dozwolonego zakresu (#x9 | #xA | #xD | [#x20-#xD7FF] | [#xE000-#xFFFD] | [#x10000-#x10FFFF]); zastosować do KAŻDEJ wartości tekstowej i atrybutu ZANIM trafi do XMLWriter.
  - Zaimplementować politykę HTML per-wywołanie: 'escape' (domyślnie, przez text()), 'cdata' (owinięcie z guardem ]]>), 'strip' (usunięcie tagów) — parametr wyboru na poziomie wywołania, nie hardkod.
  - Memory-bounded: NIGDY DOMDocument, NIGDY build-all-then-serialize; pisać inkrementalnie z okresowym flush do zasobu; benchmark że pamięć jest płaska dla dużego strumienia.
  - Failing-test-first (SEC): najpierw napisać testy że dla śmieciowych inputów (nazwa='A]]>B<x>', opis zawierający \x00/\x0B/\x1F, atrybut z '"'&<>', emoji/4-bajtowy UTF-8, sekwencja ']]>' w treści CDATA) output jest ZAWSZE well-formed (parsowalny przez XMLReader/simplexml bez błędu libxml), DOPIERO potem implementacja.
  - Test że control-chars są usunięte a legalne (tab/newline) zachowane; test że '& < > " \'' są poprawnie escapowane w text i w atrybutach; test że CDATA z ']]>' nie łamie dokumentu.
- **Poza zakresem:**
  - Kontrakt ItemWriter i GenericXmlWriter — osobny ticket XMLF-P0-04 (buduje NA XmlWriterCore)
  - Descriptor feedu / sloty / transformacje — M2/M4
  - Zapis do MinIO / delivery — M3 (XmlWriterCore pisze do dowolnego strumienia/pliku)
  - Parsowanie XML (import) + XXE guard — hook §7
- **AC:**
  - [ ] XmlWriterCore istnieje w src/Export/Infrastructure/Writer/ i jest jedyną klasą w repo dotykającą \XMLWriter
  - [ ] Output zawsze zaczyna się od deklaracji XML z encoding=UTF-8
  - [ ] Test property/fuzz: dla zestawu śmieciowych stringów (]]>, <script>, \x00, \x0B, \x1F, emoji, cudzysłowy, ampersand) output jest ZAWSZE well-formed (parsuje się bez libxml error)
  - [ ] Sanitizer usuwa nielegalne control-chars XML 1.0 (\x00-\x08, \x0B, \x0C, \x0E-\x1F) i zachowuje \x09/\x0A/\x0D oraz legalne znaki >= \x20
  - [ ] CDATA z zawartym ']]>' nie łamie dokumentu (split na ']]]]><![CDATA[>')
  - [ ] Polityka HTML (escape/cdata/strip) wybieralna per wywołanie, nie hardkodowana
  - [ ] Brak użycia DOMDocument/DOMElement; pamięć płaska dla strumienia 10k+ elementów (test lub benchmark)
  - [ ] PHPUnit ≥80% pokrycia nowej logiki, testy bezpieczeństwa napisane przed implementacją (widoczne w historii commitów jako failing-first)
- **Smoke:**
  - Napisać jednorazowy skrypt/test uruchamiający XmlWriterCore na produkcie z nazwą 'Kabel ]]> <b>HDMI</b> \x0B 2m' + opisem z '<script>alert(1)</script>' i zapisujący do /tmp/feed-smoke.xml
  - xmllint --noout /tmp/feed-smoke.xml — musi zwrócić exit 0 (well-formed) bez żadnego błędu
  - grep sprawdza że w output nie ma surowego '<script>' poza CDATA/escaped, oraz że deklaracja UTF-8 jest obecna
- **Reuse:** apps/api/src/Export/Infrastructure/Writer/CsvStreamWriter.php — wzorzec streamingowego writera do pliku (open/write/close), do naśladowania stylu · apps/api/src/Export/Infrastructure/Writer/XlsxStreamWriter.php — wzorzec OpenSpout streaming (openToFile), analogia dla trybu memory-bounded
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.3 (XmlWriterCore — warstwa najniższa), §5 pkt 6 (XML injection/poprawność), §6.9 (bezpieczeństwo), §6.12 (memory FrankenPHP), §11 (ryzyko XML injection) · docs/adr/0023-konfigurator-xml-placement.md decyzja 1-2 · agent/lessons — IMP2-2.8 #1552 CSV formula injection (analog)
- **DoD:** standard.

### XMLF-P0-04: feat(export): add ItemWriter associative contract and GenericXmlWriter
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M0 · **Est:** 6-9h · **Risk:** medium
- **Blocked by:** XMLF-P0-03, XMLF-P0-02 · **Blocks:** XMLF-P0-05, XMLF-P2-04, XMLF-P2-05
- **Po co:** Pozycyjny RowWriter (writeRow(list<string>)) nie zna nazw elementów — nie da się nim napisać XML, gdzie klucz kolumny staje się nazwą węzła. ADR-0023 wprowadza równoległy asocjacyjny kontrakt ItemWriter, żeby CSV/XLSX zostały czyste, a XML dostał to, czego potrzebuje. GenericXmlWriter to konkretna implementacja dla trybu ad-hoc (§6.10): generyczny wrapper <products><product> bez descriptora — dostarcza natychmiastową wartość (eksport katalogu do XML) i waliduje, że para XmlWriterCore + ItemWriter działa end-to-end, zanim M2 zbuduje na nich pełny silnik feedów.
- **Stan obecny:** Istnieje tylko pozycyjny RowWriter (interface z writeHeaders(list<string>)/writeRow(list<string>)/close()) w src/Export/Infrastructure/Writer/. ExportBuilder zwraca Generator yielding array<string,string> (klucz kolumny → zserializowany string, np. 'sku'→'ABC', 'description.pl'→'...'), gdzie klucz może zawierać locale/channel po kropce ('description.pl'). Nie ma miejsca, które konsumuje ten asocjacyjny kształt zachowując klucze — SyncExportRunner spłaszcza go do pozycyjnych list wg planu kolumn. XmlWriterCore (XMLF-P0-03) dostarcza bezpieczną serializację.
- **Zakres:**
  - Zdefiniować kontrakt ItemWriter (asocjacyjny): open/writeHeader (root + otwarcie kolekcji), writeItem(array<string,string> $item), close(). Umieścić interfejs tak, by był osiągalny przez Export/Feed przez seam — rekomendacja: src/Export/Contracts/Writer/ItemWriter.php (spójne z Export_Contracts z XMLF-P0-02) lub src/Export/Infrastructure/Writer/ jeśli tylko Export go konsumuje w M0; zdecydować wg ADR-0023 i deptrac.
  - Zaimplementować GenericXmlWriter implements ItemWriter w src/Export/Infrastructure/Writer/: root <products>, per item <product>, per klucz kolumny → element; wartość przez XmlWriterCore (auto-escape + sanitizer).
  - Nazwy elementów z kluczy code.locale/code.channel: rozbić klucz — bazowa nazwa atrybutu jako element, locale/channel jako ATRYBUTY XML (<description locale="pl">, <name channel="shopify">), bo kropka jest nielegalna w nazwie elementu XML (§11, §6.10). Sanityzacja bazowej nazwy elementu do legalnego NCName (fallback np. zamiana niedozwolonych znaków).
  - Multi-value: wartości z separatorem ValueSerializer MULTI_VALUE_GLUE '|' → powtarzalne elementy (<image>a</image><image>b</image>) LUB zachowanie pipe — wybrać powtarzalne elementy jako domyślne (spójne z hierarchicznym formatem), udokumentować.
  - Null/blank: pusta wartość → pominięcie elementu albo pusty element — wybrać spójnie z ValueSerializer (null→'') i udokumentować (rekomendacja: pusty element dla stabilnego schematu, lub pominięcie — zdecydować i przetestować).
  - GenericXmlWriter MAY implementować także RowWriter (nagłówki zachowane jako nazwy elementów), jeśli to upraszcza wpięcie w SyncExportRunner (XMLF-P0-05) — ale asocjacyjna ścieżka jest autorytatywna.
  - Testy jednostkowe: mapowanie klucz→element, locale/channel→atrybut, multi-value→powtórzenia, śmieciowe wartości pozostają well-formed (delegacja do XmlWriterCore), pusty item.
- **Poza zakresem:**
  - Descriptor-driven XmlFeedWriter (sloty, element vs atrybut wg szablonu, wrapIn, repeatable Ceneo) — M2 (XMLF-P2-*)
  - Transformacje (price/enum_map/default) — M4
  - Wpięcie w SyncExportRunner/ExportJobHandler + ExportFormat::Xml — XMLF-P0-05
  - Zmiana kontraktu RowWriter dla CSV/XLSX (zostaje pozycyjny, nietknięty)
- **AC:**
  - [ ] Interfejs ItemWriter istnieje z metodami open/writeHeader, writeItem(array<string,string>), close()
  - [ ] GenericXmlWriter implements ItemWriter i produkuje <products><product>...</product></products>
  - [ ] Klucz 'sku' → <sku>; klucz 'description.pl' → <description locale="pl">; klucz z channelem → atrybut channel
  - [ ] Multi-value ('a|b|c') → powtarzalne elementy (udokumentowana decyzja)
  - [ ] Cała serializacja przechodzi przez XmlWriterCore — brak bezpośredniego dostępu do \XMLWriter w GenericXmlWriter
  - [ ] Output GenericXmlWriter dla dowolnego array<string,string> (w tym śmieciowe wartości) jest zawsze well-formed
  - [ ] Deptrac 0: klasy nie sięgają Catalog/Channel Internals; PHPStan max; PHPUnit ≥80% nowej logiki
- **Smoke:**
  - Napisać test/skrypt składający GenericXmlWriter z 3 itemami (w tym jeden z 'description.pl' zawierającym HTML i multi-value 'image_link') do /tmp/adhoc-smoke.xml
  - xmllint --noout /tmp/adhoc-smoke.xml — exit 0 (well-formed)
  - Zweryfikować w /tmp/adhoc-smoke.xml, że <description locale="pl"> ma atrybut locale i że multi-value dało wiele elementów
- **Reuse:** apps/api/src/Export/Infrastructure/Writer/XmlWriterCore.php — rdzeń serializacji (z XMLF-P0-03), delegacja escape/sanitize/cdata · apps/api/src/Export/Infrastructure/Writer/RowWriter.php — pozycyjny kontrakt, którego GenericXmlWriter MOŻE też implementować dla wpięcia w runner · apps/api/src/Export/Application/Builder/ExportBuilder.php — źródło Generator<array<string,string>> (klucz kolumny → wartość) konsumowanego przez ItemWriter · apps/api/src/Export/Application/Builder/ValueSerializer.php — definiuje MULTI_VALUE_GLUE '|', null→'', asset→asset_id; GenericXmlWriter interpretuje ten wynik
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.3 (ItemWriter kontrakt + interfejs), §6.10 (ad-hoc GenericXmlWriter: locale/channel jako atrybuty, multi-value), §11 (nazwy elementów code.locale) · docs/adr/0023-konfigurator-xml-placement.md decyzja 2
- **DoD:** standard.

### XMLF-P0-05: feat(export): add ExportFormat::Xml and wire GenericXmlWriter into sync and async runners
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M0 · **Est:** 5-8h · **Risk:** medium
- **Blocked by:** XMLF-P0-04 · **Blocks:** XMLF-P0-06
- **Po co:** To spina rdzeń serializacji z istniejącym flow eksportu, żeby użytkownik faktycznie mógł pobrać XML tą samą ścieżką co XLSX/CSV (sync <100 obiektów przez HTTP, ≥100 async z Mercure progresem). Bez tego XmlWriterCore + GenericXmlWriter to martwy kod. Cała reszta pipeline'u (preflight, kolumny, scope, sesja, download, anulowanie) reużywana 1:1 — dokładamy tylko nowy case enuma i gałąź w openWriter. To domyka tryb 2 (ad-hoc XML) po stronie backendu i odblokowuje FE (XMLF-P0-06).
- **Stan obecny:** ExportFormat enum ma tylko Xlsx i Csv (z komentarzem 'XML/JSON deferred to Faza 1'); mimeType()/extension() to match po tych dwóch. SyncExportRunner::openWriter (linia 500) to prywatna metoda: if Xlsx → XlsxStreamWriter->openToFile, else → CsvStreamWriter->open($path, $encoding); zwraca RowWriter. runToFile (linia 296) woła openWriter dla obu ścieżek (sync ~linia 345, async-do-file ~linia 451). ExportJobHandler (async) deleguje do SyncExportRunner::runToFile (linia 135), więc wpięcie w openWriter automatycznie obsługuje async. SYNC_THRESHOLD=100 rozstrzyga sync vs async (bez zmian). filePath wzorzec '{tenant_id}/{session_id}.{format}' — extension() z enuma decyduje o rozszerzeniu.
- **Zakres:**
  - Dodać case Xml = 'xml' do ExportFormat; mimeType() → 'application/xml'; extension() → 'xml' (już zwraca $this->value, więc 'xml' wychodzi automatycznie — potwierdzić).
  - Rozszerzyć SyncExportRunner::openWriter o gałąź ExportFormat::Xml → nowy GenericXmlWriter otwarty do $path (przez ścieżkę RowWriter jeśli GenericXmlWriter implementuje RowWriter, lub adapter jeśli tylko ItemWriter — dopasować do decyzji z XMLF-P0-04).
  - Jeśli GenericXmlWriter jest tylko ItemWriter (nie RowWriter): dodać cienki most w runToFile tak, by asocjacyjna mapa itemu z ExportBuilder trafiała do writeItem() zamiast spłaszczania do pozycyjnej listy — dla ścieżki XML; XLSX/CSV bez zmian. Alternatywnie GenericXmlWriter implementuje RowWriter (headers→nazwy elementów) i zero zmian w runnerze poza openWriter.
  - Potwierdzić, że routing sync/async (SYNC_THRESHOLD=100), per-chunk progress, cancel (ExportCancelledException), zapis ExportSession/ExportLog, upload do MinIO działają dla XML bez modyfikacji (ExportJobHandler deleguje do runToFile).
  - Zaktualizować ExportFormat, gdzie enum jest walidowany na wejściu (np. akceptowane formaty w preflight/controller/DTO) — dopuścić 'xml' w liście dozwolonych wartości.
  - ApiTestCase: sync XML export (mały zbiór, <100) zwraca 200 z Content-Type application/xml i well-formed treścią; async XML export (≥100) zwraca 202 i po przetworzeniu sesja ma filePath .xml + status done.
  - Zregenerować docs/api-spec/v0.json jeśli dopuszczenie 'xml' zmienia schemat OpenAPI (enum formatów w custom trasach eksportu).
- **Poza zakresem:**
  - Zmiana kafelka XML w FE wizardzie — XMLF-P0-06
  - Feedy / descriptor / FeedProfile — M1+
  - Nowe kolumny/scope/format profili poza dopuszczeniem 'xml'
  - gzip/ETag/cache-and-serve (to delivery feedów, M3) — ad-hoc XML to zwykły download jak XLSX/CSV
- **AC:**
  - [ ] ExportFormat::Xml istnieje; mimeType()==='application/xml'; extension()==='xml'
  - [ ] SyncExportRunner::openWriter zwraca writer XML dla ExportFormat::Xml; XLSX/CSV ścieżki nietknięte
  - [ ] Sync eksport XML (<SYNC_THRESHOLD) zwraca HTTP 200 z Content-Type application/xml i well-formed body
  - [ ] Async eksport XML (≥SYNC_THRESHOLD) zwraca 202; po ExportJobHandler sesja ma filePath z rozszerzeniem .xml i status done; plik w MinIO parsowalny
  - [ ] Walidacja wejścia akceptuje 'xml' jako format wszędzie, gdzie akceptuje 'xlsx'/'csv'
  - [ ] ApiTestCase pokrywa oba tryby (sync 200 + async 202→done); PHPStan max, Deptrac 0
  - [ ] docs/api-spec/v0.json zregenerowany jeśli enum formatów jest częścią OpenAPI (bramka 'OpenAPI spec drift' zielona)
- **Smoke:**
  - Zalogować się na https://pim.localhost (admin@demo.localhost / changeme), uruchomić eksport produktów z formatem XML dla małego zbioru (<100) — DevTools Network: response 200, Content-Type application/xml
  - Pobrać plik i uruchomić xmllint --noout na pobranym pliku — exit 0 (well-formed)
  - Uruchomić eksport dla ≥100 obiektów — response 202, poczekać na done (Mercure/refresh), pobrać wynikowy .xml i xmllint --noout — exit 0
  - DevTools Console — brak czerwonych błędów
- **Reuse:** apps/api/src/Export/Domain/Enum/ExportFormat.php — enum do rozszerzenia o case Xml (mimeType/extension match) · apps/api/src/Export/Application/Sync/SyncExportRunner.php — openWriter (~linia 500) do rozszerzenia; runToFile (linia 296) i routing bez zmian · apps/api/src/Export/Application/Async/ExportJobHandler.php — deleguje do SyncExportRunner::runToFile (linia 135), TenantContext::set, per-chunk progress+cancel; działa dla XML bez zmian · apps/api/src/Export/Infrastructure/Writer/GenericXmlWriter.php — writer XML z XMLF-P0-04 wpinany w openWriter · apps/api/src/Shared/OpenApi/CustomRouteOpenApiFactory.php — regeneracja docs/api-spec/v0.json dla custom tras eksportu (ADR-0020) · config/packages/flysystem.yaml — exports.storage, ścieżka {tenant_id}/{session_id}.{format} (format=xml)
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.10 (ExportFormat::Xml, reuse całego flow EXR), §3 (sync <100 / async ≥100) · docs/adr/0023-konfigurator-xml-placement.md decyzja 1 · docs/adr/0020-openapi-custom-route-documentation.md
- **DoD:** standard.

### XMLF-P0-06: feat(exports): unblock XML format tile in export wizard
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M0 · **Est:** 4-6h · **Risk:** low
- **Blocked by:** XMLF-P0-05 · **Blocks:** —
- **Po co:** Backend obsługuje już eksport XML (XMLF-P0-05), ale w kreatorze eksportu kafelek 'XML' jest wyszarzony jako 'wkrótce' (SOON_FORMATS). Ten ticket odblokowuje go, dając użytkownikowi realnie działającą ścieżkę: wybierz XML → eksportuj → pobierz well-formed plik. To domyka tryb 2 (ad-hoc XML) end-to-end i dostarcza pierwszą widoczną wartość funkcji Konfigurator XML bez żadnego nowego ekranu.
- **Stan obecny:** StepScopeFormat.tsx: ACTIVE_FORMATS = [xlsx, csv]; SOON_FORMATS = ['xml','json','gsheets','pdf'] renderowane jako disabled SelectableCard z tłumaczeniami format_soon.xml ('XML') i format_soon_desc.xml ('Hierarchical feed' / odpowiednik PL). applyProfile twardo mapuje format do 'csv'|'xlsx' (linia 108: format === 'csv' ? 'csv' : 'xlsx') — nie zna 'xml'. Klucze i18n: apps/admin/src/locales/en.json i pl.json mają bloki format_desc, format_soon, format_soon_desc (~linia 3092 en / 3144 pl). Typ ExportFormat w ../types.
- **Zakres:**
  - Przenieść 'xml' z SOON_FORMATS do ACTIVE_FORMATS w StepScopeFormat.tsx z descKey 'exports.wizard.format_desc.xml'.
  - Dodać klucz i18n exports.wizard.format_desc.xml w en.json i pl.json (opis aktywnego formatu, np. EN 'Hierarchical XML feed' / PL 'Hierarchiczny plik XML'); usunąć/zostawić format_soon.xml + format_soon_desc.xml (usunąć, skoro nie jest już 'soon', chyba że współdzielone).
  - Zaktualizować typ ExportFormat w ../types tak, by dopuszczał 'xml' (jeśli to union literałów), oraz applyProfile/mapowanie formatu profilu, by rozpoznawał zapisany 'xml' zamiast wymuszać 'xlsx'/'csv'.
  - Potwierdzić, że payload wysyłany do backendu przy wyborze XML zawiera format 'xml' (nie jest filtrowany jako SOON) i że SET_FORMAT działa dla 'xml' w wizard-store.
  - Zweryfikować, że kafelek XML jest teraz selectable (nie disabled), a a11y (rola radio/aria) zachowana przez SelectableCard.
  - Playwright E2E: login → kreator eksportu → wybór entity product → krok 2 wybór formatu XML → przejście do kroku 4 → eksport → pobranie pliku; asercja że plik ma rozszerzenie .xml i (jeśli fixture pozwala) jest well-formed.
  - Uruchomić axe-core na kroku 2 z odblokowanym kafelkiem — 0 serious/critical.
- **Poza zakresem:**
  - Nowe ekrany feedów / hub / mapper — M5 (osobna zakładka Feedy)
  - Odblokowanie json/gsheets/pdf — nadal SOON
  - Zmiany backendu (zrobione w XMLF-P0-05)
  - Descriptor/transformacje/preview feedu
- **AC:**
  - [ ] Kafelek 'XML' w kroku 2 kreatora jest aktywny (selectable), nie disabled
  - [ ] Wybór XML ustawia state.format='xml' i wysyła format 'xml' w payloadzie eksportu
  - [ ] Klucz i18n exports.wizard.format_desc.xml istnieje w en.json i pl.json; brak literałów w JSX
  - [ ] applyProfile poprawnie obsługuje profil zapisany z formatem 'xml' (nie wymusza xlsx/csv)
  - [ ] Playwright E2E: pełen flow login→wybór XML→eksport→download przechodzi; pobrany plik ma rozszerzenie .xml
  - [ ] axe-core 0 serious/critical na kroku 2; Biome strict + tsc zielone
  - [ ] Typ ExportFormat w ../types zawiera 'xml'
- **Smoke:**
  - Zalogować się na https://pim.localhost (admin@demo.localhost / changeme), otworzyć kreator eksportu, przejść do kroku 2 (Format)
  - Kliknąć kafelek XML — musi się zaznaczyć (nie być wyszarzony); przejść przez kreator do kroku eksportu
  - Uruchomić eksport, w DevTools Network potwierdzić że payload ma format:'xml' i response 200/202; pobrać plik
  - xmllint --noout na pobranym .xml — exit 0; DevTools Console bez czerwonych błędów
- **Reuse:** apps/admin/src/features/exports/wizard/steps/StepScopeFormat.tsx — ACTIVE_FORMATS/SOON_FORMATS (linie 44-50), applyProfile format mapping (linia 108), render kafelków (linie 166-185) · apps/admin/src/locales/en.json — bloki format_desc/format_soon/format_soon_desc (~linia 3092) · apps/admin/src/locales/pl.json — te same bloki (~linia 3144) · apps/admin/src/components/ui-v2/selectable-card.tsx — SelectableCard/SelectableCardGroup (a11y radio) reużyty bez zmian · apps/admin/src/features/exports/types — typ ExportFormat do rozszerzenia o 'xml'
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.10 (odblokowanie kafelka XML, zero nowego UI), §3 (tryb 2 ad-hoc) · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/ (kontekst wizualny; M0 nie dodaje ekranów feedów)
- **DoD:** standard.


# M1 — Model feedu + CRUD

### XMLF-P1-01: feat(export): add FeedProfile entity, migration, RLS and repository
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M1 · **Est:** 6-9h · **Risk:** medium · `[PM]`
- **Blocked by:** — · **Blocks:** XMLF-P1-02, XMLF-P1-03, XMLF-P1-04, XMLF-P2-04, XMLF-P4-02, XMLF-P6-02
- **Po co:** FeedProfile jest rdzeniem persystentnego trybu konfiguratora XML — to "ExportProfile z szablonem XML, harmonogramem i stabilnym URL" (plan §3, §6.2). Bez tej encji nie ma gdzie zapisać definicji feedu (szablon, mapowania, filtr, scope, cache, token), więc blokuje cały downstream: CRUD API, silnik generujący, delivery, harmonogram i UI. Feed jest read-only projekcją katalogu — encja nie dotyka danych domenowych, ale musi być twardo izolowana per tenant (RLS + GUC) bo publiczny URL feedu rozwiązuje tenant z tokenu.
- **Stan obecny:** Kontekst Export ma trzy encje konfiguracyjno-sesyjne (ExportProfile/ExportSession/ExportLog w apps/api/src/Export/Domain/Entity/) mapowane przez ORM XML (apps/api/src/Export/Infrastructure/Doctrine/Orm/Mapping/*.orm.xml, ADR-0011) z repozytoriami interface+Doctrine (Domain/Repository + Infrastructure/Doctrine/Repository). Nie istnieje żadna encja feedu. ExportProfile pokazuje wzorzec: AggregateRoot + TenantScoped, bare Uuid dla cross-BC objectTypeId (ADR-0015), config jako JSONB, UUIDv7 id. Migracje w apps/api/migrations/ z przykładami RLS (ENABLE ROW LEVEL SECURITY + CREATE POLICY na app.current_tenant) m.in. Version20260628100000.
- **Zakres:**
  - Encja FeedProfile w apps/api/src/Export/Domain/Entity/FeedProfile.php — extends AggregateRoot implements TenantScoped, UUIDv7 id, pola per plan §6.2: code (VARCHAR 64 slug), name, template_kind (google_shopping|ceneo|meta|custom), object_type_id (bare Uuid, ADR-0015, MVP zawsze product), descriptor (JSONB), field_mappings (JSONB default []), filter (JSONB nullable), channel_id (bare Uuid nullable), publication_channel, locale, currency (ISO 4217), schedule_cron (nullable = tylko manual), delivery (JSONB default {}), token_hash, status (active|paused|error), last_run_id (nullable Uuid), cached_file_path/cached_file_size/cached_item_count/cached_at, created_at/updated_at.
  - Enum-y domenowe: FeedTemplateKind i FeedStatus w apps/api/src/Export/Domain/Enum/ (wzorzec ExportFormat/ExportStatus) — string-backed, walidacja wartości w konstruktorze/setterach.
  - Metody domenowe: rename, updateDescriptor, updateFieldMappings, updateFilter, pause/resume (mutacja status), recordCache(path,size,count,at), rotateToken(hash) — z touch() na updated_at (wzorzec ExportProfile).
  - ORM XML mapping apps/api/src/Export/Infrastructure/Doctrine/Orm/Mapping/FeedProfile.orm.xml (ADR-0011 — mapping w Infrastructure, nie atrybuty na encji) z JSONB type dla descriptor/field_mappings/filter/delivery.
  - Repozytorium: interface apps/api/src/Export/Domain/Repository/FeedProfileRepositoryInterface.php + Doctrine impl apps/api/src/Export/Infrastructure/Doctrine/Repository/DoctrineFeedProfileRepository.php (save, findById, findByCode(tenant,code), findByTenant paginowane, findDueForSchedule(now) dla harmonogramu, remove).
  - Migracja apps/api/migrations/Version{ts}.php: CREATE TABLE feed_profiles z kolumnami i indeksami z §6.2 (idx_feed_profiles_tenant na (tenant_id, updated_at DESC); partial idx_feed_profiles_schedule WHERE schedule_cron IS NOT NULL AND status='active'), UNIQUE(tenant_id, code), ENABLE ROW LEVEL SECURITY + CREATE POLICY na app.current_tenant GUC (wzorzec Version20260628100000). FK tenant_id → tenants(id).
  - Weryfikacja że TenantAssignmentListener przypisuje tenant_id na save (nie ręcznie w handlerze) oraz że worker/CLI ścieżka ustawia GUC app.current_tenant (wzorzec IMP2-2.5) — feed regenerowany w workerze musi mieć GUC set.
- **Poza zakresem:**
  - FeedRun/FeedRunLog (osobny ticket XMLF-P1-02)
  - CRUD API i kontrolery (XMLF-P1-03)
  - Walidacja kształtu pola descriptor (XMLF-P1-04 — tu descriptor jest surowym JSONB bez walidacji zawartości)
  - Generowanie feedu / FeedGenerator (milestone M2)
  - Mint/hash/rotate tokenu jako logika (tu tylko kolumna token_hash; lifecycle tokenu w milestone delivery M3)
  - Szyfrowanie credentiali Basic w delivery (M3)
  - Seed szablonów predefiniowanych (M2)
- **AC:**
  - [ ] Migracja up() tworzy tabelę feed_profiles ze wszystkimi kolumnami §6.2, UNIQUE(tenant_id,code), oboma indeksami i włączonym RLS z policy na app.current_tenant; down() ją usuwa; migracja jest idempotentna względem re-run CI.
  - [ ] FeedProfile jest TenantScoped — próba zapisu bez ustawionego tenant kontekstu kończy się przypisaniem przez TenantAssignmentListener; nie ma set tenant ręcznie w kodzie domenowym.
  - [ ] Test izolacji multi-tenant: tenant A tworzy feed, tenant B (inny GUC) robi findByTenant/findByCode → 0 wyników (cross-read=0), zarówno przez Doctrine filter jak i RLS.
  - [ ] descriptor/field_mappings/filter/delivery persystują jako JSONB i round-trip'ują bez utraty typów (array in → array out).
  - [ ] UNIQUE(tenant_id,code) egzekwowany na DB — drugi feed z tym samym code w tym samym tenant rzuca constraint violation (ApiTestCase/repo test).
  - [ ] PHPStan max przechodzi (bare Uuid dla channel_id/object_type_id, brak cross-BC importu klas Channel/Catalog — tylko Uuid).
  - [ ] Deptrac: FeedProfile w warstwie Export nie sięga Channel/Catalog Internals (bare Uuid, cross-BC tylko przez Contracts w późniejszych ticketach).
  - [ ] FeedProfile ma kolumnę `validation_policy` (`skip_invalid`|`include_with_warning`), default `skip_invalid`.
- **Smoke:**
  - pnpm stack:up; zaloguj się na https://pim.localhost (admin@demo.localhost / changeme).
  - Przez tinker/psql: SELECT do information_schema potwierdza tabelę feed_profiles + policy (SELECT * FROM pg_policies WHERE tablename='feed_profiles').
  - INSERT ręczny feed_profile dla tenanta demo z SET app.current_tenant → SELECT zwraca wiersz; zmiana GUC na inny tenant → SELECT zwraca 0 wierszy (proof izolacji).
  - Potwierdź w migration status (doctrine:migrations:status) że migracja jest 'executed' i nie ma diffu w schema:validate.
- **Reuse:** apps/api/src/Export/Domain/Entity/ExportProfile.php — wzorzec encji konfiguracyjnej: AggregateRoot + TenantScoped, UUIDv7, bare Uuid objectTypeId, config JSONB, touch() · apps/api/src/Export/Infrastructure/Doctrine/Orm/Mapping/ExportProfile.orm.xml — wzorzec ORM XML mapping w Infrastructure (ADR-0011) z JSONB · apps/api/src/Export/Domain/Repository/ExportProfileRepositoryInterface.php + apps/api/src/Export/Infrastructure/Doctrine/Repository/DoctrineExportProfileRepository.php — wzorzec interface+Doctrine repo · apps/api/src/Export/Domain/Enum/ExportFormat.php — wzorzec string-backed enum domenowego (dla FeedTemplateKind/FeedStatus) · apps/api/migrations/Version20260628100000.php — wzorzec migracji z ENABLE ROW LEVEL SECURITY + CREATE POLICY na app.current_tenant GUC
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.2 (schema feed_profiles), §6.9 (token_hash), §8 faza 2 · docs/adr/0011-orm-xml-mapping-in-infrastructure.md · docs/adr/0015-cross-bc-fk-policy.md (bare Uuid cross-BC) · docs/adr/0014-tenant-as-shared-kernel.md
- **DoD:** standard.

### XMLF-P1-02: feat(export): add FeedRun and FeedRunLog entities, migrations, RLS and repositories
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M1 · **Est:** 5-7h · **Risk:** low
- **Blocked by:** XMLF-P1-01 · **Blocks:** XMLF-P2-04, XMLF-P3-01, XMLF-P3-02, XMLF-P3-04, XMLF-P4-02, XMLF-P4-03
- **Po co:** FeedRun audytuje pojedynczą generację feedu (kiedy, czym wyzwolona, ile itemów/skipów/warningów, plik, czas, błąd), a FeedRunLog trzyma linie logu per-produkt ("SKU-123: missing g:gtin — skipped") składające się na raport zdrowia feedu (plan §6.2, §6.5). Bez nich silnik feedu (M2) nie ma gdzie zapisać wyniku ani monitor UI (M-monitor) nie ma czego wyświetlić. To odpowiednik ExportSession/ExportLog, ale rozdzielony na run (nagłówek) + log (linie) bo feed loguje per-produkt walidację required-field.
- **Stan obecny:** Export ma ExportSession (nagłówek sesji, filterSnapshot JSONB, status enum, filePath) i ExportLog (level/message/context) w apps/api/src/Export/Domain/Entity/ z ORM XML mapping i repozytoriami. Nie ma encji feedu. XMLF-P1-01 dostarcza FeedProfile (last_run_id wskazuje na FeedRun). Migracje z RLS istnieją w apps/api/migrations/.
- **Zakres:**
  - Encja FeedRun w apps/api/src/Export/Domain/Entity/FeedRun.php — UUIDv7 id, tenant_id (TenantScoped), feed_profile_id (Uuid, FK ON DELETE CASCADE), trigger (schedule|manual|first_publish), status (pending|running|done|error|cancelled), item_count/skipped_count/warning_count (int default 0), file_path (nullable), file_size_bytes, duration_ms, error_message, started_at, completed_at. Metody: markRunning, markDone(itemCount,skipped,warnings,path,size,durationMs), markError(msg), markCancelled.
  - Enum-y FeedRunTrigger i FeedRunStatus w apps/api/src/Export/Domain/Enum/ (string-backed).
  - Encja FeedRunLog w apps/api/src/Export/Domain/Entity/FeedRunLog.php — UUIDv7 id, feed_run_id (Uuid, FK ON DELETE CASCADE), level (info|warning|error), object_sku (nullable = feed-level), slot (nullable, np. g:gtin), message, created_at. Factory helpery: info/warning/error(runId, sku, slot, message).
  - ORM XML mapping dla obu w apps/api/src/Export/Infrastructure/Doctrine/Orm/Mapping/ (ADR-0011).
  - Repozytoria: FeedRunRepositoryInterface + Doctrine impl (save, findById, findByProfile(profileId) paginowane started_at DESC, findLatestForProfile) oraz FeedRunLogRepositoryInterface + Doctrine impl (append batch, findByRun(runId), countByLevel(runId)).
  - Migracja apps/api/migrations/Version{ts}.php: CREATE TABLE feed_runs (idx_feed_runs_profile na (feed_profile_id, started_at DESC), FK feed_profile_id → feed_profiles ON DELETE CASCADE, FK tenant_id) + CREATE TABLE feed_run_logs (idx_feed_run_logs_run na feed_run_id, FK feed_run_id → feed_runs ON DELETE CASCADE); ENABLE ROW LEVEL SECURITY + policy na feed_runs (tenant_id z GUC). feed_run_logs izolowany kaskadowo przez feed_run_id (bez własnego tenant_id — dokumentuj decyzję).
  - Repozytorium FeedRunLog musi appendować batchami z EntityManager::clear() per N (CLAUDE.md §3.10 worker memory) — logów per run może być tysiące (jeden per skipnięty produkt).
- **Poza zakresem:**
  - Faktyczne wypełnianie FeedRun/FeedRunLog w trakcie generacji (to robi FeedGenerator w M2)
  - Mercure progres per run (milestone harmonogram/monitor)
  - UI monitora runów (milestone FE)
  - Ustawienie FeedProfile.last_run_id po runie (logika w FeedGenerator M2; tu tylko kolumna istnieje z P1-01)
  - Cancel-token flow (reuse ExportCancelledException w M2)
- **AC:**
  - [ ] Migracja tworzy feed_runs i feed_run_logs z FK ON DELETE CASCADE (usunięcie FeedProfile kaskadowo usuwa runy i logi — potwierdzone testem), indeksami z §6.2 i RLS na feed_runs; down() usuwa obie tabele w poprawnej kolejności.
  - [ ] FeedRun jest TenantScoped z RLS; test izolacji: run tenanta A nie jest widoczny przy GUC tenanta B (cross-read=0).
  - [ ] FeedRunLog appendowane batchem wołają EntityManager::clear() po flush w pętli (custom PHPStan flush-bez-clear rule przechodzi zielono).
  - [ ] markDone/markError/markCancelled ustawiają status, completed_at i pola liczników zgodnie z semantyką; markError zapisuje error_message.
  - [ ] findByProfile zwraca runy posortowane started_at DESC (dla monitora), countByLevel zwraca poprawne liczby info/warning/error dla runu.
  - [ ] PHPStan max + Deptrac zielone (feed_run_logs nie odwołuje się do klas Catalog przez object_sku — to zwykły string).
- **Smoke:**
  - pnpm stack:up; zaloguj się na https://pim.localhost.
  - psql: potwierdź tabele feed_runs + feed_run_logs i policy (pg_policies) na feed_runs.
  - Ręczny INSERT feed_run + kilka feed_run_logs powiązanych; DELETE feed_profile (z P1-01) → potwierdź kaskadowe usunięcie runów i logów (SELECT count = 0).
  - SET app.current_tenant na innego tenanta → SELECT z feed_runs zwraca 0 wierszy (proof izolacji).
- **Reuse:** apps/api/src/Export/Domain/Entity/ExportSession.php — wzorzec encji sesji/runu: status enum, filePath, filterSnapshot JSONB, timestamps · apps/api/src/Export/Domain/Entity/ExportLog.php — wzorzec encji logu (level/message/context) · apps/api/src/Export/Domain/Repository/ExportSessionRepositoryInterface.php + ExportLogRepositoryInterface.php — wzorzec repozytoriów sesja/log · apps/api/src/Export/Infrastructure/Doctrine/Repository/DoctrineExportSessionRepository.php + DoctrineExportLogRepository.php — wzorzec Doctrine impl z batch append · apps/api/migrations/Version20260628100000.php — wzorzec migracji z RLS policy na app.current_tenant
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.2 (schema feed_runs + feed_run_logs), §6.5 (feed health / skip_invalid) · docs/adr/0011-orm-xml-mapping-in-infrastructure.md · CLAUDE.md §3.10 (FrankenPHP worker memory — clear() per batch)
- **DoD:** standard.

### XMLF-P1-03: feat(export): add FeedProfile CRUD API with OpenAPI registration
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M1 · **Est:** 8-12h · **Risk:** medium
- **Blocked by:** XMLF-P1-01, XMLF-P1-04 · **Blocks:** —
- **Po co:** Bez CRUD API nie ma jak z UI stworzyć, wylistować, edytować, wstrzymać ani usunąć feedu — to backend pod hub feedów i kreator (§9). API jest produktem first-class (CLAUDE.md): admin używa tych samych endpointów co integratorzy, a cała powierzchnia musi być w OpenAPI. FeedProfile to byt proceduralno-konfiguracyjny z operacjami nie-CRUD (pause/resume), więc idzie przez custom #[Route] kontrolery w CQRS (ADR-0012, ADR-0020), analogicznie do ExportProfileController — nie przez API Platform ApiResource.
- **Stan obecny:** Export wystawia konfigurację przez custom kontrolery w apps/api/src/Export/Presentation/Controller/ (ExportProfileController, ExportSessionController, ExportPreflightController) — wzorzec CQRS z custom #[Route]. ApiConfigurator ma podobne kontrolery. CustomRouteOpenApiFactory (apps/api/src/Shared/OpenApi/CustomRouteOpenApiFactory.php, ADR-0020) auto-rejestruje custom /api/* trasy do docs/api-spec/v0.json; nowa trasa bez regeneracji = czerwona bramka 'OpenAPI spec drift'. XMLF-P1-01 dostarcza FeedProfile + repo; XMLF-P1-04 dostarcza walidację descriptora (wołaną z create/update).
- **Zakres:**
  - Kontrolery custom #[Route] w apps/api/src/Export/Presentation/Controller/Feed/ (CQRS, ADR-0012): FeedProfileListController (GET /api/feeds — paginacja cursor/offset jak lista Export, filtr po status/template_kind), FeedProfileCreateController (POST /api/feeds), FeedProfileGetController (GET /api/feeds/{id}), FeedProfileUpdateController (PUT/PATCH /api/feeds/{id}), FeedProfileDeleteController (DELETE /api/feeds/{id}), FeedProfilePauseController (POST /api/feeds/{id}/pause), FeedProfileResumeController (POST /api/feeds/{id}/resume).
  - Command/Query handlery (CQRS): CreateFeedProfile, UpdateFeedProfile, DeleteFeedProfile, PauseFeed, ResumeFeed + ListFeedProfiles/GetFeedProfile query — w apps/api/src/Export/Application/Feed/ (Messenger command/query bus jak w istniejącym Export).
  - Request DTO + Symfony Validator constraints na payloadzie CRUD: code (slug, NotBlank, Length 64, regex), name, template_kind (Choice z FeedTemplateKind), object_type_id, locale, currency (ISO 4217), schedule_cron (opcjonalnie walidowany CronExpressionParser::isValid), delivery shape. descriptor przechodzi przez walidator z XMLF-P1-04.
  - Response DTO: nie zwraca token_hash ani delivery.auth.encrypted_password (jak webhookSecret w APIC — sekrety nigdy nie wracają). Zwraca cached_* (rozmiar/liczba/data), status, next_run (jeśli schedule).
  - Błędy w RFC 7807 Problem Details (CLAUDE.md §9); duplikat code w tenant → 409/422 z czytelnym komunikatem; nieistniejący id → 404.
  - Rejestracja tras w CustomRouteOpenApiFactory (jeśli wymaga jawnego wpisu) + regeneracja docs/api-spec/v0.json (bramka OpenAPI spec drift zielona).
  - Autoryzacja: endpointy uwierzytelnione (sesja/RBAC), NIE public — publiczny URL feedu to osobny ticket w M3. Weryfikacja że TenantContext filtruje zapytania (feed cudzego tenanta = 404).
- **Poza zakresem:**
  - Publiczny endpoint serwujący feed GET /feeds/{token}.xml (milestone delivery M3 — PUBLIC_ACCESS + token)
  - Preview endpoint POST /api/feeds/{id}/preview i /api/feeds/preview (milestone preview)
  - Regenerate-now endpoint (milestone harmonogram/delivery)
  - Głęboka walidacja zawartości descriptora (XMLF-P1-04 — tu tylko wpięcie jego validatora do create/update)
  - Mint/rotate/revoke tokenu (milestone delivery M3 — tu token_hash tworzony placeholderem/pustym przy create, właściwy lifecycle później)
  - FE hub/kreator (milestone FE — ten ticket dostarcza kontrakt API pod nie)
  - RBAC permission fine-grained per feed (reuse istniejącego wzorca autoryzacji Export)
- **AC:**
  - [ ] GET /api/feeds zwraca listę feedów tylko bieżącego tenanta z paginacją; feed innego tenanta nigdy nie pojawia się w wyniku (ApiTestCase 2-tenant, cross-read=0).
  - [ ] POST /api/feeds tworzy FeedProfile z walidowanym payloadem; duplikat (tenant,code) → 409/422 Problem Details; brak wymaganego pola → 422 z listą pól.
  - [ ] PUT/PATCH /api/feeds/{id} aktualizuje profil; GET /api/feeds/{id} cudzego tenanta → 404 (nie 403).
  - [ ] POST /api/feeds/{id}/pause ustawia status=paused, /resume ustawia status=active; odpowiedź odzwierciedla nowy status.
  - [ ] DELETE /api/feeds/{id} usuwa profil (kaskadowo runy/logi z P1-02); zwraca 204.
  - [ ] Żadna odpowiedź API nie zawiera token_hash ani delivery.auth.encrypted_password (test kontraktu odpowiedzi).
  - [ ] docs/api-spec/v0.json zawiera wszystkie nowe trasy /api/feeds* z poprawnymi schematami; bramka 'OpenAPI spec drift' zielona.
  - [ ] ApiTestCase pokrywa każdy endpoint (happy path + 404 + walidacja + izolacja); PHPStan max + Deptrac zielone.
  - [ ] CRUD wystawia i waliduje `validation_policy`.
  - [ ] Endpoint agregatów KPI hubu (active/paused/error, itemsSyndicated) zwraca realne dane per tenant.
- **Smoke:**
  - pnpm stack:up; zaloguj się na https://pim.localhost (DevTools Network otwarte).
  - curl -b cookie POST /api/feeds z minimalnym payloadem (code, name, template_kind=custom, locale, currency) → 201 z body feedu bez token_hash.
  - GET /api/feeds → 200, lista zawiera utworzony feed; GET /api/feeds/{id} → 200.
  - POST /api/feeds/{id}/pause → 200 status=paused; POST /api/feeds/{id}/resume → 200 status=active.
  - POST duplikatu code → 409/422; DELETE /api/feeds/{id} → 204; kolejny GET → 404.
  - Potwierdź brak czerwonych errorów w DevTools Console i że /api/feeds jest w Swagger/OpenAPI docs.
- **Reuse:** apps/api/src/Export/Presentation/Controller/ExportProfileController.php — wzorzec custom #[Route] CRUD kontrolera (CQRS, ADR-0012) · apps/api/src/Export/Presentation/Controller/ExportSessionController.php — wzorzec listy/paginacji i response DTO · apps/api/src/Export/Domain/Repository/ExportProfileRepositoryInterface.php — kontrakt repo używany przez handlery (via FeedProfileRepositoryInterface z P1-01) · apps/api/src/Shared/OpenApi/CustomRouteOpenApiFactory.php — auto-rejestracja custom tras do docs/api-spec/v0.json (ADR-0020) · apps/api/src/ApiConfigurator/Presentation/Controller/ProfileTestController.php — wzorzec ukrywania sekretów w response (webhookSecret nigdy nie wraca)
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.2 (model), §6.9 (sekrety nie wracają), §8 faza 2, §9 (hub/kreator konsumujące API) · docs/adr/0012-cqrs-application-layer.md · docs/adr/0020-openapi-custom-route-documentation.md · CLAUDE.md 'Reguły implementacyjne' pkt 3 (API first-class, hybrid surface), pkt 9 (RFC 7807)
- **DoD:** standard.

### XMLF-P1-04: feat(export): add FeedDescriptor JSONB shape validation
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M1 · **Est:** 6-9h · **Risk:** medium · `[PM]`
- **Blocked by:** XMLF-P1-01 · **Blocks:** XMLF-P1-03, XMLF-P5-01
- **Po co:** descriptor to deklaratywny JSONB opisujący strukturę wyjścia XML feedu (root/channel/item/slots, plan §6.3). Jeśli pozwolimy zapisać malformed descriptor, silnik feedu (M2) wybuchnie w runtime na workerze albo — gorzej — wygeneruje niepoprawny XML odrzucony przez marketplace. Ten ticket wprowadza value object + walidator, który odrzuca zły kształt descriptora JUŻ NA ZAPISIE (create/update), przesuwając błąd z runtime do momentu konfiguracji (fail-fast, zasada deklaratywności z APIC). To kontrakt kształtu, na którym oprze się cała reszta silnika, dlatego blokuje descriptor/szablony i generator w M2.
- **Stan obecny:** Nie ma żadnej reprezentacji descriptora XML w kodzie — plan §6.3 definiuje jego kształt (root z element/attributes/namespaces; channel.header[]; item.slots[] z target/node/source/required/maxLength/transform/html; węzły element|attribute|repeatable|keyvalue; wrapIn). ExportSession.filterSnapshot pokazuje wzorzec przechowywania złożonego JSONB, ale bez walidacji struktury. XMLF-P1-01 dostarcza kolumnę descriptor JSONB; XMLF-P1-03 woła ten validator w create/update.
- **Zakres:**
  - Value object FeedDescriptor w apps/api/src/Export/Domain/Feed/Descriptor/ (immutable, fromArray(array): self / toArray(): array) reprezentujący root + channel/header + item + slots, z sub-VO: DescriptorNode, DescriptorSlot (target, node kind, source, required, requiredOneOf, maxLength, html policy, transform ref), SourceSpec (kind attribute|static|template + ref/value).
  - Node kinds jako enum FeedNodeKind: element, attribute, repeatable, keyvalue, wrapIn (plan §6.3) — walidacja że node ma dozwolony kind.
  - Walidator FeedDescriptorValidator (Symfony Validator Constraint + ConstraintValidator, wzorzec repo) sprawdzający: root ma element (string, legalna nazwa XML — regex NCName); namespaces to mapa prefix→URI; każdy slot ma target (legalna nazwa elementu/atrybutu XML) i node z FeedNodeKind; source.kind ∈ {attribute,static,template} z wymaganym ref/value; repeatable/wrapIn mają dzieci (children[]); required to bool; requiredOneOf to lista slotów istniejących w item; maxLength dodatni int; html ∈ {escape,cdata,strip}; transform.ref (jeśli jest) to znany kind (zamknięta lista §6.4) — reject descriptora z nieznanym kluczem/typem.
  - Odrzucenie malformed descriptora z czytelną ścieżką błędu (np. 'item.slots[3].node: unknown node kind "foo"') w formacie RFC 7807 przy wpięciu do CRUD (P1-03).
  - Ochrona przed atakami kształtem: głębokość zagnieżdżenia (limit nesting wrapIn/repeatable), maksymalna liczba slotów, brak nielegalnych nazw elementów XML (nazwa z '.' jak description.pl → odrzucona, bo descriptor mapuje na legalne sloty — plan §11 ryzyko element names).
  - Punkt wpięcia: metoda validate(array $descriptor): void wołana z CreateFeedProfile/UpdateFeedProfile (P1-03) oraz gotowa do reużycia przez preview draft (M-preview).
- **Poza zakresem:**
  - Wykonanie descriptora / generowanie XML (FeedGenerator M2)
  - Seed 3 predefiniowanych szablonów (Google/Ceneo/Meta) — to M2, ale ich descriptory MUSZĄ przechodzić ten validator (test regresyjny w M2)
  - Walidacja że source.ref wskazuje istniejący atrybut w katalogu (semantyka, nie kształt — należy do mapowania M2, wymaga cross-BC Contracts)
  - Walidacja per-produkt required/format w trakcie generacji (feed health, M2 §6.5)
  - Implementacja transformacji (§6.4) — tu tylko walidacja że transform.kind jest z dozwolonej listy nazw
  - UI edytora descriptora (milestone FE)
- **AC:**
  - [ ] FeedDescriptor::fromArray odrzuca (rzuca / kolekcjonuje violations) każdy z przypadków: brak root.element, nielegalna nazwa XML w target (np. zawiera '.'), nieznany node kind, source.kind spoza {attribute,static,template}, source=attribute bez ref, source=static/template bez value, transform.ref spoza zamkniętej listy §6.4, requiredOneOf wskazujący nieistniejący slot, przekroczony limit głębokości/liczby slotów.
  - [ ] Poprawny descriptor Google Shopping z §6.3 (przykład w planie) przechodzi walidację bez błędów (test fixture).
  - [ ] Walidator jest wpięty do POST /api/feeds i PUT /api/feeds/{id} (P1-03): zapis feedu z malformed descriptor zwraca 422 RFC 7807 z pointerem do wadliwego węzła; poprawny → 201/200.
  - [ ] toArray() ∘ fromArray() jest round-trip stabilne (VO nie gubi ani nie dodaje pól).
  - [ ] Sanityzacja nazw: descriptor z targetem będącym nielegalną nazwą elementu XML jest odrzucany (nie 'naprawiany' cicho).
  - [ ] PHPStan max (VO w pełni typowane, brak mixed poza granicą fromArray) + PHPUnit ≥80% ścieżek walidacji (positive + każdy negative case).
- **Smoke:**
  - pnpm stack:up; zaloguj się na https://pim.localhost.
  - curl POST /api/feeds z payloadem zawierającym descriptor z nieznanym node kind ('foo') → 422 Problem Details z pointerem do slotu (proof odrzucenia).
  - curl POST /api/feeds z poprawnym descriptorem custom (root rss/channel/item z jednym slotem element source=attribute ref=sku) → 201.
  - curl PUT /api/feeds/{id} podmieniający descriptor na target 'description.pl' (nielegalna nazwa XML) → 422.
  - Potwierdź brak 500 (walidacja łapie błąd zanim dojdzie do persystencji) i brak czerwonych errorów w Console.
- **Reuse:** apps/api/src/Export/Domain/Entity/ExportSession.php — wzorzec przechowywania złożonego JSONB (filterSnapshot) jako punkt odniesienia dla descriptor · apps/api/src/Export/Application/Builder/ColumnDefinition.php — wzorzec value object opisującego strukturę kolumny/węzła (key/kind/code/locale/channel) · apps/api/src/Catalog/Application/Filter/FilterDslResolver.php — wzorzec walidacji/rozwiązywania deklaratywnego JSONB DSL (grouped shape, reserved paths) jako inspiracja walidatora kształtu
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.3 (kształt descriptora + node kinds element/attribute/repeatable/keyvalue/wrapIn), §6.4 (zamknięta lista transformacji), §11 (ryzyko nazw elementów XML z code.locale) · docs/adr/0012-cqrs-application-layer.md · CLAUDE.md 'Reguły implementacyjne' pkt 4 (authoritative JSONB shape) + pkt 9 (RFC 7807)
- **DoD:** standard.


# M2 — Descriptor, szablony, silnik

### XMLF-P2-01: feat(export): add FeedDescriptor value object with slot model and per-slot validation rules
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M2 · **Est:** 6-9h · **Risk:** low
- **Blocked by:** — · **Blocks:** XMLF-P2-02, XMLF-P2-03, XMLF-P2-04, XMLF-P2-05, XMLF-P2-07, XMLF-P3-01, XMLF-P6-01
- **Po co:** Descriptor to serce deklaratywnego konfiguratora XML (plan §6.3): jeden immutable value object opisuje strukturę wyjścia feedu (root + namespaces + channel/header + item slots), który UI edytuje, a runtime wykonuje. Bez ustandaryzowanego, walidowanego kształtu descriptora nie da się seedować szablonów (P2-02), sterować writerem (P2-05) ani walidować itemów w generatorze (P2-04). Model slotów (element/attribute/repeatable/keyvalue/wrapIn) + reguły (required/requiredOneOf/maxLength/format) odwzorowuje wprost TEMPLATE_SLOTS z designu, więc FE mapper i BE mówią jednym słownikiem.
- **Stan obecny:** Silnik Export produkuje flat array<string,string> per produkt (ExportBuilder), a writery są pozycyjne (RowWriter: writeHeaders/writeRow po indeksie) — nie znają nazw elementów ani struktury zagnieżdżonej. Brak jakiegokolwiek modelu descriptora XML; ExportFormat zna tylko xlsx/csv. Kształt slotów istnieje wyłącznie w mockupie FE (feed-data.jsx TEMPLATE_SLOTS) — nic po stronie BE.
- **Zakres:**
  - Nowy immutable VO App\Export\Feed\Domain\Descriptor\FeedDescriptor: root {element, attributes, namespaces}, channel {element, header[], item{element, slots[]}}.
  - VO FeedSlot: target (nazwa slotu, np. g:id / o@id / imgs.i / attrs.a), node ∈ {element, attribute, repeatable, keyvalue}, opcjonalny parent (dla node=attribute, np. 'o'), opcjonalny wrapIn (grupujący element, np. imgs / attrs / g:shipping).
  - VO SlotValidationRule per slot: required(bool), requiredOneOf(list<string> nazw slotów w grupie), maxLength(?int), html ∈ {escape, cdata, strip}, format ∈ enum SlotFormat {text, html, url, price, number, date, enum, category}, enums(?list<string>) dla format=enum.
  - Enum SlotNodeKind {Element, Attribute, Repeatable, Keyvalue} i SlotFormat {Text, Html, Url, Price, Number, Date, Enum, Category} + Wysiwyg n/a.
  - fromArray(array): self / toArray(): array — deserializacja z/serializacja do JSONB feed_profiles.descriptor; twarda walidacja kształtu (nieznany node → wyjątek InvalidDescriptorException; requiredOneOf referuje istniejące targety; attribute wymaga parent; enum wymaga niepustego enums).
  - Walidacja legalności nazw elementów/atrybutów XML (NCName) na etapie fromArray — descriptor nie może zawierać nazwy niedozwolonej przez XML 1.0.
  - Metody nawigacyjne: rootNamespaces(): array<string,string>, headerNodes(): list<...>, itemSlots(): list<FeedSlot>, findSlot(target): ?FeedSlot, requiredOneOfGroups(): list<list<string>>.
- **Poza zakresem:**
  - Seedowanie konkretnych szablonów Google/Ceneo/Meta (to P2-02).
  - Field mappings i transformacje (to P2-03) — descriptor opisuje STRUKTURĘ i REGUŁY, nie źródło wartości.
  - Wykonanie descriptora / składanie XML (XmlFeedWriter — P2-05).
  - Egzekwowanie required przy generacji (FeedGenerator — P2-04).
  - Persystencja / encja FeedProfile (M1).
- **AC:**
  - [ ] FeedDescriptor::fromArray(FeedDescriptor::toArray($d)) === $d dla każdego z 4 kształtów (google_shopping/ceneo/meta/custom) — round-trip stabilny.
  - [ ] node=attribute bez parent, node=enum bez enums, requiredOneOf wskazujący nieistniejący slot → InvalidDescriptorException z czytelnym komunikatem.
  - [ ] Nazwa slotu z niedozwolonym znakiem XML (np. 'description.pl' jako element) odrzucona przez walidację NCName.
  - [ ] SlotFormat i SlotNodeKind pokrywają 1:1 wartości node/fmt z feed-data.jsx TEMPLATE_SLOTS (element/attribute/repeatable/keyvalue; text/html/url/price/number/date/enum/category).
  - [ ] PHPUnit ≥80% pokrycia dla FeedDescriptor/FeedSlot/SlotValidationRule + testy tablicowe wszystkich reguł.
  - [ ] Deptrac: VO żyje w Export\Feed\Domain, zero zależności do Channel/Catalog App.
- **Smoke:**
  - Ten ticket to pure VO bez endpointu/UI — smoke ograniczony do CI (PHPUnit zielony).
  - Uruchom nowy test round-trip lokalnie przez CI (kernel-suite nie odpala się na hoście) i potwierdź zielony job.
- **Reuse:** apps/api/src/Export/Infrastructure/Writer/RowWriter.php — pozycyjny kontrakt, uzasadnia dlaczego descriptor potrzebuje NAZW slotów (nowy model, nie rozszerzenie RowWriter) · apps/api/src/Export/Application/Builder/ValueSerializer.php — MULTI_VALUE_GLUE i konwencje serializacji, nad którymi descriptor/reguły operują (kontekst, nie zależność) · docs/api/jsonb-schemas.md — autorytatywny kontrakt kształtu pól JSONB envelope, do którego descriptor musi być zgodny przy odczycie wartości
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.3 (descriptor + 3 typy węzłów + wrapIn), §5 pkt 1-3 (reguły Google/Ceneo/Meta), §11 (nazwy elementów z code.locale) · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-data.jsx — TEMPLATE_SLOTS (node/required/requiredOneOf/maxLength/fmt/enums/parent) · docs/adr/ (ADR-0023 draft — umiejscowienie konfiguratora XML, §12 planu)
- **DoD:** standard.

### XMLF-P2-02: feat(export): seed three built-in feed templates plus blank/clone custom template
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M2 · **Est:** 8-12h · **Risk:** medium
- **Blocked by:** XMLF-P2-01 · **Blocks:** XMLF-P2-04, XMLF-P2-06, XMLF-P3-02, XMLF-P6-01
- **Po co:** MVP obiecuje 3 gotowe szablony marketplace (Google Shopping / Ceneo / Meta) + custom, żeby klient bez developera zbudował feed w 5 minut (plan §6.5, decyzja produktowa #2). Szablony = is_built_in deskryptory + domyślne mapowania trzymane W KODZIE (nie w tabeli DB — mniej migracji, wersjonowanie w kodzie, plan §6.2). Każdy szablon niesie własną tabelę slotów z regułami walidacji specyficznymi dla odbiorcy (Google price '123.45 PLN', Ceneo avail enum 0/1/2, Meta 'in stock' ze spacją). To fundament pod 'Utwórz feed z szablonu' (P2-06) i walidację health (P2-04).
- **Stan obecny:** Kształt slotów i domyślnych mapowań istnieje tylko w mockupie FE (feed-data.jsx: TEMPLATE_SLOTS + DEFAULT_MAPPINGS + FEED_TEMPLATES). Po stronie BE brak jakiejkolwiek definicji szablonu; FeedDescriptor VO (P2-01) dostarcza kontener, ale nie ma jeszcze konkretnych instancji dla żadnego kanału.
- **Zakres:**
  - Interfejs FeedTemplate: kind(): FeedTemplateKind, isBuiltIn(): bool, descriptor(): FeedDescriptor, defaultMappings(): list<FeedFieldMapping-array> (kształt mappingu z P2-03, tu jako dane), requiredSlots()/slotRules() wyprowadzone z descriptora.
  - Enum FeedTemplateKind {GoogleShopping, Ceneo, Meta, Custom} (wartości google_shopping/ceneo/meta/custom — zgodne z template_kind w feed_profiles i FEED_TEMPLATES.kind z designu).
  - GoogleShoppingTemplate: root rss version=2.0 + ns g:, channel/item, 13 slotów z feed-data.jsx (g:id required maxLen50, g:title maxLen150, g:description html=cdata maxLen5000, g:availability enum in_stock/out_of_stock/preorder/backorder, g:price fmt price, g:gtin/g:mpn requiredOneOf, g:google_product_category fmt category, g:item_group_id) + DEFAULT_MAPPINGS.google_shopping.
  - CeneoTemplate: root offers version=1, item o z atrybutami (id/url/price/avail/stock — node=attribute parent=o; avail enum 0/1/2/3/7/14), elementy name(maxLen200)/cat(cdata,category)/desc(cdata), imgs.main(element required url) + imgs.i(repeatable url wrapIn=imgs), attrs.a(keyvalue wrapIn=attrs) + DEFAULT_MAPPINGS.ceneo.
  - MetaTemplate: wariant Google — g:id maxLen100, g:title maxLen200, g:description maxLen9999, g:availability enum 'in stock'/'out of stock'/'preorder', g:additional_image_link repeatable, g:brand/g:condition required + DEFAULT_MAPPINGS.meta.
  - CustomTemplate: is_built_in=false — descriptor blank (root definiowalny) LUB sklonowany z wybranego predef; domyślnie 10 slotów neutralnych z feed-data.jsx custom (sku/name/ean/price_net/price_gross/currency/stock/category/url/image) + DEFAULT_MAPPINGS.custom.
  - FeedTemplateRegistry: byKind(FeedTemplateKind): FeedTemplate, all(): list<FeedTemplate> — do listowania szablonów w kreatorze i do klonowania (P2-06).
  - Test kontraktowy: descriptor każdego wbudowanego szablonu przechodzi FeedDescriptor::fromArray bez wyjątku; wszystkie ref w defaultMappings odnoszą się do istniejących slotów szablonu.
- **Poza zakresem:**
  - Zapis szablonu do FeedProfile / klonowanie do encji (to P2-06 — 'Utwórz feed z szablonu').
  - Tabela DB feed_templates (świadoma decyzja: stałe w kodzie, plan §6.2).
  - Allegro jako predef (hook §7).
  - FE tiles wyboru szablonu (M5).
  - Endpoint listujący szablony (dołoży go P2-06 lub M5, jeśli potrzebny do kreatora).
- **AC:**
  - [ ] FeedTemplateRegistry->all() zwraca dokładnie 4 szablony (google_shopping, ceneo, meta, custom); byKind zwraca właściwy dla każdego kind.
  - [ ] Sloty każdego szablonu 1:1 odpowiadają TEMPLATE_SLOTS z feed-data.jsx (liczba, node, required/requiredOneOf, maxLength, fmt, enums, parent) — asercja tablicowa per szablon.
  - [ ] defaultMappings każdego szablonu 1:1 odpowiadają DEFAULT_MAPPINGS z feed-data.jsx (slot, source.kind, source.ref/value, transform.kind + params).
  - [ ] Ceneo: id/url/price/avail/stock mają node=attribute parent='o'; imgs.i ma node=repeatable wrapIn='imgs'; attrs.a ma node=keyvalue wrapIn='attrs'.
  - [ ] Descriptor każdego szablonu przechodzi FeedDescriptor::fromArray bez wyjątku (test kontraktowy).
  - [ ] PHPUnit ≥80% dla templates + registry.
  - [ ] Deptrac: templates w Export\Feed\Domain/Application, brak zależności do Channel/Catalog App.
- **Smoke:**
  - Ten ticket to dane w kodzie bez UI/endpointu — smoke ograniczony do CI (PHPUnit zielony na testach kontraktowych szablonów).
  - Po dostarczeniu P2-06 pełny smoke (feed z szablonu → URL 200 → xmllint) potwierdzi poprawność seedów end-to-end.
- **Reuse:** apps/api/src/Channel/Domain/Entity/ChannelPublicationProfile.php — column_aliases (attr-code->output header) jako wzorzec kształtu domyślnego mapowania; zalążek mapowania z publication profile (plan §6.4) · apps/api/src/Export/Domain/Entity/ExportProfile.php — wzorzec is_built_in / konfiguracji profilu, kalka pod predef descriptory · apps/api/src/Channel/Domain/Entity/ChannelCategoryNode.php — getExternalCode() jako źródło ID kategorii dla slotów category (Ceneo cat / g:google_product_category)
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.5 (3 szablony + reguły), §6.2 (predef jako stałe w kodzie), §5 (spec Google/Ceneo/Meta) · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-data.jsx — FEED_TEMPLATES, TEMPLATE_SLOTS, DEFAULT_MAPPINGS · docs/adr/ (ADR-0018 channel publication profile — zalążek mapowania)
- **DoD:** standard.

### XMLF-P2-03: feat(export): add FeedFieldMapping with closed-list transforms over ValueSerializer output
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M2 · **Est:** 8-12h · **Risk:** medium
- **Blocked by:** XMLF-P2-01 · **Blocks:** XMLF-P2-04
- **Po co:** Feed WYMAGA transformacji (świadome odejście od zasady 1:1 z APIC, decyzja produktowa #3): Google żąda price '123.45 PLN', availability jako enum, Ceneo avail jako 0/1/2. FeedFieldMapping wiąże slot descriptora ze źródłem (atrybut/static/template) i JEDNĄ transformacją z zamkniętej listy (plan §6.4). Transformacje działają NAD stringiem z ValueSerializer, więc feed nie duplikuje odczytu JSONB. Ostrzeżenie o niezgodności typu przy zapisie (price na polu nienumerycznym) to zasada iPaaS — łapie błąd konfiguracji zanim zepsuje feed produkcyjny.
- **Stan obecny:** ValueSerializer serializuje ObjectValue JSONB → string (price '{amount} {currency}', multi pipe, null->'', asset->asset_id). Brak jakiejkolwiek warstwy transformacji nad tym wynikiem; kształt mappingu {slot, source, transform} istnieje tylko w feed-data.jsx DEFAULT_MAPPINGS + FEED_TRANSFORMS. FeedDescriptor (P2-01) definiuje sloty i reguły formatu, ale nie łączy ich ze źródłem danych.
- **Zakres:**
  - VO FeedFieldMapping: slot(string), source ∈ {kind:attribute, ref:code} | {kind:static, value} | {kind:template, value:'...{sku}...'} | null (slot niezmapowany), transform?(FeedTransform). fromArray/toArray dla JSONB feed_profiles.field_mappings.
  - Enum FeedTransformKind (zamknięta lista, plan §6.4 + FEED_TRANSFORMS): None, Default (alias Fallback), Price, Number, Date, EnumMap, Concat (alias Template), StripHtml, Truncate.
  - Interfejs FeedTransform::apply(string $serialized, array $context): string + params per transform (Default.value, Price.currency, Number.precision, Date.format, EnumMap.rule/map+default, Template.value z interpolacją pól itemu, Truncate.to).
  - Implementacje: DefaultTransform (wartość gdy puste), PriceTransform (num → '{num.2} {ISO}' z currency feedu/kontekstu), NumberTransform (separator+precyzja), DateTransform (ISO-8601 / format odbiorcy), EnumMapTransform (reguły nazwane: stock>0→in_stock, ceneo_avail, meta_stock + dowolna mapa), ConcatTransform/TemplateTransform (interpolacja {ref} z innych pól itemu), StripHtmlTransform (usuń tagi), TruncateTransform (miękko po słowie do maxLength slotu).
  - TemplateInterpolator: rozwija {sku}/{url_slug}/{store_url}/{currency} w source.template i Concat/Template transform z mapy itemu + zmiennych feedu (store_url, currency).
  - FeedMappingTypeChecker: przy zapisie mappingu porównuje transform z fmt slotu i typem atrybutu źródłowego → lista ostrzeżeń niezgodności (np. 'price transform na atrybucie typu text', 'enum_map bez reguły dla slotu enum'). Zwraca warnings, NIE blokuje zapisu (soft, plan §6.4).
  - FeedTransformFactory: fromArray(transform-array): FeedTransform — walidacja że kind ∈ zamknięta lista (nieznany → wyjątek).
- **Poza zakresem:**
  - Dowolne wyrażenia / skrypty / if-then wieloetapowe (hook §7 — silnik dowolnych transformacji).
  - Odczyt wartości z bazy (to ValueSerializer via ExportBuilder — transformacje operują na gotowym stringu).
  - Egzekwowanie required/requiredOneOf per item (to FeedGenerator P2-04; tu tylko transform + type-check przy zapisie).
  - Persystencja field_mappings na encji (M1 dostarcza kolumnę; tu VO + logika).
  - FE dropdown wyboru transformacji w mapperze (M5).
- **AC:**
  - [ ] PriceTransform: '19.9' + currency PLN → '19.90 PLN'; NumberTransform precision=2: '1234.5' → '1234.50'; TruncateTransform to=150: string 200 znaków przycięty miękko ≤150.
  - [ ] EnumMapTransform reguła 'stock': stock_qty '0' → out_of_stock, '15' → in_stock; reguła 'ceneo_avail' i 'meta_stock' zwracają wartości zgodne z enumami slotów Ceneo/Meta.
  - [ ] StripHtmlTransform: '<p>Wkręt <b>x</b></p>' → 'Wkręt x'; TemplateTransform '{store_url}/p/{url_slug}' interpoluje z mapy itemu.
  - [ ] FeedTransformFactory::fromArray z kind spoza zamkniętej listy → wyjątek.
  - [ ] FeedMappingTypeChecker: price transform na atrybucie type=text zwraca warning; poprawne mapowanie zwraca [] warnings.
  - [ ] Wszystkie 9 transformacji z FEED_TRANSFORMS pokryte testami tablicowymi wejście→wyjście; PHPUnit ≥80%.
  - [ ] Deptrac: transformacje operują na string, zero zależności do Catalog/Channel App (czytają tylko wynik ValueSerializer przekazany jako string).
- **Smoke:**
  - Pure logika bez UI — smoke ograniczony do CI (PHPUnit zielony na macierzy transformacji).
  - Pełny smoke (transform widoczny w wygenerowanym XML → xmllint) po złożeniu z P2-04/P2-05 w feedzie z tokenem.
- **Reuse:** apps/api/src/Export/Application/Builder/ValueSerializer.php — wynik serialize() (price '{amount} {currency}', MULTI_VALUE_GLUE='|', null->'') jest wejściem każdej transformacji; transformacje działają NAD tym stringiem · apps/api/src/Channel/Domain/Entity/ChannelPublicationProfile.php — column_aliases jako zalążek mapowania (pre-wypełnienie field_mappings dla kanału, plan §6.4) · docs/api/jsonb-schemas.md — envelope wartości (price {amount,currency}, envelope {value,locale,channel}) do poprawnego type-check przy zapisie mappingu
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.4 (mapowanie + zamknięta lista transformacji + warning niezgodności), §5 pkt 1 (Google price/enum) · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-data.jsx — FEED_TRANSFORMS, DEFAULT_MAPPINGS (source/transform kształt) · Project Plan/feature-konfigurator-xml-plan.md §11 (ryzyko: 1:1 to za mało dla feedów)
- **DoD:** standard.

### XMLF-P2-04: feat(export): add FeedGenerator orchestrator reusing ExportBuilder with per-item validation
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M2 · **Est:** 14-20h · **Risk:** high · `[PM]`
- **Blocked by:** XMLF-P0-03, XMLF-P0-04, XMLF-P1-01, XMLF-P1-02, XMLF-P2-01, XMLF-P2-02, XMLF-P2-03, XMLF-P2-05 · **Blocks:** XMLF-P3-02, XMLF-P3-03, XMLF-P3-05, XMLF-P4-01, XMLF-P4-02, XMLF-P4-04, XMLF-P5-02, XMLF-P5-03, XMLF-P6-03
- **Po co:** FeedGenerator to orkiestrator spinający cały silnik feedu: z descriptora+mapowań buduje plan kolumn, reużywa ExportBuilder (Generator<array<string,string>> z chunkowaniem + EntityManager::clear()), per item stosuje transformacje i walidację required, oddaje do XmlFeedWriter i persystuje FeedRun + FeedRunLog (plan §6.3, §6.5). To odpowiednik SyncExportRunner/ExportJobHandler, ale z warstwą szablonu. Cross-context: czyta Channel wyłącznie przez Contracts (scope/kategorie), a Export engine reużywa w całości — stąd Plan Mode (CLAUDE.md: >1 bounded context + decyzja architektoniczna). Memory-safe pod FrankenPHP worker mode jest nienegocjowalne (§3.10): flush-then-clear per chunk N=1000, keyset streaming, zero DOM.
- **Stan obecny:** ExportBuilder::build(iterable $objects, ExportSession): Generator yielding array<string,string>; CALLER owns chunking + EntityManager::clear() (CLEAR_INTERVAL=200 w SyncExportRunner). ExportJobHandler (Messenger) ustawia TenantContext, robi per-chunk progress+cancel. Brak orkiestratora feedu; FeedDescriptor (P2-01), templates (P2-02), transformacje (P2-03) i XmlFeedWriter (P2-05) istnieją jako klocki, ale nic ich nie spina w run.
- **Zakres:**
  - PLAN MODE: przed implementacją zaproponuj plan spięcia Export engine + Channel Contracts + seam do ExportBuilder (feed nie ma ExportSession — potrzebny adapter budujący plan kolumn z descriptora/mapowań i przekazujący scope/channelCodes do buildera lub równoległa ścieżka).
  - FeedGenerator (App\Export\Feed\Application): generate(FeedProfile, FeedGenerationContext): FeedRun. Buduje plan kolumn = zbiór atrybutów-źródeł z field_mappings (source.kind=attribute → ref) + built-in (sku/main_image/category) potrzebne przez sloty.
  - Reuse ExportBuilder: iteruj keyset-strony CatalogObject (filtr z FeedProfile.filter przez istniejący selektor/list service), woła build() per strona; EntityManager::clear() per chunk N=1000 (AbstractBatchHandler lub jawny flush-then-clear — custom PHPStan rule flush-bez-clear, CLAUDE.md §3.10).
  - Per item: zastosuj FeedFieldMapping/transformacje (P2-03) do mapy z ExportBuilder → mapa slot→wartość; podłącz external_code kategorii kanału (CHC) do slotów fmt=category via Channel Contracts.
  - Walidacja required/requiredOneOf/maxLength/format per item (reguły z descriptora P2-01). Tryb per feed: skip_invalid (default — item pominięty, warning w FeedRunLog, skipped_count++) | include_with_warning (item trafia, warning zalogowany).
  - Streamowanie do XmlFeedWriter (P2-05): writeHeader(descriptor, context) → writeItem(map) per item → close(); output do php://temp → przekazany dalej (M3 dołoży MinIO PUT; tu generator zwraca strumień/ścieżkę tymczasową + policzone metryki).
  - Persystencja FeedRun (trigger schedule|manual|first_publish, status pending→running→done/error, item_count/skipped_count/warning_count, duration_ms) + FeedRunLog (level info|warning|error, object_sku, slot, message) — kalka ExportSession/ExportLog.
  - Obsługa cancel/error jak ExportJobHandler (ExportCancelledException-analog); TenantContext::set przed odczytem; RLS/TenantFilter aktywny.
  - Preview path: generate() z limitem N=5 i output do pamięci (nie do pliku) → używane przez endpoint podglądu (M5) — gwarancja podgląd==produkcja (plan §6.11).
- **Poza zakresem:**
  - MinIO cache + publiczny URL + token + gzip + ETag (M3 — delivery).
  - Cron/harmonogram + jitter + Mercure progres (M4 — schedule+monitor).
  - XmlFeedWriter internals (P2-05 — dostarcza kontrakt ItemWriter i składanie węzłów).
  - Endpointy REST (CRUD/preview/regenerate) i FE (M5).
  - Nowy selektor produktów — reuse istniejącego list/filter service z filter DSL (plan §6.6).
- **AC:**
  - [ ] FeedGenerator dla FeedProfile z 3 sample produktami produkuje FeedRun status=done, item_count zgodny z liczbą przepuszczonych, skipped_count zgodny z regułami required.
  - [ ] skip_invalid: produkt bez wymaganego g:gtin/g:mpn (requiredOneOf) NIE trafia do feedu, w FeedRunLog jest wpis warning z object_sku + slot; include_with_warning: ten sam produkt trafia + warning zalogowany.
  - [ ] maxLength: g:title >150 znaków → przycięcie (via Truncate transform) LUB warning zgodnie z konfiguracją; format=enum poza słownikiem → warning.
  - [ ] Memory: benchmark generacji ≥10k SKU utrzymuje worker RAM płaski (EntityManager::clear() per 1000 zweryfikowany testem/benchmarkiem; brak DOMDocument).
  - [ ] external_code kategorii kanału (CHC) wstawiany do slotu category; brak mapowania → skip/warning zależnie od required slotu.
  - [ ] TenantContext ustawiony; próba generacji z tenantem A nie widzi produktów tenanta B (cross-read=0).
  - [ ] ApiTestCase/PHPUnit ≥80% dla FeedGenerator (skip vs include, required/requiredOneOf, tenant isolation, cancel/error).
  - [ ] Deptrac: FeedGenerator sięga Export\* bezpośrednio, Channel/Catalog wyłącznie przez *\Contracts\* (Export_Feed_Internals → Export_Contracts + Channel_Contracts + Catalog_Contracts).
- **Smoke:**
  - Login admin@demo.localhost / changeme na pim.localhost.
  - Utwórz FeedProfile z szablonu Google (P2-06) na tenancie demo z realnymi produktami; wywołaj generację (endpoint regenerate/preview z M5 lub bezpośrednio przez console command jeśli dostępny w tej fazie).
  - Sprawdź FeedRun w bazie/monitorze: status=done, item_count>0, skipped_count spójny z produktami bez g:gtin.
  - Pobierz wygenerowany strumień/plik i zwaliduj: xmllint --noout feed.xml → brak błędów (well-formed).
  - DevTools Console/Network przy triggerze: brak 5xx, brak czerwonych errorów.
  - Cross-tenant: zaloguj na drugim tenancie, potwierdź że nie widać feed_runs pierwszego.
- **Reuse:** apps/api/src/Export/Application/Builder/ExportBuilder.php — build(iterable $objects, ExportSession): Generator<array<string,string>>; CALLER owns chunking + EntityManager::clear(); batch-prefetch values/relations/categories per strona — rdzeń produkcji itemów feedu · apps/api/src/Export/Application/Async/ExportJobHandler.php — wzorzec Messenger handler: TenantContext::set, per-chunk progress+cancel, flush-then-clear — kalka pod FeedRun handler · apps/api/src/Export/Application/Sync/SyncExportRunner.php — runToFile + CLEAR_INTERVAL=200 keyset streaming, openWriter — wzorzec pętli generacji do pliku · apps/api/src/Export/Domain/Entity/ExportSession.php — filterSnapshot JSONB + status enum, kalka pod FeedRun; ExportLog (level/message/context) kalka pod FeedRunLog · apps/api/src/Channel/Contracts/ — ChannelPublicationResolverInterface::resolvePublishedCodes/resolvePublishedLocales, ChannelResolverInterface::resolveId — scope/publication feedu przez Contracts (Deptrac seam) · apps/api/src/Channel/Domain/Entity/ChannelCategoryNodeMapping.php — masterCategoryId→channelNodeIds + ChannelCategoryNode.getExternalCode() dla slotów category · apps/api/src/Catalog/Application/Filter/FilterDslResolver.php — resolwuje FeedProfile.filter (flat/grouped DSL) do selekcji asortymentu wchodzącego do feedu
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.3 (FeedGenerator jako 3. warstwa silnika), §6.5 (walidacja skip_invalid/include_with_warning), §6.11 (preview 5 sample), §6.12 (memory/worker mode) · CLAUDE.md §3.10 (AbstractBatchHandler / flush-then-clear / PHPStan rule flush-bez-clear) · docs/adr/ (ADR-0018 publication profile, ADR-0015 bare UUID cross-BC, ADR-0023 draft)
- **DoD:** standard.

### XMLF-P2-05: feat(export): add descriptor-driven XmlFeedWriter implementing ItemWriter contract
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M2 · **Est:** 10-14h · **Risk:** medium
- **Blocked by:** XMLF-P0-03, XMLF-P0-04, XMLF-P2-01 · **Blocks:** XMLF-P2-04, XMLF-P4-04, XMLF-P5-04
- **Po co:** Pozycyjny RowWriter (writeHeaders/writeRow po indeksie) nie zna nazw elementów ani struktury zagnieżdżonej, więc jest niewystarczający dla feed XML sterowanego descriptorem (plan §6.3, mini-ADR §12 pkt 2). XmlFeedWriter implementuje nowy asocjacyjny kontrakt ItemWriter: writeHeader(descriptor, context) składa root+channel+namespaces, writeItem(map) iteruje sloty descriptora i buduje węzły (element/attribute/repeatable/keyvalue/wrapIn) przez XmlWriterCore. To jedyne miejsce, które tłumaczy descriptor na strukturę XML — trzyma CSV/XLSX czyste, a wszystkie writery konsumują ten sam Generator z ExportBuilder.
- **Stan obecny:** M0 dostarczyło XmlWriterCore (streaming XMLWriter + auto-escaping + CDATA + sanityzacja control-chars), kontrakt ItemWriter (writeHeader/writeItem/close) i GenericXmlWriter dla trybu ad-hoc. FeedDescriptor (P2-01) opisuje sloty i węzły. Brakuje writera, który dla descriptora feedu (Google/Ceneo/Meta) składa strukturę: Google rss/channel/item, Ceneo offers/o z atrybutami + imgs/attrs, Meta additional_image_link repeatable.
- **Zakres:**
  - XmlFeedWriter implements ItemWriter (App\Export\Feed\Infrastructure\Writer): konstruowany z FeedDescriptor.
  - writeHeader(FeedDescriptor $descriptor, array $context): deklaracja XML UTF-8, root element z attributes + namespaces (xmlns:g), otwarcie channel + header nodes (title/link/description z source static/template interpolowanym z context: feed_name/store_url).
  - writeItem(array $item): otwórz element item (rss item / Ceneo o) → iteruj sloty descriptora → dla każdego slotu wyciągnij wartość po target z mapy $item (po transformacjach z P2-04) → złóż węzeł wg node: element (<g:title>val</g:title>), attribute (o price='val' na elemencie rodzica), repeatable (galeria → wiele <i url=''> lub <g:additional_image_link>), keyvalue (<a name='Producent'>val</a> — nazwa jako atrybut, wartość jako treść), wrapIn (grupowanie: <imgs> wokół <i>, <attrs> wokół <a>).
  - Obsługa html per slot (escape/cdata/strip) delegowana do XmlWriterCore: fmt=html + html=cdata → CDATA; puste wartości pomijane lub emitowane wg reguły slotu (required już wyegzekwowane w P2-04 — writer tylko składa).
  - Kolejność: atrybuty node=attribute muszą być zapisane PRZED dziećmi elementu item (XMLWriter wymaga writeAttribute przed startElement dziecka) — writer grupuje sloty attribute najpierw.
  - close(): zamknięcie item/channel/root + endDocument; strumień memory-bounded (nigdy DOMDocument, plan §6.12).
  - Deterministyczna kolejność slotów = kolejność w descriptorze (stabilny output dla dyfów/testów).
- **Poza zakresem:**
  - XmlWriterCore (dostarczone w M0 — escaping/CDATA/control-char sanitizer).
  - GenericXmlWriter dla ad-hoc (M0).
  - Orkiestracja generacji / chunking / walidacja required (P2-04 — writer dostaje już zwalidowaną mapę).
  - Transformacje wartości (P2-03 — writer dostaje string po transformacji).
  - MinIO/gzip (M3).
- **AC:**
  - [ ] Google descriptor + 1 item → poprawny <rss version='2.0' xmlns:g='...'><channel>...<item><g:id>...</g:id>...</item></channel></rss>; xmllint --noout czysty.
  - [ ] Ceneo descriptor: <o id='' url='' price='' avail='' stock=''> z atrybutami PRZED dziećmi; <imgs><main url=''/><i url=''/><i url=''/></imgs> (repeatable pod wrapIn); <attrs><a name='Producent'>Klimas</a></attrs> (keyvalue).
  - [ ] Meta descriptor: g:additional_image_link powielony dla galerii (repeatable); availability 'in stock' ze spacją zserializowany poprawnie.
  - [ ] Wartość z ]]> / <script> / znakiem \x0B w mapie itemu → output nadal well-formed (delegacja do XmlWriterCore); test na śmieciowym payloadzie.
  - [ ] Kolejność slotów w output = kolejność w descriptorze (deterministyczny snapshot).
  - [ ] PHPUnit ≥80%: snapshot per szablon (Google/Ceneo/Meta) + test node=attribute-przed-dziećmi + test repeatable/keyvalue/wrapIn.
  - [ ] Deptrac: XmlFeedWriter w Export\Feed\Infrastructure, zależy tylko od XmlWriterCore (Export\Infrastructure) + FeedDescriptor (Export\Feed\Domain).
- **Smoke:**
  - Pure serializacja bez UI — smoke przez CI (PHPUnit snapshot per szablon zielony).
  - Zapisz output snapshot testu do pliku i uruchom xmllint --noout na wygenerowanym XML Google/Ceneo/Meta — brak błędów.
  - Pełny live smoke (feed URL 200 + xmllint) po spięciu z P2-04 + M3 delivery.
- **Reuse:** apps/api/src/Export/Infrastructure/Writer/RowWriter.php — pozycyjny kontrakt (writeHeaders/writeRow), którego XmlFeedWriter NIE może użyć (potrzebne nazwy elementów) — uzasadnia równoległy asocjacyjny ItemWriter · apps/api/src/Export/Infrastructure/Writer/XlsxStreamWriter.php — wzorzec streamingowego writera (OpenSpout, close(), memory-bounded) do naśladowania · apps/api/src/Export/Infrastructure/Writer/CsvStreamWriter.php — wzorzec streamingu do php://temp · apps/api/src/Export/Application/Builder/ValueSerializer.php — MULTI_VALUE_GLUE='|' konwencja rozbicia galerii na repeatable węzły
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.3 (ItemWriter kontrakt + XmlFeedWriter + 3 typy węzłów + wrapIn), §5 pkt 2 (Ceneo element vs atrybut vs repeatable vs keyvalue), §6.9 (well-formed guard) · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-data.jsx — TEMPLATE_SLOTS (node element/attribute/repeatable/keyvalue, parent, fmt) · docs/adr/ (ADR-0023 draft §12 pkt 2 — nowy kontrakt ItemWriter zamiast rozszerzania RowWriter)
- **DoD:** standard.

### XMLF-P2-06: feat(export): create feed from template by cloning built-in descriptor and mappings
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M2 · **Est:** 6-9h · **Risk:** low
- **Blocked by:** XMLF-P2-02 · **Blocks:** —
- **Po co:** Główny punkt startu klienta: 'Utwórz feed z szablonu' klonuje wbudowany descriptor + domyślne mapowania do edytowalnej kopii na FeedProfile.descriptor/field_mappings (plan §6.2, §8 faza 3). Klient dostaje gotowy Google/Ceneo/Meta, a potem edytuje mapowania pod swój katalog — zamiast budować od zera. Custom = blank lub klon predef. To spina templates (P2-02) z modelem feedu (M1) i domyka descriptor→feed przed silnikiem generacji.
- **Stan obecny:** FeedTemplateRegistry (P2-02) dostarcza 4 szablony (descriptor + defaultMappings) w kodzie. Encja FeedProfile (M1) ma kolumny descriptor JSONB i field_mappings JSONB oraz template_kind. Brak operacji, która materializuje szablon do konkretnego FeedProfile — obecnie descriptor/field_mappings powstałyby ręcznie.
- **Zakres:**
  - Command/service CreateFeedFromTemplate: wejście {template_kind, name, code, scope (locale/currency/channel/publication opcjonalnie), filter opcjonalnie}. Pobiera FeedTemplate z registry (P2-02), klonuje descriptor()->toArray() → FeedProfile.descriptor i defaultMappings() → FeedProfile.field_mappings (deep copy, edytowalna kopia — zmiana nie wpływa na predef w kodzie).
  - template_kind=custom: descriptor blank (root definiowalny, minimalny szkielet) LUB clone z podanego source_kind (klonuj predef jako punkt startu).
  - Zalążek mapowania z Channel: gdy podano publication channel, pre-wypełnij/nadpisz field_mappings z channel_publication_profiles.column_aliases (attr-code→output header) via Channel Contracts (plan §6.4) — nakładka na defaultMappings szablonu.
  - Endpoint POST /api/feeds (custom #[Route] lub API Platform) przyjmujący template_kind → tworzy FeedProfile status=paused/draft z rozklonowanym descriptorem+mapowaniami; zwraca profil (bez tokenu — token minting w M3).
  - Walidacja: code unikalny per tenant (unique tenant_id,code jak feed_profiles), template_kind ∈ enum, descriptor po klonowaniu przechodzi FeedDescriptor::fromArray.
  - Rejestracja trasy w CustomRouteOpenApiFactory → regeneracja docs/api-spec/v0.json (bramka OpenAPI spec drift).
  - TenantAssignmentListener ustawia tenant_id na zapisie FeedProfile (nigdy ręcznie).
- **Poza zakresem:**
  - Mint/rotate/revoke tokenu URL feedu (M3 — delivery).
  - Edycja descriptora/mapowań w UI (M5 — kreator/mapper).
  - Faktyczna generacja XML (P2-04 FeedGenerator).
  - Pełne CRUD FeedProfile (list/get/update/delete) jeśli dostarczone w M1 — tu tylko operacja 'from template'.
  - Harmonogram / delivery config (M3/M4).
- **AC:**
  - [ ] POST /api/feeds z template_kind=google_shopping tworzy FeedProfile, którego descriptor JSONB === GoogleShoppingTemplate.descriptor().toArray() i field_mappings === defaultMappings (deep copy).
  - [ ] Edycja field_mappings utworzonego feedu NIE zmienia GoogleShoppingTemplate w kodzie (kopia niezależna).
  - [ ] template_kind=custom bez source → descriptor blank (minimalny root); z source_kind=ceneo → klon Ceneo.
  - [ ] Z podanym publication channel: field_mappings wzbogacone o column_aliases publication profile (Channel Contracts), reszta z szablonu.
  - [ ] code zduplikowany per tenant → 422 RFC 7807; template_kind spoza enum → 422.
  - [ ] Descriptor po klonowaniu przechodzi FeedDescriptor::fromArray (valid).
  - [ ] Nowa trasa widoczna w docs/api-spec/v0.json (OpenAPI snapshot zaktualizowany).
  - [ ] ApiTestCase dla endpointu (200/201 happy path + 422 walidacje + cross-tenant 404/isolation); PHPUnit ≥80%.
- **Smoke:**
  - Login admin@demo.localhost / changeme na pim.localhost.
  - curl -X POST https://pim.localhost/api/feeds z body {template_kind:'google_shopping', name:'GS PL', code:'gs_pl'} + auth → oczekiwane 201, body z descriptor + field_mappings.
  - Sprawdź w DevTools/response że descriptor zawiera root rss + sloty g:id/g:title/g:price i field_mappings mają domyślne source/transform.
  - Powtórz POST z tym samym code → 422 (unikalność per tenant).
  - Cross-tenant: zaloguj na drugim tenancie, GET /api/feeds → nie widać feedu pierwszego (isolation).
  - Uwaga: token/URL feedu i xmllint pojawiają się dopiero po M3+P2-04 (ten ticket nie generuje jeszcze pliku XML).
- **Reuse:** apps/api/src/Export/Domain/Entity/ExportProfile.php — wzorzec encji konfiguracyjnej profilu (kalka FeedProfile.descriptor/field_mappings JSONB) · apps/api/src/Channel/Domain/Entity/ChannelPublicationProfile.php — column_aliases (attr-code→output header) jako zalążek mapowania przy tworzeniu feedu dla kanału (plan §6.4) · apps/api/src/Channel/Contracts/ — ChannelResolverInterface::resolveId, ChannelPublicationResolverInterface::resolvePublishedCodes — odczyt scope/aliasów kanału przez Contracts (Deptrac seam) · apps/api/src/Shared/OpenApi/CustomRouteOpenApiFactory.php — auto-rejestracja custom /api/feeds do docs/api-spec/v0.json (ADR-0020, bramka OpenAPI spec drift)
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.2 (predef klonowane do FeedProfile.descriptor przy 'Utwórz feed z szablonu'), §8 faza 3, §6.4 (zalążek mapowania z publication profile) · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-data.jsx — FEED_TEMPLATES (kind/builtIn), DEFAULT_MAPPINGS · docs/adr/ (ADR-0020 OpenAPI custom route, ADR-0018 publication profile, ADR-0023 draft)
- **DoD:** standard.

### XMLF-P2-07: feat(export): add custom FeedDescriptor structure editor (root/namespaces + slots)
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M2 · **Est:** 12-18h · **Risk:** medium · `[PM]`
- **Blocked by:** XMLF-P2-01 · **Blocks:** XMLF-P3-01, XMLF-P5-06
- **Po co:** Plan §7 wymaga custom feedu „od zera" (nie tylko klon predefa). Krytyk kompletności wskazał, że backlog wspiera wyłącznie klon sztywnego zestawu slotów — brak możliwości zdefiniowania własnej struktury XML. Operator wybrał pełny edytor struktury. Bez tego partner B2B z nietypowym formatem musi czekać na developera.
- **Stan obecny:** P2-01 daje FeedDescriptor VO + model slotów; P2-02 seeduje 3 predef + blank custom (10 sztywnych slotów). Brak API do edycji STRUKTURY: definiowania root/namespaces, dodawania/zmiany nazwy/usuwania slotów i wyboru typu węzła per slot.
- **Zakres:**
  - API edycji struktury custom descriptora: ustaw root (element + atrybuty + namespaces), dodaj/zmień nazwę/usuń slot, ustaw typ węzła per slot (element/attribute/repeatable/keyvalue/wrapIn), reguły per slot (required/requiredOneOf/maxLength/format/html).
  - Walidacja przez P2-01 VO (kanoniczny kształt) — odrzuć niepoprawne nazwy elementów XML, cykliczne wrapIn, duplikaty slotów.
  - Persystencja edytowanej struktury w `FeedProfile.descriptor` (JSONB); tylko dla `template_kind=custom` (predef są is_built_in, niemodyfikowalne strukturalnie — tylko mapowania).
  - Guard: nazwy elementów/atrybutów sanityzowane do legalnych nazw XML 1.0 (bez kropek/spacji; kolizja → błąd walidacji).
- **Poza zakresem:**
  - FE edytora struktury (osobny XMLF-P5-06)
  - Edycja struktury predefiniowanych szablonów (tylko mapowania)
  - Silnik dowolnych transformacji (hook §7)
- **AC:**
  - [ ] API pozwala zdefiniować od zera custom descriptor: root+namespaces + N slotów z typem węzła i regułami.
  - [ ] Walidacja odrzuca nielegalne nazwy XML, duplikaty slotów i cykliczne wrapIn (test).
  - [ ] Zapisany custom descriptor przechodzi przez P2-01 VO bez błędu i jest gotowy dla FeedGenerator.
  - [ ] Predef (google/ceneo/meta) pozostają niemodyfikowalne strukturalnie (próba edycji struktury → 409/422).
- **Smoke:**
  - Utwórz custom feed → zdefiniuj root `<catalog>` + 3 sloty (element `sku`, repeatable `image`, attribute `id` na root) → zapisz.
  - Preview 5-sample → XML zgodny ze zdefiniowaną strukturą, well-formed (`xmllint --noout`).
- **Reuse:** apps/api/src/Export/Feed/... FeedDescriptor VO (XMLF-P2-01) — walidacja kształtu · Project Plan/feature-konfigurator-xml-plan.md §6.3 — typy węzłów descriptora
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.3, §7 (custom „od zera") · Luka #1 z krytyki kompletności backlogu
- **DoD:** standard.


# M3 — Mapowanie, scope, delivery

### XMLF-P3-01: feat(export): feed mapper API (template slots + PIM catalog, PUT field_mappings) with publication-profile seed
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M3 · **Est:** 10-14h · **Risk:** medium
- **Blocked by:** XMLF-P1-02, XMLF-P2-01, XMLF-P2-07 · **Blocks:** XMLF-P4-01, XMLF-P6-02, XMLF-P6-03, XMLF-P6-04
- **Po co:** Krok 3 kreatora feedu (plan §9.2, mapper §9.3) potrzebuje danych z backendu: listy slotów szablonu (z required/format/maxLength) po lewej i katalogu atrybutów PIM danego ObjectType po prawej, plus zapisu mapowań slot→atrybut+transformacja. Bez tego FE mapper nie ma czego renderować ani gdzie zapisać. Zalążek mapowań z ChannelPublicationProfile.column_aliases + allow-listy published_attribute_codes (ADR-0018) daje operatorowi gotowy punkt startu zamiast pustej macierzy — to zasada 'zalążek z publication profile' z planu §6.4.
- **Stan obecny:** FeedProfile (encja + descriptor JSONB + field_mappings JSONB, XMLF-P1) i FeedGenerator (XMLF-P2) istnieją, ale nie ma endpointu wystawiającego sloty descriptora ani katalogu atrybutów PIM, ani walidowanego PUT dla field_mappings. ChannelPublicationProfile.getColumnAliases() (array<string,string> attr-code->output header) i getPublishedAttributeCodes() (?list, null=publish-all, []=nothing) są dostępne przez ChannelPublicationResolverInterface::resolvePublishedCodes(channelCode, objectTypeId, tenant). Descriptor definiuje sloty (target/node/source/required/requiredOneOf/maxLength). FE mapper (feed-mapper.jsx) czeka na kontrakt.
- **Zakres:**
  - GET /api/feeds/{id}/mapper (custom #[Route], authenticated) — zwraca {slots: [...z descriptora FeedProfile: target, node, required, requiredOneOf, maxLength, format/enum], attributes: [...katalog atrybutów PIM dla object_type_id feedu: code, label i18n, type, isLocalized/isScoped]}.
  - GET wariant dla draftu (bez zapisanego feedu): GET /api/feeds/mapper?template_kind=...&object_type_id=... — sloty z seeda szablonu (predef descriptor z XMLF-P2), katalog atrybutów z object_type_id. Umożliwia mapper w kreatorze przed pierwszym zapisem.
  - PUT /api/feeds/{id}/field-mappings — body {field_mappings: [{slot, source: {kind:attribute|static|template, ref?/value?}, transform?}]}; waliduje: slot istnieje w descriptorze, source.ref istnieje w katalogu atrybutów object_type, transform ∈ zamknięta lista; zapis do FeedProfile.field_mappings JSONB.
  - Walidacja niezgodności typu (soft-warning, nie 4xx): transform 'price'/'number' na atrybucie nienumerycznym → response zawiera warnings[] (zasada iPaaS z planu §6.4), zapis mimo to.
  - Seeder mapowań: metoda/serwis seedFromPublicationProfile(FeedProfile) — dla feedu z channel_id/publication_channel bierze ChannelPublicationResolver::resolvePublishedCodes (allow-lista) + ChannelPublicationProfile.columnAliases (attr-code -> output header) i pre-wypełnia field_mappings jako {slot: <alias-lub-slot-dopasowany>, source: {kind:attribute, ref:<attr-code>}} dla atrybutów obecnych w allow-liście; wywoływane przez mapper draft GET gdy field_mappings puste.
  - Reguła bezpieczeństwa z planu §6.9: mapper nie wystawia pól systemowych/wrażliwych — katalog atrybutów po prawej filtrowany allow-listą published_attribute_codes gdy publication_channel ustawiony (null=all, []=nic).
  - ApiTestCase dla GET (saved + draft), PUT (happy path, nieznany slot -> 422, nieznany atrybut -> 422, type-mismatch -> 200 z warnings), seed z publication profile (2 scenariusze: z aliasami / bez).
  - Rejestracja tras w CustomRouteOpenApiFactory (ADR-0020) + regeneracja docs/api-spec/v0.json.
- **Poza zakresem:**
  - Zamknięta lista transformacji jako runtime-executor (należy do XMLF-P2 FeedGenerator; tu tylko walidacja że transform.kind ∈ lista przy zapisie mapowania)
  - AI-assisted mapping / Cmd+K sugestie mapowań (hook, plan §7)
  - FE mapper component (osobny ticket M5, blokowany tym)
  - Filtr asortymentu i scope resolution (XMLF-P3-02)
  - Kategorie kanałowe external_code (XMLF-P3-03)
- **AC:**
  - [ ] GET /api/feeds/{id}/mapper zwraca 200 z tablicą slotów odzwierciedlającą descriptor feedu (target, node, required, requiredOneOf, maxLength, enum jeśli jest) oraz katalogiem atrybutów PIM dla object_type feedu
  - [ ] GET /api/feeds/mapper?template_kind=google_shopping&object_type_id=... (draft) zwraca sloty z seeda predef szablonu bez potrzeby zapisanego feedu
  - [ ] PUT /api/feeds/{id}/field-mappings z poprawnym body zapisuje field_mappings i zwraca 200 z zapisanym stanem
  - [ ] PUT z source.ref nieistniejącym w object_type zwraca 422 (RFC 7807), z nieznanym slotem 422, z transform.kind spoza zamkniętej listy 422
  - [ ] PUT z transform 'price' na atrybucie nienumerycznym zwraca 200 + warnings[] opisujące niezgodność (nie blokuje zapisu)
  - [ ] Dla feedu z publication_channel: draft mapper pre-wypełnia field_mappings z column_aliases + resolvePublishedCodes; atrybuty spoza allow-listy (published_attribute_codes) nie pojawiają się w katalogu
  - [ ] Cross-tenant: GET/PUT mapper dla feedu innego tenanta zwraca 404, field_mappings drugiego tenanta niewidoczne
  - [ ] docs/api-spec/v0.json zawiera 3 nowe trasy; bramka OpenAPI spec drift zielona
- **Smoke:**
  - Zaloguj się na https://pim.localhost (admin@demo.localhost / changeme)
  - curl -s GET /api/feeds/{id}/mapper z Bearer tokenem → 200, w Network body ma slots[] i attributes[]
  - curl PUT /api/feeds/{id}/field-mappings z {field_mappings:[{slot:'g:title',source:{kind:'attribute',ref:'name'}}]} → 200
  - curl GET /api/feeds/mapper?template_kind=ceneo&object_type_id=<product> → 200 ze slotami Ceneo (id/url/price/avail/name/cat/imgs.main)
  - Utwórz feed z channel_id wskazującym publication profile z column_aliases → draft mapper zwraca pre-wypełnione field_mappings
  - DevTools Console: brak czerwonych errorów
- **Reuse:** apps/api/src/Channel/Contracts/ChannelPublicationResolverInterface.php — resolvePublishedCodes(channelCode, Uuid objectTypeId, Tenant): ?list (null=all, []=none) allow-lista atrybutów · apps/api/src/Channel/Domain/Entity/ChannelPublicationProfile.php — getColumnAliases() array<string,string> attr-code->output header (zalążek mapowania), getPublishedAttributeCodes() ?list, isPublishAll() · apps/api/src/Export/Application/Builder/PublicationColumnPlanner.php — plan(ExportSession, $objectTypeIds): ?list — wzorzec wołania resolvePublishedCodes dla allow-listy · apps/api/src/Channel/Contracts/ChannelResolverInterface.php — resolveId(code, Tenant): ?Uuid do rozwiązania channel_id feedu · apps/api/src/Shared/OpenApi/CustomRouteOpenApiFactory.php — auto-rejestracja custom /api/* tras do docs/api-spec/v0.json (ADR-0020) · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-data.jsx — TEMPLATE_SLOTS (kontrakt kształtu slotów: slot/node/required/requiredOneOf/maxLength/fmt/enums) + DEFAULT_MAPPINGS
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.4 (mapowanie + zalążek z publication profile), §9.3 (mapper), §8 faza 5 · ADR-0018 (channel publication profile — column_aliases, published_attribute_codes) · ADR-0020 (OpenAPI custom route documentation) · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-mapper.jsx
- **DoD:** standard.

### XMLF-P3-02: feat(export): persist FeedProfile.filter and apply filter DSL + scope resolution in FeedGenerator
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M3 · **Est:** 8-12h · **Risk:** medium
- **Blocked by:** XMLF-P1-02, XMLF-P2-02, XMLF-P2-04 · **Blocks:** XMLF-P3-03, XMLF-P3-05, XMLF-P4-01, XMLF-P6-02
- **Po co:** Feed to read-only projekcja podzbioru katalogu (plan §6.6): operator decyduje który asortyment wchodzi (filtr, np. 'enabled=true AND completeness>=80% AND category IN Elektronika') i w jakim scope (pojedynczy locale + waluta + opcjonalnie kanał z allow-listą publikacji). Reuse istniejącego filter DSL = zero drugiej implementacji (zasada z EXR-10), a scope przez PublicationColumnPlanner spina feed z ADR-0018.
- **Stan obecny:** FeedProfile ma kolumnę filter JSONB (plan §6.2) + locale/currency/channel_id/publication_channel, ale FeedGenerator (XMLF-P2) generuje na całym object_type bez zawężenia filtrem ani scope. FilterDslResolver (Catalog/Application/Filter) rozwiązuje flat {attr,op,value} lub grouped {operator,conditions[]} do SQL/paramów, z reserved paths (main_image, category, description[.locale], completeness_pct) i custom -> attributes_indexed GIN — ten sam DSL co lista produktów i eksport (ExportSession.filterSnapshot). PublicationColumnPlanner::plan(ExportSession,$objectTypeIds) zwraca allow-listę kolumn przez resolvePublishedCodes. ExportBuilder::build(iterable $objects, ExportSession) konsumuje itemy — trzeba mu podać wyfiltrowany, poscopowany iterable.
- **Zakres:**
  - Persist + odczyt FeedProfile.filter (filter DSL snapshot) — walidacja kształtu przy zapisie CRUD (flat lub grouped, zgodnie z FilterDslResolver); PUT /api/feeds/{id} akceptuje filter w body.
  - FeedGenerator: przed streamowaniem produktów zastosuj FilterDslResolver na FeedProfile.filter → SQL/paramy zawężające zbiór CatalogObject (reserved paths + attributes_indexed GIN), keyset-streaming (plan §6.12, near-snapshot).
  - Scope resolution: FeedProfile.locale (single) + currency (single) przekazane do ExportBuilder/ValueSerializer jako scope wartości (jeden locale, jedna waluta do formatu price); brak multi-locale (multi = wiele feedów, plan §6.6).
  - Channel overlay opcjonalny: gdy FeedProfile.channel_id ustawiony → wartości per-kanał (ObjectValue.channelId); gdy publication_channel ustawiony → allow-lista atrybutów przez PublicationColumnPlanner (?publication semantyka ADR-0018).
  - Mapowanie FeedProfile scope na ExportSession-kompatybilny obiekt/parametry, żeby ExportBuilder i PublicationColumnPlanner (które przyjmują ExportSession) dostały spójny kontekst — bez duplikacji logiki plannera.
  - PHPUnit: FilterDslResolver zastosowany do feed filter (flat + grouped + reserved path category), scope single-locale (drugi locale nie wycieka), channel overlay (wartość channel-specific wygrywa), publication allow-lista (atrybut spoza listy nie w wyjściu).
  - ApiTestCase: PUT /api/feeds/{id} z filter DSL persystuje i regeneracja produkuje węższy zbiór; smoke że item_count feed_run < total katalog.
- **Poza zakresem:**
  - FE AdvancedFilterPanel w kreatorze feedu (M5, reuse istniejącego komponentu; tu tylko BE persist+apply)
  - Multi-locale w jednym feedzie (świadomie poza zakresem — wiele feedów; plan §6.6, §11)
  - Pełna migawka transakcyjna (hook; MVP near-snapshot keyset — plan §6.8)
  - Warianty flat i item_group_id (XMLF-P3-03)
  - Kategorie external_code (XMLF-P3-03)
- **AC:**
  - [ ] PUT /api/feeds/{id} z filter (flat {attr,op,value}) zapisuje FeedProfile.filter; regeneracja produkuje feed tylko z pasujących produktów
  - [ ] Grouped filter {operator:'and',conditions:[...]} z reserved path 'category' i custom attr zawęża zbiór poprawnie (przez FilterDslResolver, attributes_indexed GIN)
  - [ ] FeedProfile.locale=pl → wygenerowany feed zawiera wartości pl, wartości en nie wyciekają
  - [ ] FeedProfile.currency=PLN → transform price formatuje z 'PLN' (spójne z g:price '123.45 PLN')
  - [ ] FeedProfile z channel_id → wartości channel-specific z ObjectValue.channelId mają pierwszeństwo nad globalnymi
  - [ ] FeedProfile z publication_channel → atrybuty spoza resolvePublishedCodes allow-listy nie trafiają do feedu (null=all, []=nic)
  - [ ] feed_runs.item_count odzwierciedla liczbę produktów po filtrze (< total katalogu gdy filtr zawęża)
  - [ ] Generacja keyset-streaming, EntityManager::clear() per chunk — brak wzrostu RAM na 50k SKU (benchmark w M6)
- **Smoke:**
  - Zaloguj się na https://pim.localhost (admin@demo.localhost / changeme)
  - PUT /api/feeds/{id} z filter {attr:'enabled',op:'eq',value:true} → 200
  - Regeneruj feed (POST regenerate z M2/M4) → feed_run done, item_count = liczba enabled produktów
  - Pobierz feed URL (po M3-05) → xmllint --noout feed.xml (well-formed), liczba <item> == item_count
  - PUT filter z locale=en, currency=EUR → regeneracja → g:price w EUR, tytuły angielskie
  - DevTools Console: brak czerwonych errorów
- **Reuse:** apps/api/src/Catalog/Application/Filter/FilterDslResolver.php — resolve flat/grouped filter DSL do SQL; reserved paths main_image/category/description[.locale]/completeness_pct; custom -> attributes_indexed GIN · apps/api/src/Export/Application/Builder/ExportBuilder.php — build(iterable $objects, ExportSession): Generator<array<string,string>>; caller owns chunking + EntityManager::clear() (CLEAR_INTERVAL=200) · apps/api/src/Export/Application/Builder/PublicationColumnPlanner.php — plan(ExportSession, $objectTypeIds): ?list allow-lista kolumn przez resolvePublishedCodes (?publication overlay) · apps/api/src/Export/Application/Builder/ValueSerializer.php — JSONB->string z locale/channel scope; price '{amount} {currency}' · apps/api/src/Channel/Contracts/ChannelResolverInterface.php — resolveId(code, Tenant): ?Uuid dla channel overlay · apps/api/src/Export/Domain/Entity/ExportSession.php — filterSnapshot JSONB (wzorzec persist filter DSL), scope fields dla PublicationColumnPlanner
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.6 (filtr + scope), §6.12 (keyset streaming), §8 faza 5 · ADR-0018 (publication profile allow-lista) · apps/admin/src/lib/filters/filter-dsl.ts (kontrakt FilterCondition/FilterGroup/FilterDsl — FE strona tego samego DSL)
- **DoD:** standard.

### XMLF-P3-03: feat(export): channel-category external_code resolution and flat variants with item_group_id in FeedGenerator
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M3 · **Est:** 10-14h · **Risk:** medium
- **Blocked by:** XMLF-P2-04, XMLF-P3-02 · **Blocks:** —
- **Po co:** Feedy marketplace wymagają ID kategorii odbiorcy (Ceneo <cat>, Google g:google_product_category) — bierzemy je z external_code węzła kategorii kanałowej (CHC), zero edycji per produkt (plan §6.7). Warianty produktowe muszą wejść flat: każdy wariant jako osobny <item> z własnym g:id + item_group_id = SKU mastera, bo Google grupuje warianty przez g:item_group_id (plan §6.6). Bez tego feed Google/Ceneo jest niekompletny i odrzucany przez Merchant Center.
- **Stan obecny:** FeedGenerator (XMLF-P2) generuje płaską listę master-produktów bez rozwiązywania kategorii marketplace ani rozwijania wariantów. ChannelCategoryNode ma getExternalCode(): ?string (znormalizowany, ID w systemie zewnętrznym) + LTREE path. ChannelCategoryNodeMapping mapuje masterCategoryId (Uuid, soft FK do objects kind=category) -> channelNodeIds (JSONB list<string>). Descriptor slotów (feed-data.jsx) ma dla Google slot g:google_product_category fmt:'category' + g:item_group_id, dla Ceneo slot 'cat' fmt:'category'. Warianty: master ma dzieci-warianty (variant_axes w JSONB) — ExportBuilder umie je iterować (wzorzec eksportu flat, PRD-eksportów §8.3).
- **Zakres:**
  - Category resolution service w FeedGenerator: dla produktu → znajdź master category (objects kind=category) → ChannelCategoryNodeMapping.masterCategoryId -> channelNodeIds -> ChannelCategoryNode.getExternalCode() dla kanału feedu; wstaw w slot z fmt='category' (Ceneo <cat>, Google g:google_product_category).
  - Brak external_code / brak mapowania kategorii: zachowanie per slot required — jeśli slot required (Ceneo cat) → skip produktu + warning w FeedRunLog (slot='cat', message); jeśli optional (Google g:google_product_category) → pomiń slot + warning; zgodnie ze skip_invalid/include_with_warning z XMLF-P2.
  - Warianty flat: FeedGenerator rozwija master z wariantami na N itemów — każdy wariant osobny <item> z własnym source (g:id z variant sku), master bez wariantów = 1 item; item_group_id/g:item_group_id source = parent (master) sku dla wariantów, pusty dla samodzielnych produktów.
  - Cross-BC tylko przez Contracts (Deptrac): kategorie kanałowe czytane przez port kontraktowy Channel (dodać/reuse resolver w Channel/Contracts jeśli brak; bare UUID cross-BC per ADR-0015), nie bezpośredni dostęp do ChannelCategoryNode z Export.
  - PHPUnit: category resolved (mapowany węzeł ma external_code -> slot wypełniony), category missing required -> skip+warning, category missing optional -> slot pominięty+warning, wariant master z 3 wariantami -> 3 itemy z item_group_id=master sku, produkt bez wariantów -> 1 item bez item_group_id.
  - ApiTestCase/integration: regeneracja feedu Ceneo z produktami mapowanymi na kategorie -> <cat> zawiera external_code; feed Google -> warianty jako osobne <item> z g:item_group_id.
- **Poza zakresem:**
  - Edytor drzewa kategorii kanałowych i przypisywanie external_code (należy do epiku CHC, tu tylko odczyt)
  - Allegro categoryId jako predef szablon (hook; osiągalny custom descriptorem — plan §7)
  - Warianty jako zagnieżdżona struktura (MVP tylko flat; plan §6.6)
  - Feed dla ObjectType innego niż product (hook; plan §7)
  - Token/URL/delivery (XMLF-P3-04, XMLF-P3-05)
- **AC:**
  - [ ] Produkt zmapowany na węzeł kategorii kanałowej z external_code='123' → feed Ceneo <cat> zawiera '123' (w CDATA), feed Google g:google_product_category zawiera '123'
  - [ ] Produkt bez mapowania kategorii, slot 'cat' required (Ceneo) → produkt pominięty, FeedRunLog warning slot='cat' object_sku=<sku>, skipped_count++
  - [ ] Produkt bez kategorii, slot g:google_product_category optional (Google) → item wygenerowany bez tego slotu, FeedRunLog warning
  - [ ] Master z 3 wariantami → 3 <item>, każdy z unikalnym g:id (variant sku) i g:item_group_id = master sku
  - [ ] Produkt bez wariantów → 1 <item>, g:item_group_id pusty/pominięty
  - [ ] Deptrac: Export/Feed sięga kategorii kanałowych wyłącznie przez Channel/Contracts (0 naruszeń), cross-BC ref jako bare UUID (ADR-0015)
  - [ ] Cross-tenant: external_code i mapowania kategorii tenanta B niewidoczne przy generacji feedu tenanta A
- **Smoke:**
  - Zaloguj się na https://pim.localhost (admin@demo.localhost / changeme)
  - Przygotuj produkt zmapowany na węzeł kategorii kanałowej z external_code; regeneruj feed Ceneo
  - Pobierz feed URL → xmllint --noout feed.xml → 200 well-formed; grep '<cat>' zawiera external_code
  - Utwórz feed Google z produktem-masterem posiadającym warianty; regeneruj
  - xmllint --noout google.xml; policz <item> == liczba wariantów; sprawdź g:item_group_id == master sku
  - Sprawdź FeedRunLog w monitorze (M4) dla produktu bez kategorii → warning widoczny
  - DevTools Console: brak czerwonych errorów
- **Reuse:** apps/api/src/Channel/Domain/Entity/ChannelCategoryNode.php — getExternalCode(): ?string (ID kategorii marketplace), LTREE path · apps/api/src/Channel/Domain/Entity/ChannelCategoryNodeMapping.php — getMasterCategoryId(): Uuid, getChannelNodeIds(): list<string> (mapowanie master->węzły kanału) · apps/api/src/Channel/Contracts/ChannelResolverInterface.php — resolveId(code, Tenant): ?Uuid dla kanału feedu · apps/api/src/Export/Application/Builder/ExportBuilder.php — build(iterable $objects, ExportSession): Generator; iteracja produktów+wariantów, chunking · apps/api/deptrac.yaml — Channel_Contracts jako jedyny dozwolony seam z Export/Feed do kategorii kanałowych · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-data.jsx — sloty fmt:'category' (Google g:google_product_category, Ceneo cat) + g:item_group_id
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.6 (warianty flat item_group_id), §6.7 (kategorie external_code), §5 pkt 2 (Ceneo cat), §8 faza 5 · ADR-0015 (cross-BC bare UUID) · ADR-0018 (publication profile)
- **DoD:** standard.

### XMLF-P3-04: feat(export): feed URL token lifecycle (mint/rotate/revoke) and delivery config with encrypted Basic auth
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M3 · **Est:** 12-16h · **Risk:** high · `[PM]` `[SEC]`
- **Blocked by:** XMLF-P1-02 · **Blocks:** XMLF-P3-05
- **Po co:** Publiczny URL feedu musi być chroniony tokenem, po który crawler marketplace sięga cyklicznie (plan §6.9, decyzja #5). Token feedu to OSOBNY byt od klucza tenanta: per-feed, read-only, revokowalny, nieodgadywalny (>=128-bit), pokazany raz przy mincie. Opcjonalny HTTP Basic (Google Merchant i Ceneo go wspierają) z hasłem szyfrowanym odwracalnie (musimy je porównać z przychodzącym). To fundament bezpieczeństwa publicznego endpointu z XMLF-P3-05 — dlatego security-first, failing-test-first, i Plan Mode (nowa powierzchnia ataku + wzorzec ApiKey adaptowany).
- **Stan obecny:** FeedProfile ma kolumny token_hash VARCHAR i delivery JSONB (plan §6.2), ale brak logiki mint/rotate/revoke ani szyfrowania Basic. Wzorzec do adaptacji: ApiKey (keyHash Argon2id, keyPrefix 12-char indexed dla O(1) lookup, revokedAt, rateLimitPerHour) + Argon2idApiKeyHasher (hash/verify/needsRehash). Token feedu to lżejszy byt: query-param w URL (?token= lub /{token}.xml), per-feed, read-only — NIE reużywamy ApiKey encji, tylko hasher. AesGcmEncryptionService (encrypt(): EncryptedSecret, decrypt(EncryptedSecret): string, libsodium AES-256-GCM, version-keyed) + EncryptedSecret (ciphertext base64, version int) dla Basic password.
- **Zakres:**
  - Token lifecycle na FeedProfile: mint (generuj >=128-bit base62 token, hash Argon2idApiKeyHasher::hash, zapisz token_hash + krótki indeksowany prefix dla O(1) lookup jak keyPrefix; jawny token zwrócony RAZ w response), rotate (nowy token, invaliduje stary), revoke (wyzeruj/oznacz, URL przestaje działać).
  - Endpointy authenticated: POST /api/feeds/{id}/token (mint/rotate — zwraca token raz), DELETE /api/feeds/{id}/token (revoke). Token NIGDY nie wraca w GET /api/feeds/{id} (jak webhookSecret).
  - Delivery config JSONB walidacja + zapis: {gzip: bool, auth: {type: 'none'|'basic', username?, encrypted_password?}}. PUT /api/feeds/{id}/delivery.
  - Basic password: przy zapisie auth.type=basic → hasło szyfrowane AesGcmEncryptionService::encrypt() -> EncryptedSecret (ciphertext+version) w delivery JSONB; nigdy nie wraca plaintextem w API (maskowane jak sekret).
  - Serwis weryfikacji tokenu (constant-time): resolveByToken(rawToken) -> ?FeedProfile — prefix lookup + Argon2id verify; miss zwraca null (do 404 w M3-05, nie 403). Weryfikacja Basic: decrypt delivery password i porównaj (dla użycia w M3-05).
  - FAILING-TEST-FIRST (SEC): najpierw testy — token nieodgadywalny (entropia >=128-bit), token != tenant key (osobny lifecycle), revoke unieważnia, rotate invaliduje poprzedni, hash Argon2id (nie plaintext w DB), Basic password nie wraca w API i jest zaszyfrowany at-rest, constant-time verify (brak timing leak przez path).
  - ApiTestCase: mint zwraca token raz i tylko raz, GET feed nie ujawnia tokenu ani Basic hasła, rotate/revoke happy path, cross-tenant token feedu B nie rozwiązuje feedu A.
- **Poza zakresem:**
  - Publiczny endpoint serwujący feed + PUBLIC_ACCESS + ETag/304/gzip serve (XMLF-P3-05 — konsumuje resolveByToken z tego ticketu)
  - Rate-limit publicznego endpointu (XMLF-P3-05, choć wzorzec ApiKeyRateLimitListener wskazany)
  - FE ekran mint/rotate/copy URL (M5)
  - Push do FTP/SFTP z credentiale (hook — reuse AesGcm + SsrfGuard; plan §7)
  - Redakcja tokenu w access logach (dokumentowany follow-up, plan §11)
- **AC:**
  - [ ] POST /api/feeds/{id}/token generuje token >=128-bit, zwraca jawny token w response DOKŁADNIE raz; kolejny GET/POST nie ujawnia go ponownie
  - [ ] FeedProfile.token_hash to hash Argon2id (weryfikowalny needsRehash), nigdy plaintext; prefix indeksowany dla O(1) lookup
  - [ ] resolveByToken(validToken) zwraca FeedProfile; resolveByToken(wrongToken) zwraca null (constant-time verify)
  - [ ] rotate: stary token przestaje rozwiązywać feed, nowy działa; revoke: token przestaje rozwiązywać (URL dead)
  - [ ] PUT delivery z auth.type=basic zapisuje username + zaszyfrowane hasło (EncryptedSecret ciphertext+version); GET /api/feeds/{id} nie zwraca hasła plaintextem
  - [ ] Token feedu ma osobny lifecycle od ApiKey/klucza tenanta — revoke tokenu feedu nie dotyka żadnego ApiKey
  - [ ] gzip flag i auth config persystują w delivery JSONB i są odczytywalne przez generator (M3-05)
  - [ ] Cross-tenant: token feedu tenanta B nie rozwiązuje żadnego feedu tenanta A (resolveByToken respektuje izolację)
- **Smoke:**
  - Zaloguj się na https://pim.localhost (admin@demo.localhost / changeme)
  - POST /api/feeds/{id}/token → 201/200, response ma pole token (skopiuj); status 200
  - GET /api/feeds/{id} → response NIE zawiera pola token ani plaintext Basic password
  - POST /api/feeds/{id}/token ponownie (rotate) → nowy token; stary token nie zadziała na publicznym URL (weryfikacja pełna w M3-05)
  - PUT /api/feeds/{id}/delivery z {gzip:true, auth:{type:'basic', username:'x', password:'y'}} → 200; GET feed nie ujawnia 'y'
  - DELETE /api/feeds/{id}/token → 204; DevTools Console bez czerwonych errorów
- **Reuse:** apps/api/src/ApiConfigurator/Infrastructure/Security/Argon2idApiKeyHasher.php — hash(rawKey): string, verify(rawKey, hash): bool, needsRehash(hash): bool (reuse hashera dla tokenu feedu) · apps/api/src/ApiConfigurator/Domain/Entity/ApiKey.php — wzorzec keyHash + keyPrefix (12-char indexed O(1) lookup) + revokedAt (adaptacja, NIE reuse encji) · apps/api/src/Shared/Infrastructure/Crypto/AesGcmEncryptionService.php — encrypt(): EncryptedSecret, decrypt(EncryptedSecret): string, needsRotation() (Basic password odwracalny) · apps/api/src/Shared/Application/Crypto/EncryptedSecret.php — ciphertext base64 + version int (wire format sekretu Basic w delivery JSONB) · apps/api/src/ApiConfigurator/Infrastructure/Security/ApiKeyAuthenticator.php — wzorzec prefix lookup -> verify -> expiry/revoke (adaptacja do resolveByToken) · apps/api/src/Shared/OpenApi/CustomRouteOpenApiFactory.php — rejestracja tras token/delivery do docs/api-spec/v0.json
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.9 (token + Basic), decyzja #5, §12 mini-ADR pkt 6, §8 faza 6 · ADR-0016 (api-configurator key format — wzorzec hasha) · ADR-0017 (BYOK encryption strategy — AesGcm) · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-hub.jsx (URL + rotate/revoke akcje)
- **DoD:** standard.

### XMLF-P3-05: feat(export): public cache-and-serve feed URL endpoint with ETag/304, gzip and per-token rate limit
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M3 · **Est:** 14-18h · **Risk:** high · `[PM]` `[SEC]`
- **Blocked by:** XMLF-P2-04, XMLF-P3-02, XMLF-P3-04 · **Blocks:** XMLF-P3-06, XMLF-P5-01, XMLF-P5-03, XMLF-P5-04, XMLF-P6-02
- **Po co:** To domknięcie modelu dostarczania (plan §6.8, decyzja #4): regeneracja streamuje feed do MinIO, a publiczny URL GET /api/feeds/{token}.xml serwuje TYLKO plik z cache — nigdy nie generuje na żądanie (anti-DoS: crawler pulluje 50k SKU feed często). Endpoint bez sesji (PUBLIC_ACCESS), tenant+feed rozwiązywany z tokenu, conditional GET (304) i gzip obowiązkowe (crawler Google/Ceneo je wysyła). To nowa powierzchnia ataku — Plan Mode + security-first.
- **Stan obecny:** Token lifecycle + resolveByToken (constant-time) + delivery config gotowe w XMLF-P3-04. FeedGenerator (XMLF-P2, wzbogacony M3-02/03) generuje XML — trzeba go streamować do MinIO feeds/{tenant}/{feed}.xml[.gz] i zapisać cached_at/cached_file_path/cached_file_size/cached_item_count na FeedProfile. Wzorce: exports.storage (config/packages/flysystem.yaml, aws adapter, path {tenant_id}/{session_id}.{format}); SyncExportRunner::runToFile streamuje do pliku; security.yaml PUBLIC_ACCESS (np. '^/api/assets/[0-9a-f-]+/preview$' — no session, credential IS authority). CustomRouteOpenApiFactory rejestruje custom trasy (ADR-0020). ApiKeyRateLimitListener (per-key sliding window, 429 + Retry-After) jako wzorzec rate-limitu.
- **Zakres:**
  - Regeneracja -> cache: FeedGenerator streamuje wynik do Flysystem feeds/{tenant_id}/{feed_id}.xml (+ .xml.gz gdy delivery.gzip) — chunked, nie load-all (plan §6.12); po sukcesie zapis cached_file_path/cached_file_size/cached_item_count/cached_at na FeedProfile.
  - Publiczny endpoint GET /api/feeds/{token}.xml (+ .xml.gz): rozwiązuje tenant+feed przez resolveByToken (constant-time, miss -> 404 nie 403 — nie zdradzamy istnienia); ustawia TenantContext + GUC app.current_tenant PRZED odczytem; serwuje cached_file_path przez Flysystem readStream (BEZ generacji na żądanie).
  - Nagłówki: Content-Type application/xml; charset=utf-8, Last-Modified={cached_at}, ETag={feed_id}-{cached_at hash}; obsługa If-None-Match/If-Modified-Since -> 304 (pusta odpowiedź); Content-Encoding: gzip gdy Accept-Encoding: gzip i mamy .gz.
  - Opcjonalny HTTP Basic gate: gdy delivery.auth.type=basic -> weryfikuj Authorization Basic (decrypt hasła z EncryptedSecret, porównaj); brak/zły -> 401 WWW-Authenticate Basic.
  - security.yaml: dodaj access_control regułę '^/api/feeds/[A-Za-z0-9]+\.xml(\.gz)?$' roles PUBLIC_ACCESS (no session; token IS authority).
  - Rate-limit per token (sliding window, wzorzec ApiKeyRateLimitListener) + per tenant; przekroczenie -> 429 + Retry-After.
  - Rejestracja trasy w CustomRouteOpenApiFactory (ADR-0020) + regeneracja docs/api-spec/v0.json.
  - FAILING-TEST-FIRST (SEC): endpoint serwuje TYLKO cache (0 zapytań do object_values na request path — anti-DoS), miss token -> 404, cross-tenant token -> 404, brak sesji wymagany (nie 401 auth wall), 304 na If-None-Match z aktualnym ETag, gzip serve, Basic 401 gdy skonfigurowany, rate-limit 429.
- **Poza zakresem:**
  - Generacja on-request (świadomie zakazana — anti-DoS; endpoint tylko serwuje cache, plan §6.8)
  - Harmonogram cron regeneracji + jitter (XMLF-P4 — reuse CronExpressionParser + ScheduleDispatcherService; jitter documented follow-up)
  - Mercure progres regeneracji (XMLF-P4)
  - FE hub URL/copy/status (M5)
  - Push do FTP zamiast pull (hook; plan §7)
  - XXE guard (N/A — feed tylko generuje XML, nie parsuje; guard dojdzie z importem XML — plan §6.9)
- **AC:**
  - [ ] Regeneracja streamuje feed do MinIO feeds/{tenant_id}/{feed_id}.xml (+ .gz gdy gzip); FeedProfile.cached_at/cached_file_path/cached_file_size/cached_item_count zapisane
  - [ ] GET /api/feeds/{validToken}.xml zwraca 200 well-formed XML z Content-Type application/xml; charset=utf-8, ETag, Last-Modified — treść z cache (endpoint nie generuje: 0 odczytów object_values w tym request path)
  - [ ] GET z If-None-Match równym aktualnemu ETag (lub If-Modified-Since >= cached_at) zwraca 304 bez ciała
  - [ ] GET /api/feeds/{invalidToken}.xml zwraca 404 (nie 403); token feedu innego tenanta zwraca 404
  - [ ] GET .xml.gz z Accept-Encoding: gzip zwraca Content-Encoding: gzip i mniejszy payload
  - [ ] Feed z delivery.auth.type=basic: GET bez/ze złym Authorization zwraca 401 WWW-Authenticate Basic; z poprawnym -> 200
  - [ ] Trasa jest PUBLIC_ACCESS w security.yaml (no session) i weryfikuje token PRZED serwowaniem; TenantContext ustawiony z tokenu przed jakimkolwiek odczytem (RLS + TenantFilter aktywne)
  - [ ] Przekroczenie rate-limitu per token -> 429 + Retry-After; docs/api-spec/v0.json zawiera trasę; bramka OpenAPI spec drift zielona
- **Smoke:**
  - Zaloguj się na https://pim.localhost (admin@demo.localhost / changeme), zmint token feedu (M3-04), zregeneruj feed
  - curl -s https://pim.localhost/api/feeds/{token}.xml -o feed.xml -w '%{http_code}' → 200
  - xmllint --noout feed.xml → brak błędów (well-formed XML)
  - curl -I https://pim.localhost/api/feeds/{token}.xml → nagłówki ETag, Last-Modified, Content-Type application/xml obecne
  - curl -H 'If-None-Match: <ETag z poprzedniego>' https://pim.localhost/api/feeds/{token}.xml -w '%{http_code}' → 304
  - curl https://pim.localhost/api/feeds/BADTOKEN.xml -w '%{http_code}' → 404
  - curl --compressed https://pim.localhost/api/feeds/{token}.xml.gz -I → Content-Encoding: gzip (gdy gzip włączony)
  - DevTools Console: brak czerwonych errorów przy pobraniu z UI
- **Reuse:** apps/api/config/packages/security.yaml — PUBLIC_ACCESS access_control pattern ('^/api/assets/[0-9a-f-]+/preview$' — no session, credential IS authority); dodać regułę dla /api/feeds/{token}.xml · apps/api/config/packages/flysystem.yaml — exports.storage aws adapter; ścieżka feeds/{tenant_id}/{feed_id}.xml[.gz] (wzorzec {tenant_id}/{session_id}.{format}) · apps/api/src/Export/Application/Sync/SyncExportRunner.php — runToFile / openWriter wzorzec streamowania do pliku (adaptacja do MinIO stream) · apps/api/src/ApiConfigurator/Infrastructure/Security/ApiKeyRateLimitListener.php — per-key sliding window, 429 + Retry-After (wzorzec rate-limitu per token feedu) · apps/api/src/Shared/Infrastructure/Crypto/AesGcmEncryptionService.php — decrypt(EncryptedSecret): string do weryfikacji Basic password · apps/api/src/Shared/OpenApi/CustomRouteOpenApiFactory.php — rejestracja publicznej trasy feedu do docs/api-spec/v0.json (ADR-0020)
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.8 (cache-and-serve), §6.9 (PUBLIC_ACCESS, izolacja, rate-limit), §6.12 (streaming), decyzja #4, §12 mini-ADR pkt 5-6, §8 faza 6 · ADR-0020 (OpenAPI custom route) · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-hub.jsx (URL feedu, status), feed-monitor.jsx
- **DoD:** standard.

### XMLF-P3-06: feat(export): add feed URL pull telemetry (per-token access counter, 24h aggregate)
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M3 · **Est:** 5-8h · **Risk:** low · `[SEC]`
- **Blocked by:** XMLF-P3-05 · **Blocks:** XMLF-P4-03, XMLF-P5-07
- **Po co:** Zaprojektowany hub i monitor renderują „Pobrania · 24h" (pulls24h) + sparkline, a plan §6.9 wymaga logu dostępu do publicznego URL (bez sekretów). Krytyk kompletności wskazał brak backendu dla tej metryki — bez licznika pobrań KPI i wykrywanie abuse per token nie mają źródła danych.
- **Stan obecny:** P3-05 wystawia publiczny endpoint cache-and-serve + rate-limit per token, ale nie zlicza ani nie agreguje trafień crawlera. FeedRun (P4-03) loguje REGENERACJE, nie POBRANIA — to inna klasa zdarzeń.
- **Zakres:**
  - Lekki licznik dostępu per token przy każdym serwowaniu publicznego URL (inkrement, bez logowania tokenu/sekretów).
  - Agregacja 24h (do KPI hubu i sparkline) + last_pulled_at na feedzie.
  - Współdzielenie z rate-limitem P3-05 (to samo per-token accounting).
  - Redakcja tokenu w logach dostępu (nigdy pełny token w access logu).
- **Poza zakresem:**
  - Pełna analityka geo/UA crawlera (hook)
  - Webhook feed.pulled (hook §7)
- **AC:**
  - [ ] Każde trafienie publicznego URL zwiększa licznik per token; brak sekretów w logu (test).
  - [ ] Endpoint/agregat 24h zwraca liczbę pobrań per feed dla KPI + sparkline.
  - [ ] `last_pulled_at` aktualizowane; widoczne w monitorze feedu.
- **Smoke:**
  - Uderz `GET /api/feeds/{token}.xml` 5× → hub pokazuje 5 pobrań/24h dla feedu.
  - Sprawdź access log — token zredagowany, brak pełnej wartości.
- **Reuse:** apps/api/src/ApiConfigurator/Infrastructure/Security/ApiKeyRateLimitListener.php — per-token accounting · Publiczny endpoint feedu (XMLF-P3-05)
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.9 (log dostępu), §6.8 · Design feed-data.jsx FEED_KPI.pulls24h + sparkline · Luka #2 z krytyki
- **DoD:** standard.


# M4 — Harmonogram, monitor, preview

### XMLF-P4-01: feat(export): feed regeneration scheduling via cron + manual trigger
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M4 · **Est:** 10-14h · **Risk:** medium
- **Blocked by:** XMLF-P2-04, XMLF-P3-01, XMLF-P3-02, XMLF-P4-02 · **Blocks:** XMLF-P6-04
- **Po co:** Feed marketplace jest wartościowy tylko gdy dane w nim są świeże — crawler Google Merchant / Ceneo pobiera plik cyklicznie i oczekuje aktualnego asortymentu, cen i dostępności. Klient musi móc ustawić harmonogram regeneracji (np. co godzinę, codziennie o 03:00) bez developera, a także wymusić regenerację ad-hoc ('Regeneruj teraz') po ręcznej korekcie katalogu. Reużywamy sprawdzony silnik cron z importu (CronExpressionParser + ScheduleDispatcherService) zamiast pisać drugi scheduler — poprawki w jednym miejscu działają na oba obszary (spójne z zasadą reuse z planu §4/§6.8).
- **Stan obecny:** CronExpressionParser (isValid/nextRun/nextRuns/describe PL) i ScheduleDispatcherService (computeNextRun/runNow) istnieją i są używane przez harmonogram importu (VIEW-IMP-04, #502). ImportSchedule ma pola cron/cron_human/enabled/next_run/last_run_*. FeedProfile (M1) ma już kolumnę schedule_cron VARCHAR(64) NULL = tylko manualnie. Brak jakiejkolwiek warstwy planowania regeneracji feedu — feed generuje się wyłącznie przy jawnym wywołaniu FeedGenerator (M2). Brak wbudowanego jittera per-tenant (worker crona dostarczany osobno) — plan §6.8 dokumentuje to jako follow-up.
- **Zakres:**
  - Dodać serwis FeedScheduleService (Export/Feed/Application/Service/) reużywający CronExpressionParser: metody isValidCron, computeNextRun(FeedProfile) zapisujące next_run + human-readable opis PL na profilu, describe() dla podglądu w UI.
  - Walidacja schedule_cron przy zapisie FeedProfile: isValid → jeśli false zwróć RFC 7807 z komunikatem; NULL schedule_cron = feed tylko manualny (bez next_run).
  - Endpoint POST /api/feeds/{id}/regenerate ('Regeneruj teraz') tworzący FeedRun trigger=manual i dispatchujący RunFeedMessage (handler z XMLF-P4-02); zwraca 202 + feed_run_id + Mercure topic do śledzenia.
  - Trigger first_publish: przy utworzeniu FeedProfile (M1 create endpoint) automatycznie enqueue pierwszej regeneracji trigger=first_publish, aby URL feedu miał plik od razu (bez czekania na pierwszy cron tick).
  - Trigger schedule: udostępnić metodę dispatchDue() (FeedProfile z status=active AND schedule_cron NOT NULL AND next_run <= now) enqueue'ującą RunFeedMessage trigger=schedule i przeliczającą next_run — wywoływaną przez cron worker (dostarczany osobno, jak w imporcie).
  - Endpoint GET /api/feeds/{id}/schedule zwracający next_run + kolejne 5 uruchomień (nextRuns) + opis PL (describe) do timeline w UI.
  - Zarejestrować nowe custom trasy w CustomRouteOpenApiFactory (regenerate uses feed_id path param) i zregenerować docs/api-spec/v0.json.
  - Udokumentować w PR body i lessons.md że per-tenant jitter to świadomy follow-up (brak wbudowanego jittera — plan §6.8), a cron worker daemon dostarczany osobnym ticketem infrastruktury (wzorzec VIEW-IMP-04.1).
- **Poza zakresem:**
  - Sam cron worker daemon (long-running proces skanujący due feeds) — dostarczany osobno, jak w imporcie (VIEW-IMP-04.1); ten ticket dostarcza dispatchDue() gotowe do wywołania.
  - Per-tenant jitter (rozłożenie regeneracji feedów w czasie by nie uderzać o 03:00 naraz) — dokumentowany follow-up, plan §6.8.
  - Adaptive scheduling ('regeneruj tylko gdy katalog się zmienił') — hook, plan §7.
  - Wewnętrzna logika samej generacji XML (FeedGenerator) — M2; async handler + progress — XMLF-P4-02.
  - UI harmonogramu (pole cron w wizardzie, timeline) — ekran M5.
- **AC:**
  - [ ] POST /api/feeds/{id}/regenerate zwraca 202 z feed_run_id i tworzy FeedRun o trigger=manual, status=pending.
  - [ ] Zapis FeedProfile z nieprawidłowym schedule_cron (np. '99 99 * * *') zwraca 422 RFC 7807; z poprawnym ustawia next_run i human-readable opis PL.
  - [ ] Utworzenie FeedProfile enqueue'uje FeedRun trigger=first_publish (weryfikowane w ApiTestCase przez licznik feed_runs).
  - [ ] GET /api/feeds/{id}/schedule zwraca next_run (ISO-8601 UTC), listę 5 kolejnych uruchomień i opis PL zgodny z CronExpressionParser::describe.
  - [ ] dispatchDue() enqueue'uje RunFeedMessage tylko dla feedów active + schedule_cron != NULL + next_run <= now i przelicza next_run po enqueue (test z zamrożonym czasem).
  - [ ] FeedProfile innego tenanta nie jest widoczny/regenerowalny (cross-read = 0; TenantFilter + RLS).
  - [ ] docs/api-spec/v0.json zawiera nowe trasy regenerate/schedule (bramka OpenAPI drift zielona).
  - [ ] Enum trigger FeedRun (schedule|manual|first_publish) pokryty testem — każda ścieżka ustawia właściwą wartość.
- **Smoke:**
  - Zaloguj się na https://pim.localhost (admin@demo.localhost / changeme).
  - Utwórz feed z szablonu Google Shopping ze schedule_cron '0 * * * *'; sprawdź w DevTools Network że POST create zwraca 201 i że natychmiast powstaje FeedRun first_publish.
  - GET /api/feeds/{id}/schedule → sprawdź że zwraca next_run i opis 'co godzinę' (PL).
  - Kliknij 'Regeneruj teraz' (lub curl POST /api/feeds/{id}/regenerate z tokenem sesji) → status 202, feed_run_id w body.
  - Poczekaj na dokończenie runu, pobierz publiczny URL feedu → 200 well-formed XML; zweryfikuj xmllint --noout na pobranym pliku (exit 0).
  - DevTools Console bez czerwonych errorów.
- **Reuse:** apps/api/src/Import/Application/Service/CronExpressionParser.php — reuse 1:1 do isValid/nextRun/nextRuns/describe(PL) dla schedule_cron feedu · apps/api/src/Import/Application/Service/ScheduleDispatcherService.php — wzorzec computeNextRun + runNow (stamp run row + refresh next_run); FeedScheduleService to kalka · apps/api/src/Import/Domain/Entity/ImportSchedule.php — wzorzec pól cron/cron_human/enabled/next_run/last_run_* + index (tenant_id, enabled, next_run) dla planowania feedu · apps/api/src/Shared/OpenApi/CustomRouteOpenApiFactory.php — auto-rejestracja custom tras regenerate/schedule do docs/api-spec/v0.json (ADR-0020)
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.8 (delivery + harmonogram, jitter follow-up), §6.2 (FeedProfile.schedule_cron), §4 (reuse Schedule) · ADR-0018 (channel publication profile — scope), ADR-0020 (OpenAPI custom route) · VIEW-IMP-04 (#502) — wzorzec cron parser + dispatcher w imporcie
- **DoD:** standard.

### XMLF-P4-02: feat(export): async FeedRunHandler with per-chunk progress and Mercure SSE
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M4 · **Est:** 12-16h · **Risk:** medium
- **Blocked by:** XMLF-P1-01, XMLF-P1-02, XMLF-P2-04 · **Blocks:** XMLF-P4-01, XMLF-P4-03
- **Po co:** Regeneracja feedu 50k SKU nie może blokować requestu HTTP ani zabić workera na OOM — musi biec asynchronicznie przez Messenger, streamować do MinIO z memory-bounded chunkowaniem, i raportować postęp na żywo do UI (pipeline zapytanie→serializacja→walidacja→zapis→gotowe z designu feed-monitor.jsx). Klient patrzący na 'Regeneracja w toku' musi widzieć progres per chunk i móc anulować długi run. To dokładny odpowiednik async eksportu (ExportJobHandler), przeniesiony na feed z warstwą szablonu.
- **Stan obecny:** ExportJobHandler (Messenger, dziedziczy AbstractBatchHandler, TenantContext::set, idempotency guard na status pending, per-chunk onChunk z progress+ETA+cancel przez odczyt statusu z DB, upload do MinIO, reload encji po EM clear) jest wzorcem 1:1. ExportProgressPublisher publikuje progress/status do dwóch prywatnych topików Mercure (exportUser + exportSession) przez MercureSubscribeTopics. MercureSubscribeTopics ma exportSession/exportUser ale NIE ma feed-runs topiku. ExportCancelledException istnieje. FeedGenerator (M2) już streamuje XML do MinIO i zapisuje FeedRun+logi, ale nie ma async wrappera ani progressu Mercure. Design feed-monitor.jsx pokazuje topic 'feed-runs.{feed_id}' i 5-stage pipeline.
- **Zakres:**
  - RunFeedMessage (Export/Feed/Domain/Message/) niosący feedRunId (+ tenant-aware, wzorzec TenantAwareMessage) na kolejce transportu 'import' (dev worker bierze 'import'+'scheduler_maintenance', patrz memory export-async-dev-worker-queue).
  - FeedRunHandler (#[AsMessageHandler], extends AbstractBatchHandler) mirror ExportJobHandler: load FeedRun (state survives retries), idempotency guard (skip jeśli status != pending), TenantContext::set z tenanta feedu, markRunning + publish status, wywołanie FeedGenerator z callbackiem onChunk, po sukcesie markDone + ustawienie cached_file_path/cached_at/cached_item_count na FeedProfile, w finally cleanup temp.
  - Per-chunk onChunk: publish progress (items_done, items_total, progress_pct, eta) + odczyt statusu FeedRun z DB (Connection::fetchOne) → jeśli cancelled rzuć FeedCancelledException (wzorzec ExportCancelledException); handler łapie, publish status, log info, bez re-throw.
  - Reload FeedRun/FeedProfile po zakończeniu FeedGenerator (streaming runner czyści EM — IMP2-2.6) przed post-flight status flush.
  - Dodać do MercureSubscribeTopics::feedRun(tenantId, base, feedId) i feedRunDetail(...) — topic 'tenant/{tid}/feeds/{feed_id}/runs' (mirror exportSession), private:true; dodać wpisy do forTenant() (subscribe claim) tak by cookie autoryzowało topic feedów.
  - FeedProgressPublisher (Export/Feed/Application/) mirror ExportProgressPublisher: progress(FeedRun, itemsDone, eta) + status(FeedRun) publikujące do topiku feed-runs.{feed_id}, private:true, hub failure loguje ale nie przerywa (MinIO to source of truth).
  - Endpoint POST /api/feeds/{id}/runs/{runId}/cancel flipujący persisted status FeedRun na cancelled (czytany przez onChunk).
  - Zapewnić memory safety: batchSize 1000, EntityManager::clear per chunk (AbstractBatchHandler); brak DOM (XMLWriter streaming z M0/M2).
- **Poza zakresem:**
  - Sama serializacja XML + descriptor + walidacja required (FeedGenerator) — M2.
  - Trigger schedule/manual/first_publish enqueue (dispatch) — XMLF-P4-01 (ten ticket dostarcza handler który konsumuje RunFeedMessage).
  - Cron worker daemon — osobny ticket infra.
  - FE render pipeline'u i live progressu (feed-monitor.jsx FeedDetail) — ekran M5.
  - History/monitor API (list runów, KPI) — XMLF-P4-03.
- **AC:**
  - [ ] FeedRunHandler przetwarza RunFeedMessage: pending→running→done, ustawia FeedProfile.cached_file_path/cached_at/cached_item_count po sukcesie (ApiTestCase/Integration).
  - [ ] Idempotency: druga dostawa tego samego RunFeedMessage na FeedRun status!=pending jest skipowana (log info), bez wyjątku transition.
  - [ ] onChunk publikuje event 'progress' z items_done/items_total/progress_pct/eta co chunk (weryfikacja przez fake HubInterface zbierający Update'y).
  - [ ] Anulowanie: flip statusu FeedRun na cancelled przez POST cancel → następny onChunk rzuca FeedCancelledException, handler kończy status=cancelled, bez re-throw, publikuje status.
  - [ ] MercureSubscribeTopics::feedRun zwraca 'tenant/{tid}/feeds/{feedId}/runs' i jest w forTenant() subscribe claim; wszystkie Update mają private:true.
  - [ ] Worker RAM płaski dla 50k SKU (benchmark: brak DOM, clear per chunk) — brak flush-bez-clear (PHPStan rule zielony).
  - [ ] Tenant z RunFeedMessage ustawiany w TenantContext przed odczytem; cross-tenant feed run nie jest przetwarzany (cross-read=0).
- **Smoke:**
  - Zaloguj się na https://pim.localhost; upewnij że worker działa (dev: bierze kolejkę 'import').
  - Utwórz feed Google Shopping z filtrem dającym kilka tys. produktów; kliknij 'Regeneruj teraz'.
  - Otwórz DevTools → EventStream/Network: sprawdź połączenie SSE do topiku feed-runs.{feed_id} i eventy progress (progress_pct rosnące) + status done.
  - Po done pobierz publiczny URL feedu → 200 well-formed XML; xmllint --noout na pliku (exit 0), item_count zgodny z FeedRun.item_count.
  - Uruchom ponownie i kliknij Anuluj w połowie → FeedRun status=cancelled, event status cancelled na SSE, brak czerwonego alarmu w Console.
- **Reuse:** apps/api/src/Export/Application/Async/ExportJobHandler.php — wzorzec 1:1 async handlera (AbstractBatchHandler, TenantContext::set, idempotency guard, onChunk progress+cancel, reload po EM clear) · apps/api/src/Export/Application/Async/ExportProgressPublisher.php — wzorzec publikacji progress/status do prywatnych topików Mercure (private:true, hub failure non-fatal) · apps/api/src/Export/Application/Async/ExportCancelledException.php — wzorzec wyjątku anulowania czytanego z persisted status w onChunk · apps/api/src/Shared/Infrastructure/Mercure/MercureSubscribeTopics.php — rozszerzenie o feedRun() topic + wpis w forTenant() (mirror exportSession, tenant-scoped private) · apps/api/src/Shared/Application/AbstractBatchHandler.php — base handler z flushAndClear per chunk (memory-bounded worker, CLAUDE.md §3.10)
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.8 (progres Mercure feed-runs.{feed_id}), §6.12 (worker memory), §6.2 (FeedRun) · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-monitor.jsx (5-stage pipeline, topic feed-runs.{feed.id}, LiveBadge Mercure SSE) · AUD-001 (#1573) tenant-scoped Mercure topics; EXP-06 (#585) async export pattern; memory: export-async-dev-worker-queue
- **DoD:** standard.

### XMLF-P4-03: feat(export): feed run history, monitor and health KPI API
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M4 · **Est:** 10-14h · **Risk:** low
- **Blocked by:** XMLF-P1-02, XMLF-P4-02, XMLF-P3-06 · **Blocks:** XMLF-P5-01, XMLF-P5-07
- **Po co:** Klient musi widzieć czy feedy są zdrowe: globalny monitor (regeneracje 24h, produkty syndykowane, pominięte, błędy — kafelki KPI z feed-monitor.jsx), historię runów per feed, i drill-down do konkretnego runu z logiem per-produkt/slot ('SKU-123: missing g:gtin — skipped'). Bez tego operator nie wie że 146 produktów wypadło z feedu Google przez brak GTIN. To realizuje sekcję 'feed health' z planu (§6.5) i ekrany FeedMonitor/FeedDetail/FeedRunDrilldown z designu.
- **Stan obecny:** FeedRun (item_count/skipped_count/warning_count/status/trigger/file_size/duration/error_message/started_at/completed_at) i FeedRunLog (level/object_sku/slot/message) to encje z M1; FeedGenerator (M2) i FeedRunHandler (P4-02) je wypełniają. Brak jakiegokolwiek API czytającego runy/logi/KPI — dane są w bazie ale niedostępne dla UI. Design feed-monitor.jsx ma RunsTable (global + per-feed przez showFeed prop), 4 kafelki KPI (Regeneracje 24h / Produkty syndykowane / Pominięte 24h / Błędy 24h), FeedRunDrilldown Sheet z tabelą FeedRunLog (SKU/Slot/Komunikat) i kafelkami W-feedzie/Pominięte/Ostrzeżenia/Rozmiar.
- **Zakres:**
  - GET /api/feed-runs — globalna lista runów wszystkich feedów tenanta, cursor-based pagination (>1000), sort started_at DESC, filtr status (all|success|warning|error), zwraca feed name/code, trigger, duration, item_count, skipped_count, warning_count, file_size, status.
  - GET /api/feeds/{id}/runs — lista runów jednego feedu (per-feed historia z FeedDetail), ten sam kształt + pagination.
  - GET /api/feeds/{id}/runs/{runId} — detal runu (kafelki drill-down: item_count/skipped_count/warning_count/file_size/duration/error_message).
  - GET /api/feeds/{id}/runs/{runId}/logs — FeedRunLog drill-down, pagination, filtr level (info|warning|error), pola object_sku/slot/message/level; wykorzystanie indexu idx_feed_run_logs_run.
  - GET /api/feed-runs/kpi — health KPI globalne: regeneracje w oknie 24h, suma produktów syndykowanych (item_count), suma skipped_count 24h, liczba runów error 24h + ostatni error_message (dla kafelka 'feed B2B — brak <price>').
  - GET /api/feeds/{id}/health — per-feed KPI do FeedDetail (produkty, pominięte, rozmiar, next_run, coverage mapped/total) reużywając FeedProfile.cached_* + ostatni FeedRun.
  - Wszystkie zapytania scope'owane tenantem (TenantFilter + RLS), read-only projekcje (żadnych write); RFC 7807 dla 404 (feed/run innego tenanta = 404, nie 403).
  - Zarejestrować trasy w CustomRouteOpenApiFactory i zregenerować docs/api-spec/v0.json.
- **Poza zakresem:**
  - Zapis runów/logów (FeedGenerator + FeedRunHandler) — M2 / XMLF-P4-02.
  - Live progress (Mercure) — XMLF-P4-02 (monitor API to historia + KPI zbiorcze, nie stream).
  - FE ekrany FeedMonitor/FeedDetail/FeedRunDrilldown — M5.
  - Eksport logu do CSV / pobranie pliku feedu (przyciski w drill-downie) — osobny ticket M5/delivery.
  - AI-sugestie mapowania w raporcie katalogu — hook Faza 2.
- **AC:**
  - [ ] GET /api/feed-runs zwraca runy wszystkich feedów tenanta posortowane started_at DESC z cursor-pagination i działającym filtrem status (ApiTestCase per wariant).
  - [ ] GET /api/feeds/{id}/runs zwraca tylko runy tego feedu; run innego feedu/tenanta nie wycieka (cross-read=0).
  - [ ] GET /api/feeds/{id}/runs/{runId}/logs zwraca linie FeedRunLog z object_sku/slot/message/level, filtr level działa, pagination >1000 przez cursor.
  - [ ] GET /api/feed-runs/kpi zwraca poprawne agregaty 24h (regeneracje, produkty syndykowane, skipped, błędy) — test z seedowanymi runami w oknie i poza oknem.
  - [ ] GET /api/feeds/{id}/health zwraca produkty/pominięte/rozmiar/next_run/coverage z cached_* + ostatniego runu.
  - [ ] 404 RFC 7807 dla run/feed nieistniejącego lub innego tenanta (bez ujawniania istnienia).
  - [ ] docs/api-spec/v0.json zawiera wszystkie nowe trasy (bramka OpenAPI drift zielona).
- **Smoke:**
  - Zaloguj się na https://pim.localhost; wygeneruj co najmniej 2 feedy i po kilka runów każdego (część z pominięciami — feed Google z produktami bez GTIN).
  - GET /api/feed-runs (z tokenem sesji) → 200, lista runów obu feedów, sort DESC; dodaj ?status=warning → tylko runy z warningami.
  - GET /api/feed-runs/kpi → 200, sprawdź że skipped/errors zgadzają się z faktycznymi runami z ostatnich 24h.
  - Wybierz run z pominięciami: GET /api/feeds/{id}/runs/{runId}/logs → 200, linie z object_sku/slot='g:gtin'/message='missing required g:gtin — skipped'.
  - Zweryfikuj że publiczny URL feedu wciąż zwraca 200 well-formed XML (xmllint --noout exit 0) i item_count == GET .../runs/{runId}.item_count.
  - DevTools Console bez czerwonych errorów.
- **Reuse:** apps/api/src/Export/Domain/Entity/ExportLog.php — wzorzec encji logu (level/message/context) do czytania FeedRunLog per run/slot · apps/api/src/Export/Application/Async/ExportProgressPublisher.php — kontekst statusów/liczników które monitor agreguje (item/skipped/warning counts) · apps/api/src/Shared/OpenApi/CustomRouteOpenApiFactory.php — rejestracja tras feed-runs/kpi/health do docs/api-spec/v0.json (ADR-0020) · apps/api/src/Catalog/Application/Filter/FilterDslResolver.php — wzorzec cursor-based paginacji list >1000 (RFC 7807 na błędy)
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.5 (feed health, skip_invalid/include_with_warning), §6.2 (FeedRun/FeedRunLog schema) · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-monitor.jsx (FeedMonitor KPI kafelki, RunsTable global+per-feed, FeedRunDrilldown → FeedRunLog SKU/Slot/Komunikat) · ADR-0020 (OpenAPI custom route); CLAUDE.md pkt 9 (cursor pagination >1000, RFC 7807)
- **DoD:** standard.

### XMLF-P4-04: feat(export): live feed preview endpoint with health report
- **Typ:** `feat` · **Cls:** BE · **Milestone:** M4 · **Est:** 10-14h · **Risk:** medium · `[PM]`
- **Blocked by:** XMLF-P2-04, XMLF-P2-05 · **Blocks:** XMLF-P5-04, XMLF-P5-05
- **Po co:** Klient budujący feed w wizardzie musi zobaczyć rzeczywisty XML dla ~5 sample produktów PRZED zapisem i publikacją — inaczej publikuje w ciemno i dowiaduje się o błędach dopiero od crawlera marketplace. Podgląd musi używać DOKŁADNIE tego samego silnika co produkcja (FeedGenerator), żeby 'to co widzisz == to co dostanie crawler' (napis w feed-preview.jsx). Do tego raport zdrowia: które sloty puste / za długie / brak wymaganych pól, ile produktów przejdzie/zostanie pominiętych. To realizuje wizję epik-04 'Preview output' i §6.11 planu. Flag PM: preview draftu przyjmuje descriptor z ciała requestu (cross-context: descriptor + FeedGenerator + walidacja), a gwarancja preview==produkcja to decyzja architektoniczna warta potwierdzenia w Plan Mode.
- **Stan obecny:** FeedGenerator (M2) generuje pełny feed do MinIO z descriptora + mapowań + walidacją required/format i logami. Brak trybu 'in-memory, N=5, bez MinIO'. Design feed-preview.jsx renderuje XmlView (sformatowany/raw), badge 'well-formed', raport zdrowia (valid/total, skipped, per-slot count/level) i projekcję na pełny katalog. FEED_SAMPLE_PRODUCTS.slice(0,5), buildFeedLines/feedHealth to mocki FE — brak realnego endpointu. FilterDslResolver istnieje (BE) do wyboru pierwszych N pasujących do filtra. ExportBuilder zwraca Generator itemów (caller owns chunking) — dla N=5 trywialnie ograniczalny.
- **Zakres:**
  - POST /api/feeds/{id}/preview (feed zapisany) — bierze FeedProfile z bazy, wybiera pierwsze N=5 produktów pasujących do FeedProfile.filter (reuse FilterDslResolver), woła FeedGenerator w trybie preview (output do pamięci, nie MinIO), zwraca sformatowany XML string + raport walidacji.
  - POST /api/feeds/preview (draft, przed zapisem) — przyjmuje w ciele descriptor + field_mappings + filter + template_kind + locale/channel/currency (kształt jak FeedProfile bez persystencji); ta sama ścieżka FeedGenerator z limitem 5 → identyczny output. Walidacja kształtu descriptora (RFC 7807 na malformed).
  - Tryb preview w FeedGenerator: parametr sampleLimit (N=5) + writer do php://temp / string zamiast Flysystem MinIO stream; ZERO rozgałęzień logiki serializacji/walidacji (ta sama pętla slotów, transformacje, required checks) — tylko inne ujście i limit, by zagwarantować preview==produkcja.
  - Raport zdrowia w odpowiedzi: valid/total (ile z N przejdzie), skipped (brak required), per-slot lista {slot, level, count} (ile z N ma slot pusty/za długi/niepoprawny format), zgodnie z feed-preview.jsx feedHealth().
  - Wykrywanie problemów: empty (slot required pusty), too-long (przekroczony maxLength), missing (requiredOneOf niespełnione) — reużywając walidatora slotów z M2 (ta sama walidacja co pełna generacja).
  - Bezpieczeństwo: preview przez XmlWriterCore (auto-escaping, sanityzacja control-chars, HTML per-slot cdata/strip) — well-formed nawet dla śmieciowych danych (§6.9); brak zapisu do MinIO, brak dotknięcia provenance (read-only).
  - Limit rozmiaru/kosztu: N twardo ograniczone (max 5, nie konfigurowalne przez klienta by uniknąć DoS), tenant-scope, wynik in-memory zwracany synchronicznie (nie async).
  - Zarejestrować obie trasy w CustomRouteOpenApiFactory i zregenerować docs/api-spec/v0.json.
- **Poza zakresem:**
  - Pełna generacja feedu do MinIO — M2 (preview to ten sam silnik z limitem 5 + output in-memory).
  - FE ekran podglądu (feed-preview.jsx: XmlView, Segmented formatted/raw, health report render) — M5.
  - Projekcja na pełny katalog ('~12 408', topIssues) jako realne liczby — może korzystać z KPI z XMLF-P4-03 lub zostać hookiem; preview zwraca health tylko dla próbki N=5.
  - AI-sugestie poprawek mapowania ('Zasugeruj poprawki · AI') — hook Faza 2 (disabled w designie).
  - Async / kolejkowanie — preview jest zawsze synchroniczny.
- **AC:**
  - [ ] POST /api/feeds/{id}/preview zwraca 200 z well-formed XML (walidowalny xmllint) dla ≤5 pierwszych produktów pasujących do FeedProfile.filter + raportem health.
  - [ ] POST /api/feeds/preview (draft) z descriptorem w ciele zwraca identyczny kształt output dla tego samego zestawu danych co feed zapisany (test: zapisany feed vs draft z tym samym descriptorem → ten sam XML).
  - [ ] Preview używa tej samej pętli slotów/transformacji/walidacji co pełna generacja (weryfikacja: sample z preview jest bajt-identyczny z odpowiadającymi <item> pełnego feedu dla tych samych produktów).
  - [ ] Raport health zwraca valid/total, skipped, i per-slot {slot, level, count} zgodnie z regułami required/requiredOneOf/maxLength/format.
  - [ ] Śmieciowe dane (nazwa z ']]>', '<script>', znak \x0B) → output nadal well-formed (xmllint parse OK); test security-first.
  - [ ] Draft z malformed descriptorem → 422 RFC 7807 (bez 500).
  - [ ] Preview nie zapisuje do MinIO ani nie tworzy FeedRun/FeedRunLog; provenance ObjectValue nietknięte (read-only).
  - [ ] Feed/preview innego tenanta niedostępny (cross-read=0); docs/api-spec/v0.json zaktualizowany.
- **Smoke:**
  - Zaloguj się na https://pim.localhost; utwórz (lub użyj) feed Google Shopping z filtrem trafiającym w kilka produktów.
  - POST /api/feeds/{id}/preview (z tokenem sesji) → 200; zapisz zwrócony XML do pliku i uruchom xmllint --noout (exit 0).
  - Sprawdź raport health w body: valid/total i skipped zgodne z brakami (np. produkt bez GTIN → skipped, slot g:gtin w per-slot z level warning/error).
  - POST /api/feeds/preview z draft descriptorem (skopiuj descriptor feedu, zmień jeden slot) → 200 well-formed XML odzwierciedlający zmianę.
  - Zregeneruj pełny feed i pobierz jego publiczny URL → 200 well-formed XML; potwierdź że pierwsze <item> zgadzają się z preview (preview == produkcja).
  - DevTools Console bez czerwonych errorów.
- **Reuse:** apps/api/src/Export/Application/Builder/ExportBuilder.php — Generator itemów array<string,string>; dla preview ograniczony do N=5 (caller owns limit + clear) · apps/api/src/Catalog/Application/Filter/FilterDslResolver.php — wybór pierwszych N produktów pasujących do FeedProfile.filter (ten sam DSL co lista/eksport) · apps/api/src/Export/Application/Sync/SyncExportRunner.php — wzorzec synchronicznego runToFile; preview to jego odpowiednik z output in-memory zamiast pliku (openWriter ~line 500) · apps/api/src/Shared/OpenApi/CustomRouteOpenApiFactory.php — rejestracja tras preview (saved + draft) do docs/api-spec/v0.json (ADR-0020)
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.11 (preview 5 sample, in-memory, preview==produkcja), §6.5 (walidacja/health), §6.9 (well-formed guard) · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-preview.jsx (XmlView formatted/raw, badge well-formed, raport zdrowia valid/total/skipped/perSlot, napis 'preview == crawler') · epik-04 'Preview output'; ADR-0020 (OpenAPI custom route); IMP2-2.8 (#1552) wzorzec escaping/injection guard
- **DoD:** standard.


# M5 — UI

### XMLF-P5-01: feat(admin): add Feeds tab to Konfigurator shell and feeds hub
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M5 · **Est:** 10-14h · **Risk:** medium
- **Blocked by:** XMLF-P1-04, XMLF-P3-05, XMLF-P4-03 · **Blocks:** XMLF-P5-02, XMLF-P5-05, XMLF-P5-07, XMLF-P6-04
- **Po co:** Feed produktowy XML to trzeci obywatel obszaru Konfigurator (obok Połączeń i Mojego API). Hub feedów to punkt wejścia: klient widzi wszystkie skonfigurowane feedy, ich status, URL pod crawler, pokrycie mapowania i najbliższą regenerację, i stąd tworzy nowe. Bez huba nie ma jak dotrzeć do kreatora ani zarządzać istniejącymi feedami. Realizuje wizję epik-04 §3.3 Feeds i §9 pkt 1 planu.
- **Stan obecny:** Shell KonfiguratorApiLayout ma trzy zakładki (connections/producer/monitor) na PillTabs nad Outletem; brak zakładki Feedy i brak jakiegokolwiek widoku feedów. Backend M1 dostarcza CRUD FeedProfile (lista + status + cached_item_count/cached_at), M3 token URL, M4 next-run/run stats. Design feed-hub.jsx (FeedHub + FeedKpiStrip + FeedCard + NewFeedCard + AdHocXmlNote) jest kompletny i po polsku.
- **Zakres:**
  - Dodać czwartą zakładkę 'feeds' do PillTabs w KonfiguratorApiLayout.tsx (activeId derived z pathname prefix `${BASE}/feeds`), klucz i18n api_configurator.shell.tabs.feeds.
  - Zarejestrować zagnieżdżone trasy /integrations/api-configurator/feeds (hub), /feeds/new (wizard — placeholder do P5-02), /feeds/:id (detail — placeholder do P5-05) w App.tsx pod istniejącym Outletem shell.
  - Utworzyć katalog features/api-configurator/feeds/ (hub/, wizard/, mapper/, monitor/, api/, i18n scoping) zgodnie z konwencją features/api-configurator.
  - Zbudować FeedsHub: FeedKpiStrip (active/paused/error/itemsSyndicated/pulls24h — z GET /api/feeds/stats), toolbar z search po name|code + segmented filtr all/active/paused/error, grid feed cards + NewFeedCard (CTA 'Nowy feed' → openWizard), empty state gdy 0 feedów.
  - FeedCard: ikona rss + tone per template, TemplateBadge (google_shopping/ceneo/meta/custom), ConnStatusPill(status), wiersz URL z CopyButton + przycisk 'Rotuj token' (wywołuje rotate z P5-04 API — w tym tickecie tylko akcja+toast), grid metryk (produkty w feedzie + skipped, harmonogram cron_human+cron, CoverageBar mapped/total, rozmiar + nextRun), healthNote gdy błąd, footer z ost. regeneracją + akcje Regeneruj/Wstrzymaj-Wznów/Edytuj.
  - AdHocXmlNote: link-karta do kreatora eksportu (/exports wizard) z FormatPill XML, treść wyjaśniająca ad-hoc XML (generyczny wrapper, wolny wybór kolumn) — bez nowego flow.
  - Refine dataProvider hook do GET /api/feeds (lista) + GET /api/feeds/stats; typy TS z OpenAPI shared-types.
  - Wszystkie stringi przez t() z kluczami EN w locales/en.json + pl.json (feeds.hub.*, feeds.card.*, feeds.status.*, feeds.template.*).
- **Poza zakresem:**
  - Kreator feedu (P5-02..P5-04) — tu tylko trasa+CTA
  - Ekran detalu/monitora feedu (P5-05)
  - Faktyczna implementacja rotate/revoke tokenu i regenerate (BE M3/M4) — hub tylko wywołuje istniejące endpointy
  - Ad-hoc XML eksport (ExportFormat::Xml, kafelek EXR) — osobny tor M0
  - Delete feedu z potwierdzeniem — jeśli nie ma w design, poza zakresem
- **AC:**
  - [ ] Zakładka 'Feedy' widoczna w PillTabs Konfiguratora i podświetlona dla każdej trasy pod /integrations/api-configurator/feeds
  - [ ] GET /api/feeds renderuje FeedCard per feed z poprawnym TemplateBadge, ConnStatusPill i CoverageBar (mapped/total)
  - [ ] FeedKpiStrip pokazuje wartości z /api/feeds/stats (active/paused/error/itemsSyndicated/pulls24h)
  - [ ] Search filtruje po name i code; segmented filtr zawęża po status; oba działają łącznie
  - [ ] URL feedu ma działający CopyButton (kopiuje pełny URL do schowka); przycisk 'Rotuj token' wyzwala akcję rotate i pokazuje feedback (toast + pending)
  - [ ] Empty state (0 feedów) pokazuje NewFeedCard z CTA prowadzącym do /feeds/new
  - [ ] AdHocXmlNote linkuje do kreatora eksportu
  - [ ] Zero literałów JSX — wszystkie stringi przez t(); klucze obecne w en.json i pl.json
  - [ ] axe-core 0 serious/critical na hubie (pill-tab role, przyciski z aria-label dla ikon rotate/pause)
  - [ ] `FeedKpiStrip` podpięty do realnych agregatów (w tym `pulls24h` z XMLF-P3-06).
  - [ ] Shell ma dwie pod-zakładki „Feedy" + „Monitor"; istniejące zakładki APIC nie regresują.
- **Smoke:**
  - Login admin@demo.localhost / changeme na https://pim.localhost
  - Przejdź do Integracje → Konfigurator API → zakładka Feedy
  - Sprawdź że lista feedów renderuje się z /api/feeds (DevTools Network 200), KPI strip ma wartości
  - Wpisz frazę w search i przełącz filtr statusu — lista się zawęża
  - Kliknij CopyButton przy URL feedu — URL w schowku; kliknij 'Rotuj token' — toast + Network 200
  - Skopiowany URL wklej do przeglądarki/curl: feed URL zwraca 200 well-formed XML; zweryfikuj `curl -s '<url>' | xmllint --noout -`
  - DevTools Console bez czerwonych errorów
- **Reuse:** apps/admin/src/features/api-configurator/layout/KonfiguratorApiLayout.tsx — shell PillTabs do rozszerzenia o zakładkę feeds · apps/admin/src/components/ui-v2/pill-tabs.tsx — komponent zakładek · apps/admin/src/features/api-configurator/ — konwencja struktury feature (hub/api/i18n scoping) · apps/admin/src/locales/en.json + pl.json — dodanie kluczy feeds.* (wzorzec api_configurator.*) · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-hub.jsx — autorytatywny layout huba (FeedHub/FeedCard/NewFeedCard/AdHocXmlNote) · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-primitives.jsx — CopyButton/CoverageBar/FeedUrlCard/TemplateBadge do portu na shadcn · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-data.jsx — FEED_KPI/FEEDS shape referencyjny dla typów
- **Referencje:** plan §9 pkt 1 (Hub Feedy), §6.2 (FeedProfile), §3 (Feed vs ad-hoc), §6.8 (delivery/URL) · design feedy/feed-hub.jsx, feedy/feed-primitives.jsx, Feedy.html · ADR-0022 (granica konsument/producent — feed jako sąsiad producenta w shellu Konfigurator)
- **DoD:** standard.

### XMLF-P5-02: feat(admin): feed wizard shell with template and scope steps
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M5 · **Est:** 12-16h · **Risk:** medium
- **Blocked by:** XMLF-P5-01, XMLF-P2-04 · **Blocks:** XMLF-P5-03, XMLF-P5-06
- **Po co:** Kreator feedu to serce flow 'feed w 5 minut bez developera' (§9 pkt 2). Krok 1 (Szablon) determinuje strukturę XML i sloty predef; Krok 2 (Zakres) decyduje który asortyment i w jakim scope (locale/waluta/kanał/publication profile) trafia do feedu — reużywając ten sam filtr DSL co lista produktów i eksport, bez drugiej implementacji. Shell steppera spina wszystkie 5 kroków.
- **Stan obecny:** Brak kreatora feedu. Trasa /feeds/new zarejestrowana jako placeholder w P5-01. Istnieje wzorzec pełnostronicowego wizarda EXR (features/exports/wizard/steps/) i AdvancedFilterPanel + use-filter-dsl-state do reużycia filtra. Backend: M2 dostarcza listę szablonów (FEED_TEMPLATES: descriptor + root/ns + built-in flag), M1 zapis draftu FeedProfile, Channel publication profiles jako opcje scope. Design feed-wizard.jsx (FeedWizard + FEED_WIZARD_STEPS + StepTemplate + StepScope) kompletny.
- **Zakres:**
  - Zbudować FeedWizard jako pełnostronicowy (nie modal) widok pod /feeds/new i /feeds/:id/edit; header z back-to-hub + tytuł Nowy/Edytuj feed + TemplateBadge wybranego szablonu.
  - Stepper na 5 kroków (FEED_WIZARD_STEPS: template/scope/mapping/delivery/preview) ze stanami done/active/pending, klikalne tylko kroki <= current; footer Wstecz/Dalej/Zapisz-i-wygeneruj z walidacją canNext (krok 0: kind && name).
  - State kreatora (kind, name, code, locale, currency, channel, publication, mappings, skipPolicy, cron, gzip, authType, filter conditions) trzymany w wizardzie i przekazywany do kroków; edit mode wczytuje istniejący FeedProfile z GET /api/feeds/:id.
  - Step 1 Template: SelectableCard-grid dla google_shopping/ceneo/meta/custom z GET /api/feeds/templates (root/ns/desc/builtIn), badge predef|custom, stan 'wybrany'; po wyborze auto-fill name+code i skopiowanie DEFAULT_MAPPINGS szablonu do state mappings; karta name (required) + code (auto-slug z name, mono).
  - Step 2 Scope: karta 'Scope wartości' z locked ObjectType=Produkt, SelectInput locale (single, wymagany), SelectInput waluta, SelectInput kanał (overlay), SelectInput publication profile (z GET /api/channels/publication-profiles — allow-lista atrybutów + column_aliases jako zalążek mapowania); nota o wariantach flat + item_group_id.
  - Step 2 Filter: reużyć AdvancedFilterPanel + use-filter-dsl-state dla asortymentu feedu (filter DSL persistowany jako FeedProfile.filter); live-count 'W feedzie: N produktów' z preflight count API.
  - Zapis draftu przez POST/PATCH /api/feeds; wszystkie stringi przez t() (feeds.wizard.*, feeds.wizard.step.*, feeds.scope.*).
- **Poza zakresem:**
  - Krok 3 Mapper (P5-03), Krok 4 Delivery (P5-04), Krok 5 Preview (P5-04)
  - Własna implementacja filtra DSL — wyłącznie reuse AdvancedFilterPanel
  - Edycja descriptora custom od zera (JSON editor) — MVP klonuje predef lub domyślny custom slot set
  - Custom SelectableCard od zera — reuse wzorca z EXR/IMP jeśli istnieje
- **AC:**
  - [ ] /feeds/new renderuje pełnostronicowy wizard z 5-krokowym stepperem; kroki active/done/pending mają poprawny styl i klikalność (tylko <= current)
  - [ ] Wybór szablonu w kroku 1 ustawia kind, kopiuje DEFAULT_MAPPINGS do state, auto-wypełnia name+code; przycisk Dalej aktywny dopiero gdy kind && name.trim()
  - [ ] Krok 2 renderuje AdvancedFilterPanel (nie druga implementacja) i persistuje filter DSL do FeedProfile.filter
  - [ ] SelectInput publication profile ładuje profile z /api/channels/publication-profiles i przekazuje wybór do state (zalążek mapowania w P5-03)
  - [ ] Live-count produktów aktualizuje się po zmianie filtra (preflight count 200)
  - [ ] Edit mode (/feeds/:id/edit) wczytuje istniejący feed i pre-wypełnia wszystkie pola kroków 1-2
  - [ ] Zero literałów JSX; klucze i18n w en.json + pl.json
  - [ ] axe-core 0 serious/critical (stepper jako lista kroków z aria-current, selecty z labelami)
- **Smoke:**
  - Login na https://pim.localhost, Konfigurator → Feedy → 'Nowy feed'
  - Krok 1: wybierz kafelek Google Shopping — TemplateBadge się pojawia, name/code auto-fill; Dalej aktywne
  - Krok 2: dodaj warunek filtra (Status = aktywny), sprawdź że live-count się przelicza (Network 200); wybierz locale=pl, publication profile z listy
  - Kliknij Dalej — przechodzi do kroku 3 (placeholder/mapper); Wstecz wraca zachowując state
  - Zapisz draft (jeśli dostępny) — POST/PATCH /api/feeds 200/201; feed URL wygenerowanego feedu zwraca 200 well-formed XML: `curl -s '<url>' | xmllint --noout -`
  - DevTools Console bez czerwonych errorów
- **Reuse:** apps/admin/src/features/exports/wizard/steps/ — wzorzec pełnostronicowego steppera EXR (StepScopeFormat/StepColumns/StepSummary) · apps/admin/src/components/catalog/advanced-filter-panel.tsx — panel filtra do reużycia jako asortyment feedu · apps/admin/src/lib/filters/use-filter-dsl-state.ts + filter-dsl.ts — stan i typy filtra DSL (FilterCondition/FilterGroup/FilterDsl) · apps/admin/src/features/api-configurator/feeds/ — katalog utworzony w P5-01 · apps/admin/src/locales/en.json + pl.json — klucze feeds.wizard.* · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-wizard.jsx — autorytatywny layout (FeedWizard/StepTemplate/StepScope) · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-data.jsx — FEED_TEMPLATES/DEFAULT_MAPPINGS shape
- **Referencje:** plan §9 pkt 2 (Kreator feedu — kroki 1-2), §6.5 (szablony predef), §6.6 (filtr + scope + publication profile), §6.4 (zalążek mapowania z column_aliases) · design feedy/feed-wizard.jsx (StepTemplate + StepScope) · ADR-0018 (publication profile — column_aliases + allow-lista)
- **DoD:** standard.

### XMLF-P5-03: feat(admin): feed wizard mapper step (slots to PIM attributes)
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M5 · **Est:** 14-18h · **Risk:** high
- **Blocked by:** XMLF-P5-02, XMLF-P2-04, XMLF-P3-05 · **Blocks:** XMLF-P5-04
- **Po co:** Mapper to komponent kluczowy całego konfiguratora (§9 pkt 3): tu klient wiąże sloty szablonu (g:title, Ceneo o/name, custom) z atrybutami PIM, dobiera transformacje z zamkniętej listy, widzi na żywo wynik na próbce i ostrzeżenia o niezgodności typu. Od jakości tego ekranu zależy czy klient sam zbuduje poprawny feed. Wskaźnik pokrycia i badge required/requiredOneOf pilnują kompletności zanim feed wyjdzie do marketplace.
- **Stan obecny:** Wizard shell (P5-02) ma placeholder kroku 3. Backend: M2 dostarcza descriptor+sloty per szablon (TEMPLATE_SLOTS), M2 zamkniętą listę transformacji (FEED_TRANSFORMS), M3 mapper API (zapis field_mappings + zalążek z publication profile column_aliases), preview sample dla kolumny 'wynik'. Design feed-mapper.jsx (FeedMapper + SourcePicker + slotWarn/fmtHint) kompletny; primitives SlotNodeBadge/TransformPill/TypeCompat/CoverageBar gotowe do portu.
- **Zakres:**
  - Zbudować FeedMapper jako Krok 3 wizarda: header z 'Produkt → TemplateBadge', licznik ostrzeżeń typu, CoverageBar wymaganych (reqMapped/reqSlots) + całkowitego pokrycia.
  - Segmented skip-policy (skip_invalid | include_with_warning) wiązany do state skipPolicy.
  - Dwukolumnowa tabela slot↔atrybut z nagłówkami (Slot szablonu / Źródło·atrybut PIM / Transformacja / Wynik·próbka); render slotów z GET /api/feeds/templates/:kind/slots (TEMPLATE_SLOTS).
  - Kolumna Slot: font-mono slot name, badge required ('wym.') i requiredOneOf ('1 z N' z tooltipem), SlotNodeBadge(node: element/attribute/repeatable/keyvalue), fmtHint (price/number/url/enum/category/html + maxLength).
  - SourcePicker per slot: select z optgroup 'Atrybut PIM' (GET /api/feeds/attributes → FEED_PIM_ATTRS: code/label/type), 'Wartość statyczna…' (input mono), 'Szablon/interpolacja…' (input {store_url}/p/{url_slug}); zapis source do mappings.
  - Transform dropdown z zamkniętej listy FEED_TRANSFORMS (none/default/price/number/date/enum_map/template/strip_html/truncate); truncate ustawia to=maxLength slotu, default ustawia value.
  - Kolumna 'Wynik·próbka': live resolveSlot na sample product (GET /api/feeds/preview-sample lub inline resolver z BE), TypeCompat ikona ok/warn, TransformPill, wyróżnienie tooLong gdy > maxLength; empty state z '⚠ brak wartości' dla required.
  - slotWarn: error (required/requiredOneOf niezmapowany) → border rose + panel; warning (typ atrybutu money/number vs fmt price/number bez transformacji) → border amber + panel z komunikatem 'typ → fmt: dodaj transformację'.
  - Nota o zalążku mapowania z publication profile (column_aliases) + external_code kategorii kanału. Stringi przez t() (feeds.mapper.*).
- **Poza zakresem:**
  - Edytor descriptora custom (dodawanie/usuwanie slotów) — MVP mapuje predefiniowane sloty
  - Silnik dowolnych transformacji / wieloetapowe if-then — zamknięta lista (hook §7)
  - AI-assisted mapping / sugestie (Faza 2)
  - Faktyczna walidacja BE required przy generacji (feed health) — tu tylko UI hint; egzekwuje FeedGenerator M4
  - Drag-and-drop mapowania — MVP tryb tabeli (design decision §9)
- **AC:**
  - [ ] Krok 3 renderuje wszystkie sloty wybranego szablonu z /api/feeds/templates/:kind/slots z poprawnym node badge, required/requiredOneOf badge i fmtHint
  - [ ] SourcePicker pozwala wybrać atrybut PIM / static / template; zmiana źródła aktualizuje field_mappings i kolumnę Wynik
  - [ ] Transform dropdown ma dokładnie 9 opcji z FEED_TRANSFORMS; wybór transformacji odzwierciedla się w TransformPill i próbce
  - [ ] Niezmapowany slot required pokazuje error (border rose + '⚠ brak wartości'); niezgodność typu (np. text→price bez transformacji) pokazuje warning amber z komunikatem
  - [ ] CoverageBar wymaganych pokazuje reqMapped/reqSlots i licznik ostrzeżeń typu aktualizuje się na żywo
  - [ ] Segmented skip-policy zmienia skipPolicy w state wizarda i jest persistowany do FeedProfile
  - [ ] Kolumna Wynik pokazuje live sample z BE (nie hardkod) dla zmapowanych slotów; tooLong wyróżniony przy przekroczeniu maxLength
  - [ ] Zero literałów JSX; klucze i18n en.json + pl.json
  - [ ] axe-core 0 serious/critical (selecty z labelami, badge z aria-label, tabela z nagłówkami)
- **Smoke:**
  - Login, Feedy → Nowy feed → Google Shopping → Dalej (scope) → Dalej (mapper)
  - Sprawdź że sloty g:id..g:item_group_id renderują się z /api/feeds/templates/google_shopping/slots (Network 200) z badge required
  - W SourcePicker g:title wybierz atrybut 'name' — kolumna Wynik pokazuje wartość próbki (Network 200 dla sample), TypeCompat ok
  - W g:price wybierz atrybut typu text bez transformacji — pojawia się warning amber 'dodaj transformację'; ustaw transform=price → warning znika
  - Odmapuj g:id (required) — pojawia się error rose i CoverageBar wymaganych spada
  - Przełącz skip-policy na 'Dołącz z ostrzeżeniem' — segmented reaguje
  - Zapisz feed i pobierz jego URL: `curl -s '<url>' | xmllint --noout -` zwraca well-formed XML
  - DevTools Console bez czerwonych errorów
- **Reuse:** apps/admin/src/features/api-configurator/feeds/ — katalog i wizard shell z P5-02 · apps/admin/src/components/ui-v2/ — shadcn Select/Segmented/badge do portu SourcePicker/SlotNodeBadge/TransformPill · apps/admin/src/locales/en.json + pl.json — klucze feeds.mapper.* · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-mapper.jsx — autorytatywny komponent (FeedMapper/SourcePicker/slotWarn/fmtHint) · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-data.jsx — TEMPLATE_SLOTS/DEFAULT_MAPPINGS/FEED_TRANSFORMS/FEED_PIM_ATTRS shape · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-primitives.jsx — SlotNodeBadge/TransformPill/CoverageBar do portu
- **Referencje:** plan §9 pkt 3 (Mapper komponent kluczowy), §6.3 (descriptor sloty element/attribute/repeatable/keyvalue), §6.4 (mapowanie + transformacje + zalążek column_aliases), §6.5 (required/requiredOneOf/format), §6.7 (external_code kategorii) · design feedy/feed-mapper.jsx · ADR-0018 (column_aliases zalążek), ADR-0015 (cross-BC bare UUID przy kategoriach kanału)
- **DoD:** standard.

### XMLF-P5-04: feat(admin): feed wizard delivery and preview steps
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M5 · **Est:** 12-16h · **Risk:** medium
- **Blocked by:** XMLF-P5-03, XMLF-P3-05, XMLF-P2-05, XMLF-P4-04 · **Blocks:** —
- **Po co:** Krok 4 (Dostarczanie) i 5 (Podgląd) domykają kreator: klient ustala harmonogram regeneracji, kompresję i autoryzację URL, dostaje stabilny token pokazany raz (wzorzec mint API key), a przed publikacją widzi rzeczywisty XML dla 5 sample produktów + raport zdrowia ('23 bez g:gtin'). Podgląd generuje ten sam silnik co produkcja — to gwarancja że 'co widzisz == co dostanie crawler' (§6.11), fundament zaufania klienta zanim feed pójdzie do Google/Ceneo.
- **Stan obecny:** Wizard ma kroki 1-3 (P5-02/P5-03). Backend: M3 delivery dostarcza mint/rotate/revoke tokenu URL + Basic auth (AesGcm) + gzip + cache-and-serve; M4 harmonogram cron (reuse CronExpressionParser); M2/M4 preview endpoint POST /api/feeds/:id/preview (i draft POST /api/feeds/preview) → sformatowany XML 5 sample + raport walidacji. Design: feed-wizard.jsx StepDelivery + feed-preview.jsx FeedPreview; primitives CronBuilder/FeedUrlCard/ApiToggle/Segmented/XmlView gotowe.
- **Zakres:**
  - Step 4 Delivery (StepDelivery): karta 'Harmonogram regeneracji' z CronBuilder (reuse wzorca cron z ImportSchedule; presety + human-readable next-run) wiązany do state cron; toggle gzip (ApiToggle).
  - Karta 'Autoryzacja URL': Segmented none|basic ('Token w URL' / 'Token + HTTP Basic'); przy basic — pola użytkownik + hasło (type=password, mono, nota 'szyfrowane AES-GCM'); hasło nigdy nie wraca z API (write-only).
  - FeedUrlCard: publiczny URL feedu + token pokazany raz (minted=true dla nowego feedu, wzorzec mint API key), CopyButton pełnego URL, akcje Rotuj (regeneracja tokenu → nowy URL raz) i Revoke; SecurityNote o modelu pull cache-and-serve.
  - Step 5 Preview (FeedPreview): dwukolumnowy widok — lewa XML preview z Segmented Sformatowany|Raw (XmlView highlight / pre raw), badge 'well-formed', CopyButton raw, footer z root path (rss/channel/item lub offers/o); dane z POST /api/feeds/:id/preview (5 sample).
  - Prawa kolumna: Raport zdrowia (valid/total + skipped + per-slot uwagi z HealthDot) z odpowiedzi preview; karta 'Projekcja na pełny katalog' (topIssues z /api/feeds/:id/health lub stats); disabled CTA AI-sugestie (Faza 2); SecurityNote o always-well-formed XML.
  - Footer wizarda: przycisk 'Zapisz i wygeneruj' (POST /api/feeds + trigger first_publish regeneracji) na kroku 5.
  - Stringi przez t() (feeds.delivery.*, feeds.preview.*, feeds.health.*).
- **Poza zakresem:**
  - Backend generacji/serwowania feedu (M3/M4) — FE tylko konsumuje endpointy
  - Jitter między tenantami (BE follow-up per reuse map note)
  - AI-sugestie mapowania (disabled CTA, Faza 2)
  - Push do FTP/SFTP (hook §7)
  - Adaptive scheduling (hook §7)
- **AC:**
  - [ ] Krok 4 renderuje CronBuilder z presetami i podglądem next-run; zmiana crona aktualizuje state i human-readable opis
  - [ ] Toggle gzip i Segmented auth (none/basic) działają; przy basic pojawiają się pola user+hasło, hasło jako password i nie wraca z GET
  - [ ] FeedUrlCard pokazuje URL + token raz przy mincie; Rotuj generuje nowy token/URL (POST rotate 200) i pokazuje go raz; Revoke unieważnia (200)
  - [ ] Krok 5 ładuje POST /api/feeds/:id/preview i renderuje XML 5 sample; przełącznik Sformatowany/Raw działa; CopyButton kopiuje raw XML
  - [ ] Raport zdrowia pokazuje valid/total, skipped i per-slot uwagi z odpowiedzi preview (nie hardkod)
  - [ ] Badge 'well-formed' obecny; footer pokazuje poprawny root path per szablon (rss/channel/item vs offers/o)
  - [ ] 'Zapisz i wygeneruj' zapisuje feed i wyzwala first_publish (201 + redirect do detalu/huba)
  - [ ] Zero literałów JSX; klucze i18n en.json + pl.json
  - [ ] axe-core 0 serious/critical (toggle/segmented z labelami, XML preview jako region z aria-label, disabled AI CTA z aria-disabled)
- **Smoke:**
  - Login, dokończ kreator do kroku 4
  - Ustaw cron (np. codziennie 04:00), włącz gzip, przełącz auth na Basic — pola user/hasło się pojawiają
  - Sprawdź FeedUrlCard: URL + token widoczny; kliknij Rotuj — nowy URL, Network 200
  - Przejdź do kroku 5: XML preview ładuje się z POST /api/feeds/preview (Network 200), badge well-formed; przełącz Raw/Sformatowany; skopiuj raw
  - Raport zdrowia pokazuje liczby valid/skipped z odpowiedzi; kliknij 'Zapisz i wygeneruj' — 201
  - Otwórz publiczny URL zapisanego feedu: `curl -s '<feed_url>' | xmllint --noout -` → zwraca 200 i well-formed XML
  - DevTools Console bez czerwonych errorów
- **Reuse:** apps/admin/src/features/api-configurator/feeds/wizard/ — wizard shell z P5-02/P5-03 · apps/admin/src/components/ui-v2/ — shadcn toggle/segmented/select do portu ApiToggle/Segmented · apps/admin/src/locales/en.json + pl.json — klucze feeds.delivery.* / feeds.preview.* · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-wizard.jsx — StepDelivery (autorytatywny) · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-preview.jsx — FeedPreview (autorytatywny) · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-primitives.jsx — CronBuilder/FeedUrlCard/XmlView do portu
- **Referencje:** plan §9 pkt 2 (kroki 4-5) + pkt 4 (Preview panel), §6.8 (delivery pull cache-and-serve + gzip + ETag/304), §6.9 (token mint/rotate/revoke + Basic AesGcm + PUBLIC_ACCESS), §6.11 (preview 5 sample = produkcja), §6.5 (raport zdrowia) · design feedy/feed-wizard.jsx (StepDelivery), feedy/feed-preview.jsx · ADR-0020 (custom route preview/regenerate w OpenAPI), wzorzec ApiKey mint z ApiConfigurator
- **DoD:** standard.

### XMLF-P5-05: feat(admin): feed monitor with per-feed detail and run drill-down
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M5 · **Est:** 14-18h · **Risk:** medium
- **Blocked by:** XMLF-P5-01, XMLF-P4-04 · **Blocks:** —
- **Po co:** Monitor daje operatorowi wgląd w zdrowie syndykacji (§9 pkt 5): globalna historia regeneracji wszystkich feedów, detal pojedynczego feedu z progresem regeneracji na żywo (Mercure feed-runs.{id} + FeedStagePipeline), KPI i drill-down do FeedRunLog per-produkt/slot ('SKU-123: missing g:gtin — skipped'). Bez tego klient nie wie dlaczego feed odrzucony przez marketplace ani ile produktów pominięto. Realizuje wzorzec ScheduleRuns/EXR sesje na feedach.
- **Stan obecny:** Trasa /feeds/:id (detail) placeholder z P5-01; brak globalnego monitora i drill-downu. Backend: M1 encje FeedRun/FeedRunLog + API listy runów (global + per-feed), M4 regenerate endpoint + Mercure feed-runs.{feedId} (wzorzec MercureSubscribeTopics.exportSession, events private), M4 run stats/KPI. Design feed-monitor.jsx (FeedMonitor + FeedDetail + FeedRunDrilldown + FeedStagePipeline + RunsTable) kompletny; primitives HealthDot/StatusPill/TriggerBadge/ProgressBar/LiveBadge gotowe.
- **Zakres:**
  - FeedMonitor (globalny): 4 KPI karty (regeneracje 24h / produkty syndykowane / pominięte 24h / błędy 24h) z GET /api/feeds/runs/stats; sekcja 'Historia regeneracji' z segmented filtrem all/success/warning/error; RunsTable (showFeed=true) z GET /api/feeds/runs.
  - RunsTable: HealthDot per status, kolumny Feed·start / Trigger (TriggerBadge) / Czas / Produkty (+skipped) / Rozmiar / StatusPill / chevron; wiersz klikalny → openRun (drill-down sheet). Empty state 'Brak regeneracji'.
  - FeedDetail (per-feed, /feeds/:id): header z back + name + TemplateBadge + ConnStatusPill + LiveBadge (gdy active), wiersz URL z Copy+Rotuj, akcje Edytuj + 'Regeneruj teraz'.
  - Regeneracja na żywo: 'Regeneruj teraz' wywołuje POST /api/feeds/:id/regenerate; subskrypcja Mercure feed-runs.{feedId} (reuse wzorca exportSession topic) → FeedStagePipeline (query/serialize/validate/store/done) + ProgressBar aktualizowane per etap SSE.
  - KPI feedu (produkty/pominięte/rozmiar/CoverageBar/następny run) z GET /api/feeds/:id; healthNote gdy ostatnia regeneracja nieudana; sekcja 'Historia regeneracji' (RunsTable showFeed=false, runy tego feedu).
  - FeedRunDrilldown (Sheet): header run (feed/status/trigger/start/czas/rozmiar), error banner, 4 metryki (w feedzie/pominięte/ostrzeżenia/rozmiar), tabela FeedRunLog (HealthDot/SKU/slot/komunikat) z GET /api/feeds/runs/:runId/logs, akcje Pobierz log CSV / Pobierz plik feedu / Regeneruj ponownie.
  - Stringi przez t() (feeds.monitor.*, feeds.detail.*, feeds.run.*, feeds.log.*).
- **Poza zakresem:**
  - Backend generacji/regeneracji i emisji Mercure (M4) — FE konsumuje topic i endpoint
  - Faktyczny eksport logu do CSV po stronie BE (jeśli osobny endpoint — tylko wywołanie)
  - Webhook feed.regenerated (hook §7)
  - Wykresy sparkline zaawansowane poza KPI z design
- **AC:**
  - [ ] Globalny monitor renderuje 4 KPI z /api/feeds/runs/stats i RunsTable z /api/feeds/runs; filtr all/success/warning/error zawęża listę
  - [ ] Kliknięcie wiersza runu otwiera FeedRunDrilldown Sheet z FeedRunLog z /api/feeds/runs/:runId/logs (SKU/slot/komunikat)
  - [ ] FeedDetail (/feeds/:id) pokazuje header, URL z Copy+Rotuj, KPI feedu, historię runów tego feedu
  - [ ] 'Regeneruj teraz' wywołuje POST /api/feeds/:id/regenerate (202/200) i uruchamia FeedStagePipeline; etapy query→done aktualizują się na żywo z Mercure feed-runs.{feedId}, ProgressBar rośnie
  - [ ] LiveBadge (Mercure SSE) widoczny podczas regeneracji z etykietą topicu feed-runs.{id}
  - [ ] healthNote pokazuje się gdy ostatni run error; StatusPill/HealthDot/TriggerBadge poprawne per status
  - [ ] Zero literałów JSX; klucze i18n en.json + pl.json
  - [ ] axe-core 0 serious/critical (tabela z nagłówkami, Sheet z focus-trap + aria-label, przyciski ikon z aria-label, live region dla progresu)
- **Smoke:**
  - Login, Feedy → kliknij feed → FeedDetail; sprawdź KPI + URL + historia runów (Network 200)
  - Kliknij 'Regeneruj teraz' — POST /api/feeds/:id/regenerate 200/202; FeedStagePipeline przechodzi query→serialize→validate→store→done na żywo (Mercure SSE w Network → EventStream)
  - Po zakończeniu: URL feedu zwraca zaktualizowany plik; `curl -s '<feed_url>' | xmllint --noout -` → 200 well-formed XML
  - Przejdź do globalnego monitora (/feeds monitor); filtr error — lista zawęża; kliknij run → Sheet z FeedRunLog per SKU/slot
  - Sprawdź komunikaty logu (np. 'missing g:gtin — skipped'); zamknij Sheet
  - DevTools Console bez czerwonych errorów
- **Reuse:** apps/admin/src/features/api-configurator/feeds/ — katalog + trasa detalu z P5-01 · apps/api/src/Shared/Infrastructure/Mercure/MercureSubscribeTopics.php — wzorzec topicu (exportSession) do adaptacji na feed-runs.{feedId} · apps/admin/src/components/ui-v2/ — shadcn Sheet/table/badge do portu RunsTable/FeedRunDrilldown/StatusPill/HealthDot · apps/admin/src/locales/en.json + pl.json — klucze feeds.monitor.* / feeds.run.* · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-monitor.jsx — autorytatywny (FeedMonitor/FeedDetail/FeedRunDrilldown/FeedStagePipeline/RunsTable) · Zrodla/Front_Claude_Design/NOWY UI/PIM-nowoczesny/feedy/feed-primitives.jsx — HealthDot/StatusPill/TriggerBadge/LiveBadge/ProgressBar do portu
- **Referencje:** plan §9 pkt 5 (Monitor feedu + drill-down FeedRunLog), §6.8 pkt 4 (Mercure feed-runs.{feed_id} progres), §6.2 (FeedRun/FeedRunLog), §6.5 (skipped/warning feed health) · design feedy/feed-monitor.jsx · reuse map: MercureSubscribeTopics.exportSession (private events), ExportProgressPublisher wzorzec
- **DoD:** standard.

### XMLF-P5-06: feat(admin): add descriptor structure editor step for custom feeds
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M5 · **Est:** 10-14h · **Risk:** medium
- **Blocked by:** XMLF-P5-02, XMLF-P2-07 · **Blocks:** —
- **Po co:** Para FE do XMLF-P2-07: dla feedu custom operator musi móc zbudować strukturę XML (root/namespaces/sloty) zanim przejdzie do mapowania. Bez tego kroku wybór „Custom" w kreatorze nie realizuje obietnicy „od zera" z planu §7.
- **Stan obecny:** Kreator (P5-02) ma kroki Szablon/Scope; mapper (P5-03) mapuje na sloty. Dla predef sloty są stałe; dla custom brak UI do zdefiniowania struktury.
- **Zakres:**
  - Krok/edytor struktury widoczny tylko dla `template_kind=custom` (między Szablonem a Mapowaniem): edycja root + namespaces, lista slotów (dodaj/zmień nazwę/usuń, typ węzła, reguły required/maxLength/format).
  - Walidacja inline (nielegalne nazwy XML, duplikaty) zgodna z API P2-07; podgląd struktury.
  - i18n (klucze EN, tłumaczenia pl/en; ban literałów), axe-core 0 serious/critical.
- **Poza zakresem:**
  - Struktura dla predef (niemodyfikowalna)
  - Mapowanie źródeł (to P5-03)
- **AC:**
  - [ ] Wybór Custom → pojawia się krok edytora struktury; predef go pomija.
  - [ ] Dodanie/usunięcie/zmiana slotu + typu węzła zapisuje descriptor przez API P2-07.
  - [ ] Walidacja UI blokuje nielegalne nazwy XML i duplikaty; a11y czysto.
- **Smoke:**
  - Kreator → Custom → edytor struktury: dodaj root + 3 sloty → dalej do mapowania → preview well-formed.
  - axe-core na kroku edytora = 0 serious/critical.
- **Reuse:** apps/admin/.../feedy — komponenty kreatora (XMLF-P5-02) · API edytora struktury (XMLF-P2-07)
- **Referencje:** Design feed-wizard.jsx StepTemplate (Custom tile) · Plan §7 custom „od zera" · Para do XMLF-P2-07
- **DoD:** standard.

### XMLF-P5-07: feat(admin): add global Feeds monitor tab (cross-feed run history + aggregate KPI)
- **Typ:** `feat` · **Cls:** FE · **Milestone:** M5 · **Est:** 8-12h · **Risk:** low
- **Blocked by:** XMLF-P5-01, XMLF-P4-03, XMLF-P3-06 · **Blocks:** —
- **Po co:** Zaprojektowany feed-app.jsx ma osobny globalny ekran Monitor (cross-feed KPI: Regeneracje 24h / Produkty syndykowane / Pominięte 24h / Błędy 24h + historia wszystkich feedów). Krytyk kompletności wskazał, że P5-05 pokrywa tylko detal pojedynczego feedu — globalny monitor był niezmapowany.
- **Stan obecny:** P5-01 dodaje pod-zakładkę Monitor w shellu; P5-05 renderuje detal per-feed + drill-down. Brak globalnego ekranu cross-feed z agregatem KPI (feed-monitor.jsx FeedMonitor).
- **Zakres:**
  - Globalny ekran Monitor pod pod-zakładką: cross-feed KPI row + tabela historii runów wszystkich feedów (kolumna feed), filtry status.
  - Reuse RunsTable/StatusPill/TriggerBadge z monitora per-feed; drill-down do FeedRunDrilldown.
  - Podpięcie do API historii runów (P4-03) + agregatów (w tym pulls24h z P3-06); i18n + axe-core.
- **Poza zakresem:**
  - Detal per-feed (to P5-05)
  - Nowe metryki poza designem
- **AC:**
  - [ ] Pod-zakładka Monitor renderuje globalny cross-feed ekran (KPI + historia wszystkich feedów).
  - [ ] Filtry status działają; drill-down otwiera FeedRunLog danego runu.
  - [ ] KPI (regeneracje/syndykowane/pominięte/błędy 24h) z realnych agregatów.
- **Smoke:**
  - Konfigurator → Feedy → Monitor → widok cross-feed z runami ≥2 feedów; filtr „błędy" zawęża.
  - Klik run → sheet FeedRunLog z liniami per produkt/slot.
- **Reuse:** Design feed-monitor.jsx FeedMonitor/RunsTable · API historii runów (XMLF-P4-03) · Telemetria pobrań (XMLF-P3-06)
- **Referencje:** Design feed-app.jsx (tab monitor) + feed-monitor.jsx · Luka #3 z krytyki
- **DoD:** standard.


# M6 — Hardening + launch

### XMLF-P6-01: test(export): XML injection and always-well-formed feed suite
- **Typ:** `test` · **Cls:** SEC · **Milestone:** M6 · **Est:** 6-10h · **Risk:** medium · `[SEC]`
- **Blocked by:** XMLF-P2-01, XMLF-P2-02 · **Blocks:** —
- **Po co:** Feed XML jest odczytywany przez crawlery marketplace (Google Merchant, Ceneo, Meta). Jeden niepoprawny znak w danych produktowych (nazwa z ']]>', '<script>', znak sterujący '\x0B', niezbalansowany tag) łamie cały plik XML i marketplace odrzuca CAŁY feed, nie pojedynczy produkt — to bezpośrednia utrata widoczności ofert klienta. To ten sam wektor co lekcja CSV formula injection (IMP2-2.8 / #1552), przeniesiony na warstwę XML. Plan §5.6, §6.9 i §11 wymagają twardego testu 'feed jest zawsze well-formed XML' na śmieciowym payloadzie oraz polityki HTML per-slot (strip/cdata/escape, nigdy raw).
- **Stan obecny:** XmlWriterCore (auto-escaping przez php XMLWriter, sanityzacja control-chars XML 1.0, CDATA, polityka HTML per-slot) powstaje w M0 (XMLF-P0), a slotowa serializacja feedu w XmlFeedWriter/FeedGenerator w M2. Brak dedykowanej suity bezpieczeństwa która adversarialnie karmi silnik śmieciowymi wartościami i dowodzi że wyjście zawsze przechodzi walidację well-formedness. Bez tej suity 'well-formed guard' z planu jest deklaracją, nie egzekwowanym kontacktem.
- **Zakres:**
  - Failing-test-first: napisz test PHPUnit (Integration, KernelTestCase) który generuje feed dla zestawu adversarial fixture'ów PRZED sprawdzeniem że silnik je bezpiecznie obsługuje.
  - Zdefiniuj katalog śmieciowych payloadów jako fixture: nazwa/opis z ']]>' (łamacz CDATA), '<script>alert(1)</script>', surowe '&' i '<'/'>', cudzysłowy w atrybutach XML (o price="..."), znaki sterujące niedozwolone w XML 1.0 (\x00-\x08, \x0B, \x0C, \x0E-\x1F), niezbalansowane/niedomknięte tagi HTML, bardzo długi ciąg (> maxLength slotu), multi-byte UTF-8 i emoji.
  - Dla każdego payloadu i każdego z 3 szablonów predef (Google Shopping, Ceneo, Meta) oraz custom descriptora: wygeneruj feed przez FeedGenerator do bufora, sparsuj wynik strict parserem (DOMDocument->loadXML z LIBXML_NONET | LIBXML_NOENT wyłączony) i asertuj że parsuje się bez błędu (assertNull na libxml_get_last_error).
  - Test polityki HTML per-slot: slot z html='cdata' owija w CDATA (payload ']]>' bezpiecznie rozbity, nie przecieka), slot z html='strip' usuwa tagi (brak '<' w output), slot z html='escape' encoduje '&<>' — żaden tryb nie emituje surowego HTML w treści elementu.
  - Test sanityzacji control-chars: payload z \x0B/\x00 daje output bez tych bajtów i nadal well-formed (nie 'zjedzony' element).
  - Test escapowania atrybutów XML (Ceneo o price/id/url, g: namespace na elemencie): cudzysłów w wartości atrybutu nie wychodzi z kontekstu atrybutu.
  - Test nazw elementów w trybie ad-hoc GenericXmlWriter: klucz 'description.pl' nie tworzy nielegalnej nazwy elementu (kropka -> atrybut locale / underscore, per §6.10) — output well-formed.
  - Dodaj mały helper/fixture-provider tak, aby dodanie nowego szablonu w przyszłości automatycznie przechodziło przez ten sam adversarial zestaw (data provider PHPUnit).
- **Poza zakresem:**
  - Guard XXE / bezpieczny parsing IMPORTU XML — feed tylko generuje, nie parsuje wejścia (hook 'import XML', patrz XMLF-P6-04).
  - Zmiany w samym XmlWriterCore / FeedGenerator poza naprawą realnych dziur wykrytych przez suitę (jeśli suita wykryje bug, fix idzie tym samym PR-em jako część security-first).
  - Walidacja zgodności ze specyfikacją marketplace (required-field feed health) — to walidacja domenowa M2, nie well-formedness.
- **AC:**
  - [ ] Istnieje suita testów oznaczona jako security (grupa/namespace) pokrywająca >=8 klas adversarial payloadów x 3 szablony predef + custom + ad-hoc.
  - [ ] Dla KAŻDEGO adversarial payloadu wygenerowany dokument parsuje się jako well-formed XML (DOMDocument->loadXML zwraca true, libxml_get_last_error() zwraca false).
  - [ ] Test dowodzi że ']]>' w polu z html='cdata' NIE łamie CDATA (brak przedwczesnego domknięcia sekcji).
  - [ ] Test dowodzi że znaki sterujące XML 1.0 (\x00-\x08,\x0B,\x0C,\x0E-\x1F) są usunięte z output i wynik pozostaje well-formed.
  - [ ] Test dowodzi że '<script>' nigdy nie pojawia się surowo w treści elementu (strip usuwa, escape encoduje, cdata izoluje).
  - [ ] Cudzysłów/apostrof w wartości mapowanej na atrybut XML (Ceneo <o price>) jest poprawnie escapowany i nie wychodzi z kontekstu atrybutu.
  - [ ] Suita jest failing-test-first: commit history / PR pokazuje test dodany przed (lub równocześnie z) fixem, jeśli jakikolwiek bug został wykryty.
  - [ ] CI green; pokrycie nowej logiki serializacji (jeśli dokładana) >=80%.
- **Smoke:**
  - pnpm stack:up; zaloguj admin@demo.localhost / changeme na https://pim.localhost.
  - Utwórz feed z szablonu Google Shopping obejmujący produkt, którego nazwę ustaw ręcznie na wartość ze śmieciem: 'Bur]]>ger <script> \x0B test' (przez UI edycji produktu lub API).
  - Kliknij 'Regeneruj teraz'; poczekaj aż FeedRun = done.
  - Otwórz publiczny URL feedu: curl -s 'https://pim.localhost/feeds/{token}.xml' -o /tmp/feed.xml -w '%{http_code}\n' — oczekiwany 200.
  - Zweryfikuj well-formedness: xmllint --noout /tmp/feed.xml — brak błędu (exit 0), mimo śmieciowej nazwy produktu.
  - Sprawdź w /tmp/feed.xml że nazwa produktu jest escapowana/w CDATA, brak surowego '<script>' i brak bajtu \x0B.
  - DevTools Console: brak czerwonych errorów przy renderze podglądu feedu.
- **Reuse:** apps/api/tests/Integration/Export/ExportMemoryBenchmarkTest.php — wzorzec Integration KernelTestCase + Foundry + grupowanie CI dla suity Export · apps/api/src/Export/Infrastructure/Writer/ — XmlWriterCore (M0) auto-escaping/CDATA/control-char sanitizer testowany przez tę suitę; XlsxStreamWriter/CsvStreamWriter jako wzorzec kontraktu writera · apps/api/src/Export/Feed/Application/ — FeedGenerator (M2) jako obiekt pod test (per-item transform+walidacja+writeItem) · apps/api/src/Export/Application/Builder/ValueSerializer.php — MULTI_VALUE_GLUE i null->'' , baza nad którą działa polityka HTML per-slot · apps/api/src/Export/Domain/Enum/ExportFormat.php — case Xml (M0) dla ścieżki ad-hoc GenericXmlWriter w teście nazw elementów
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §5.6 (XML injection zasada), §6.9 (bezpieczeństwo: well-formed guard, HTML per-slot), §11 (ryzyko XML injection), §8 faza 10 (hardening) · IMP2-2.8 / #1552 — lekcja CSV formula injection (analog) · docs/api/jsonb-schemas.md — envelope wartości JSONB (źródło stringów do serializacji)
- **DoD:** standard.

### XMLF-P6-02: test(export): feed tenant-isolation and public-endpoint security suite
- **Typ:** `test` · **Cls:** SEC · **Milestone:** M6 · **Est:** 8-12h · **Risk:** high · `[SEC]` `[PM]`
- **Blocked by:** XMLF-P1-01, XMLF-P3-01, XMLF-P3-02, XMLF-P3-05 · **Blocks:** —
- **Po co:** Publiczny URL feedu (GET /feeds/{token}.xml) to nowa powierzchnia ataku bez sesji — kredencjalem jest token w URL. Musimy dowieść testami (nie deklaracją) że: (a) token jednego tenanta nigdy nie zwraca feedu innego tenanta, (b) nieistniejący/cudzy token zwraca 404 a nie 403 (nie zdradzamy istnienia feedu — enumeration resistance), (c) token jest nieodgadywalny (>=128-bit entropii), (d) publiczny endpoint jest rate-limitowany (429 + Retry-After) przeciw scrapingowi, (e) endpoint TYLKO serwuje plik z cache i nie odpala logiki domenowej ani generowania (anti-DoS). Plan §6.9 i §11 czynią te punkty twardymi, egzekwowanymi security-first — analogicznie do audytu W0-5/#1577.
- **Stan obecny:** Publiczny kontroler feedu, token (mint/hash/rotate/revoke wzorem ApiKey) i wpis PUBLIC_ACCESS w security.yaml powstają w M3 (delivery). Rate-limit publicznego endpointu również w M3 wzorem ApiKeyRateLimitListener. Model feedu (FeedProfile z token_hash, TenantScoped + RLS) powstaje w M1. Brak dedykowanej suity bezpieczeństwa która 2-tenantową sondą dowodzi izolacji, testuje 404-vs-403 dla cudzego tokenu, mierzy entropię tokenu i weryfikuje że PUBLIC_ACCESS endpoint nie wykonuje logiki domenowej.
- **Zakres:**
  - PLAN MODE: ticket dotyka granicy Export/Feed <-> Shared security (PUBLIC_ACCESS) + izolacji tenantów (RLS/TenantFilter) — cross-context, wymaga świadomej decyzji o kształcie asercji izolacji i o tym co znaczy 'endpoint nie odpala logiki domenowej'.
  - Failing-test-first: napisz asercje bezpieczeństwa przed potwierdzeniem że implementacja M3 je spełnia; każda dziura = fix tym samym PR-em.
  - 2-tenant probe (ApiTestCase): seed tenanta A z feedem + tokenem A i tenanta B z feedem + tokenem B; żądanie GET /feeds/{token_A}.xml zwraca WYŁĄCZNIE produkty tenanta A (0 produktów B), i odwrotnie — cross-read count = 0.
  - Test 404-not-403: GET z tokenem nieistniejącym oraz GET z tokenem tenanta B na ścieżce udającej feed tenanta A zwraca 404 (nie 403, nie 401) — brak wycieku informacji o istnieniu feedu.
  - Test enumeration resistance: wygeneruj N tokenów i asertuj długość/alfabet dający >=128-bit entropii (np. base62 o odpowiedniej długości), oraz że token nie zawiera przewidywalnego prefiksu wskazującego tenant/feed id (token != klucz tenanta, osobny lifecycle).
  - Test rate-limit publicznego endpointu: przekroczenie limitu per-token zwraca HTTP 429 z nagłówkiem Retry-After (wzorzec ApiKeyRateLimitListener); reset po oknie.
  - Test PUBLIC_ACCESS scope: żądanie na publiczny URL serwuje TYLKO plik z cache (Flysystem stream), nie wywołuje FeedGenerator ani zapytań domenowych o katalog — asercja np. przez brak nowego FeedRun / brak wzrostu licznika generacji / spy że generator nie został wywołany; brak cache -> 404/409 zdefiniowany, nie generacja on-hit.
  - Test revoke/rotate: po revoke tokenu GET zwraca 404; po rotate stary token 404, nowy 200.
  - Test że token_hash nigdy nie wraca w API (analog webhookSecret) i jawny token pokazany tylko raz przy mint.
- **Poza zakresem:**
  - Implementacja samego kontrolera/tokenu/rate-limitu (M3) — tu tylko suita bezpieczeństwa + fix realnych dziur wykrytych.
  - HTTP Basic auth feedu (osobny wektor, testowany razem z M3 delivery jeśli zaimplementowany) — poza polem tego SEC ticketu jeśli Basic nie jest jeszcze wired; wtedy [DEF] follow-up.
  - XML well-formedness / injection — to XMLF-P6-01.
  - Aktywacja Postgres RLS jako taka (RBAC ticket) — tu tylko dowód że feed_profiles respektuje istniejącą izolację.
- **AC:**
  - [ ] 2-tenant probe: feed tenanta A serwuje 0 produktów tenanta B (cross-read = 0), potwierdzone w obie strony.
  - [ ] GET z cudzym/nieistniejącym tokenem zwraca dokładnie 404 (test asertuje status == 404, != 403, != 401).
  - [ ] Test entropii tokenu dowodzi >=128-bit (długość * log2(alfabet) >= 128) i brak przewidywalnego prefiksu id-owego.
  - [ ] Przekroczenie rate-limitu na publicznym URL zwraca 429 z obecnym i dodatnim nagłówkiem Retry-After.
  - [ ] Test dowodzi że publiczny endpoint NIE wywołuje FeedGenerator (brak nowego FeedRun / spy na generator = 0 wywołań) — serwuje wyłącznie cache.
  - [ ] Po revoke token daje 404; po rotate stary token daje 404 a nowy 200.
  - [ ] token_hash nie występuje w żadnej odpowiedzi API; jawny token dostępny tylko w odpowiedzi mint/rotate.
  - [ ] Suita to ApiTestCase (realny Postgres, bez mocków izolacji); CI green; nowa logika >=80% pokrycia.
- **Smoke:**
  - pnpm stack:up; zaloguj admin@demo.localhost / changeme.
  - Utwórz feed dla tenanta demo, skopiuj token; curl -s 'https://pim.localhost/feeds/{token}.xml' -o /tmp/feed.xml -w '%{http_code}\n' -> 200; xmllint --noout /tmp/feed.xml -> exit 0.
  - Podmień jeden znak w tokenie i powtórz curl -w '%{http_code}\n' -> oczekiwany 404 (nie 403).
  - Rotate token w UI; ponów curl ze STARYM tokenem -> 404; z nowym -> 200 + xmllint --noout ok.
  - Wykonaj w pętli ~kilkadziesiąt żądań na publiczny URL (for i in $(seq 1 60); do curl -s -o /dev/null -w '%{http_code} ' "https://pim.localhost/feeds/{token}.xml"; done) -> pojawia się 429 z nagłówkiem Retry-After (curl -I pokazuje Retry-After).
  - DevTools Network: publiczne GET nie tworzy nowego FeedRun w monitorze (odśwież monitor — brak nowego runu z tych żądań).
  - Console: brak czerwonych errorów.
- **Reuse:** apps/api/config/packages/security.yaml — wzorzec PUBLIC_ACCESS (linia ~110: '^/api/assets/[0-9a-f-]+/preview$') dla publicznego URL feedu bez sesji; test scope tego wpisu · apps/api/src/ApiConfigurator/Infrastructure/Security/ApiKeyRateLimitListener.php — RateLimiterFactoryInterface + TooManyRequestsHttpException (429 + Retry-After) jako wzorzec rate-limitu publicznego endpointu · apps/api/src/ApiConfigurator/Infrastructure/Security/Argon2idApiKeyHasher.php — hash/verify constant-time; token feedu reużywa hashera (osobny lekki byt) · apps/api/src/ApiConfigurator/Infrastructure/Security/ApiKeyAuthenticator.php — wzorzec prefix-lookup -> verify -> revoke/expiry, kalka dla rozwiązania tenant+feed z tokenu · apps/api/src/Shared/Infrastructure/Doctrine/Filter/TenantFilterConfigurator.php — TenantFilter/RLS egzekwowany na feed_profiles; dowód izolacji w 2-tenant probe · apps/api/src/Shared/Application/TenantContext.php — TenantContext::set z tokenu przed odczytem; GUC app.current_tenant · apps/api/tests/Integration/Export/ExportMemoryBenchmarkTest.php — wzorzec seed 2 tenantów + TenantContext w teście
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.9 (izolacja tenantów, PUBLIC_ACCESS scope, rate-limit, enumeration, cross-tenant token miss = 404), §5.5 (cache-and-serve anti-DoS), §11 (ryzyko token w URL) · ADR-0018 (publication profile — scope), ADR-0022 (granica producent/konsument) · audyt W0-5 / #1577 — wzorzec publicznego endpointu bez sesji · docs/adr/0016-api-configurator-key-format.md — wzorzec key format/hash
- **DoD:** standard.

### XMLF-P6-03: perf(export): benchmark 50k SKU feed generation under memory budget
- **Typ:** `perf` · **Cls:** BE · **Milestone:** M6 · **Est:** 6-10h · **Risk:** medium
- **Blocked by:** XMLF-P2-04, XMLF-P3-01 · **Blocks:** —
- **Po co:** Feed produktowy dla realnego katalogu to 50k SKU x ~15 pól = wiele MB XML. W FrankenPHP worker mode aplikacja żyje w pamięci między requestami — budowa DOM (DOMDocument) lub load-all-into-memory dla 50k SKU zabije worker na OOM (CLAUDE.md §3.10). Musimy udowodnić benchmarkiem że FeedGenerator generuje 50k SKU w <30s przy płaskim RAM ~50-95 MiB i twardo pod 256 MiB, streamując przez XMLWriter z EntityManager::clear() per chunk. To ten sam kontrakt wydajnościowy co istniejący ExportMemoryBenchmarkTest (256 MiB threshold) i IMP2. Bez benchmarku 'cache-and-serve' opiera się o niesprawdzone założenie że regeneracja jest tania.
- **Stan obecny:** Silnik feedu (FeedGenerator reużywający ExportBuilder, streaming XMLWriter, clear() per chunk) powstaje w M2. Istnieje wzorcowy ExportMemoryBenchmarkTest.php (THRESHOLD_BYTES = 256*1024*1024, STREAMING_DELTA_BYTES = 128 MiB, grupa 'import-benchmark', keyset walk + batch-prefetch per page, seed set-based DB-side) oraz ExportBenchmarkCommand.php. Endpoint /api/metrics eksponuje metryki Prometheus (QueryDurationHistogram). Brak benchmarku dedykowanego generacji feedu 50k oraz brak alertu frankenphp_worker_memory_bytes > 256MB udokumentowanego dla ścieżki feed.
- **Zakres:**
  - Napisz FeedMemoryBenchmarkTest (Integration KernelTestCase, grupa 'import-benchmark' jak Export) który seeduje 50k obiektów set-based (DB-side SQL, negligible PHP memory) i generuje pełny feed XML przez FeedGenerator do bufora/temp, nie do DOM.
  - Zmierz peak memory (memory_get_peak_usage(true)) i asertuj < 256 MiB (THRESHOLD_BYTES) oraz delta streaming < ~128 MiB — reużyj stałych i wzorca z ExportMemoryBenchmarkTest.
  - Asertuj płaskość RAM: po wygenerowaniu 50k itemów zużycie pamięci nie rośnie liniowo z liczbą itemów (próbkowanie co N chunków — peak stabilny ~50-95 MiB poza jednorazowymi buforami).
  - Zmierz czas generacji i asertuj < 30s dla 50k (cel z §6.12) — z marginesem na CI (np. miękki próg + log rzeczywistego czasu, twardy fail dopiero powyżej bezpiecznego sufitu).
  - Zweryfikuj że FeedGenerator woła EntityManager::clear() per chunk (dziedziczy AbstractBatchHandler lub jawny clear po flush w pętli) — jeśli custom PHPStan rule flush-bez-clear tego nie łapie, dołóż asercję integracyjną (np. licznik obiektów w Identity Map nie rośnie).
  - Dodaj wariant benchmarku dla najcięższego szablonu (Ceneo z repeatable <imgs>/<attrs> — więcej węzłów per item) żeby zmierzyć worst-case struktury.
  - Udokumentuj / potwierdź istnienie alertu Prometheus frankenphp_worker_memory_bytes > 256MB scrapowanego z /api/metrics; jeśli reguła feed-specyficzna wymaga wpisu — dodaj ją w config alertów (bez zmiany progu; alert jest reużywalny globalnie dla workera).
  - Podłącz benchmark do dedykowanego CI stepu 'import-benchmark' (jak ExportMemoryBenchmarkTest), nie do głównej matrycy szardów.
- **Poza zakresem:**
  - Optymalizacja ExportBuilder / keyset walk — to reuse M2/istniejące; benchmark tylko dowodzi kontraktu, fix regresji jeśli benchmark czerwony.
  - Benchmark ścieżki serwowania publicznego URL (to O(rozmiar pliku) cache-read, nie O(katalog)) — mierzymy generację, nie serwowanie.
  - Adaptive scheduling / regeneracja tylko przy zmianie katalogu — hook (XMLF-P6-04).
  - Full-stack load test crawlera (wiele równoległych pullów) — poza MVP hardeningu.
- **AC:**
  - [ ] FeedMemoryBenchmarkTest generuje feed dla 50k obiektów i memory_get_peak_usage(true) < 256 MiB (twardy fail powyżej).
  - [ ] Delta streaming (peak - baseline) < ~128 MiB; RAM płaski (nie rośnie liniowo z liczbą itemów — udowodnione próbkowaniem).
  - [ ] Czas generacji 50k SKU < 30s (cel; log rzeczywistego czasu w output benchmarku, twardy sufit z marginesem CI).
  - [ ] Test dowodzi braku DOMDocument w ścieżce feedu (streaming XMLWriter; np. brak wzrostu pamięci proporcjonalnego do rozmiaru drzewa).
  - [ ] Identity Map nie akumuluje: liczba zarządzanych encji po N chunkach stabilna (clear() działa).
  - [ ] Benchmark biegnie w dedykowanym CI stepie 'import-benchmark', zielony.
  - [ ] Wariant Ceneo (repeatable nodes) również pod 256 MiB.
  - [ ] Alert Prometheus frankenphp_worker_memory_bytes > 256MB potwierdzony/obecny; /api/metrics eksponuje metrykę.
- **Smoke:**
  - pnpm stack:up; zaloguj admin@demo.localhost / changeme.
  - Zaseeduj większy zestaw produktów (skrypt seed lub import) i utwórz feed Google Shopping obejmujący wszystkie.
  - Kliknij 'Regeneruj teraz'; obserwuj FeedRun -> done, sprawdź item_count i duration_ms w monitorze.
  - Otwórz publiczny URL: curl -s 'https://pim.localhost/feeds/{token}.xml' -o /tmp/feed.xml -w '%{http_code} %{size_download}\n' -> 200 + rozsądny rozmiar; xmllint --noout /tmp/feed.xml -> exit 0.
  - Sprawdź metryki: curl -s https://pim.localhost/api/metrics | grep -i worker_memory -> wartość < 256*1024*1024 podczas/po regeneracji.
  - Console: brak czerwonych errorów; worker nie restartuje się (brak OOM w logach docker).
- **Reuse:** apps/api/tests/Integration/Export/ExportMemoryBenchmarkTest.php — bezpośredni wzorzec: THRESHOLD_BYTES 256MiB, STREAMING_DELTA_BYTES 128MiB, #[Group('import-benchmark')], seed set-based, keyset walk · apps/api/src/Benchmark/Export/ExportBenchmarkCommand.php — wzorzec komendy benchmarku · apps/api/src/Export/Application/Builder/ExportBuilder.php — reużywany generator itemów (chunking + caller-owned clear, CLEAR_INTERVAL=200), obiekt pod benchmark feedu · apps/api/src/Export/Feed/Application/ — FeedGenerator (M2) jako obiekt pod benchmark · apps/api/src/Export/Infrastructure/Writer/ — XmlWriterCore/XmlFeedWriter (M0/M2) streaming XMLWriter (nigdy DOMDocument) · apps/api/src/Shared/Infrastructure/Metrics/QueryDurationHistogram.php — wzorzec metryk Prometheus na /api/metrics · apps/api/config/packages/doctrine.yaml — dbal.logging false w prod (256 MiB ceiling), logger nie akumuluje w benchmarku
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §6.12 (wydajność/worker memory, cel 50k <30s, ~50-95 MiB, alert >256MB), §8 faza 10 (benchmark 50k), §11 (ryzyko memory FrankenPHP) · CLAUDE.md §3.10 — FrankenPHP worker memory management, AbstractBatchHandler, clear() per chunk, Prometheus alert 256MB · IMP2-2.6 / #1482, AUD-015/AUD-016 / #1632 — precedens streaming export benchmark
- **DoD:** standard.

### XMLF-P6-04: docs(export): deferred-hooks backlog and user-facing feed documentation
- **Typ:** `docs` · **Cls:** DOCS · **Milestone:** M6 · **Est:** 4-6h · **Risk:** low · `[DEF]`
- **Blocked by:** XMLF-P3-01, XMLF-P4-01, XMLF-P5-01 · **Blocks:** —
- **Po co:** Plan §7 świadomie wycina osiem obszarów z MVP (import XML z guardem XXE, push FTP/SFTP/S3, silnik dowolnych transformacji, Allegro predef, feedy nie-produktowe, AI-assisted mapping, adaptive scheduling, webhook feed.regenerated). Bez zapisanego backlogu te decyzje 'znikną' i przy pierwszym painie ktoś je zaimplementuje ad-hoc bez kontekstu (dlaczego odłożone, jak reużyć istniejące klocki). Równolegle pilot-tenant potrzebuje user-facing dokumentacji: jak stworzyć feed, jak wygląda URL, jak podać go do Google Merchant/Ceneo, co znaczy raport zdrowia. To domyka gate 'launch' — hardening bez dokumentacji odbiorcy nie jest gotowy do soft-launchu.
- **Stan obecny:** Świadome odejścia są opisane prozą w planie §1, §7 i §11, ale nie istnieją jako śledzone [DEF] tickety w backlogu ani jako GitHub Issues z labelem deferred. Brak user-facing dokumentacji feedów (jak w epik-04 obiecane 'Feeds'). Repo trzyma backlogi feature'ów jako pliki w Project Plan/ (wzorzec feature-*-tickets.md) + Issues/milestones.
- **Zakres:**
  - Utwórz plik backlogu deferred-hooków w Project Plan/ (wzorzec feature-*-tickets.md) z ośmioma [DEF] ticketami — po jednym per hook z §7: (1) Import XML dostawca->PIM z twardym guardem XXE (libxml no_external_entities), reuse silnika IMP2 (parser XML -> ValueWriteCore); (2) Push feedu do FTP/SFTP/S3 klienta, reuse SsrfGuard + AesGcmEncryptionService na credentiale; (3) Silnik dowolnych transformacji (wyrażenia, if/then wieloetapowe, lookup z tabeli); (4) Allegro jako predef szablon (feed/oferty + kategorie przez external_code CHC); (5) Feed dla innych ObjectType niż product (kategorie, zasoby jako feed); (6) AI-assisted mapping / Cmd+K ('zbuduj feed Google z SEO'), reuse wzorca PRD-eksportów §10.2; (7) Adaptive scheduling (regeneruj tylko przy zmianie katalogu) + pełna migawka transakcyjna + jitter między tenantami; (8) Webhook feed.regenerated (dziś pull + Mercure w UI).
  - Każdy [DEF] ticket: krótki business_context (kiedy pain), co reużyć (dokładne ścieżki z reuse-mapy planu §4), dlaczego odłożone (decyzja operatora / architektura), szacunek zgrubny. Bez otwierania GitHub Issue na starcie (label 'deferred'/'later' gdy pain się pojawi).
  - Dopisz jitter między tenantami jako explicit follow-up w hooku adaptive scheduling — plan §4 nota: CronExpressionParser+ScheduleDispatcher nie mają wbudowanego jittera, feed scheduling to reużywa, jitter jest udokumentowanym follow-upem.
  - Napisz user-facing dokumentację feedów w docs/ (np. docs/feeds/ lub docs/export/feeds.md): czym jest feed vs eksport ad-hoc, jak utworzyć feed z szablonu (Google/Ceneo/Meta/custom), mapowanie pól + transformacje (zamknięta lista), filtr i scope (1 locale/1 waluta/kanał), harmonogram, jak skopiować/rotować/revokować URL+token, jak podać URL do Google Merchant Center / Ceneo (w tym opcjonalny HTTP Basic), jak czytać raport zdrowia (skip_invalid vs include_with_warning) i monitor runów.
  - Dodaj sekcję 'ograniczenia MVP' w user-docs linkującą do deferred-hooków (co jeszcze nie działa: import, push, Allegro, multi-locale w jednym feedzie) — spójne z §11 open questions.
  - Zaktualizuj agent/current_status.md (zamknięcie epiku XMLF) i odhacz odpowiednie pozycje — jako część deliverable §10 planu.
- **Poza zakresem:**
  - Implementacja któregokolwiek hooka — to backlog, nie kod.
  - Otwieranie GitHub Issues dla [DEF] ticketów na starcie (flag [DEF] = brak issue do czasu painu).
  - Threat model / security-checklist RBAC (osobne Phase 6 RBAC tickety #720/#722).
  - Tłumaczenie user-docs na wiele języków poza pl/en jeśli repo trzyma docs w jednym języku (docs .md = polski per konwencja repo).
- **AC:**
  - [ ] Istnieje plik backlogu w Project Plan/ z 8 [DEF] ticketami (import XML+XXE, push FTP/SFTP/S3, arbitrary transforms, Allegro predef, non-product feeds, AI-assisted mapping, adaptive scheduling, feed.regenerated webhook).
  - [ ] Każdy [DEF] ticket cytuje konkretne klocki do reużycia z reuse-mapy (SsrfGuard, AesGcmEncryptionService, IMP2 ValueWriteCore, CHC external_code, CronExpressionParser) i powód odłożenia.
  - [ ] Hook import XML explicit wymienia guard XXE (libxml no_external_entities) jako twardy wymóg.
  - [ ] Hook adaptive scheduling odnotowuje jitter między tenantami jako follow-up (nota z §4).
  - [ ] Istnieje user-facing dokumentacja feedów w docs/ pokrywająca: tworzenie feedu, mapowanie+transformacje, scope/filtr, harmonogram, URL+token (kopiuj/rotate/revoke), integracja z Google Merchant/Ceneo, raport zdrowia, monitor.
  - [ ] User-docs zawiera sekcję 'ograniczenia MVP' linkującą do deferred-hooków.
  - [ ] agent/current_status.md zaktualizowany o zamknięcie epiku XMLF.
- **Smoke:**
  - Otwórz plik backlogu deferred-hooków w Project Plan/ i potwierdź 8 [DEF] ticketów z reuse + powodem odłożenia.
  - Otwórz user-docs feedów w docs/ i przejdź krok po kroku instrukcję 'utwórz feed z szablonu Google' — zweryfikuj że opisane kroki odpowiadają realnemu UI na https://pim.localhost (zaloguj admin@demo.localhost / changeme, wejdź w Konfigurator -> Feedy).
  - Zweryfikuj że instrukcja kopiowania URL feedu działa: skopiuj URL wg docs, curl -s '{url}' -w '%{http_code}\n' -> 200; xmllint --noout na pobranym pliku -> exit 0 (dowód że dokumentacja opisuje działający flow).
  - Sprawdź że sekcja 'ograniczenia MVP' wymienia import/push/Allegro/multi-locale jako niedostępne (spójne z §7/§11).
- **Reuse:** Project Plan/feature-konfigurator-xml-plan.md §7 (lista hooków) i §4 (reuse-mapa) — źródło treści backlogu · apps/api/src/Import/ — SsrfGuard (#1475) + ValueWriteCore (IMP2) cytowane w hookach push i import XML · apps/api/src/Shared/Infrastructure/Crypto/AesGcmEncryptionService.php — cytowany w hooku push (credentiale FTP/SFTP) · apps/api/src/Import/Application/Service/CronExpressionParser.php — cytowany w hooku adaptive scheduling + nota o braku jittera · apps/api/src/Channel/Domain/Entity/ChannelCategoryNode.php — external_code cytowany w hooku Allegro · agent/current_status.md — aktualizacja po zamknięciu epiku (deliverable §10) · Project Plan/PRD/PRD-PIM-exports.md §10.2 — wzorzec AI-assisted mapping cytowany w hooku AI
- **Referencje:** Project Plan/feature-konfigurator-xml-plan.md §1 (poza zakresem), §7 (MVP vs hooki — 8 pozycji), §9 (brief UI, źródło user-docs kroków), §11 (open questions: Allegro, multi-locale), §10 (deliverable: backlog + update current_status) · epik-04-publikacje.md §3.3 (Feeds — obietnica user-facing), PRD-PIM-exports.md §6.3/§13 (XML deferred), §10.2 (AI mapping) · feature-imports-v2-tickets.md — wzorzec formatu pliku backlogu
- **DoD:** standard (docs-only — bez bramek kodowych).

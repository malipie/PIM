# Plan implementacji — Konfigurator XML: feedy produktowe + format eksportu (Cortex PIM)

> **Status:** draft do rozbicia na tickety. Utworzony 2026-07-01.
> **Kontekst:** operator (Marcin) chce **konfiguratora XML** — narzędzia, w którym klient bez developera tworzy feedy produktowe XML dla kanałów/marketplace (Google Shopping, Ceneo, Meta/Facebook Catalog, custom B2B), mapuje atrybuty PIM na węzły XML, filtruje asortyment, ustala harmonogram regeneracji i dostaje stabilny URL feedu. Dodatkowo XML dochodzi jako **format w istniejącym kreatorze eksportu** (obok XLSX/CSV) dla dumpów ad-hoc.
> **To domknięcie znanej, ale niezaprojektowanej luki:** epik-04 (`Project Plan/UI/epik-04-publikacje.md` §3.3 „Feeds", US-EP04-NEW-001/002) i epik 0.10 (API Configurator, „XML feeds + Google Shopping predef") obiecują tę funkcję; PRD eksportów (`Project Plan/PRD/PRD-PIM-exports.md` §6.3, §13) świadomie wyciął XML z MVP do „Fazy 1"; uniwersalny konfigurator API (`feature-api-configurator-uniwersalny-plan.md` §7) świadomie wyciął „GraphQL/SOAP/XML" (jest REST/JSON-only). Ten dokument wypełnia tę lukę.
> **Powiązania:** `01-architektura-pim.md` §5 (Channel), §7 (integracje), §3.10 (memory FrankenPHP); `src/Export/` (silnik eksportu — reuse); `src/Channel/` (kanały + publication profile); ADR-0018 (channel publication profile), ADR-0015 (cross-BC bare UUID), ADR-0020 (OpenAPI custom route), ADR-0011 (ORM XML mapping), ADR-0009 (ObjectType).
> **Ten dokument NIE jest backlogiem ticketów.** Zawiera architekturę + dwa briefy: dla agenta projektującego UI (§9) i dla agenta przepisującego plan na tickety (§10). Zakres i format spójne z `feature-api-configurator-uniwersalny-plan.md`.

---

## 0. TL;DR

Budujemy **jeden obszar „Konfigurator XML"** z dwoma trybami, które współdzielą jeden silnik serializacji:

1. **Feedy** (persistent) — klient definiuje w UI feed produktowy XML: wybiera szablon (Google Shopping / Ceneo / Meta / **custom**), mapuje pola szablonu na atrybuty PIM, filtruje asortyment, ustala scope (locale/kanał/waluta), harmonogram regeneracji i dostaje **stabilny URL feedu z autoryzacją**, po który crawler marketplace'u sięga cyklicznie. To wizja epik-04 „Feeds".
2. **Eksport ad-hoc** (one-shot) — XML jako dodatkowy `ExportFormat` w istniejącym 4-krokowym kreatorze eksportu (obok XLSX/CSV). Wolny wybór kolumn + generyczny wrapper `<products><product>…`, jednorazowy download. Zero nowego flow UI — odblokowanie kafelka „XML" (dziś disabled „wkrótce", EXR-D1).

Kluczowa decyzja architektoniczna (spójna z zasadą uniwersalnego konfiguratora API): **konfigurator XML to warstwa „szablon + mapowanie + transformacje + delivery" NAD istniejącym silnikiem Export.** Feed nie pisze własnego selektora produktów ani odczytu wartości — reużywa `ExportBuilder` (który już zwraca `Generator<array<string,string>>` z chunkowaniem i `EntityManager::clear()` po stronie wywołującego). Dokładamy wyłącznie: **deklaratywny descriptor XML, streamingowy `XmlFeedWriter`, lekką warstwę transformacji, model feedu z URL/harmonogramem/cache, i predefiniowane szablony.**

Jedno zdanie pozycjonujące: *„Feed Google Shopping albo custom XML dla partnera B2B w 5 minut, bez developera — mapowanie pól, filtr, podgląd na żywo, stabilny URL i harmonogram; ten sam silnik co eksport, więc poprawki w Export automatycznie poprawiają feedy."*

---

## 1. Cel i zakres

**Cel:** klient (lub pilot-tenant) tworzy i utrzymuje feedy XML oraz eksportuje katalog do XML bez dotykania kodu. PIM pozostaje źródłem prawdy; feed to zawsze **read-only projekcja** danych produktowych do konkretnego formatu odbiorcy.

**W zakresie (potwierdzone z operatorem 2026-07-01):**
- **Feedy wychodzące XML** — silnik generowania + mapowanie pól + filtr asortymentu + scope (locale/kanał/waluta) + harmonogram (cron) + **URL feedu z autoryzacją** + monitoring runów.
- **Szablony predefiniowane:** **Google Shopping** (Merchant Center), **Ceneo**, **Meta / Facebook Catalog** + **custom** (klient buduje własny descriptor od zera lub klonuje predef).
- **XML jako format eksportu ad-hoc** — nowy `ExportFormat::Xml` w kreatorze EXR, generyczny wrapper, download jak XLSX/CSV.
- **Podgląd na żywo** — rzeczywisty XML dla ~5 sample produktów przed zapisem (wizja epik-04 „Preview output").
- **Bezpieczeństwo:** poprawne escapowanie XML (analog lekcji CSV formula injection IMP2-2.8), izolacja tenantów, token feedu (nie klucz tenanta), rate-limit publicznego endpointu, brak sekretów w feedzie.

**Poza zakresem (świadomie; hooki §7):**
- **Import XML** (dostawcy przysyłający XML → PIM) — operator wybrał zakres bez importu. Gdy wejdzie: osobny adapter w silniku IMP2 (parser XML → `ValueWriteCore`), z twardym guardem XXE. Nie ten dokument.
- **Push feedu do FTP/SFTP/S3 klienta** — MVP serwuje feed z URL (pull model). Push = hook (reuse SSRF-safe client + `AesGcmEncryptionService` na credentiale).
- **Allegro jako predef szablon** — operator nie zaznaczył; Allegro to bardziej API ofert niż klasyczny feed XML. Architektura kategorii kanałowych (CHC `external_code`) i custom descriptor **umożliwiają** feed Allegro, ale gotowego szablonu w MVP nie ma.
- **Silnik dowolnych transformacji** (skrypty, wyrażenia warunkowe wieloetapowe) — MVP ma zamkniętą listę lekkich transformacji (§6.5). Reszta = hook.
- **Feed inny niż produktowy** (kategorie, zasoby jako feed) — MVP tylko `product` (+ warianty flat). Inne ObjectType = hook.
- **AI-assisted mapping / Cmd+K** („zbuduj mi feed Google z SEO") — Faza 2, reuse wzorca z PRD eksportów §10.2.

**Relacja do sąsiednich feature'ów (żeby nie dublować):**

| Feature | Co robi | Granica względem konfiguratora XML |
|---|---|---|
| **Export (EXR)** | XLSX/CSV download + profile + sesje | Konfigurator XML **reużywa** jego silnik (`ExportBuilder`). Ad-hoc XML to nowy format w tym samym kreatorze. |
| **API Configurator konsument (APIC)** | Uniwersalny REST/JSON sync dwukierunkowy | Rozłączne: APIC to sync push/pull JSON-em; feed XML to statyczna projekcja pod crawler. Feed **nie** idzie przez APIC. |
| **API Configurator producent** (`ApiConfigurator`) | Nasze API jako produkt + klucze + webhooki | Współdzielimy shell UI „Konfigurator" i wzorzec `ApiKey`. Feed to trzeci obywatel tego obszaru obok „Połączenia" i „Moje API". |
| **Channel + PublicationProfile (ADR-0018)** | Per-kanał allow-lista atrybutów/locale + `column_aliases` | Feed **reużywa** publication profile jako scope + zalążek mapowania (`column_aliases` = mapowanie atrybut→pole wyjściowe). |
| **Channel Categories (CHC)** | Drzewa kategorii kanałów + `external_code` | Feed czyta `external_code` węzła jako ID kategorii marketplace (Ceneo `<cat>`, przyszły Allegro). |

---

## 2. Decyzje produktowe (zatwierdzone przez operatora 2026-07-01)

| # | Decyzja | Wybór | Konsekwencja architektoniczna |
|---|---------|-------|-------------------------------|
| 1 | Zakres | **Feedy wychodzące + XML jako format eksportu ad-hoc** (bez importu XML) | Dwa tryby, jeden silnik serializacji. Import XML = hook (osobny adapter IMP2 z guardem XXE). |
| 2 | Szablony predef | **Google Shopping + Ceneo + Meta/Facebook Catalog + custom** | 3 seedowane deskryptory `is_built_in` + edytor custom. Allegro osiągalny przez custom, bez gotowego szablonu. |
| 3 | Transformacje | **Lekka, zamknięta lista** (static, default/fallback, format price/date/number, concat/template, enum-map, strip-html/CDATA) | Feed WYMAGA transformacji (Google żąda `price` „123.45 PLN", `availability` enum) — świadome odejście od zasady „1:1" z APIC. Dowolne transformacje = hook. |
| 4 | Model dostarczania | **Pull: cache-and-serve** (regeneracja wg harmonogramu → plik w MinIO → publiczny URL z tokenem) | Nie generujemy 50k SKU na każde uderzenie crawlera. Reuse Schedule + MinIO + gzip + ETag. Push do FTP = hook. |
| 5 | Autoryzacja URL | **Token feedu w URL** (nieodgadywalny) + opcjonalny **HTTP Basic** | Crawlery (Google Merchant) nie wysyłają nagłówków auth; token w ścieżce + opcjonalny Basic (Google go wspiera). Token ≠ klucz tenanta (osobny lifecycle, revokowalny per feed, read-only). |
| 6 | Deliverable | **Dokument projektowy** (ten plik) | Bez backlogu ticketów i bez wireframe — te powstają w §9/§10 jako briefy dla kolejnych agentów. |

Decyzje domyślne (nie wymagały pytania, wynikają z architektury repo):
- **Umiejscowienie silnika:** rozszerzenie kontekstu `Export` o serializację XML + nowy pod-obszar feedów; **nie** nowy bounded context (uzasadnienie §6.1, mini-ADR §12).
- **Reuse Export zamiast własnego selektora** — analogicznie do zasady „outbound reużywa Export engine" z `feature-api-configurator-uniwersalny-plan.md` §6.8.
- **Cross-BC tylko przez `*\Contracts\*`** (Deptrac) — feed czyta Channel/Catalog wyłącznie przez istniejące porty (`ChannelResolverInterface`, `ChannelPublicationResolverInterface`).

---

## 3. Dwa tryby: Feed vs Eksport ad-hoc

To rozróżnienie organizuje cały plan. Oba produkują XML tym samym silnikiem, ale różnią się cyklem życia, konfiguracją i UI.

| | **Feed** (persistent) | **Eksport ad-hoc** (one-shot) |
|---|---|---|
| Po co | Ciągła syndykacja do marketplace/partnera (crawler pulluje cyklicznie) | Jednorazowy zrzut katalogu do XML (dla dewa/partnera/backupu) |
| Konfiguracja | Szablon + mapowanie pól + transformacje + filtr + scope + harmonogram | Wolny wybór kolumn + scope (jak XLSX/CSV dziś) |
| Struktura XML | Wg szablonu odbiorcy (`<item>` Google, `<o>` Ceneo, custom) | Generyczny wrapper `<products><product><sku>…</sku>…</product></products>` |
| Trwałość | Encja `FeedProfile` + stabilny URL + cache w MinIO | Sesja `ExportSession` (jak dziś), plik jednorazowy |
| Uruchomienie | Cron (regeneracja) + „Regeneruj teraz" + pull crawlera z URL | „Eksportuj" w kreatorze → download (sync <100 / async ≥100, jak dziś) |
| Encje | `FeedProfile`, `FeedRun`, `FeedRunLog` (nowe) | `ExportSession`/`ExportLog` (istniejące) + `ExportFormat::Xml` |
| UI | Nowa zakładka „Feedy" w Konfiguratorze | Odblokowany kafelek „XML" w kroku 2 kreatora EXR |
| Wysiłek | ~gros pracy (§6.2–6.9) | mały (§6.10 — nowy writer + enum + kafelek) |

**Wspólny rdzeń:** obie ścieżki wołają `ExportBuilder` → dostają `Generator<array<string,string>>` (mapa `kolumna → wartość` per produkt, ze scope locale/kanał i uprawnieniami atrybutów) → przepuszczają przez `XmlWriterCore` (streaming `XMLWriter`, escaping, memory-safe). Feed dokłada nad tym: descriptor szablonu (który klucz → który węzeł/atrybut/namespace), transformacje i delivery. Eksport ad-hoc używa generycznego mapowania klucz→element bez descriptora.

---

## 4. Stan obecny (as-is) — co reużywamy

Zbadane w kodzie 2026-07-01. **Nie budujemy od zera — montujemy na istniejących klockach silnika Export i Channel.**

| Klocek | Lokalizacja | Rola w konfiguratorze XML |
|--------|-------------|----------------------------|
| **`ExportBuilder`** | `Export/Application/Builder/ExportBuilder.php` | **Rdzeń produkcji itemów.** Zwraca `Generator` yielding `array<string,string>` (klucze = klucze kolumn `sku`/`description.pl`, wartości = zserializowane stringi). Caller owns chunking + `EntityManager::clear()` → memory-bounded. Feed i ad-hoc XML **konsumują ten sam generator**. |
| **`ColumnResolver` + `ColumnDefinition`** | `Export/Application/Builder/` | Rozwiązanie planu kolumn (atrybut → klucz kolumny, locale/channel expansion). Feed: plan kolumn = zbiór atrybutów-źródeł z mapowań. |
| **`ValueSerializer`** | `Export/Application/Builder/ValueSerializer.php` | Serializacja wartości JSONB → string (multi-value pipe, blank dla null, asset URL). Feed reużywa; transformacje feedu działają **nad** wynikiem serializera. |
| **`PublicationColumnPlanner` + `ChannelPublicationResolverInterface`** | `Export/Application/Builder/` + `Channel/Contracts/` (ADR-0018) | **Per-kanał allow-lista atrybutów/locale + `column_aliases`.** `channel_publication_profiles.column_aliases (jsonb)` = mapowanie atrybut→pole wyjściowe — **gotowy zalążek mapowania feedu**. Scope feedu do kanału = wybór publication profile. |
| **`RowWriter` (kontrakt) + `CsvStreamWriter` / `XlsxStreamWriter`** | `Export/Infrastructure/Writer/` | Wzorzec streamingowego writera. **UWAGA:** `RowWriter` jest **pozycyjny** (`writeHeaders(list<string>)` + `writeRow(list<string>)`) — niewystarczający dla XML, który potrzebuje **kluczy** (nazw elementów). Wprowadzamy równoległy kontrakt `ItemWriter` (asocjacyjny) — §6.3. |
| **`ExportFormat` enum** | `Export/Domain/Enum/ExportFormat.php` | Dziś `xlsx`/`csv` z komentarzem „XML/JSON deferred to Faza 1". Dokładamy `case Xml = 'xml'` (mime `application/xml`, ext `xml`) — ad-hoc XML. |
| **`ExportJobHandler` (async) + `SyncExportRunner` (sync)** | `Export/Application/{Async,Sync}/` | Ścieżki uruchomienia + progres Mercure + anulowanie + zapis `ExportSession`. Ad-hoc XML reużywa 1:1. Feed: analogiczny `FeedRunHandler` (regeneracja async). |
| **`ExportProfile`/`ExportSession`/`ExportLog`** | `Export/Domain/Entity/` | Wzorzec encji konfiguracyjnej + sesji + logu. Kalka pod `FeedProfile`/`FeedRun`/`FeedRunLog`. |
| **`CronExpressionParser` + `ScheduleDispatcherService` (+ encja `ImportSchedule`)** | `Import/Application/Service/` (parser, dispatcher) | **Harmonogram regeneracji feedu — reuse 1:1** (jak w APIC §6.7). Cron + dispatcher + tracking runów + jitter między tenantami. |
| **`ApiKey` (Argon2id) + rate-limit** | `ApiConfigurator/Infrastructure/Security/` | Wzorzec tokenu feedu (hash + weryfikacja constant-time) i rate-limitingu publicznego endpointu. Token feedu = osobny byt, nie klucz tenanta. |
| **`AesGcmEncryptionService` + `EncryptedSecret`** | `Shared/.../Crypto/` | Szyfrowanie credentiali HTTP Basic feedu (odwracalne — trzeba je porównać z przychodzącym). Push do FTP (hook) użyje tego samego. |
| **Channel Categories `external_code` (CHC)** | `Channel/` (CHC-channel-categories) | ID kategorii marketplace na węźle drzewa kanału. Feed czyta `external_code` zmapowanego węzła → wstawia jako `<cat>` (Ceneo) / kategoria Allegro (custom). |
| **Advanced filter DSL** | FE `useFilterDslState` + `AdvancedFilterPanel`; BE `filter_snapshot` JSONB | Filtr asortymentu feedu = ten sam DSL co lista produktów i eksport (zero drugiej implementacji). Persystowany w `FeedProfile.filter`. |
| **MinIO / Flysystem** | `Shared/` + infra | Cache wygenerowanego feedu: `feeds/{tenant_id}/{feed_id}.xml[.gz]`. Wzorzec `exports/{tenant_id}/…`. |
| **Mercure SSE** | infra | Progres regeneracji feedu (jak `export-jobs.{session_id}`). |
| **Catalog: `ObjectValue` envelope + `attributes_indexed` GIN + `Provenance`** | `Catalog/Domain/` | Źródło wartości (przez ExportBuilder). Feed = read-only, `provenance` bez zmian. |
| **security.yaml `PUBLIC_ACCESS` (z audytu W0-5/#1577)** | `apps/api/config/packages/security.yaml` | Wzorzec publicznego endpointu bez sesji — publiczny URL feedu (tenant rozwiązywany z tokenu, potem RLS/TenantFilter). |
| **`CustomRouteOpenApiFactory` (ADR-0020)** | `Shared/OpenApi/` | Custom trasy (feed URL, regenerate, preview) muszą trafić do `docs/api-spec/v0.json` — bramka „OpenAPI spec drift". |
| **SSRF guard (`SsrfGuard`, z #1475)** | `Import/.../Media/` | Tylko dla hooka „push do FTP/URL klienta". MVP pull-only tego nie potrzebuje. |

**Wniosek:** realnie nowego kodu domenowego jest niewiele — descriptor + `XmlWriterCore`/`XmlFeedWriter` + `FeedProfile`/`FeedRun` + delivery/URL + 3 seedy szablonów. Reszta to spięcie istniejących silników. To trzyma złożoność w ryzach i sprawia, że poprawki w Export automatycznie poprawiają feedy (ta sama zaleta co „reuse Import/Export" w APIC).

---

## 5. Research — best practices → zasady projektowe

Źródła w §13. Z każdego wyciągamy konkretną zasadę.

1. **Google Merchant / Google Shopping feed spec** — format to RSS 2.0 z namespace `xmlns:g="http://base.google.com/ns/1.0"`: `<rss><channel><title/><link/><description/><item>…</item></channel></rss>`. Item ma zamknięty zestaw pól `g:` z twardymi regułami: `g:id` (wymagany, ≤50 zn.), `g:title` (≤150), `g:description` (≤5000, HTML dozwolony w CDATA), `g:link`, `g:image_link`, `g:availability` (`in_stock|out_of_stock|preorder|backorder`), `g:price` (format „123.45 PLN" — liczba + spacja + ISO 4217), `g:brand`, `g:gtin`/`g:mpn`, `g:condition` (`new|refurbished|used`), `g:google_product_category`, `g:shipping`/`g:tax` (zagnieżdżone). → **Zasada:** szablon = deklaratywny descriptor ze **slotami** (pole + typ węzła + wymagalność + reguła formatu + limit długości); walidacja per-pole per-produkt przy generacji; wsparcie zagnieżdżenia (`g:shipping` ma dzieci) i CDATA dla opisów.
2. **Ceneo XML spec** — inny root: `<offers version="1"><o id="" url="" price="" avail="" stock="" ...><cat><![CDATA[…]]></cat><name/><imgs><main url=""/><i url=""/></imgs><desc/><attrs><a name="Producent">…</a></attrs></o></offers>`. Część danych to **atrybuty XML** (`o price=""`), część to **elementy** (`<name>`), część **powtarzalna** (`<imgs><i>`), a atrybuty produktu to `<a name="">` (nazwa jako atrybut, wartość jako treść). → **Zasada:** descriptor musi rozróżniać **element vs atrybut XML**, obsługiwać **węzły powtarzalne** (galeria → wiele `<i>`) i **mapowanie klucz-wartość** (atrybuty PIM → lista `<a name="…">…</a>`).
3. **Meta / Facebook Catalog feed** — bardzo zbliżony do Google (te same pola `g:`, RSS-like albo CSV), z drobnymi różnicami (`availability` z podkreśleniem, `condition`, `additional_image_link`). → **Zasada:** szablony to warianty tego samego modelu descriptora; Meta ≈ Google z inną tabelą slotów — potwierdza słuszność deklaratywnego descriptora zamiast kodu per-marketplace.
4. **Feed managery (BaseLinker Kreator XML, Channable, DataFeedWatch/Feedonomics, Productsup)** — wszystkie mają: (a) bibliotekę gotowych szablonów per kanał, (b) mapper pole-szablonu ↔ pole-źródła z **regułami** (static, if/then, format, replace, combine), (c) **filtr** produktów wchodzących do feedu, (d) **harmonogram** regeneracji, (e) **preview** + walidację przed publikacją, (f) stabilny URL feedu. → **Zasada:** to jest sprawdzony, oczekiwany zestaw funkcji — MVP musi mieć wszystkie sześć w minimalnej formie; brak któregokolwiek = „gorszy niż BaseLinker" (analog do lekcji z PRD eksportów).
5. **Cache-and-serve dla feedów skali** — crawler Google/Ceneo pulluje feed cyklicznie i bywa duży (50k SKU × 15 pól = wiele MB). Generowanie na każde uderzenie = DoS na własny worker. Standard: regeneracja wg harmonogramu → statyczny plik → serwowanie z cache + `ETag`/`Last-Modified` + `gzip`. → **Zasada:** MVP = cache-and-serve (decyzja #4); URL zwraca ostatni wygenerowany plik; „Regeneruj teraz" i cron odświeżają cache; conditional GET (304) i gzip obowiązkowe.
6. **XML injection / poprawność** (analog lekcji CSV formula injection, IMP2-2.8 / #1552) — wartości produktowe trafiające do XML muszą być escapowane (`& < > " '`), pozbawione **nielegalnych znaków sterujących XML 1.0**, a HTML w opisach albo strip-owany, albo owinięty w CDATA (nie surowo). → **Zasada:** cała serializacja przez `XMLWriter` (auto-escaping), sanityzacja control-chars przed zapisem, polityka HTML per-slot (`strip` | `cdata` | `escape`); twardy test „feed jest zawsze well-formed XML" nawet dla śmieciowych danych.
7. **Kursor/monotonia nie dotyczy** (inaczej niż APIC inbound) — feed to pełna projekcja, nie delta-sync; nie ma cursora do dryfu. Za to dochodzi **spójność migawki**: feed generowany podczas edycji katalogu ma odzwierciedlać jeden punkt w czasie (keyset streaming daje read-committed spójność „wystarczająco dobrą"; pełna migawka transakcyjna = hook). → **Zasada:** feed generowany keyset-streamingiem (jak eksport bulk-path, EXR cz.1 #1545); dokumentujemy semantykę spójności („near-snapshot").

---

## 6. Architektura docelowa

### 6.1 Umiejscowienie (bounded context)

- **Silnik serializacji + ad-hoc XML → `src/Export/`** (rozszerzenie). XML to kolejny format eksportu; jego serializer należy obok `CsvStreamWriter`/`XlsxStreamWriter`. `ExportFormat::Xml` + `XmlWriterCore` żyją w Export.
- **Feedy → nowy pod-obszar `src/Export/Feed/`** (a nie osobny bounded context). Uzasadnienie: feed to „ExportProfile z szablonem XML i URL-em" — semantycznie ta sama domena (projekcja katalogu na wyjście). Osobny BC dla ~6 klas domenowych to overhead nieuzasadniony (ta sama logika co ADR-0018 Option C odrzucone). Deptrac: `Export/Feed` może sięgać `Export/*`; do Channel/Catalog wyłącznie przez `*\Contracts\*`.
- **Delivery / publiczny URL → `Export/Feed/Presentation/`** — publiczny kontroler feedu (bez sesji, tenant z tokenu) + kontrolery CRUD/preview/regenerate (uwierzytelnione).
- **Wspólny shell UI → obszar „Konfigurator"** — trzecia zakładka „Feedy" obok „Połączenia" (APIC konsument) i „Moje API" (APIC producent). Wspólny model sekretów (`AesGcm`), audytu (`FeedRun`) i wzorca `ApiKey`.

> Decyzja do potwierdzenia w ADR (szkic §12): `Export/Feed` jako pod-obszar Export vs. rozszerzenie Channel BC (bo feed „opisuje kanał"). Rekomendacja: **`Export/Feed`** — bo silnik reużywa Export w całości, a Channel dostarczamy tylko przez `Contracts` (scope/kategorie). Feed to „jak projektujemy katalog na wyjście", nie „czym jest kanał".

### 6.2 Model domenowy

Wszystkie encje `TenantScoped` + RLS + GUC w workerach (wzorzec IMP2-2.5). Pola konfiguracyjne złożone → JSONB (wzorzec `ExportProfile`/`ApiProfile`).

**`FeedProfile`** — definicja feedu (odpowiednik `ExportProfile` + URL + harmonogram).

```sql
CREATE TABLE feed_profiles (
    id              UUID PRIMARY KEY,                 -- UUIDv7
    tenant_id       UUID NOT NULL REFERENCES tenants(id),
    code            VARCHAR(64) NOT NULL,             -- slug, unikalny per tenant
    name            VARCHAR(255) NOT NULL,
    template_kind   VARCHAR(32) NOT NULL,             -- google_shopping | ceneo | meta | custom
    object_type_id  UUID NOT NULL,                    -- bare ref (ADR-015), MVP zawsze 'product'
    descriptor      JSONB NOT NULL,                   -- deklaratywny szablon XML (§6.3) — dla predef skopiowany z seeda, edytowalny
    field_mappings  JSONB NOT NULL DEFAULT '[]',      -- lista {slot, source, transform} (§6.4)
    filter          JSONB,                            -- filter DSL snapshot (który asortyment wchodzi)
    channel_id      UUID,                             -- bare ref; scope wartości per kanał (?channel=) + publication profile
    publication_channel VARCHAR(64),                  -- ?publication=<channel> (ADR-0018 allow-lista + column_aliases)
    locale          VARCHAR(12),                      -- pojedyncza wersja językowa feedu (feed = 1 locale; multi = wiele feedów)
    currency        VARCHAR(3),                        -- ISO 4217 do formatu price
    schedule_cron   VARCHAR(64),                       -- reuse CronExpressionParser; NULL = tylko manualnie
    delivery        JSONB NOT NULL DEFAULT '{}',      -- {gzip: bool, auth: {type: none|basic, username?, encrypted_password?}}
    token_hash      VARCHAR(255) NOT NULL,            -- hash tokenu URL (Argon2id/HMAC — wzorzec ApiKey); jawny token pokazany raz
    status          VARCHAR(16) NOT NULL,             -- active | paused | error
    last_run_id     UUID,                             -- FK → feed_runs (ostatni run)
    cached_file_path TEXT,                            -- MinIO: feeds/{tenant_id}/{id}.xml[.gz]
    cached_file_size BIGINT,
    cached_item_count INTEGER,
    cached_at       TIMESTAMPTZ,                      -- do ETag/Last-Modified
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (tenant_id, code)
);
CREATE INDEX idx_feed_profiles_tenant ON feed_profiles(tenant_id, updated_at DESC);
CREATE INDEX idx_feed_profiles_schedule ON feed_profiles(status) WHERE schedule_cron IS NOT NULL AND status = 'active';
```

**`FeedRun`** — audyt jednej generacji (odpowiednik `ExportSession` + `SyncRun`).

```sql
CREATE TABLE feed_runs (
    id              UUID PRIMARY KEY,
    tenant_id       UUID NOT NULL REFERENCES tenants(id),
    feed_profile_id UUID NOT NULL REFERENCES feed_profiles(id) ON DELETE CASCADE,
    trigger         VARCHAR(16) NOT NULL,             -- schedule | manual | first_publish
    status          VARCHAR(16) NOT NULL,             -- pending | running | done | error | cancelled
    item_count      INTEGER NOT NULL DEFAULT 0,       -- ile produktów trafiło do feedu
    skipped_count   INTEGER NOT NULL DEFAULT 0,       -- ile pominięto (nie przeszły walidacji required-field)
    warning_count   INTEGER NOT NULL DEFAULT 0,
    file_path       TEXT,                             -- wygenerowany plik (staje się cached_file_path profilu po success)
    file_size_bytes BIGINT,
    duration_ms     INTEGER,
    error_message   TEXT,
    started_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    completed_at    TIMESTAMPTZ
);
CREATE INDEX idx_feed_runs_profile ON feed_runs(feed_profile_id, started_at DESC);
```

**`FeedRunLog`** — linie logu per run (błędy walidacji per produkt → „feed health").

```sql
CREATE TABLE feed_run_logs (
    id           UUID PRIMARY KEY,
    feed_run_id  UUID NOT NULL REFERENCES feed_runs(id) ON DELETE CASCADE,
    level        VARCHAR(8) NOT NULL,                 -- info | warning | error
    object_sku   VARCHAR(255),                        -- którego produktu dotyczy (NULL = feed-level)
    slot         VARCHAR(64),                         -- które pole szablonu (np. g:gtin)
    message      TEXT NOT NULL,                       -- np. "missing required g:gtin — item skipped"
    created_at   TIMESTAMPTZ NOT NULL DEFAULT NOW()
);
CREATE INDEX idx_feed_run_logs_run ON feed_run_logs(feed_run_id);
```

**Szablony predefiniowane** seedujemy jako `is_built_in` deskryptory (tabela `feed_templates` albo stałe w kodzie + seed do `descriptor` przy tworzeniu profilu). Rekomendacja: **stałe/seed w kodzie** (`GoogleShoppingTemplate`, `CeneoTemplate`, `MetaTemplate` — zwracają domyślny descriptor + domyślne mapowania), klonowane do `FeedProfile.descriptor` przy „Utwórz feed z szablonu". Brak osobnej tabeli = mniej migracji; predef można wersjonować w kodzie.

**Zmiany w istniejących encjach:** brak, poza `ExportFormat` enum (`+ Xml`). Feed jest read-only na `objects`/`object_values` — nie dotyka danych domenowych (jak eksport, PRD-eksportów §5.2).

### 6.3 Deklaratywny descriptor XML + silnik serializacji

**Descriptor** to JSONB opisujący strukturę wyjścia (Airbyte-like „manifest", spójne z zasadą deklaratywności z APIC §5.1). UI go edytuje, runtime wykonuje. Kształt (przykład skrócony Google Shopping):

```json
{
  "root": { "element": "rss", "attributes": { "version": "2.0" },
            "namespaces": { "g": "http://base.google.com/ns/1.0" } },
  "channel": { "element": "channel",
    "header": [
      { "element": "title", "source": { "kind": "static", "value": "{feed_name}" } },
      { "element": "link",  "source": { "kind": "static", "value": "{store_url}" } },
      { "element": "description", "source": { "kind": "static", "value": "Product feed" } }
    ],
    "item": {
      "element": "item",
      "slots": [
        { "target": "g:id",           "node": "element", "source": {"kind":"attribute","ref":"sku"}, "required": true, "maxLength": 50 },
        { "target": "g:title",        "node": "element", "source": {"kind":"attribute","ref":"name"}, "required": true, "maxLength": 150 },
        { "target": "g:description",  "node": "element", "source": {"kind":"attribute","ref":"description"}, "html": "cdata", "maxLength": 5000 },
        { "target": "g:link",         "node": "element", "source": {"kind":"template","value":"{store_url}/p/{sku}"}, "required": true },
        { "target": "g:image_link",   "node": "element", "source": {"kind":"attribute","ref":"main_image"}, "required": true },
        { "target": "g:availability", "node": "element", "source": {"kind":"attribute","ref":"stock_qty"},
          "transform": {"kind":"enum_map","default":"out_of_stock","gt0":"in_stock"}, "required": true },
        { "target": "g:price",        "node": "element", "source": {"kind":"attribute","ref":"price"},
          "transform": {"kind":"price","currency":"{currency}"}, "required": true },
        { "target": "g:brand",        "node": "element", "source": {"kind":"attribute","ref":"brand"},
          "transform": {"kind":"default","value":"Unbranded"} },
        { "target": "g:condition",    "node": "element", "source": {"kind":"static","value":"new"} },
        { "target": "g:gtin",         "node": "element", "source": {"kind":"attribute","ref":"ean"}, "requiredOneOf": ["g:gtin","g:mpn"] }
      ]
    }
  }
}
```

Descriptor obsługuje trzy typy węzłów (potrzebne przez Ceneo): `element` (`<name>val</name>`), `attribute` (`o price="val"` — atrybut rodzica), `repeatable` (galeria → wiele `<i url="">`). Oraz `wrapIn` (grupowanie, np. `g:shipping` z dziećmi; Ceneo `<imgs>` wokół `<i>`; `<attrs>` wokół `<a>`).

**Silnik — trzy warstwy (od najniższej):**

- **`XmlWriterCore`** (`Export/Infrastructure/Writer/`) — cienka otoczka nad php `XMLWriter` w trybie streamingowym (do `php://temp`→MinIO albo bezpośrednio do output). Odpowiada za: deklarację XML + encoding UTF-8, otwieranie/zamykanie elementów, **auto-escaping**, CDATA, sanityzację nielegalnych znaków sterujących XML 1.0. Memory-bounded (nie buduje drzewa DOM). To jedyne miejsce, które dotyka `XMLWriter`.
- **`ItemWriter` (kontrakt) + `XmlFeedWriter`** — **nowy kontrakt asocjacyjny** (bo `RowWriter` jest pozycyjny i nie zna nazw elementów):

  ```php
  interface ItemWriter {
      public function writeHeader(FeedDescriptor $descriptor, array $context): void; // root + channel/header
      /** @param array<string,string> $item mapa klucz-kolumny → wartość (z ExportBuilder, po transformacjach) */
      public function writeItem(array $item): void;
      public function close(): void;
  }
  ```

  `XmlFeedWriter` implementuje `ItemWriter`: dla każdego itemu iteruje sloty descriptora, wyciąga wartość po `source.ref` z mapy, składa węzeł (element/atrybut/repeatable) przez `XmlWriterCore`. Ad-hoc XML używa uproszczonego `GenericXmlWriter` (klucz→element, bez descriptora).
- **`FeedGenerator`** (`Export/Feed/Application/`) — orkiestrator: bierze `FeedProfile` → buduje plan kolumn ze slotów+mapowań → woła `ExportBuilder` (Generator itemów, chunk + `clear()`) → per item stosuje transformacje (§6.4) + walidację required (§6.5) → `XmlFeedWriter->writeItem()` → streamuje do MinIO → zapisuje `FeedRun` + logi. To odpowiednik `SyncExportRunner`/`ExportJobHandler`, ale z warstwą szablonu.

**Dlaczego nie rozszerzyć `RowWriter`:** `RowWriter` celowo jest „flat row-of-strings" (komentarz w kodzie). XML potrzebuje nazw i struktury — wtłaczanie tego w pozycyjny kontrakt zabrudziłoby CSV/XLSX. Osobny `ItemWriter` trzyma oba czyste; oba konsumują ten sam `Generator<array<string,string>>` z `ExportBuilder`.

### 6.4 Mapowanie pól + lekkie transformacje

`FeedFieldMapping` (element `field_mappings[]`): `{ slot, source, transform? }`, gdzie:
- `slot` — docelowe pole szablonu (`g:title`, Ceneo `name`, custom `<my_field>`).
- `source` — `{kind: "attribute", ref: "<attr_code>"}` | `{kind: "static", value}` | `{kind: "template", value: "…{sku}…"}` (interpolacja innych pól/zmiennych feedu).
- `transform` — **jedna** z zamkniętej listy (decyzja #3):

| transform | Działanie | Przykład |
|---|---|---|
| `default` / `fallback` | wartość gdy źródło puste | `brand → "Unbranded"` |
| `price` | liczba → „123.45 {currency}" (format Google/Meta) | `19.9 → "19.90 PLN"` |
| `number` | format liczby (separator, precyzja) | `1234.5 → "1234.50"` |
| `date` | ISO-8601 / format odbiorcy | `2026-07-01T10:00+02:00` |
| `enum_map` | mapowanie wartości/warunku na słownik | `stock_qty>0 → in_stock` |
| `concat` / `template` | sklejanie pól | `name + " " + brand` |
| `strip_html` | usuń tagi (dla pól bez CDATA) | opis → plain text |
| `truncate` | przytnij do `maxLength` (miękko, po słowie) | `g:title ≤150` |

Transformacje działają **nad** wyjściem `ValueSerializer` (string). **Bez** dowolnych wyrażeń/skryptów — to hook (§7). Niezgodność (np. `price` na polu nienumerycznym) → warning przy zapisie mapowania (zasada iPaaS z APIC §6.5) i warning w `FeedRunLog` przy generacji.

**Zalążek mapowania z Channel:** przy „Utwórz feed dla kanału X" pre-wypełniamy mapowania z `channel_publication_profiles.column_aliases` (ADR-0018) i `ChannelObjectTypeMapping.targetField` — operator dostaje gotowy punkt startu zamiast pustej macierzy.

### 6.5 Szablony predefiniowane + walidacja

Trzy seedowane szablony (`is_built_in`), każdy = descriptor + domyślne mapowania + tabela slotów z regułami walidacji:

- **Google Shopping** — RSS 2.0 + `g:`, sloty jak w §6.3. Required: `g:id, g:title, g:description, g:link, g:image_link, g:availability, g:price`; `requiredOneOf: [g:gtin, g:mpn, (g:brand+g:mpn)]`. Reguły formatu: `g:price` = „{num} {ISO}", `g:availability` ∈ enum.
- **Ceneo** — root `<offers version="1">`, item `<o>` z atrybutami (`id/url/price/avail/stock`), elementy (`<name>`, `<desc>` w CDATA), `<cat>` z `external_code` węzła kategorii kanału, `<imgs><main/><i/></imgs>` (repeatable), `<attrs><a name="">` (mapowanie atrybutów PIM). Required: `id, name, price, cat, imgs.main`.
- **Meta / Facebook Catalog** — warianty pól `g:` (RSS-like), różnice: `availability` (`in stock`/`out of stock`), `additional_image_link` (repeatable), `condition`. Reużywa modelu Google z inną tabelą slotów.

**Walidacja per generacja (feed health):** dla każdego produktu `FeedGenerator` sprawdza `required`/`requiredOneOf`/`maxLength`/format. Zachowanie konfigurowalne per feed:
- **`skip_invalid`** (default) — produkt bez wymaganego pola nie trafia do feedu; wpis `warning` w `FeedRunLog` (`"SKU-123: missing g:gtin — skipped"`); `skipped_count++`.
- **`include_with_warning`** — produkt trafia, warning zalogowany (dla feedów, gdzie odbiorca toleruje braki).

Przed publikacją UI pokazuje **raport zdrowia** („feed ma 23 produkty bez `g:gtin`, 5 bez `g:image_link`") — to jest „AI quality validation pre-export" z PRD-eksportów §10, tu jako deterministyczna walidacja rdzenia (AI-sugestie = hook).

### 6.6 Filtrowanie asortymentu + scope

- **Filtr** — który asortyment wchodzi do feedu: ten sam **filter DSL** co lista produktów i eksport (`AdvancedFilterPanel` + `useFilterDslState`; BE `filter` JSONB). Persystowany na `FeedProfile.filter`. Przykład: „tylko `enabled=true` i `completeness ≥ 80%` i `category IN drzewo Elektronika`". Reużycie = zero drugiej implementacji DSL (zasada z EXR-10).
- **Scope wartości** — feed = **jeden locale + jedna waluta + opcjonalnie jeden kanał**. Multi-locale/kanał = wiele feedów (Google żąda osobnego feedu per kraj/język). `?channel=` daje overlay wartości per kanał; `?publication=<channel>` (ADR-0018) daje allow-listę atrybutów + aliasy. To spójne z istniejącym `PublicationColumnPlanner`.
- **Warianty** — flat (jak eksport, PRD-eksportów §8.3): każdy wariant osobny `<item>` z własnym `g:id` + `item_group_id` = SKU mastera (Google grupuje warianty przez `g:item_group_id`). Descriptor Google ma slot `g:item_group_id ← parent_sku`.

### 6.7 Kategorie kanałowe (mapowanie na ID marketplace)

Feedy marketplace wymagają ID kategorii odbiorcy (Ceneo `<cat>`, Google `g:google_product_category`, Allegro categoryId). Reużywamy **CHC channel categories**: węzeł drzewa kanału ma `external_code` (ID w systemie zewnętrznym), a produkt jest zmapowany na węzeł. `FeedGenerator` czyta `external_code` zmapowanego węzła i wstawia w odpowiedni slot (`<cat>`, `g:google_product_category`). Zmiana `external_code` na węźle → aktualizacja wszystkich produktów przy następnej regeneracji (zero edycji per produkt — zasada CHC). Gdy brak mapowania kategorii → zależnie od `required` slotu: skip lub warning.

### 6.8 Generowanie + delivery (pull, cache-and-serve)

Model dostarczania (decyzja #4):

1. **Regeneracja** (cron wg `schedule_cron` przez `ScheduleDispatcherService`, albo „Regeneruj teraz", albo `first_publish` przy utworzeniu) → `FeedGenerator` streamuje XML do MinIO `feeds/{tenant}/{feed}.xml` (+ wariant `.xml.gz` gdy `delivery.gzip`). Po sukcesie: `FeedRun` = done, plik staje się `cached_file_path`, ustawiamy `cached_at`/`cached_item_count`.
2. **Serwowanie** — publiczny URL `GET /feeds/{token}.xml` (i `.xml.gz`): kontroler rozwiązuje tenant+feed z tokenu → zwraca **plik z cache** przez Flysystem stream (nie generuje na żądanie). Nagłówki: `Content-Type: application/xml; charset=utf-8`, `Last-Modified: {cached_at}`, `ETag: {feed_id}-{cached_at}`, obsługa `If-None-Match`/`If-Modified-Since` → `304`. `Content-Encoding: gzip` gdy klient akceptuje i mamy `.gz`.
3. **Harmonogram** — reuse `ImportSchedule` + `CronExpressionParser` + jitter między tenantami (żeby nie regenerować wszystkich feedów o 03:00 naraz). Adaptive („regeneruj tylko gdy katalog się zmienił") = hook; MVP stały cron.
4. **Progres** — Mercure SSE `feed-runs.{feed_id}` (jak `export-jobs.{session_id}`): postęp per chunk, done/error. UI hub feedów odświeża status na żywo.

Semantyka spójności: near-snapshot (keyset streaming, read-committed). Pełna migawka transakcyjna albo generacja z pre-snapshotu = hook.

### 6.9 Autoryzacja URL feedu + bezpieczeństwo

**Token (decyzja #5):**
- Każdy `FeedProfile` ma **nieodgadywalny token** (≥128-bit, base62) w URL. Przechowujemy `token_hash` (wzorzec `ApiKey` Argon2id/HMAC); jawny token pokazany **raz** przy tworzeniu (jak mint API key) + „Rotate" (regeneracja) i „Revoke". Rozwiązanie tenant+feed z tokenu przez lookup po hashu (constant-time).
- **Opcjonalny HTTP Basic** (`delivery.auth.type = basic`) — Google Merchant i Ceneo wspierają Basic na URL feedu. Hasło szyfrowane `AesGcmEncryptionService` (odwracalne — porównujemy z przychodzącym), nigdy nie wraca w API (jak `webhookSecret`).

**Bezpieczeństwo (twarde, egzekwowane testami — security-first jak w audycie):**
- **Publiczny endpoint bez sesji** — wzorzec `PUBLIC_ACCESS` z security.yaml (audyt W0-5). Endpoint **tylko** serwuje cache; żadnej logiki domenowej, żadnego generowania (anti-DoS).
- **Izolacja tenantów** — token → tenant; ustawienie `TenantContext` + GUC `app.current_tenant` przed jakimkolwiek odczytem; RLS + TenantFilter na `feed_profiles`; MinIO ścieżka per tenant. Cross-tenant token miss = 404 (nie 403 — nie zdradzamy istnienia).
- **XML injection / poprawność** (analog IMP2-2.8 #1552) — cała serializacja przez `XmlWriterCore` (auto-escaping `& < > " '`), sanityzacja control-chars, HTML per-slot (`strip`/`cdata`/`escape`, nigdy raw). **Twardy test:** „feed jest zawsze well-formed XML" na śmieciowym payloadzie (nazwa z `]]>`, `<script>`, znak `\x0B`).
- **Rate-limit** publicznego endpointu per token (anti-abuse/scraping) + per tenant. 429 z `Retry-After`.
- **Enumeration** — token ≥128-bit; brak listowania feedów po URL; log dostępu w `FeedRun`-adjacent metryce (bez sekretów).
- **XXE — N/A** (nie parsujemy XML w ścieżce feedu; tylko generujemy). Guard XXE dojdzie z hookiem „import XML".
- **Brak sekretów w feedzie** — feed to dane produktowe; walidacja że mapowanie nie wystawia pól systemowych/wrażliwych (allow-lista atrybutów przez publication profile).

### 6.10 XML jako format eksportu ad-hoc (tryb 2)

Minimalne rozszerzenie kreatora EXR (dziś kafelek „XML" jest disabled „wkrótce", EXR-D1):
- `ExportFormat::Xml` (+ mime `application/xml`, ext `xml`).
- `GenericXmlWriter implements ItemWriter` — generyczny wrapper: root `<products>`, item `<product>`, slot per wybrana kolumna → element o nazwie klucza (`<description.pl>` → sanityzacja nazwy elementu XML: kropka niedozwolona → `<description locale="pl">` albo `description_pl`; decyzja: **atrybuty locale/channel** na elemencie, `<description locale="pl" channel="shopify">`). Multi-value → powtarzalne elementy albo pipe (spójnie z eksportem).
- Reuse całego flow EXR: preflight (count + sync/async routing), kolumny, scope, sesja, download, Mercure. Zero nowego UI poza odblokowaniem kafelka.
- Brak szablonu/transformacji/URL — to jest różnica względem feedu (§3). Ad-hoc XML = „dump", feed = „projekcja pod odbiorcę".

### 6.11 Podgląd na żywo (preview)

`POST /api/feeds/{id}/preview` (i wariant dla draftu przed zapisem: `POST /api/feeds/preview` z ciałem descriptora) → generuje XML dla **N=5 sample produktów** (pierwsze pasujące do filtra) synchronicznie → zwraca sformatowany, podświetlony XML + raport walidacji (które sloty puste/za długie). To realizacja epik-04 „Preview output — pokazuje rzeczywisty XML dla 5 sample produktów". Preview używa tego samego `FeedGenerator` (limit 5, output do pamięci zamiast MinIO) → gwarancja że podgląd == produkcja.

### 6.12 Wydajność / FrankenPHP worker memory

- `FeedGenerator` extends `AbstractBatchHandler` (albo woła `EntityManager::clear()` per chunk N=1000) — obowiązkowe pod worker mode (CLAUDE.md §3.10; custom PHPStan rule flush-bez-clear).
- `XMLWriter` w trybie streamingowym pisze do `php://temp`→MinIO chunkowanym PUT — **brak load-all-into-memory** i **brak DOM** (nigdy `DOMDocument` dla 50k SKU).
- Keyset streaming produktów (jak EXR bulk-path #1545), `ExportBuilder` już iteruje po `iterable<CatalogObject>` z chunkowaniem po stronie generatora.
- Cel: feed 50k SKU generowany <30s, worker RAM płaski ~50-95 MiB (benchmark jak EXR/IMP2). Prometheus alert `frankenphp_worker_memory_bytes > 256MB`.
- Cache-and-serve = publiczny endpoint ma koszt O(rozmiar pliku) na odczyt, nie O(katalog) — crawler nie generuje.

---

## 7. Zakres MVP vs hooki

**MVP (pierwsza iteracja):**
- Silnik: `XmlWriterCore` + `ItemWriter`/`XmlFeedWriter` + `FeedGenerator` (streaming, memory-safe).
- Feed descriptor (deklaratywny JSONB) + edytor + 3 szablony predef (Google Shopping, Ceneo, Meta) + custom (klon/od zera).
- Mapowanie pól + zamknięta lista lekkich transformacji (§6.4) + zalążek mapowania z publication profile.
- Filtr asortymentu (reuse DSL) + scope (1 locale / 1 waluta / opcj. kanał) + warianty flat (`item_group_id`).
- Kategorie kanałowe przez `external_code` (CHC).
- Delivery pull cache-and-serve: URL z tokenem + opcj. Basic + gzip + ETag/304 + harmonogram cron + „Regeneruj teraz".
- Walidacja per szablon (required/format) + raport zdrowia + `skip_invalid`/`include_with_warning`.
- Preview na żywo (5 sample) + monitor runów (`FeedRun`/`FeedRunLog`) + pause/resume.
- Tryb 2: `ExportFormat::Xml` w kreatorze EXR (generyczny wrapper).
- Bezpieczeństwo: escaping/well-formed guard, token, izolacja tenantów, rate-limit, PUBLIC_ACCESS.

**Hooki (świadomie odłożone, osobne decyzje):**
- **Import XML** (dostawca → PIM) — adapter w IMP2 + parser + **guard XXE** (libxml `no_external_entities`).
- **Push do FTP/SFTP/S3** klienta — reuse `SsrfGuard` + `AesGcmEncryptionService`.
- **Silnik dowolnych transformacji** (wyrażenia, if/then wieloetapowe, lookup z tabeli).
- **Allegro** jako predef (feed/oferty + kategorie przez `external_code`) — dziś osiągalny custom-descriptorem.
- **Feed dla innych ObjectType** niż product (kategorie, zasoby jako feed).
- **AI-assisted mapping / Cmd+K** („zbuduj feed Google z SEO", „zasugeruj mapowania") — wzorzec PRD-eksportów §10.2.
- **Adaptive scheduling** (regeneruj tylko przy zmianie katalogu) + pełna migawka transakcyjna.
- **Marketplace szablonów** jako paczki (analog connector-packów APIC).
- **Webhook `feed.regenerated`** (dziś: pull + Mercure w UI).

---

## 8. Rozbicie na fazy (high-level — agent §10 zamieni na tickety)

1. **Fundament serializacji** — `XmlWriterCore` (streaming XMLWriter + escaping + control-char sanitizer + CDATA) + `ItemWriter` kontrakt + `GenericXmlWriter` + `ExportFormat::Xml` + odblokowany kafelek w EXR (tryb 2 gotowy najwcześniej, bo najmniejszy i weryfikuje rdzeń).
2. **Model feedu** — `FeedProfile`/`FeedRun`/`FeedRunLog` (encje + migracje + RLS + repo) + CRUD API (API Platform + custom routes) + descriptor jako JSONB (walidacja kształtu).
3. **Descriptor + szablony** — `FeedDescriptor` value object + 3 seedy (Google/Ceneo/Meta) + tabele slotów/reguł + „Utwórz feed z szablonu".
4. **Silnik feedu** — `FeedFieldMapping` + transformacje (§6.4) + `FeedGenerator` (reuse `ExportBuilder`) + walidacja required + `FeedRun` audyt.
5. **Mapowanie + scope** — mapper API + zalążek z publication profile + filtr DSL + kategorie `external_code`.
6. **Delivery** — MinIO cache + publiczny URL + token (mint/rotate/revoke) + Basic auth + gzip + ETag/304.
7. **Harmonogram + monitor** — cron (reuse Schedule) + jitter + „Regeneruj teraz" + Mercure progres + UI monitor runów.
8. **Preview + raport zdrowia** — 5-sample preview (draft + zapisany) + walidacyjny raport.
9. **UI** — hub feedów (zakładka w Konfiguratorze) + kreator feedu + mapper + preview (brief §9).
10. **Hardening** — XML injection/escaping suite + tenant isolation + rate-limit + benchmark 50k + (opcj.) pentest slice.

Zależności: F1 blokuje F4 (writer); F2 blokuje F3–F8 (encje); F3 blokuje F4 (descriptor→generator); F4 blokuje F6/F8 (co cache'ować/podglądać); F6 blokuje F7 (co harmonogramować). Tryb 2 (F1) dostarcza wartość niezależnie od reszty.

---

## 9. ZADANIE A — Brief dla agenta projektującego UI

> **Przekaż ten rozdział agentowi „UX/UI Design".** Cel: zaprojektować UI konfiguratora XML (feedy + preview) zanim powstaną tickety. Nie projektuj silnika transformacji ani importu XML — poza zakresem MVP.

**Kontekst techniczny:** React 19 + Refine.dev + shadcn/ui (Radix + Tailwind). A11y obowiązkowa (axe-core dla customowych komponentów, 0 serious/critical). i18n przez react-i18next (klucze EN, tłumaczenia pl/en; **ban na literały w JSX**). Wzorzec wizualny: istniejący admin PIM + shell „Konfigurator" (współdzielony z APIC — zakładki „Połączenia" / „Moje API" / **nowa „Feedy"**). Reuse: `AdvancedFilterPanel` (filtr), cron picker z `ImportSchedule`, wzorzec mint/rotate klucza z `ApiKey`, `LiveStatusBadge` (Mercure).

**Do zaprojektowania (ekrany/flow):**
1. **Hub „Feedy"** — lista feedów: nazwa, szablon (badge Google/Ceneo/Meta/custom), status (active/paused/error), ostatnia regeneracja + liczba itemów, następny run (cron), URL feedu (kopiuj + rotate/revoke). Akcje wiersza: Edytuj / Regeneruj teraz / Wstrzymaj / Usuń. Empty state + CTA „Nowy feed".
2. **Kreator feedu** (pełnostronicowy, wzorzec EXR wizard, **nie modal**):
   - Krok 1 **Szablon** — kafelki Google Shopping / Ceneo / Meta / Custom (SelectableCard, reuse z EXR/IMP).
   - Krok 2 **Zakres i scope** — filtr (`AdvancedFilterPanel`), locale (single), waluta (single), kanał (opcj.) + publication profile.
   - Krok 3 **Mapowanie** — mapper dwukolumnowy: sloty szablonu (lewa, z oznaczeniem required/format) ↔ atrybuty PIM (prawa) + wybór transformacji per slot (dropdown zamkniętej listy) + `static`/`template`. Wskaźnik pokrycia + ostrzeżenie niezgodności typu (wzorzec Merge.dev/APIC field mapping). Zalążek z publication profile.
   - Krok 4 **Dostarczanie** — harmonogram (cron picker), gzip toggle, auth (none/Basic), po zapisie: **URL feedu + token pokazany raz**.
   - Krok 5 **Podgląd i publikacja** — live XML dla 5 sample + raport zdrowia („23 bez g:gtin") + „Zapisz i wygeneruj".
3. **Mapper (komponent kluczowy)** — dwie kolumny + status per slot (zmapowany/pusty/wymagany-brakujący), inline transform, przełącznik `skip_invalid`/`include_with_warning`. Rozważ tryb tabeli (Piotr/IT) zamiast drag-drop.
4. **Preview panel** — podświetlony XML (read-only), przełącznik sformatowany/raw, kopiuj, lista walidacyjnych warningów z linkiem do slotu.
5. **Monitor feedu** — historia `FeedRun` (status, itemy, skipped, czas, rozmiar), drill-down do `FeedRunLog` per-produkt, „Regeneruj". Reuse wzorca `ScheduleRuns`/EXR sesje.
6. **Tryb 2 (ad-hoc XML)** — tylko odblokowanie kafelka „XML" w istniejącym kroku 2 kreatora EXR; brak nowych ekranów (zaznacz w handoffie że to disabled→enabled).

**Deliverable agenta:** specyfikacja UI w `Project Plan/UI/` (format jak `feature-exports-redesign-tickets.md` / `feature-*`): user flows, opis ekranów/wireframe low-fi, komponenty shadcn, stany (loading/empty/error/partial/generating), noty a11y, klucze i18n. **Referencje do przejrzenia:** BaseLinker Kreator XML, Channable/DataFeedWatch feed setup, Merge.dev field mapping, Google Merchant feed diagnostics (raport zdrowia).

---

## 10. ZADANIE B — Brief dla agenta przepisującego plan na tickety

> **Przekaż ten rozdział agentowi „Engineering Planning".** Cel: zamienić ten plan + projekt UI (§9) na wykonalny backlog ticketów w konwencji repo.

**KROK 1 — RESEARCH AS-IS (obowiązkowy, przeczytaj kod, nie zakładaj):**
- `src/Export/Application/Builder/` — `ExportBuilder` (sygnatura Generatora, jak owns chunking), `ColumnResolver`, `ColumnDefinition`, `ValueSerializer`, `PublicationColumnPlanner`.
- `src/Export/Infrastructure/Writer/` — `RowWriter`, `CsvStreamWriter`, `XlsxStreamWriter` (wzorzec streamingu do naśladowania przez `XmlWriterCore`).
- `src/Export/Application/{Async,Sync}/` — `ExportJobHandler`, `SyncExportRunner`, `ExportProgressPublisher`, `ExportCancelledException` (wzorzec `FeedRunHandler`).
- `src/Export/Domain/` — `ExportProfile`/`ExportSession`/`ExportLog`, `ExportFormat`, `ExportStatus` (wzorzec `FeedProfile`/`FeedRun`).
- `src/Channel/Contracts/` — `ChannelResolverInterface`, `ChannelPublicationResolverInterface` (ADR-0018); `channel_publication_profiles` (`column_aliases`, `published_attribute_codes`, `published_locales`); CHC channel categories `external_code`.
- `src/Import/Application/Service/` — `CronExpressionParser`, `ScheduleDispatcherService` (+ encja `ImportSchedule`) — dokładne sygnatury reuse harmonogramu.
- `src/ApiConfigurator/Infrastructure/Security/` — `ApiKey` (Argon2id, rate-limit) jako wzorzec tokenu feedu.
- `src/Shared/.../Crypto/` — `AesGcmEncryptionService`, `EncryptedSecret` (Basic auth feedu).
- `apps/api/config/packages/security.yaml` — wzorzec `PUBLIC_ACCESS` (audyt W0-5/#1577) dla publicznego URL feedu.
- `src/Shared/OpenApi/CustomRouteOpenApiFactory` (ADR-0020) — rejestracja custom tras w `docs/api-spec/v0.json`.
- Filter DSL: FE `useFilterDslState`/`AdvancedFilterPanel`, BE format `filter_snapshot` (z EXR/list-advanced).
- ADR-0018, ADR-0015 (bare UUID cross-BC), ADR-0011 (ORM XML mapping w Infrastructure), konfiguracja Deptrac.

**KROK 2 — ROZBICIE NA TICKETY:**
- Epik + fazy wg §8. Każda faza = milestone; każdy ticket = własny branch + PR + CI + merge.
- Ticket = jeden spójny, atomowy zakres. Cross-context (Export↔Channel przez Contracts, publiczny endpoint+security) → osobny ticket + **Plan Mode** (CLAUDE.md).
- **DoD każdego ticketu = zielone bramki:** PHPStan max, Deptrac, PHP-CS-Fixer, PHPUnit ≥80% nowej logiki, ApiTestCase dla nowych endpointów, **Playwright E2E dla widocznych zmian**, smoke 5 min na `pim.localhost`. Bez E2E ticket nie jest done. **SMOKE TEST RULE + CLOSED MEANS CLOSED** (CLAUDE.md): przed „działa"/`gh issue close` — live-stack smoke z proofem (feed URL zwraca well-formed XML 200 + `xmllint --noout`).
- Tickety bezpieczeństwa jako osobne, **security-first / failing-test-first** (wzorzec audyt fix-plan): XML injection/well-formed, tenant isolation feedu (2-tenant probe), token enumeration/rate-limit, PUBLIC_ACCESS scope.
- Hooki z §7 → osobne tickety `deferred`/`later`, nie mieszać z MVP.
- Wypisz zależności (§8): writer→generator, encje→wszystko, descriptor→generator, generator→delivery/preview.

**Deliverable agenta:** plik(i) backlogu w `Project Plan/` w konwencji `feature-imports-v2-tickets.md` (numerowane tickety: opis, AC, DoD, zależności) + propozycja GitHub Issues/milestones + **szkic ADR** (§12). Zaktualizuj `agent/current_status.md` i `02-plan-projektu-pim.md` po utworzeniu backlogu.

---

## 11. Ryzyka i otwarte kwestie

| Ryzyko / kwestia | Opis | Mitygacja / decyzja |
|---|---|---|
| **XML injection / niepoprawny feed** | Śmieciowe dane produktowe łamią XML → cały feed odrzucony przez marketplace | Serializacja wyłącznie przez `XmlWriterCore` (auto-escape), sanityzacja control-chars, HTML per-slot; twardy test „always well-formed"; `xmllint` w smoke. Analog IMP2-2.8 #1552. |
| **Skala feedu vs generacja on-hit** | Crawler pulluje 50k SKU feed często → DoS na worker | Cache-and-serve (decyzja #4); publiczny endpoint tylko serwuje plik; regeneracja tylko cron/manual. |
| **Memory FrankenPHP** | Budowa DOM / load-all dla 50k SKU zabija worker | `XMLWriter` streaming (nigdy `DOMDocument`), `EntityManager::clear()` per chunk, benchmark 50k <256MiB. |
| **1:1 to za mało dla feedów** | Google żąda `price` „123.45 PLN", `availability` enum, kategorii marketplace | Zamknięta lista transformacji (§6.4) — świadome odejście od zasady 1:1 z APIC; dowolne = hook. |
| **Nazwy elementów XML z kluczy `code.locale`** | `description.pl` nie jest legalną nazwą elementu XML | Feed: descriptor mapuje na legalne nazwy slotów. Ad-hoc: locale/channel jako **atrybuty** elementu (`<description locale="pl">`). |
| **Token feedu w URL = sekret w logach** | URL z tokenem trafia do access logów/proxy | Token ≥128-bit, rotate/revoke, rate-limit; rozważ redakcję tokenu w logach; opcjonalny Basic dla wrażliwych. |
| **Spójność podczas edycji katalogu** | Feed regenerowany w trakcie bulk-edit = mieszany stan | Near-snapshot (keyset, read-committed) udokumentowane; pełna migawka = hook. |
| **Kategorie bez `external_code`** | Produkt w kategorii bez zmapowanego ID marketplace | Zależnie od `required` slotu: skip + warning w raporcie zdrowia; UI ostrzega przy zapisie feedu. |
| Otwarte: **Allegro** | Operator nie wybrał predef; Allegro to bardziej API ofert | Custom descriptor + `external_code` umożliwiają; gotowy szablon = hook gdy pojawi się pain. |
| Otwarte: **wiele locale w jednym feedzie** | Google żąda osobnego feedu per język/kraj | MVP: 1 feed = 1 locale; multi = wiele feedów. Zweryfikować z pilotem czy wystarcza. |
| Otwarte: **limit liczby feedów per tenant** | Tier gating (Starter/Pro/Enterprise) jak connectory (epik-04 §3.2) | Decyzja pricing — domyślnie miękki limit, twardy per tier w Fazie 1. |

---

## 12. Mini-ADR (szkic do sfinalizowania przez agenta §10)

**Tytuł:** Umiejscowienie konfiguratora XML, model serializacji i autoryzacja feedu.

**Kontekst:** PIM potrzebuje feedów XML (Google/Ceneo/Meta/custom) + XML jako formatu eksportu. Silnik Export istnieje (CSV/XLSX, flat), ale nie ma serializacji XML (nested) ani modelu feedu (URL/harmonogram). APIC (REST/JSON) i eksport (XLSX/CSV) świadomie wykluczyły XML.

**Decyzje (proponowane):**
1. **Silnik XML w `Export`** (nie nowy BC): `XmlWriterCore` + `ItemWriter` obok istniejących writerów; `ExportFormat::Xml` dla trybu ad-hoc. Feedy w pod-obszarze `Export/Feed/`.
2. **Nowy kontrakt `ItemWriter` (asocjacyjny)** zamiast rozszerzania pozycyjnego `RowWriter` — trzyma CSV/XLSX/XML czyste; wszystkie konsumują ten sam `Generator<array<string,string>>` z `ExportBuilder`.
3. **Reuse Export jako źródła itemów** — feed nie duplikuje selekcji/odczytu (analog „outbound reużywa Export" z APIC §6.8). Cross-BC do Channel/Catalog tylko przez `Contracts` (Deptrac).
4. **Feed = deklaratywny descriptor JSONB** (Airbyte-like) + zamknięta lista transformacji; predef jako seedy `is_built_in` klonowane do profilu.
5. **Delivery pull cache-and-serve** — regeneracja (cron/manual) → MinIO → publiczny URL serwuje cache (ETag/304/gzip). Push = hook.
6. **Autoryzacja token-in-URL** (hash jak `ApiKey`) + opcjonalny Basic (`AesGcm`); token ≠ klucz tenanta; publiczny endpoint przez `PUBLIC_ACCESS`, tenant z tokenu → RLS.
7. **Scope przez publication profile** (ADR-0018) — feed reużywa `?publication=<channel>` allow-listę + `column_aliases` jako zalążek mapowania.

**Konsekwencje:** czysty podział (Export = jak projektujemy na wyjście); reuse silnika Export → poprawki propagują się do feedów; dwa mechanizmy sekretów współistnieją (hash token / szyfr Basic); publiczny endpoint to nowa powierzchnia ataku obsłużona wzorcem z audytu.

**Powiązane ADR:** ADR-0018 (publication profile — reuse), ADR-0015 (bare UUID cross-BC), ADR-0020 (OpenAPI custom route), ADR-0011 (ORM XML mapping w Infrastructure), ADR-0009 (ObjectType), ADR-0022 (granica konsument/producent — feed to projekcja wychodząca, sąsiad producenta).

---

## 13. Źródła (research 2026-07-01)

- Google Merchant — Product data specification: https://support.google.com/merchants/answer/7052112
- Google Merchant — Feed file formats (RSS 2.0 / namespace g:): https://support.google.com/merchants/answer/160589
- Ceneo — Specyfikacja pliku XML (oferty): https://developers.ceneo.pl/ (sekcja plik ofertowy `<offers>/<o>`)
- Meta — Commerce / Catalog feed reference: https://www.facebook.com/business/help/120325381656392
- BaseLinker — Kreator plików XML/CSV (feed manager, wzorzec UX): https://baselinker.com
- Channable / DataFeedWatch / Feedonomics — feed mapping + rules + scheduling (wzorce feature): dokumentacja produktowa
- Airbyte — deklaratywny descriptor (manifest) jako wzorzec konfiguracji: https://docs.airbyte.com/platform/connector-development/connector-builder-ui/overview
- Wewnętrzne: `PRD-PIM-exports.md` (§6.3, §13 — XML deferred), `feature-api-configurator-uniwersalny-plan.md` (§6.8 reuse Export, §5 zasady), `epik-04-publikacje.md` (§3.3 Feeds), ADR-0018, `CHC-channel-categories-tickets.md`.


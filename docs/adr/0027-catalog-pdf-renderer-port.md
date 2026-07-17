# 0027. Katalogi PDF — renderer za portem, umiejscowienie silnika i model dostarczania

- **Status:** accepted
- **Date:** 2026-07-08
- **Deciders:** Marcin (operator), architekt rozwiązań (agent)

> Finalizowany w tickecie CPDF-P0-01 (epik CPDF). Streszczenie w `01-architektura-pim.md` §13. Wszystkie decyzje z planu §12 rozstrzygnięte; otwarte kwestie (pamięć Dompdf, wierność Dompdf↔Gotenberg) przesunięte do M6, druk CMYK do hooków §7 planu.
> **Numeracja:** 0025 (`0025-object-type-cross-field-validation.md`) i 0026 (`0026-dashboard-read-model.md`) zajęte — pierwszy wolny numer to 0027 (plan `feature-catalogs-pdf.md` §12 błędnie zakładał 0025).

## Context and Problem Statement

PIM potrzebuje funkcji **Katalogi PDF** (web-to-print): klient bez developera generuje z danych PIM firmowane dokumenty PDF — kartę produktu, katalog kolekcji/kategorii, cennik — zawsze aktualne, do pobrania i udostępnienia przez stabilny URL (model Plytix/Pimcore). Route `/catalogs-pdf` i menu istnieją dziś jako zaślepka (`ComingSoonPlaceholder`); PRD eksportów świadomie wyciął „printable catalogs" do Fazy 2+ (`PRD/PRD-PIM-exports.md`).

Silnik Export istnieje (`ExportBuilder` → `Generator<array<string,string>>`, writery CSV/XLSX/XML), a wzorzec dostarczania feedów (`Export/Feed`: cache-and-serve + token URL + monitor runów) jest sprawdzony (ADR-0023). Trzeba rozstrzygnąć trzy rzeczy, których Export dziś nie ma: (1) **jak renderować PDF** — czysto-PHP kontra headless Chromium, z których każdy ma inny trade-off wierność vs infrastruktura; (2) **gdzie żyje silnik katalogów** bez tworzenia kolejnego bounded contextu i bez duplikacji selekcji/odczytu danych; (3) **jak dostarczać dokument** pod pobranie i publiczny link bez DoS-owania workera. Dodatkowo dane produktowe trafiają do szablonu HTML → escaping/sanityzacja są bezpieczeństwem-krytyczne (analog CSV/XML injection).

## Decision Drivers

- **Zero nowej infrastruktury w domyślnej instalacji** — Cortex ma się zainstalować i działać bez dodatkowego serwisu; wysoka wierność = opcjonalny opt-in, nie wymóg.
- **Reuse zamiast duplikacji** — katalog nie pisze własnego selektora produktów ani odczytu wartości (jak „feed reużywa Export" w ADR-0023, „outbound reużywa Export" w APIC).
- **Memory-safe pod FrankenPHP worker mode** — render setek produktów nie może budować całości w pamięci bez `EntityManager::clear()` (CLAUDE.md §3.10, inaczej OOM).
- **Anti-DoS** — dokument udostępniany publicznym URL-em; renderowanie na każde pobranie zabija workera.
- **Elastyczność wierności** — layouty premium (pełne CSS3, margin boxes) i w przyszłości CMYK muszą być osiągalne bez przepisywania silnika.
- **Bezpieczeństwo** — dane produktowe w HTML (auto-escape/sanityzacja), publiczny endpoint (token w URL, izolacja tenantów, rate-limit).
- **Zgodność z Deptrac** — cross-BC tylko przez `*\Contracts\*`.

## Considered Options

1. **Konektor do InDesign / EasyCatalog / priint / Pagination** — pełna kontrola DTP/CMYK, ale zewnętrzne, płatne, ciężkie wdrożenie (obóz Akeneo). Odrzucony jako obóz rynkowy — celujemy w self-service web-to-print.
2. **Web-to-print z jednym renderem na sztywno** (tylko Dompdf albo tylko Gotenberg) — prosto, ale albo tracimy wierność (Dompdf: CSS 2.1, brak grid/flex), albo wymuszamy kontener ~1.5 GB na każdą instalację (Gotenberg).
3. **Web-to-print z renderem za portem `PdfRenderer`** (Dompdf default in-process + Gotenberg opcjonalny sidecar), silnik w pod-obszarze `Export/Catalog` (kalka `Export/Feed`), delivery cache-and-serve + token URL.
4. **Osobny bounded context `Catalog PDF`** — pełna izolacja, ale duplikacja selekcji/odczytu przy zerowej korzyści (i tak czyta Catalog/Channel/Asset przez te same Contracts).

## Decision Outcome

Chosen option: **Option 3 (web-to-print, renderer za portem, silnik w `Export/Catalog`)**, bo katalog PDF to semantycznie „projekcja katalogu na wyjście" — ta sama domena co eksport i feed — a port renderera zdejmuje jednocześnie ryzyko infrastruktury (default działa bez niczego nowego) i ryzyko wierności (premium = flip flagi na tym samym szablonie).

Rozstrzygnięcia (decyzje z planu §2/§6/§12):

1. **Web-to-print, nie konektor InDesign.** Pipeline: szablon Twig HTML/CSS → `PdfRenderer` → PDF. DTP/CMYK/druk offsetowy poza MVP (hook).
2. **Renderer za portem `PdfRenderer`** (`src/Export/Contracts/`) z adapterami: **`DompdfRenderer`** (czysty PHP, in-process, **domyślny — zero nowej infry**) i **`GotenbergRenderer`** (headless Chromium jako sidecar, aktywny tylko gdy `GOTENBERG_URL`). Wybór przez `CATALOG_PDF_RENDERER=dompdf|gotenberg`, auto-fallback do Dompdf gdy brak `GOTENBERG_URL`. **Instalacja bez Gotenberga = pełny MVP.** PDFreactor/prince = przyszły 3. adapter pod CMYK w Fazie 2 (ten sam port).
3. **Silnik katalogów w `src/Export/Catalog/`** (nie nowy BC), kalka `src/Export/Feed/` — uzasadnienie jak ADR-0023. Cross-BC do Catalog/Channel/Asset wyłącznie przez `*\Contracts\*`; rdzeń Export przez seam `Export/Contracts` (Deptrac: `Export_Catalog_Internals` → `Export_Contracts` + `{Channel,Catalog,Asset,Identity}_Contracts`).
4. **Reuse `ExportBuilder` jako źródła danych** — katalog nie duplikuje selekcji/odczytu wartości. Scope (asortyment/locale/kanał) przez publication profile (ADR-0018); mapowanie pól (slot → atrybut) na bazie `column_aliases`.
5. **Jeden parametryzowalny silnik szablonu** (decyzja operatora 2026-07-08): archetyp layoutu (`sheet`/`grid`/`pricelist`) wybiera bazowy szablon Twig; branding (logo, paleta, dane firmy, okładka) wstrzykiwany jako brand tokens (CSS variables); mapowanie pól jako parametry `CatalogProfile`. Drag-and-drop template builder (WYSIWYG) = hook Fazy 2, nie zestaw sztywnych plików.
6. **Delivery pull cache-and-serve** (kalka ADR-0023) — regeneracja (manual „Regeneruj teraz" / bulk) → PDF w MinIO (`catalogs/{tenant}/{id}.pdf`) → publiczny URL serwuje **cache** (nigdy nie generuje na żądanie); `ETag`/`304`/`Content-Disposition`. „Live URL" = zawsze ostatni wygenerowany. Cron auto-regen = hook (reuse `CronExpressionParser` + `ScheduleDispatcherService`).
7. **Autoryzacja token-in-URL** — nieodgadywalny token hashowany (wzorzec `FeedTokenService`, weryfikacja constant-time), rotate/revoke, **token ≠ klucz tenanta** (osobny lifecycle, read-only, per dokument). Publiczny endpoint przez `PUBLIC_ACCESS`, tenant rozwiązywany z tokenu → RLS/TenantFilter; cross-tenant miss = 404. Escaping/sanityzacja HTML (Twig autoescape + whitelist opisów) + rate-limit publicznego URL.
8. **MVP = RGB ekranowy, `product` (+ warianty flat).** CMYK/spady/crop marks/PDF-X, katalog wielojęzyczny w jednym pliku i ObjectType ≠ product = hooki §7.

## Consequences

- **Positive:** domyślna instalacja działa bez nowej infrastruktury (Dompdf in-process); wysoka wierność / premium = opt-in flip flagi na tym samym szablonie; reuse silnika Export → poprawki propagują się do katalogów; port `PdfRenderer` daje jasną ścieżkę na CMYK (PDFreactor) bez przepisywania; `Export/Feed` dostarcza gotowy wzorzec delivery (token, cache, monitor).
- **Negative:** dwa adaptery renderera do utrzymania z ryzykiem rozjazdu wierności na tym samym szablonie (mitygacja: podzbiór „bezpiecznego" CSS + snapshot-testy na obu, premium gejtowane do Gotenberga — CPDF-P6-05); Dompdf ładuje cały dokument do pamięci → ryzyko OOM przy dużych katalogach (mitygacja: benchmark + cap/chunk — CPDF-P6-04); publiczny endpoint to nowa powierzchnia ataku (obsłużona wzorcem `PUBLIC_ACCESS` + token hash + escaping + rate-limit).
- **Follow-ups:** archetypy `grid`/`pricelist` + `GotenbergRenderer` + benchmark + snapshot parity (M6); hooki §7 — konektor InDesign, CMYK/druk offsetowy (3. adapter PDFreactor/prince za portem), drag-drop template builder, cron auto-regen, katalog wielojęzyczny, ObjectType ≠ product, dowolne transformacje w mapowaniu.

## Alternatives Considered

- **Konektor InDesign / EasyCatalog / priint** — odrzucony: inny obóz rynkowy (zewnętrzne, płatne, ciężkie); celujemy w self-service, auto-aktualne, tańsze wdrożenie.
- **Jeden renderer na sztywno (tylko Dompdf)** — odrzucony: brak ścieżki na wierność/premium/CMYK bez przepisywania; layouty katalogowe (grid, margin boxes) przekraczają CSS 2.1.
- **Jeden renderer na sztywno (tylko Gotenberg)** — odrzucony: wymusza kontener ~1.5 GB + ~85–200 MB RAM/render na każdą instalację, łamie zasadę „zero nowej infry w domyślnej instalacji".
- **Osobny BC `Catalog PDF`** — odrzucony: overhead klas + duplikacja selekcji/odczytu przy zerowej korzyści izolacyjnej (i tak czyta Catalog/Channel/Asset przez te same Contracts).
- **Generowanie na żądanie (bez cache)** — odrzucony: DoS na workera przy pobraniach z publicznego URL, tym bardziej że render PDF jest droższy niż strumień XML feedu.
- **Autoryzacja kluczem tenanta** — odrzucony: klucz tenanta ma szerszy zakres; token dokumentu musi być read-only, per-dokument, rewokowalny bez wpływu na inne integracje.

## RBAC — powierzchnia gejtowana (CPDF-P1-03/P3-*/P4-02)

Endpointy katalogów **reużywają istniejące uprawnienia PRD** zamiast wprowadzać dedykowany moduł `catalogs_pdf` (plan §6.8 zakładał nowy moduł — świadomie odrzucone): odczyty (`list`/`get`/`preview`/monitor/KPI/historia) na `#[RequiresPermission(module: 'exports', action: 'view_all')]`, zapisy i akcje (`create`/`patch`/`delete`/`generate`/`bulk-generate`/`token`/`cancel`) na `#[RequiresPermission(module: 'integration', action: 'admin')]` — dokładnie jak siostrzany `Export/Feed`. Katalogi PDF żyją w rodzinie Export (`src/Export/Catalog/`), więc gejtowanie pod `exports`/`integration` jest spójne semantycznie i **nie tworzy nowej powierzchni RBAC** (nowy kod uprawnień dotyka `PrdRoleTemplates`/fixtures/FE/testów — klasa zmian, przed którą przestrzega re-audit RBAC). Publiczny pull to jedyny `#[NoPermissionRequired]` (token = credential). `RequiresPermissionAnnotationRule` (PHPStan) egzekwuje atrybut na każdej trasie; `CatalogApiLockdownTest` dowodzi runtime (401 na całej powierzchni poza pull → 404). Dedykowany moduł `catalogs_pdf` można dodać później jeśli operator zechce granularnej separacji — menu-gate FE (P5-05) gejtuje wtedy spójnie na `exports.view_all`.

## Aktywacja renderera Gotenberg (CPDF-P6-03)

Selekcję adaptera robi `CatalogPdfRendererFactory` (DI factory za aliasem portu `PdfRenderer`): **`CATALOG_PDF_RENDERER=gotenberg` + niepusty `GOTENBERG_URL`** ⇒ `GotenbergRenderer` (HTTP do `POST {url}/forms/chromium/convert/html`, multipart, `preferCssPageSize`, retry 429/5xx/transport z backoffem 2^n s, max 3 próby — wzorzec throttlingu Shopify §7.3); **każda inna kombinacja ⇒ Dompdf** (w tym `gotenberg` bez URL — cichy fallback, R3: instalacja bez sidecara działa). Sidecar w dev: `docker compose --profile gotenberg up -d gotenberg` (poza domyślnym stackiem) + `GOTENBERG_URL=http://gotenberg:3000` w `apps/api/.env`. Obrazy w dokumencie idą **przez URL** (decyzja B — Chromium pobiera je sam); `GotenbergRenderer` jest jedyną klasą dotykającą Gotenberg API. Test integracyjny `GotenbergRendererLiveTest` jest warunkowy (skip bez `GOTENBERG_URL`) — CI go pomija, walidacja na żywym sidecarze lokalnie.

## Parity Dompdf ↔ Gotenberg (CPDF-P6-05)

Mitygacja R2 (ten sam szablon, dwa silniki): **(1) bezpieczny podzbiór CSS** — archetypy piszemy w CSS 2.1 wspólnym dla obu silników: layout tabelami/floatami (zakaz `display: flex|grid`), brand tokens interpolowane przez Twig wprost do `<style>` (zakaz `var(--x)`), zakaz `position: sticky`; z CSS Paged Media na ścieżce Dompdf tylko basics (`@page` marginesy, `counter(page)` w `position:fixed`, `table-header-group`). Konstrukty premium (`target-counter()`, `running()`, margin boxes `@top-*`/`@bottom-*`) wolno używać WYŁĄCZNIE w bloku `{% if premium %}` (Twig-flaga, default false) — Dompdf degraduje gracefully, Gotenberg renderuje pełnię. **(2) Guard** — `CatalogTemplateCssGuardTest` skanuje każdy szablon `templates/catalog/*.html.twig` (bez komentarzy Twig) i czerwieni build przy naruszeniu podzbioru lub premium poza guardem. **(3) Snapshot parity** — `CatalogRendererParityTest` renderuje ten sam HTML na obu adapterach i porównuje strukturę: sheet MUSI mieć identyczną liczbę stron (paginacja jawnym `page-break-after`), pricelist toleruje ±1 stronę (metryki linii różnią się między silnikami), premium grid jest Gotenberg-only. Część cross-engine jest warunkowa (skip bez `GOTENBERG_URL`) — CI jej nie odpala; walidacja lokalnie na sidecarze (`docker compose --profile gotenberg`).

## System designu „Editorial" (#2608)

Redesign szablonów (operator: „PDF-y mają być piękne") wprowadza wspólny system designu dla trzech archetypów, w całości w bezpiecznym podzbiorze CSS (guard bez zmian merytorycznych, rozszerzony o partiale):

1. **Typografia** — Fraunces (serif display: okładka, tytuły) + Inter (dane/tabele; domyślnie tabularne cyfry → kolumny cen równają się bez `font-variant-numeric`). Statyczne TTF (subset latin + latin-ext, pełne polskie znaki) wendorowane w `apps/api/assets/pdf-fonts/` z licencjami OFL (żadna rodzina nie deklaruje Reserved Font Name — subset pod oryginalną nazwą jest czysty licencyjnie; Playfair Display odrzucony właśnie przez RFN). `CatalogPdfFontProvider` memoizuje je per proces workera i podaje jako **`@font-face` z data-URI** — jedyny src działający jednocześnie w Dompdf (`isRemoteEnabled=false`) i Gotenbergu (pojedynczy `index.html` bez side-files). Zwalidowane w kontenerze: Dompdf 3.1 rejestruje i **subsetuje** fonty z data-URI (koszt w PDF ≈ kilkadziesiąt kB); metryki idą do `fontCache` w katalogu temp (vendor/ zostaje read-only).
2. **Paleta z jednego koloru brandu** — `CatalogPdfPalette::fromHex()` wyprowadza `accent` / `accent_dark` (×0.68 ku czerni, duże powierzchnie) / `on_accent(_dark)` (biel jeśli kontrast WCAG ≥ 3:1, inaczej atrament — biel-first, bo na nasyconych półtonach rewers bielą to konwencja druku) / `tint_zebra` (93% ku bieli) / `tint_panel` (95.5%). Kolor operatora nigdy nie zalewa strony — działa jako system akcentów (60-30-10). Dompdf nie ma funkcji kolorów CSS, więc pochodne liczy PHP.
3. **Chrome** — `CatalogPdfChromeFactory` buduje wspólny kontekst (paleta, fonty, etykiety PL/EN wg `locale` profilu z poprawną polską liczbą mnogą, data generacji, tytuł, liczba pozycji) dla renderu PDF **i** preview wizarda — jedna fabryka utrzymuje mirror obu serwisów w sync. Wspólne partiale: `templates/catalog/_chrome/{fonts,chrome.css,cover,footer}.html.twig`.
4. **Okładka „w ramce"** dla wszystkich archetypów — panel `accent_dark` wewnątrz marginesów strony (gallery-frame), nie full-bleed: `position:fixed` (stopka) drukuje się także na pierwszej stronie, więc pełny spad kolidowałby z chrome; rama usuwa całą klasę artefaktów i wygląda na zamierzoną. Full-bleed = ewentualny premium-hook na Gotenbergu.
5. **Ostrość obrazów (sheet)** — inlining obrazów przeniesiony za pętlę streamingu (lista i tak jest buforowana), z wariantem zależnym od znanej już liczby pozycji: `medium` (800 px) gdy renderer strumieniuje (Gotenberg) lub liczba ≤ `CATALOG_PDF_HQ_IMAGE_MAX_ITEMS` (default 48, `0` wyłącza), inaczej `thumb`. Budżet bitmap: 48 × 800×800×3 B ≈ 92 MB < 256 MB workera — respektuje lekcję OOM #2601. `AssetInliner` przyjmuje `preferredVariant` (stałe stringowe na kontrakcie, bez zależności od encji `AssetVariant`).
6. **Placeholder zamiast zepsutych obrazków** — referencja assetu, której inliner nie rozwiąże (goły UUID → dotąd `<img src="<uuid>">` = X-box w PDF), zapada się do `''`, a szablon renderuje stylowany panel „brak zdjęcia"/„no image".

## Links

- Plan: `Project Plan/UI/feature-catalogs-pdf.md` §2 (decyzje), §6 (architektura), §7 (IN/OUT), §12 (mini-ADR szkic)
- Backlog: `Project Plan/feature-cpdf-tickets.md` (epik CPDF, 27 ticketów, Issues #2282–#2308)
- Related ADRs: ADR-0023 (Konfigurator XML — Export/Feed placement, cache-and-serve, token-in-URL), ADR-0018 (channel publication profile), ADR-0015 (bare UUID cross-BC), ADR-0020 (OpenAPI custom route), ADR-0011 (ORM XML mapping w Infrastructure), ADR-0009 (ObjectType)
- Tickets: CPDF-P0-01 (finalizacja tego ADR), CPDF-P0-02 (Deptrac), CPDF-P0-03 (port + DompdfRenderer)

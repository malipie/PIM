# VIEW-13 — Dashboard „Centrum dowodzenia katalogiem" (relayout, pixel-perfect, MOCK-only)

> **GitHub Issue:** [#2143](https://github.com/malipie/PIM/issues/2143)

> **Status scope:** FRONTEND-ONLY, dane statyczne (mock). Backend/wiring realnych endpointów **poza zakresem** — dorabiane w osobnych ticketach (`dashboard-do-oprogramowania.md`). Ten ticket dostarcza **pixel-perfect makietę w kodzie** wg dostarczonego screena, gotową do stopniowego podpinania danych później.
> **Trigger:** operator — „rozpisz jeden ticket na przeformatowanie strony głównej – dashboardu. Na razie tylko jako mocki, funkcjonalności za tym stojące będę dorabiał później, ma być pixel perfect jak na screenie".

---

## 1. Kontekst i cel widoku

Strona główna (`/dashboard`) to pierwszy ekran po loginie — „centrum dowodzenia katalogiem". Obecna implementacja (Dashboard v2, NUI-02 #1421) używa jasnego hero agenta + rozproszonych kafli (Activity/Sync/Completeness/Channels/Alerts/AgentActivity/Backup/TopEdited) i banera „dane demonstracyjne".

Ten ticket **przebudowuje layout do nowego, dopracowanego designu** ze screena: ciemny hero agenta z polem promptu i szybkimi akcjami na samej górze, czysty pas 4 KPI, dwie duże karty (Zdrowie danych z kompletnością per-kanał + Aktywność katalogu z rankingiem edytowanych) i pełnoszerokie „Centrum akcji" na dole. Cel: **wizualny rezultat 1:1 ze screenem**, na statycznych danych. Realne dane podpinane później per widget.

To jest **relayout** (jak VIEW-07 dla edycji produktu) — nie nowy bounded context, nie zmiana architektury. Zgodne z regułą view-first: scope = dokładnie ten jeden ekran ze screenshota.

---

## 2. Mockup / źródło designu

- **Źródło #1 (autorytatywne dla layoutu):** screenshot dostarczony przez operatora 2026-07-03 (Dashboard „Centrum dowodzenia katalogiem"). Pixel-perfect binding: layout, hierarchia, copy, kolory, spacing, wartości liczbowe — **1:1 ze screena**.
- **Źródło #2 (kontekst produktowy, NIE layout):** `Project Plan/UI/dashboard-brief-makiety.md` — misja ekranu, persony, zasady action-oriented. **Uwaga:** brief w §3/§I degraduje agenta na dół jako HOOK; **screen jest nowszy i nadrzędny** — agent wraca na górę jako hero z polem promptu. Rozbieżność świadoma, screen wygrywa.
- **Punkt wyjścia w kodzie:** `apps/admin/src/features/dashboard/` (page.tsx + 11 komponentów). Część do reuse (KpiCards, CompletenessMetrics, ActivityChart), część do przebudowy, część do usunięcia z layoutu (patrz §3.2).
- **Powiązane, poza scope:** `epik-01-dashboard.md` (placeholder epiku), `dashboard-do-oprogramowania.md` (backlog BE).

**Pixel-perfect binding:** dopuszczalne adaptacje stack-specific (shadcn/ui zamiast hand-rolled, tokeny Tailwind projektu `ink`/`ink-2`/`line`/`soft-shadow`), ale wizualny rezultat <2% pixel mismatch względem screena. Wartości liczbowe **dokładnie jak na screenie** (patrz §3.4).

---

## 3. Zakres frontend (FE)

### 3.1 Routing
- Bez zmian: trasa `/dashboard` (`App.tsx:466`), catch-all `*` → `/dashboard` (`App.tsx:757`). Auth wymagany (istniejący guard).
- Top bar („Dashboard" + selektor PL + dzwonek powiadomień) = **app shell / istniejący `topbar-v2.tsx`** — **zostaje jak jest** (decyzja operatora), strona posiada layout od sekcji „Dzień dobry" w dół.
- **Jedyny wyjątek in-scope w top barze:** usunąć pill „Audit log: aktywny · ostatnia zmiana 14 min temu" wraz z labelką MOCK — komponent `AuditLogStatus` (`src/layout/audit-log-status.tsx`), użyty tylko w `topbar-v2.tsx:86`. Usunąć render (blok `<div className="hidden md:block"><AuditLogStatus /></div>`) + import (`topbar-v2.tsx:7`) + plik `audit-log-status.tsx` (+ ewentualne testy). To jedyny odnośnik, więc usunięcie jest bezpieczne.

### 3.2 Komponenty — plan reuse / rebuild / remove

**Do przebudowy layoutu (`page.tsx`):** nowy porządek sekcji (§3.4), grid `max-w` kontener, spacing `space-y-6`.

| Komponent (istniejący) | Akcja | Uzasadnienie |
|---|---|---|
| `HeroAgentPanel` | **REBUILD** (ciemny wariant) | dziś jasny gradient orange; screen = ciemny navy hero z polem promptu, ⌘K, chipsami akcji, licznikiem „42 zaakceptowanych zmian" |
| `KpiCards` | **RESTYLE** | 4 kafle jak na screenie (wartość + delta + hint + strzałka drill-down); statyczne wartości |
| `CompletenessMetrics` | **REBUILD/ROZSZERZ** | ring 85% + pasek dystrybucji + legenda 5 progów + sekcja „KOMPLETNOŚĆ WG KANAŁU" (4 kanały) w jednej karcie |
| `ActivityChart` | **REBUILD/ROZSZERZ** | wykres 2-liniowy + toggle 7/30/90d + legenda Dodane/Zmodyfikowane + „NAJCZĘŚCIEJ EDYTOWANE" (6 pozycji) w jednej karcie |
| `AlertCenter` | **REBUILD** → `ActionCenter` | pełnoszerokie „Centrum akcji", 5 pozycji w gridzie 2-kol, liczniki severity, CTA per pozycja |
| `TopEditedProducts` | **MERGE** do karty Aktywność | na screenie to sekcja wewnątrz karty Aktywność, nie osobny kafel |
| `ChannelDistribution` | **REMOVE z layoutu** | histogram „produkty w N kanałach" **nie występuje** na screenie |
| `BackupWidget` | **REMOVE z layoutu** | sekcja System/Backup **nie występuje** na screenie |
| `RecentAgentActivity` | **REMOVE z layoutu** | osobna karta aktywności agenta **nie występuje** na screenie |
| `SyncsStatusPanel` | **REMOVE z layoutu** | przestarzały (hardkodowane Shopify/BaseLinker); sync reprezentowane w Centrum akcji |
| `DashboardMockBanner` | **REMOVE** (decyzja operatora) | amber baner „dane demonstracyjne" **nie występuje** na screenie; usunąć z `page.tsx`, plik + test skasować jeśli nieużywane gdzie indziej |

> **Uwaga:** „REMOVE z layoutu" = usunięcie z `page.tsx`, nie kasowanie plików (mogą wrócić w innym wariancie dashboardu). Jeśli komponent nie jest importowany nigdzie indziej — usuń plik + testy, żeby nie zostawić martwego kodu (zweryfikuj `grep`).
>
> **BEZ LABELEK „MOCK" (decyzja operatora):** na dashboardzie **nie dodajemy żadnej labelki `MockBadge` / „makieta" / „wkrótce"** — ani na kartach, ani w rogach, ani w top barze. Cały ekran jest statyczną makietą, ale wizualnie ma być czysty jak na screenie. Istniejący `MockBadge` (`@/components/ui/mock-badge`) **nie jest** używany w nowych komponentach dashboardu. (Poza zakresem: `MockBadge` w innych częściach apki — sidebar, agent-search, imports — zostają nietknięte.)

**Nowe komponenty do napisania:**
- `DashboardGreeting` — „Dzień dobry, {imię} 👋" + dwutonowy H1 „Centrum dowodzenia katalogiem. **Co dziś chcesz zmienić?**".
- `AgentCommandHero` (ciemny) — badge „AGENT · CLAUDE SONNET 4.5", pole promptu z placeholderem/wpisanym tekstem + kursor, „naciśnij ⌘K", przycisk „Zapytaj agenta →", chipsy szybkich akcji, licznik tygodniowy.
- `ChannelCompletenessList` (sub-komponent karty Zdrowie danych) — 4 wiersze kanał + pasek + % + liczba.
- `MostEditedList` (sub-komponent karty Aktywność) — 6 wierszy produkt + SKU·kategoria + % + „N edycji".
- `ActionCenterItem` — pojedyncza pozycja alertu (ikona severity + tytuł + 2 linie meta + CTA + „oznacz jako przeczytane").

### 3.3 State management
- **Brak Refine resources / mutacji.** Wszystkie dane **statyczne** (moduł `mocks.ts` w `features/dashboard/`), typowane interfejsami TS. Zero fetchy, zero React Query w tym ticketcie.
- Interaktywny stan lokalny **wyłącznie kosmetyczny**, bez side-effectów danych:
  - toggle zakresu wykresu 7/30/90d — `useState`, przełącza tylko podświetlenie + (opcjonalnie) statyczny wariant serii; **nie** woła API.
  - pole promptu agenta — `useState` kontrolowany input, **disabled/placeholder** (bez wysyłki); przycisk „Zapytaj agenta" i chipsy — `type="button"` bez akcji (lub `title` „wkrótce").
  - „oznacz jako przeczytane" / „Pokaż wszystkie" / drill-down strzałki — `button`/`Link` bez docelowej mutacji; mogą prowadzić do istniejących tras (np. `/products`) tam gdzie sensowne, ale bez wymogu.
- **Świadome odejście:** obecne żywe hooki (`useDashboardCounts`, `useDashboardCompleteness`) **zastępujemy statycznymi wartościami** ze screena, żeby zagwarantować pixel-perfect liczby. Hooki zostają w repo do ponownego podpięcia w ticketach BE (patrz §9).

### 3.4 Struktura sekcji widoku (kolejność renderu, top→down)
1. **Greeting** — „Dzień dobry, Kasiu 👋" + dwutonowy H1.
2. **Agent hero (ciemny)** — prompt + ⌘K + „Zapytaj agenta" + chipsy + licznik.
3. **KPI band** — 4 kafle.
4. **Grid 2-kol** — lewo: karta „Zdrowie danych / Kompletność katalogu"; prawo: karta „Aktywność katalogu / Tempo pracy zespołu".
5. **Centrum akcji** — pełna szerokość, 5 pozycji w gridzie 2-kol.

### 3.4a Mapping element-po-elemencie — Greeting + Agent hero
- ~~Greeting eyebrow: `Dzień dobry, {name} 👋`~~ — **USUNIĘTE (decyzja operatora 2026-07-03, podczas implementacji)**: linia powitalna z imieniem nie wchodzi; sekcja zaczyna się bezpośrednio od dwutonowego H1.
- **Marginesy strony (korekta operatora 2026-07-03):** kontener strony to gołe `space-y-6` — padding daje `<main>` z `AppLayout` (`p-4 md:p-6`), identycznie jak wszystkie inne podstrony. Bez własnych `px-*`.
- H1 dwutonowy: `Centrum dowodzenia katalogiem.` (`text-ink`) + ` Co dziś chcesz zmienić?` (`text-ink-2`/muted) — jeden `<h1>`, drugi fragment w `<span>` muted. Rozmiar ~`text-[40px]/leading-tight display font-semibold`.
- Agent hero: `rounded-3xl` ciemny background (navy/near-black, np. `bg-[#0f1420]`/`bg-ink` — dobrać do screena), `p-8`, `soft-shadow-lg`.
  - lewy górny: kwadratowa ikonka sparkle w zaokrąglonym boxie; obok uppercase label `AGENT · CLAUDE SONNET 4.5` (`text-[11px] tracking-wide text-white/50`).
  - linia promptu: `> stwórz feed XML dla Google Shopping z kategorii Pneumatyka` + migający kursor `|` (`text-white/90`, `text-[22px]`). To **input placeholder / kontrolowany, disabled-styled** — nie wysyła.
  - prawy: `naciśnij ⌘K` (`text-white/50`) + przycisk `Zapytaj agenta →` (biały pill `bg-white text-ink rounded-2xl`).
  - chipsy (dark pills `bg-white/5 border-white/10 text-white/80`): `Dodaj atrybut`, `Generuj opisy SEO`, `Bulk update kategorii`, `Tłumaczenia PL→DE`, `Eksport feed XML`.
  - prawy-dół muted: `42 zaakceptowanych zmian w tym tygodniu` (`text-white/50`).

### 3.4b Mapping — KPI band (4 kafle, białe `rounded-2xl border-line soft-shadow p-5`)
Każdy kafel: etykieta (`text-ink-2`), duża wartość (`display text-[40px] font-semibold text-ink`), linia delty, hint, strzałka drill-down (`→` prawy-dół).

| # | Etykieta | Wartość | Delta | Hint |
|---|---|---|---|---|
| 1 | Produkty | `12 847` | `↑ +184 · 30 dni` (zielony) | łącznie w katalogu |
| 2 | Gotowe do publikacji | `10 984` | `↑ +312 · 30 dni` (zielony) | ≥ 80% kompletności |
| 3 | Średnia kompletność | `87%` | `↑ +3 pkt · 30 dni` (zielony) | wszystkie kanały |
| 4 | Otwarte alerty | `5` | `24h · brak trendu` (neutralny, szary) | wymaga interwencji |

> Kafel #4 **bez** zielonej strzałki — reguła „nie pokazuj fałszywego trendu" gdy brak delty (NUI-02).

### 3.4c Mapping — Karta „Zdrowie danych / Kompletność katalogu"
- Nagłówek: eyebrow `Zdrowie danych`; tytuł `Kompletność katalogu`; prawy-górny pill `● + 3 pkt / tydz.` (zielony).
- Donut/ring: `85%` w środku, podpis `gotowe do publikacji`. Segmenty ringu w kolorach dystrybucji.
- Obok ringu: duża liczba `10 984` `/ 12 847 SKU ≥80%`.
- Poziomy pasek dystrybucji (stacked, `rounded-full`): zielony→jasnozielony→pomarańcz→czerwony.
- Legenda (grid 2-kol): `● 100% — 4210` · `● 80–99% — 6774` · `● 50–79% — 1118` · `● 25–49% — 598` · `● < 25% — 147`.
- Divider.
- Podsekcja `KOMPLETNOŚĆ WG KANAŁU` (uppercase eyebrow) + prawy `sort: najgorszy pierwszy`. 4 wiersze (`ChannelCompletenessList`), każdy: nazwa | poziomy pasek w kolorze | `%` | liczba:
  - Google Shopping — pasek niebieski — `76%` · `9764`
  - BaseLinker — pasek pomarańczowy — `81%` · `10 405`
  - Shopify — pasek zielony — `94%` · `11 842`
  - Comarch ERP XL — pasek zielony — `99%` · `12 718`

### 3.4d Mapping — Karta „Aktywność katalogu / Tempo pracy zespołu"
- Nagłówek: eyebrow `Aktywność katalogu`; tytuł `Tempo pracy zespołu`; prawy-górny segmented toggle `7 dni | [30 dni] | 90 dni` (aktywny 30 dni).
- Legenda: `● Dodane 1354` (ciemny punkt) · `○ Zmodyfikowane 2141` (jasny); prawy muted `średnio 117 zmian / dzień`.
- Wykres liniowy 2-serie (dodane/zmodyfikowane), oś X: `29 dni temu` … `14 dni temu` … `dziś`. Reuse biblioteki wykresów już użytej w `ActivityChart` (sprawdzić: recharts?); dane statyczne.
- Divider.
- Podsekcja `NAJCZĘŚCIEJ EDYTOWANE` (uppercase eyebrow) + prawy `Pełna lista →`. 6 wierszy (`MostEditedList`), każdy: nazwa produktu + druga linia `SKU · kategoria` (mono SKU) — prawy: `%` kompletności + `N edycji`:
  1. Czujnik indukcyjny Festo IS-50 PNP M12 · `FES–PNZ–IS50` · Czujniki — `96%` · `47 edycji`
  2. Rura zaciskowa DN50 stal nierdzewna 316L · `KLI–RZP–DN50` · Hydraulika — `88%` · `41 edycji`
  3. Wkrętarka akumulatorowa Bosch GSR 18V-90 · `BSC–WKR–18V` · Elektronarzędzia — `100%` · `38 edycji`
  4. Zawór elektromagnetyczny SMC 24V solenoid · `SMC–ZWR–24V` · Pneumatyka — `72%` · `34 edycji`
  5. Plecak fotograficzny Wandrd PRVKE Pro 31L · `WAR–PLE–PRO` · Akcesoria — `94%` · `29 edycji`
  6. Złącze M12 Murr 4-pin IP67 ekranowane · `MKP–TWR–IP67` · Złącza — `67%` · `22 edycji`

### 3.4e Mapping — „Wymaga uwagi / Centrum akcji" (pełna szerokość)
- Nagłówek: eyebrow `Wymaga uwagi`; tytuł `Centrum akcji` + badge `5 spraw`. Prawy: `2 krytyczne` (czerwony pill), `3 ostrzeżenia` (amber pill), `Pokaż wszystkie ( 12 ) →`.
- Grid 2-kol, 5 pozycji (`ActionCenterItem`). Każda: ikona severity (czerwony trójkąt = krytyczny, amber trójkąt = ostrzeżenie) + tytuł (bold) + linia meta1 `Severity · źródło · czas` (Severity kolorowany) + linia meta2 (detale, muted) — prawy: przycisk CTA (outline) + pod nim link `oznacz jako przeczytane`.

| # | Severity | Tytuł | Meta1 | Meta2 | CTA |
|---|---|---|---|---|---|
| 1 | Krytyczny | Synchronizacja „Mtodo Marketplace" nieudana — 412 rekordów odrzuconych | Krytyczny · Konfigurator API · 13:32 | token OAuth2 wygasł · 401 Unauthorized | Zobacz log |
| 2 | Krytyczny | Import „pim-catalog-0630.xlsx" zakończony częściowo — 132 wiersze z błędami | Krytyczny · Importy · wczoraj · 15:59 | SKU puste (18) · typ number (114) | Pobierz raport błędów |
| 3 | Ostrzeżenie | Feed „B2B — Hurtownia Stalko" — regeneracja przerwana (brak pola &lt;price&gt;) | Ostrzeżenie · Feedy XML · dziś · 02:00 | custom · mapowanie 6/9 pól | Otwórz feed |
| 4 | Ostrzeżenie | Google Shopping: kompletność spadła do 76% — poniżej progu publikacji (80%) | Ostrzeżenie · Zdrowie danych · dziś · 08:10 | −3 pkt / 24h · 312 SKU straciło gotowość | Pokaż produkty |
| 5 | Ostrzeżenie | Webhook „price.changed → Stal-Met": 3 dostawy w dead-letter (HTTP 503) | Ostrzeżenie · Integracje · webhooki · 13:02 | 5× retry exp. backoff wyczerpane | Otwórz log |

> Nazwy firm (Mtodo, Stalko, Stal-Met) = **przykładowe**, ze screena. Zostają jako mock copy (to nie realny tenant).

### 3.5 i18n
- Wszystkie stringi przez `t()` z kluczami pod `dashboard.*` (rozszerzyć istniejącą przestrzeń). **Ban na literały w JSX.**
- Nowe grupy kluczy: `dashboard.greeting.*`, `dashboard.agent.*` (badge/prompt_placeholder/ask/hint/actions.*/accepted_count), `dashboard.kpi.*` (labels/hints), `dashboard.health.*` (title/eyebrow/trend/ring_caption/legend.*/channels.*), `dashboard.activity.*` (title/range.*/legend.*/avg/most_edited.*), `dashboard.action_center.*` (title/badge/severity.*/items.*/cta.*/mark_read/show_all).
- Wartości mock (liczby, nazwy produktów, treści alertów) mogą siedzieć w `mocks.ts` (dane) — **ale etykiety UI** (nagłówki, przyciski, hinty) w `pl.json`/`en.json`. Na MVP dopuszczalny `defaultValue` inline jako fallback (jak w istniejącym `DashboardMockBanner`), z docelowym kompletem w `pl.json` + `en.json`.
- Imię w powitaniu: `defaultValue: 'Kasiu'` (docelowo z sesji, poza scope).

### 3.6 a11y
- Landmarki: strona w `<main>`; sekcje jako `<section>` z `aria-labelledby` na nagłówkach.
- Agent hero: pole promptu = `<input>`/`<textarea>` z `aria-label`, stan disabled poprawnie zakomunikowany (`aria-disabled` lub `disabled` + tytuł). Chipsy = `<button type="button">`.
- KPI/karty klikalne: `<a>`/`<button>` z czytelnym accessible name (nie sama strzałka — dodać `aria-label`/`sr-only`).
- Toggle 7/30/90d: `role="tablist"`/segmented — użyć shadcn `ToggleGroup` lub `Tabs` z `aria-pressed`/`aria-selected`, keyboard nav strzałkami.
- Severity ikony: `aria-hidden`, severity zakomunikowany tekstem (etykieta „Krytyczny"/„Ostrzeżenie" widoczna).
- Kontrast: ciemny hero — biały tekst/opacity ≥ AA (uwaga: `text-white/50` na ciemnym tle bywa graniczne; zweryfikować, w razie potrzeby `/60`–`/70`). Reguła z memory `fe-axe-playwright-gotchas` (zinc-400 fail → zinc-500).
- **axe-core 0 violations serious/critical** na nowym widoku.

### 3.7 Locales
- N/A — dashboard nie edytuje pól wielojęzycznych; brak `<LocaleAddDialog>`.

### 3.8 Empty / loading / error states
- **W zakresie tego ticketu (mock):** tylko **stan pełny (happy path)** ze screena — bo dane statyczne, nie ma realnego loading/error.
- **Poza zakresem (deferred, patrz §9):** empty-state/onboarding dla nowego tenanta (brief §8.2), degradacja per-widget (§8.3), wariant RBAC Editor (§8.4), skeleton loading (§8.6). Te wymagają realnego stanu danych → dorabiane razem z podpięciem BE. Zaznaczyć w PR jako świadome odejście.
- Skeleton per widget: opcjonalnie przygotować strukturę (klasy) pod przyszłość, ale nie wymagane w mock-only.

---

## 4. Zakres backend (BE)

**N/A — ticket FRONTEND-ONLY, dane statyczne.** Operator explicite: „na razie tylko jako mocki, funkcjonalności za tym stojące będę dorabiał później".

- Brak nowych/zmienionych endpointów, encji, migracji, listenerów, voterów, provenance, workerów, Mercure.
- Brak zmian w `docs/api-spec/v0.json` (brak zmian API) → gate „OpenAPI drift" nie dotyczy.
- Realne endpointy (KPI delty, activity chart, action center/`Alert`, kompletność per-kanał) pozostają w backlogu `dashboard-do-oprogramowania.md` i będą podpinane per widget w osobnych ticketach. Struktura danych mock (`mocks.ts`) **projektowana tak, by mapowała się 1:1 na przyszłe DTO** (te same pola), żeby podmiana źródła była mechaniczna.

---

## 5. Sub-tasks (checklist)

**Frontend**
- [ ] `page.tsx` — nowy layout 5 sekcji, usunięcie importów zdejmowanych widgetów.
- [ ] `mocks.ts` — typowane dane statyczne (KPI, health/ring/channels, activity/series/mostEdited, actionCenter items).
- [ ] `DashboardGreeting` — powitanie + dwutonowy H1.
- [ ] `AgentCommandHero` (ciemny) — prompt + ⌘K + „Zapytaj agenta" + chipsy + licznik.
- [ ] `KpiCards` — restyle 4 kafle wg §3.4b (na mock danych).
- [ ] `CompletenessMetrics` — rebuild: ring + pasek + legenda + `ChannelCompletenessList`.
- [ ] `ActivityChart` — rebuild: toggle 7/30/90d + wykres 2-serie + `MostEditedList`.
- [ ] `ActionCenter` + `ActionCenterItem` — pełnoszerokie, 5 pozycji, liczniki severity, CTA.
- [ ] Usunięcie z layoutu: `ChannelDistribution`, `BackupWidget`, `RecentAgentActivity`, `SyncsStatusPanel`, `DashboardMockBanner`. Usuń martwe pliki + ich testy jeśli nieużywane nigdzie indziej (`grep` przed usunięciem).
- [ ] **Top bar cleanup:** usunąć `AuditLogStatus` (pill „Audit log: aktywny … MOCK") z `topbar-v2.tsx` (render + import) + skasować `src/layout/audit-log-status.tsx` (+ test).
- [ ] **Zero labelek „MOCK":** żaden nowy komponent dashboardu nie renderuje `MockBadge` ani „makieta"/„wkrótce".
- [ ] i18n: klucze `dashboard.*` w `pl.json` + `en.json`.
- [ ] a11y: landmarki, aria-labels, toggle keyboard nav, kontrast na ciemnym hero.

**E2E + testy komponentów**
- [ ] Playwright: render `/dashboard`, asercje na kluczowe teksty (nagłówki sekcji, 4 KPI wartości, „Centrum akcji", 5 pozycji, chipsy agenta) + brak console errors.
- [ ] axe-core scan `/dashboard` — 0 serious/critical.
- [ ] (opcjonalnie) update/wywalenie testów jednostkowych usuniętych komponentów; testy dla nowych list (`ChannelCompletenessList`, `MostEditedList`, `ActionCenterItem`) render + i18n.

**Non-functional**
- [ ] typecheck, Biome strict, Vite build — zielone.
- [ ] Bundle size Δ < 50KB gzip.
- [ ] Lighthouse a11y = 100, performance ≥ 85.
- [ ] pnpm audit — 0 high/critical.

**Dokumentacja**
- [ ] `agent/current_status.md` — sekcja VIEW-13.
- [ ] `agent/lessons.md` — jeśli coś non-obvious (np. wykres/kontrast ciemnego hero).

**Manual smoke (operator)**
- [ ] Login → `/dashboard` wygląda pixel-perfect jak screen; brak czerwonych błędów w konsoli.

---

## 6. Acceptance criteria — funkcjonalne

- Widok `/dashboard` wygląda **pixel-perfect jak screen** (diff < 2%): greeting, ciemny hero agenta, 4 KPI, dwie karty (Zdrowie danych + Aktywność), Centrum akcji z 5 pozycjami.
- Wszystkie wartości liczbowe i copy **dokładnie jak na screenie** (§3.4b–e).
- Interakcje kosmetyczne działają bez błędów: toggle 7/30/90d przełącza podświetlenie; pole promptu przyjmuje focus/tekst (bez wysyłki); przyciski/chipsy klikalne bez crashy.
- i18n PL/EN przełącza etykiety UI (dane mock mogą pozostać PL).
- Brak czerwonych błędów w DevTools Console.
- Usunięte z layoutu widgety nie zostawiają martwych importów ani błędów runtime.

---

## 7. Acceptance criteria — non-functional (GATES; zredukowane do FE — brak BE)

**Dotyczy (FE):**
- **TypeScript noEmit:** 0 errors.
- **Biome strict:** 0 errors.
- **Vite build:** zielony; **bundle size Δ < 50KB gzip** (build report w PR).
- **Lighthouse:** a11y = 100, performance ≥ 85, best-practices ≥ 90.
- **axe-core:** 0 violations serious/critical na `/dashboard`.
- **Playwright E2E:** render happy-path zielony (+ brak console errors).
- **pnpm audit:** 0 high/critical.
- **i18n coverage:** nowe klucze obecne w `pl.json` + `en.json` (lub działają przez `defaultValue`).

**N/A (brak backendu w tym ticketcie — uzasadnienie: mock-only):**
- ~~PHPStan max~~ · ~~PHPUnit ≥80%~~ · ~~ApiTestCase 401/403/404~~ · ~~p95 <300ms / k6~~ · ~~N+1 / EXPLAIN ANALYZE~~ · ~~indeksy~~ · ~~pagination cursor~~ · ~~worker memory~~ · ~~multi-tenancy cross-read~~ · ~~RBAC voter~~ · ~~audit log~~ · ~~provenance~~ · ~~OpenAPI snapshot~~ — wszystkie wracają w ticketach podpięcia BE.

---

## 8. Smoke-test scenariusze (manualne, dla operatora)

1. Login `admin@demo.localhost / changeme`.
2. Po loginie ląduje na `/dashboard` (catch-all też → dashboard).
3. Porównaj 1:1 ze screenem: greeting, ciemny hero (prompt „stwórz feed XML…", chipsy, „42 zaakceptowanych zmian"), 4 KPI (12 847 / 10 984 / 87% / 5), karta Zdrowie danych (ring 85%, legenda 5 progów, 4 kanały), karta Aktywność (toggle 30 dni, „NAJCZĘŚCIEJ EDYTOWANE" 6 pozycji), Centrum akcji (5 spraw, 2 krytyczne / 3 ostrzeżenia).
4. Kliknij toggle 7/30/90d — podświetlenie się zmienia, brak crasha.
5. Kliknij w pole promptu — przyjmuje focus/tekst, nic nie wysyła.
6. Przełącz język PL/EN (jeśli EN podpięty) — etykiety UI się tłumaczą.
7. DevTools Console — brak czerwonych errorów; DevTools Network — brak nieudanych (5xx) fetchy dashboardu (dane są statyczne).
8. Zmniejsz okno (tablet/mobile) — sekcje układają się w jedną kolumnę wg priorytetu, brak poziomego scrolla.

---

## 9. Edge cases / poza zakresem

**Świadomie poza zakresem (deferred — osobne tickety):**
- Realne dane / endpointy wszystkich widgetów (KPI delty, activity chart, action center `Alert`, kompletność per-kanał, top edited) — backlog `dashboard-do-oprogramowania.md`.
- Działający agent (wysyłka promptu, ⌘K command palette, chipsy jako realne akcje) — Faza 2, epik 0.7.
- Realny drill-down z każdego widgetu do widoków docelowych (brief §5) — podpinane z danymi.
- Empty-state / onboarding checklist dla nowego tenanta (brief §8.2).
- Degradacja per-widget przy awarii endpointu (brief §8.3) + skeleton loading (§8.6).
- Wariant RBAC Editor — ukrycie sekcji System (brief §8.4). Uwaga: na tym screenie sekcji System i tak nie ma.
- Realny top bar z powiadomieniami (dzwonek) i selektorem języka — jeśli nie jest już częścią app shell.
- Widgety z v2 zdjęte z tego layoutu (ChannelDistribution, BackupWidget, RecentAgentActivity, SyncsStatusPanel) — jeśli operator zechce je wrócić w innym miejscu, osobny ticket.

**Decyzje operatora (2026-07-03) — rozstrzygnięte:**
- **`DashboardMockBanner`** (amber „dane demonstracyjne", AUD-058 #1610) → **USUNĄĆ**. Bez zamiennika.
- **Żadnych labelek „MOCK" na dashboardzie** → **nie dodawać** `MockBadge` / „makieta" / „wkrótce" nigdzie (karty, rogi, top bar). Ekran ma być wizualnie czysty jak screen, mimo że dane są statyczne.
- **Top bar** → zostaje jak jest, **z jednym wyjątkiem:** usunąć pill „Audit log: aktywny · ostatnia zmiana 14 min temu" + jego labelkę MOCK (`AuditLogStatus`).

**Edge cases pokryte:** responsywność (1-kolumna na tablet/mobile), keyboard nav toggle, disabled prompt bez wysyłki, brak console errors.

---

## 10. Powiązane ADR / dokumenty

- **ADR:** brak — relayout FE bez zmian architektonicznych (nie dotyka `ObjectType`/schema/multi-tenancy/agent-security). Zgodne z sekcją „Kiedy NIE view-first" — żaden z 10 triggerów ADR nie zachodzi.
- **Aktualizacje:** `agent/current_status.md` (sekcja VIEW-13), ewentualnie `agent/lessons.md`.
- **Kontekst:** `Project Plan/UI/dashboard-brief-makiety.md` (misja/persony — layout nadpisany przez screen), `Project Plan/UI/Wdrozenie_grafiki/dashboard-do-oprogramowania.md` (backlog BE do przyszłego podpięcia), `epik-01-dashboard.md`.
- **Poprzednia implementacja:** Dashboard v2 (NUI-02 #1421), banner AUD-058 (#1610).

---

### Estymacja
~10–14h FE (rebuild hero + 2 duże karty z sub-listami + action center + mocks + i18n + a11y + Playwright/axe). Zero BE.

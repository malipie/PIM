# Handoff: Harmon PIM — strona produktowa (PL)

## Overview
Marketingowa strona produktu **Harmon PIM** — systemu do zarządzania informacją produktową (PIM) z agentem AI, dostępnego w modelu self-host (fair-code) oraz w chmurze. Serwis liczy 8 stron: strona główna, przegląd produktu, funkcje, integracje, wdrożenie, cennik, zasoby (docs hub) i kontakt, plus modal z formularzem demo. Język całości: polski.

## About the Design Files
Pliki `.dc.html` w tym pakiecie to **referencje projektowe zbudowane w HTML** — prototypy pokazujące docelowy wygląd i zachowanie, a nie kod produkcyjny do skopiowania. Zadaniem jest **odtworzenie tych projektów w docelowym środowisku** (Next.js/React, Astro, Vue, Laravel Blade itd.) z użyciem istniejących w nim wzorców i bibliotek. Jeśli środowiska jeszcze nie ma — wybierz stack odpowiedni dla strony marketingowej (rekomendacja: Next.js/Astro + Tailwind, treść w CMS lub MDX).

Uwaga techniczna: pliki używają autorskiego runtime'u (`support.js`) z szablonem `<x-dc>`, dziurami `{{ }}`, `<sc-if>`, `<sc-for>` i klasą logiki `class Component extends DCLogic`. Wszystkie style są **inline**. To wyłącznie mechanika prototypu — w docelowym kodzie należy użyć normalnych komponentów i systemu stylów projektu. Otwórz każdy plik w przeglądarce, żeby zobaczyć docelowy rezultat.

## Fidelity
**High-fidelity.** Kolory, typografia, spacing, stany hover i interakcje są finalne. UI należy odtworzyć 1:1 (z tolerancją wynikającą z komponentów docelowego design systemu). Copy w plikach jest zatwierdzone i ma trafić do implementacji bez zmian.

## Screens / Views

Wspólny szkielet każdej strony: sticky header (68 px) → `<main>` z sekcjami → footer. Kontener treści `max-width: 1200px; margin: 0 auto; padding: 0 24px` (hero bywa węższy: 820–900 px). Sekcje przeplatają trzy tła: białe `#ffffff`, jasne `#f6f8fb`, granatowe `#0e1830`; sekcje na innym tle mają zaokrąglone górne narożniki `clamp(44px, 8vw, 130px)`, a kolejna biała sekcja nachodzi na nie przez `margin-top: -72px` + `padding-top: 144px` (efekt „wsuwanej karty"). Sekcja domykająca (`Domknięcie`) jest granatowa z zaokrąglonym dołem, a footer nachodzi na nią tym samym mechanizmem.

### 1. Header / nawigacja (wspólny)
- `position: sticky; top: 0; z-index: 60`, tło `rgba(255,255,255,.8)` + `backdrop-filter: blur(12px)`, dolna linia `1px solid #e3e9f1`, wysokość 68 px.
- Logo: SVG (4 zaokrąglone słupki: 3× `#0E1830`, jeden `#FF4F00`) + słowo `harmon` (Manrope 700, 21 px, `letter-spacing: .025em`) + plakietka `PIM` (Manrope 800, 10 px, `letter-spacing:.18em`, tło `#FF4F00`, tekst biały, radius 5 px).
- Linki (15 px, `#2f405a`, hover `#16233f`): **Produkt ▾**, **Wdrożenie PIM**, **Cennik**, **Zasoby ▾**, **Kontakt**. Aktywna strona: 600, `#16233f`, `border-bottom: 2px solid #ff4f00`.
- Dwa dropdowny (otwierane hoverem i klikiem, zamykane Escape; otwarcie jednego zamyka drugi):
  - **Produkt** → Przegląd produktu, Funkcje, Integracje
  - **Zasoby** → Self-host Harmon, Dokumentacja, Dokumentacja API, Licencja
  - Panel: `#ffffff`, `1px solid #e3e9f1`, radius 14 px, padding 8 px, `box-shadow: 0 24px 48px -18px rgba(20,32,58,.22), 0 2px 6px rgba(20,32,58,.06)`, min-width 230 px, offset `left:-14px`, `padding-top:16px` (bufor pod kursor). Pozycje: 14.5 px/500, padding 11×12 px, radius 10 px, hover tło `#f6f8fb`.
- Po prawej: „Zaloguj się" (tło `#16233f`, biały tekst, 14 px/600, padding 10×18, radius 12) i „Umów prezentację" (tło `#ff4f00`, 19 px/700, padding 10×22, radius 12, hover `#e64700` + `translateY(-1px)`).
- Poniżej 1120 px: nawigacja znika, pojawia się hamburger 44×44 (`1px solid #e3e9f1`, radius 12) i rozwijane menu pełnej szerokości z pozycjami i sekcją „Produkt" jako listą wciętą (`border-left: 2px solid #ffd0bd`).

### 2. Harmon PIM - Landing.dc.html (strona główna)
Sekcje: Hero (nagłówek + dwa zrzuty: `assets/harmon-workspace.png` w ramce okna aplikacji i nakładka `assets/harmon-agent-chat.png` pozycjonowana `right:-12px; bottom:-40px`), „Dwie drogi" (brzoskwiniowy gradient `linear-gradient(180deg,#ffdcc9 0%,#fff1ec 55%)`) z **interaktywnym suwakiem porównania** dwóch zrzutów (`harmon-bulk-action.png` vs `harmon-agent-panel.png`, `clip-path: inset(0 0 0 var(--pos,50%))`, uchwyt 2 px biały, obsługa myszy, dotyku i strzałek), sekcje wartości, typy obiektów (przełączane karty), FAQ (accordion), sekcja domykająca CTA, footer.

### 3. Produkt.dc.html
Hero → **Model licencjonowania** (karta `#f6f8fb` z dużym radiusem) → **AI i interfejs** (granat, dwa zrzuty w ramkach okna: panel agenta i workspace) → **Technologia** (PHP/Symfony, PostgreSQL) → **Własne typy obiektów** → **Feedy, API i integracje** → **Web to Print** (siatka kart PDF z `<image-slot>`, zdjęcia `produkt-*.png`) → **Workflow** → Domknięcie.

### 4. Funkcje.dc.html
Hero → Przegląd i AI (dashboard + agent) → Praca z katalogiem (workspace, akcje zbiorcze) → Model danych → **Media i publikacja** (granat, DAM + Web to Print) → Wymiana danych i zespół → **Cała lista funkcji** (indeks, wyłączalny propem `pokazIndeks`) → Domknięcie.

### 5. Integracje.dc.html
Hero → Cztery drogi integracji → **Katalog systemów** (filtrowana lista z kategoriami, stan `kat`, wyłączalny propem `pokazLogotypy`) → Przepływ danych (granat) → Jak podłączamy → Domknięcie.

### 6. Wdrozenie.dc.html
Hero z okruszkiem („Start / Wdrożenie PIM") i dwoma chipami-kotwicami → **Nasz zespół** (`#zespol`, brzoskwiniowy gradient `linear-gradient(180deg,#ffdcc9 0%,#fff1ec 55%)`, zaokrąglone tylko górne narożniki, 4 karty ról: Konsultant wdrożeniowy, Architekt struktury danych, Integrator, Opiekun po starcie; karty `rgba(255,255,255,.72)` + `1px solid #f2c9b5`, radius 20, padding 24×22) → **Jak przebiega** (`#przebieg`, 4 etapy) → Domknięcie z jednym CTA „Zapytaj o szczegóły wdrożenia".

### 7. Cennik.dc.html
Hero → **Plany** (grid `repeat(auto-fit, minmax(258px,1fr))`, 4 karty):
- **Self-Host** — `0 zł`, fair-code, kluczowe funkcje
- **Cloud** — od `1 000 zł`/mies., do 5 000 SKU
- **Cloud Pro** — wyróżniona karta na granacie `#0e1830` z plakietką, do 20 000 SKU
- **Enterprise** — `wycena`, SLA, funkcje indywidualne
Pod planami: lista funkcji w każdym planie + granatowy blok **„Dodatkowo w Cloud i Enterprise"** (4 kafle: Agent AI do zarządzania PIM-em, Tworzenie treści za pomocą AI, Web to Print, Workflow akceptacji; licznik „4 funkcje" w prawym górnym rogu) → **Chmura albo self-host** (`#selfhost`, granat) → **FAQ cennik** (accordion na brzoskwiniowym gradiencie; nad sekcją pasek `height:220px; margin:-110px 0; background:#ffdcc9` wypełniający narożniki) → Domknięcie.

### 8. Zasoby.dc.html (docs hub, wzorzec: docs.n8n.io)
Hero na brzoskwiniowym gradiencie: okruszek „Start / Zasoby", H1 **„Harmon PIM Dokumentacja"**, akapit wprowadzający, pole wyszukiwania (białe, `1px solid #f2c9b5`, radius 14, ikona lupy, skrót `/` w ramce po prawej) — w prototypie nieaktywne, docelowo szukajka po dokumentacji. Niżej grid `repeat(auto-fit, minmax(300px,1fr))` z 4 kartami sekcji: **Self-host Harmon** (`#selfhost`), **Dokumentacja** (`#dokumentacja`), **Dokumentacja API** (`#api`), **Licencja** (`#licencja`). Karta: `#f6f8fb`, `1px solid #e3e9f1`, radius 20, padding 28×26, ikona 38×38 na granacie (radius 11), tytuł 20 px/700, opis 15 px/300, stopka karty „OTWÓRZ SEKCJĘ" (JetBrains Mono, 11 px, `#bd3a08`); hover: `border-color:#ffb894` + `translateY(-2px)`. **Docelowo karty prowadzą do osobnego serwisu dokumentacji — linki są jeszcze puste (`#`).**

### 9. Kontakt.dc.html
Hero → **Formularz** (najpierw formularz: imię, firma, e-mail, telefon, wiadomość; karta biała, radius 22, `box-shadow: 0 20px 46px -20px rgba(20,32,58,.14)`) → dane adresowe i kontaktowe poniżej → Domknięcie.

### 10. Formularz-demo.dc.html (modal)
Modal „Umów darmową prezentację" otwierany z każdego CTA w nagłówku i sekcjach domykających. Overlay przyciemniający, zamykanie: klik w tło, przycisk zamknięcia, Escape. Po wysłaniu — stan potwierdzenia. Body dostaje `overflow: hidden` na czas otwarcia.

### Footer (wspólny)
Białe tło, zaokrąglone górne narożniki, `margin-top:-72px`, padding `128px 24px 40px`. Grid `repeat(auto-fit, minmax(180px,1fr))`, gap `40px 32px`, trzy kolumny zgodne z nawigacją:
- **Produkt**: Przegląd produktu, Funkcje, Integracje
- **Zasoby**: Self-host Harmon, Dokumentacja, Dokumentacja API, Licencja
- **Firma**: Wdrożenie PIM, Cennik, Kontakt
Nagłówki kolumn: JetBrains Mono, 12 px/600, uppercase, `letter-spacing:.08em`, `#16233f`. Linki 14 px, `#5b6b87`, hover `#e64700`.
Pasek dolny: linia `1px solid #e3e9f1`, logo + claim „Harmon - PIM, który myśli i działa." oraz „© 2026 Harmon PIM. Wszystkie prawa zastrzeżone."

## Interactions & Behavior
- **Reveal on scroll**: elementy z `data-reveal="n"` startują `opacity:0; translateY(10px)` i wjeżdżają `opacity .45s ease, transform .45s ease` z opóźnieniem `n × 70 ms`, gdy wejdą w viewport. Przy `prefers-reduced-motion: reduce` — brak animacji, elementy od razu widoczne.
- **Dropdowny nawigacji**: hover (mouseenter/mouseleave) + klik, Escape zamyka wszystko.
- **Menu mobilne**: breakpoint `max-width: 1120px`, przełączane hamburgerem, zamyka się po kliknięciu pozycji.
- **Suwak porównania (landing)**: przeciąganie myszą/dotykiem oraz strzałki klawiatury; pozycja jako `--pos` w `%`.
- **Accordiony FAQ**: pojedynczy otwarty panel (stan `faq`), pierwszy otwarty domyślnie.
- **Filtry katalogu systemów (Integracje)**: chipy kategorii, stan `kat`, domyślnie „wszystkie".
- **Modal demo**: `openModal` z dowolnego CTA, zamykanie klikiem w tło / Escape / przyciskiem, blokada scrolla body, po submit — ekran potwierdzenia (prototyp nie wysyła danych; docelowo podpiąć endpoint/CRM).
- **Smooth scroll** dla kotwic (`html { scroll-behavior: smooth }`), sekcje mają `scroll-margin-top: 70px` (karty docs: 132 px).
- **Responsywność**: wszystkie siatki to `repeat(auto-fit, minmax(…, 1fr))` lub `flex-wrap` z `flex: 1 1 260–330px`; typografia skaluje się przez `clamp()`.

## State Management
Na stronę (prototyp trzyma to w klasie logiki):
- `prod: boolean` — dropdown Produkt
- `zas: boolean` — dropdown Zasoby
- `menu: boolean`, `mobile: boolean` — menu mobilne i breakpoint
- `modal: boolean`, `sent: boolean` — modal demo i stan wysyłki
- `faq: number` — otwarty panel FAQ (landing, cennik)
- `kat: string` — filtr katalogu systemów (Integracje)
- `mobMode`, `undo`, `typeIdx` — stany widżetów na landingu (suwak, karty typów)
Brak fetchowania danych — treści statyczne. Do podpięcia w implementacji: wysyłka formularza demo i formularza kontaktowego.

## Design Tokens

**Kolory**
| Rola | Hex |
|---|---|
| Pomarańcz marki (CTA) | `#ff4f00` |
| Pomarańcz hover | `#e64700` |
| Pomarańcz tekstowy / etykiety | `#bd3a08` |
| Pomarańcz jasny (na granacie) | `#ff8a4c`, `#ff6d33` |
| Granat główny | `#0e1830` |
| Granat rozjaśniony (radial) | `#1d2c47` |
| Granat tekstowy / nagłówki | `#16233f` |
| Tekst podstawowy | `#2f405a` |
| Tekst pomocniczy | `#55647f`, `#5b6b87` |
| Tekst wyciszony | `#8a93a6`, `#9fadc2` |
| Tło jasne | `#f6f8fb` |
| Obramowania | `#e3e9f1`, `#dde5ef`, `#eef2f7` |
| Brzoskwinia (gradient sekcji) | `#ffdcc9` → `#fff1ec` |
| Obramowanie na brzoskwini | `#f2c9b5`, hover `#ffb894` |
| Biel | `#ffffff` |

**Typografia**
- `Inter` 300/400/500/600/700/800 — tekst i nagłówki; body `letter-spacing:-0.011em`, `font-feature-settings:"ss01","cv11"`.
- `Manrope` 700/800 — wyłącznie logotyp i plakietka PIM.
- `JetBrains Mono` 400/500/600/700 — etykiety sekcji, okruszki, ceny, liczniki (uppercase, `letter-spacing:.06–.16em`).
- Skala: H1 `clamp(32px,4.2vw,54px)`/800/`-0.04em`/1.04 · H2 `clamp(27px,3.6vw,44px)`/800/`-0.035em`/1.08 · H3 19–20 px/600–700/`-0.02em` · lead 19 px/1.55 · body 15–17.5 px/1.6–1.7 · mono-label 10.5–12 px.
- `text-wrap: balance` na nagłówkach, `text-wrap: pretty` na akapitach.

**Spacing / kształty**
- Padding sekcji: 96–144 px w pionie, 24 px w poziomie; kontener 1200 px.
- Radius: 999 px (chipy), 20–22 px (karty), 18 px (karty cennika), 14 px (panele, pola), 12 px (przyciski), 11 px (ikony), `clamp(44px,8vw,130px)` (narożniki sekcji).
- Gapy: 10 / 14 / 18 / 20 / 44 px.
- Cienie: `0 1px 0 rgba(20,32,58,.04), 0 1px 2px rgba(20,32,58,.06)` (karty), `0 20px 46px -20px rgba(20,32,58,.14)` (uniesione), `0 24px 48px -18px rgba(20,32,58,.22)` (dropdown), `0 30px 70px -24px rgba(20,32,58,.3)` (zrzuty w hero).
- Focus: `box-shadow: 0 0 0 4px rgba(255,79,0,.18)` (CTA) lub `rgba(20,32,58,.08)` (neutralne).
- Tranzycje: `.15s ease` dla tła/transformacji, `.45s ease` dla revealu.

## Assets
Wszystko w `assets/` (PNG, zrzuty aplikacji i zdjęcia produktowe używane jako placeholdery):
`harmon-workspace.png`, `harmon-dashboard.png`, `harmon-agent-panel.png`, `harmon-agent-chat.png`, `harmon-bulk-action.png`, `harmon-dam-grid.png`, `produkt-agd.png`, `produkt-meble.png`, `produkt-moda.png`, `produkt-narzedzia.png`.
Logotypy systemów w katalogu integracji ładowane są zdalnie z `https://www.google.com/s2/favicons?sz=128&domain=…` — w produkcji zastąpić własnymi plikami logo.
Ikony są inline'owanymi SVG (styl Lucide, `stroke-width` 1.75–2.4, `stroke-linecap: round`). Logo to inline SVG — do odtworzenia w kodzie, nie plik.
`image-slot.js` to narzędzie prototypu (drag-and-drop podmiana obrazka w makiecie Web to Print) — **w implementacji zastąpić zwykłym `<img>`**.

## Files
- `Harmon PIM - Landing.dc.html` — strona główna
- `Produkt.dc.html` — przegląd produktu
- `Funkcje.dc.html` — funkcje
- `Integracje.dc.html` — integracje
- `Wdrozenie.dc.html` — wdrożenie PIM
- `Cennik.dc.html` — cennik
- `Zasoby.dc.html` — docs hub (zamarkowany, treść do uzupełnienia)
- `Kontakt.dc.html` — kontakt
- `Formularz-demo.dc.html` — modal formularza demo (importowany przez pozostałe strony)
- `support.js` — runtime prototypu (nie przenosić do produkcji)
- `image-slot.js` — narzędzie prototypu (nie przenosić do produkcji)
- `assets/` — obrazy
- `screens/` — zrzuty ekranu każdej strony (kolejne kadry przewijania: `01-…` = góra strony), plus `modal-demo.png`; renderowane przy szerokości 1440 px

## Otwarte punkty
- Sekcja **Zasoby** jest zamarkowana: 4 karty prowadzą do `#`, docelowo do osobnego serwisu dokumentacji (wzorzec docs.n8n.io). Wyszukiwarka w hero to makieta.
- Formularze (demo i kontakt) nie mają backendu — do podpięcia wysyłka i walidacja serwerowa.
- Strony prawne (regulamin, polityka prywatności) nie istnieją i zostały usunięte z footera — dodać, gdy powstaną.

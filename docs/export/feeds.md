# Feedy produktowe XML — dokumentacja użytkownika

> Konfigurator API → **Feedy** (`/integrations/api-configurator/feeds`). Backend: kontekst `Export/Feed` (ADR-0023). Utworzone w XMLF-P6-04 (#1939).

## Feed vs eksport ad-hoc

| | **Feed** | **Eksport XML ad-hoc** |
|---|---|---|
| Po co | ciągła syndykacja — Google Merchant / Ceneo / Meta / partner B2B pobiera cyklicznie ten sam URL | jednorazowy zrzut katalogu do pliku XML |
| Struktura | wg szablonu odbiorcy (`<item>` Google, `<o>` Ceneo, własna) | generyczny `<products><product>…` |
| Gdzie | Konfigurator API → Feedy | kreator Eksportów → format **XML** (kafelek w kroku 2) |
| Plik | regenerowany wg harmonogramu, serwowany ze stabilnego URL | pobierany raz po wygenerowaniu |

## Jak utworzyć feed (kreator)

1. **Feedy → „Nowy feed"**. Wybierz szablon: **Google Shopping**, **Ceneo**, **Meta** (struktura stała, gotowe mapowania startowe) albo **Własny** (strukturę budujesz sam). Nazwa i kod uzupełniają się automatycznie.
2. **(Tylko Własny) Struktura dokumentu** — element główny, przestrzenie nazw, element pozycji i jego pola: rodzaj węzła (element / atrybut / powtarzalny / klucz-wartość), format (tekst, HTML, URL, cena, liczba, data), `wymagane`, maks. długość. Walidacja blokuje nielegalne nazwy XML i duplikaty; podgląd struktury po prawej.
3. **Zakres** — jeden locale + jedna waluta na feed (feed dwujęzyczny = dwa feedy), opcjonalnie kanał (nakładka wartości per kanał + drzewo kategorii marketplace) oraz **filtr asortymentu** (ten sam builder co listy produktów) z licznikiem na żywo.
4. **Mapowanie** — każdy slot szablonu ↔ atrybut PIM, wartość statyczna lub szablon tekstowy; transformacje z zamkniętej listy (format ceny `123.45 PLN`, mapowanie enum np. stan magazynowy → `in_stock`, konkatenacja, strip HTML/CDATA). Pasek pokrycia pokazuje `wymagane zmapowane / wszystkie`; podgląd wartości na próbce realnego produktu.
5. **Dostarczanie** — harmonogram regeneracji (presety cron lub własny; puste = tylko ręcznie), gzip, opcjonalny HTTP Basic. Tu też generujesz **token URL**.
6. **Podgląd** — próbka XML (N pierwszych produktów) z badge „well-formed" i raportem zdrowia. **„Zapisz i wygeneruj"** kolejkuje pierwszą regenerację i wraca do huba.

Polityka walidacji per feed: **`skip_invalid`** (produkt bez wymaganych pól wypada z pliku, trafia do logu) albo **`include_with_warning`** (wchodzi mimo braków, log ostrzega). Produkty wariantowe wychodzą „płasko": zamiast mastera emitowane są warianty, każdy z `item_group_id` = SKU mastera (wymóg Google/Ceneo).

## URL feedu i token

- Publiczny URL: `https://<host>/api/feeds/pull/{tenant}/{token}.xml` — **token jest jedynym kredencjałem** (192-bit, base64url). Pokazywany **tylko raz** przy wygenerowaniu — skopiuj od razu.
- **Rotacja**: szczegół feedu → „Rotuj" (lub ponowny mint) — stary URL natychmiast przestaje działać (404), dostajesz nowy token.
- **Unieważnienie**: „Unieważnij" — feed przestaje być publicznie dostępny do czasu wygenerowania nowego tokenu.
- URL serwuje **plik z cache** (ostatnia regeneracja) z ETag/304 i opcjonalnym gzip; pobranie nigdy nie uruchamia generowania. Limit pobrań: 120/h per feed (nadmiar dostaje `429 Retry-After`).
- Weryfikacja z konsoli: `curl -s '<URL>' -o /tmp/feed.xml -w '%{http_code}\n'` → `200`; `xmllint --noout /tmp/feed.xml` → brak błędów.

### Podpięcie do Google Merchant Center
Products → Data sources → **Add product source → Scheduled fetch**: wklej URL feedu, ustaw częstotliwość ≈ harmonogramowi regeneracji. Jeśli włączyłeś HTTP Basic — podaj login/hasło w polach „File requires sign-in". Format: RSS 2.0 z przestrzenią `g:` (szablon Google Shopping).

### Podpięcie do Ceneo
Panel partnera → plik XML: wklej URL feedu (szablon Ceneo: `<offers><o>`; kategorie z drzewa kanału przez kody Ceneo). Ceneo pobiera cyklicznie — ustaw regenerację co najmniej tak częstą jak ich crawl.

## Harmonogram i regeneracja

- Cron per feed (np. `0 3 * * *` = codziennie 3:00) + przycisk **„Regeneruj teraz"** w hubie i na szczególe.
- Regeneracja jest asynchroniczna: pipeline `pobieranie → serializacja → walidacja → zapis` widoczny na żywo na szczególe feedu (SSE). Pauza feedu wstrzymuje harmonogram (URL dalej serwuje ostatni plik).

## Raport zdrowia i monitor

- **Szczegół feedu**: historia regeneracji (czas, trigger ręczny/harmonogram, liczba pozycji, pominięte, rozmiar, status) + **drilldown** z logiem per-produkt („SKU-123 · g:gtin — missing required — skipped").
- **Feedy → Monitor**: widok globalny wszystkich feedów — KPI 24h (regeneracje, pozycje, pominięte, błędy) + historia cross-feed z filtrem statusu.
- Licznik **pobrań 24h** (i sparkline) na hubie i szczególe pokazuje, czy odbiorca faktycznie pulluje URL.

## Ograniczenia MVP

Świadomie poza zakresem — kontekst i plan wznowienia w [`Project Plan/feature-konfigurator-xml-deferred-hooks.md`](../../Project%20Plan/feature-konfigurator-xml-deferred-hooks.md):

- **Import XML** (dostawca → PIM) — tylko eksport/feedy; import wymaga guardu XXE (DEF-XMLF-01).
- **Push na FTP/SFTP/S3** — dostarczanie wyłącznie pull przez URL (DEF-XMLF-02).
- **Dowolne transformacje** (wyrażenia/if-then/lookup) — dostępna zamknięta lista (DEF-XMLF-03).
- **Allegro predef** — osiągalny szablonem Własnym; gotowy szablon to DEF-XMLF-04.
- **Feed nie-produktowy** (kategorie/zasoby) — DEF-XMLF-05.
- **Multi-locale w jednym feedzie** — jeden locale + jedna waluta per feed (feed per rynek).
- **AI-podpowiedzi mapowania** — DEF-XMLF-06 (razem z warstwą agenta).
- **Webhook `feed.regenerated`** — dziś pull + podgląd live w UI (DEF-XMLF-08).

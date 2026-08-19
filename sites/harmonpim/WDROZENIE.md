# Harmon PIM — statyczna strona WWW: build i wdrożenie

## Co tu jest

Prototyp z Claude Design (`*.dc.html`) został przekonwertowany na **czysty, statyczny HTML** —
bez CMS-a, bez frameworka, bez zależności w przeglądarce (poza jednym małym `site.js`).

```
dist/            ← GOTOWA STRONA — zawartość tego katalogu wgrywasz na serwer
tools/build.mjs  ← konwerter prototypu → dist/ (npm run build)
tools/serve.mjs  ← lokalny podgląd z gzipem (npm run serve → http://localhost:8642)
tools/test-interactions.mjs ← 24 testy interakcji (npm test)
static/          ← pliki kopiowane 1:1 do dist/ (fonty, obrazki, robots, .htaccess…)
*.dc.html        ← oryginalny prototyp (źródło prawdy dla treści i wyglądu)
```

## Wdrożenie: Hetzner Cloud (VPS, nginx) — wariant docelowy

Strona stoi na tym samym VPS-ie co aplikacja PIM (`app.harmonpim.pl`). Przy stacku
PHP/Symfony serwerem będzie zwykle **nginx — a nginx ignoruje `.htaccess`**, dlatego
w repo jest gotowy config: **`serwer/nginx-harmonpim.conf`** (bramka hasłowa, ładne
adresy, 404, cache, gzip, mail.php przez php-fpm, przekierowanie www→bez www,
zaślepka blokująca dotfiles i obce pliki .php). Przetestowany na nginx w Dockerze.

1. **DNS** (u rejestratora domeny): rekordy `A` na IP serwera dla `harmonpim.pl`,
   `www.harmonpim.pl` i `app.harmonpim.pl`.
2. **Pliki**: `rsync -avz --delete dist/ root@IP:/var/www/harmonpim/`
3. **nginx**: skopiuj `serwer/nginx-harmonpim.conf` do `/etc/nginx/sites-available/`,
   dowiąż do `sites-enabled/`, sprawdź ścieżkę socketa php-fpm (linia `fastcgi_pass`),
   `nginx -t && systemctl reload nginx`.
4. **HTTPS**: `certbot --nginx -d harmonpim.pl -d www.harmonpim.pl` (aplikacja osobno:
   `-d app.harmonpim.pl`). Po certbocie popraw redirect www na `https://`.
5. **E-mail z formularzy — WAŻNE na Hetznerze**: Hetzner Cloud domyślnie **blokuje
   wychodzące porty 25 i 465**, więc samo PHP `mail()` nie dostarczy poczty. Port **587
   działa** — skonfiguruj `msmtp` jako sendmail systemowy, logując się na skrzynkę
   w Twojej domenie (u dostawcy poczty):
   ```
   apt install msmtp msmtp-mta
   # /etc/msmtprc:
   #   account default
   #   host smtp.twojapoczta.pl   port 587   tls on   tls_starttls on
   #   auth on   user no-reply@harmonpim.pl   password …
   #   from no-reply@harmonpim.pl
   ```
   `mail.php` nie wymaga wtedy żadnych zmian (PHP użyje msmtp jako sendmaila).
   Po wdrożeniu wyślij testowe zgłoszenie z obu formularzy.
6. Strona musi wisieć w **katalogu głównym domeny** (linki rootowe `/assets/…`).
7. Zmiana domeny: stała `SITE` w `tools/build.mjs` + `npm run build` (sitemap.xml
   i robots.txt w `static/` popraw ręcznie — mają pełne adresy).

Plik `.htaccess` w `dist/` zostaje na wypadek serwowania przez Apache (np. tymczasowy
hosting współdzielony) — na nginx jest po prostu ignorowany i nieserwowany.

## Hasło na czas wdrożenia — ZDJĘTE (2026-08-07)

Strona jest publiczna. Bramka hasłowa (`wejscie.html` + reguły w Caddyfile/.htaccess/nginx)
została w całości usunięta z konfiguracji i z serwera. Gdyby kiedyś była znów potrzebna,
jest w historii repo — albo prościej: `basic_auth` w Caddy.

## Adresy

| Strona | URL |
|---|---|
| Strona główna | `/` |
| Przegląd produktu | `/produkt/` |
| Funkcje | `/funkcje/` |
| Integracje | `/integracje/` |
| Wdrożenie PIM | `/wdrozenie/` |
| Cennik | `/cennik/` |
| Kontakt | `/kontakt/` |

Podstrona `/zasoby/` została usunięta (2026-08-07) — menu „Zasoby" tylko rozwija dropdown,
a jego pozycje prowadzą do dokumentacji w aplikacji (`https://app.harmonpim.pl/docs/…`,
mapa linków: stała `DOCS_LINKS` w `tools/build.mjs`).

## SEO — co jest zrobione

- **Meta**: unikalne `<title>` i `description` (z prototypu), `canonical`, `robots`,
  Open Graph (`og:image` 1200×630 per strona) i Twitter Cards, `lang="pl"`, `og:locale pl_PL`.
- **Dane strukturalne (JSON-LD)**: `Organization` + `WebSite` + `FAQPage` (strona główna),
  `BreadcrumbList` (podstrony), `FAQPage` + `SoftwareApplication` z ofertami (cennik).
- **Indeksowanie**: `sitemap.xml`, `robots.txt`, ładne adresy `/produkt/`, strona 404 (noindex).
- **Wydajność** (Lighthouse na buildzie z gzipem): Performance 95, SEO 100,
  Best Practices 100, Accessibility 96. Obrazy WebP z `width/height` (CLS = 0),
  `srcset` dla dużych zrzutów, lazy-loading pod foldem, `fetchpriority=high` dla hero.
- **Fonty self-hosted** (variable WOFF2, subset latin + latin-ext): brak zapytań do Google Fonts —
  szybciej i czyściej pod RODO. Logotypy integracji też serwowane lokalnie.
- Jedyna uwaga a11y: plakietka „PIM" (biały tekst 10 px na pomarańczu) nie spełnia progu
  kontrastu — to świadomy branding z zatwierdzonego designu.

## Formularze (mail.php)

Formularze (modal demo + kontakt) wysyłają zgłoszenia e-mailem przez **`mail.php`** —
jedyny plik wymagający PHP (każdy typowy hosting współdzielony ma PHP w standardzie;
reszta strony jest w pełni statyczna i działa bez niego).

- **Adresy ustawiasz na górze `static/mail.php`** (po zmianie: `npm run build`), albo
  bezpośrednio w `dist/mail.php`:
  `$ADRES_DOCELOWY` — skrzynka, na którą przychodzą zgłoszenia (domyślnie kontakt@harmonpim.pl),
  `$ADRES_NADAWCY` — nadawca techniczny; **musi być w Twojej domenie** (SPF/dostarczalność),
  np. no-reply@harmonpim.pl. `Reply-To` ustawiane jest na adres zgłaszającego.
- Antyspam: honeypot + token dodawany przez JS, walidacja pól i limity długości,
  ochrona przed wstrzyknięciem nagłówków. Bez cookies, bez zewnętrznych serwisów.
- Po wgraniu na serwer **wyślij testowe zgłoszenie** z obu formularzy i sprawdź skrzynkę
  (także spam — jeśli tam wpada, upewnij się, że domena ma rekord SPF hostingu).
- Gdy wysyłka się nie powiedzie, użytkownik widzi komunikat błędu z adresem e-mail awaryjnym
  (tekst w `static/assets/js/site.js`, funkcja `pokazBlad`).

## Czego strona jeszcze NIE robi (zgodnie z prototypem)
- Przycisk **„Zaloguj się"** prowadzi do `#` — czeka na adres aplikacji.
- Brak stron prawnych (polityka prywatności, regulamin) — **formularze realnie zbierają dane
  osobowe, więc polityka prywatności jest potrzebna przed startem** (RODO).

## Praca z kodem

```bash
npm run build   # przebudowa dist/ z prototypu *.dc.html
npm run serve   # podgląd http://localhost:8642
npm test        # 24 testy interakcji (wymaga uruchomionego serve)
```

Treść edytujesz w plikach `*.dc.html` (albo bezpośrednio w `dist/`, jeśli porzucasz prototyp).
Interakcje (menu, dropdowny, modal, FAQ, suwak, animacje) są w `static/assets/js/site.js`.

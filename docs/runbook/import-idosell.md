# Runbook — import produktów z eksportu IdoSell/IAI

Procedura A→Z importu pliku eksportu IdoSell/IAI (np.
`products_export-<sklep>-<data>.csv`) do PIM **bez błędu**, z
auto-utworzeniem brakujących opcji select, pobraniem zdjęć z URL i obsługą
wielu wartości upakowanych w jednej komórce.

## Dlaczego potrzebny jest transform

Eksport IdoSell jest **EAV-pivoted**: wszystkie parametry produktu (Marka,
Kolor, Materiał…) siedzą w dwóch równoległych kolumnach
(`/parameters/parameter@name[pol]` + `/parameters/parameter/value@name[pol]`,
listy rozdzielone `\n`, wyrównane pozycyjnie), a nie jako osobne kolumny.
Importer PIM mapuje **kolumna → atrybut**, więc nie rozczyta tego wprost.
`tools/transform-idosell.py` un-pivotuje parametry na osobne kolumny i mapuje
stałe pola IdoSell na kody atrybutów PIM, odrzucając ~230 kolumn-szumu.

Wsparcie wbudowane w importer:
- **newline jako separator multi-value** (#1719) — `rozmiar`/kategorie z `\n`.
- **auto-create opcji select/multiselect** za flagą profilu/sesji (#1718).
- **pobieranie zdjęć z URL** (Asset, `AssetUrlResolver` tokenizuje po whitespace/`|`).

## Krok 0 — Pre-flight

- Stack up: `docker compose ps` — wszystkie healthy. **Worker musi być `Up`,
  nie `Restarting`** — pobranie zdjęć jest dispatchowane async na transport
  `import`; bez workera obrazy nie wejdą. W razie pętli restartów:
  `docker compose restart worker`.
- Login: `admin@demo.localhost` / `changeme` na `https://pim.localhost`.

## Krok 1 — Model: atrybuty na ObjectType „Produkt"

Importer tworzy *opcje*, ale **nie tworzy atrybutów**. Na ObjectType Product
muszą istnieć atrybuty o kodach = nagłówkach transformu. Utwórz przez
Modelowanie (UI) lub seed/API. Opcje select/multiselect **zostaw puste** —
wypełni je auto-create przy imporcie.

| Kod | Typ | Uwagi |
|---|---|---|
| `name` (`name.pl`) | text | zwykle już jest |
| `short_description` | textarea | |
| `description` | wysiwyg | HTML z IdoSell |
| `cena` | price | envelope `{amount, currency}` (`331.50 PLN`) |
| `rozmiar` | multiselect | `36\|37\|…` — opcje auto-create |
| `zdjecia` | asset | URL-e pobierane z HTTP |
| `marka` | select | |
| `kolor` | select | |
| `material` | select | |
| `material_wkladki` | select | |
| `wysokosc_obcasa` | number | |
| `obwod_cholewki` | number | |
| `wysokosc_cholewki` | number | |
| `symbol_producenta` | text | |

Kategoria `Buty damskie/Botki` (kolumna `__category__`): utwórz ją raz w UI,
albo zaakceptuj że nieznana kategoria = **warning** (wiersz i tak wejdzie,
import się nie wywróci). Importer nie tworzy kategorii ze ścieżki.

## Krok 2 — Transform

```bash
python3 tools/transform-idosell.py \
  "Zrodla/importy/products_export-<sklep>-<data>.csv"
# → Zrodla/importy/products_export-<sklep>-<data>-pim.csv
```

Skrypt loguje na stderr parametry spoza słownika `PARAM_TO_CODE` (slugowane
generycznie) — uzupełnij mapę i dodaj odpowiadający atrybut, jeśli któryś
ma trafić jako osobna kolumna.

## Krok 3 — Kreator importu

`https://pim.localhost/integrations/imports/new`:

1. **Dane** → kafel „Produkty".
2. **Źródło** → upload `…-pim.csv`. Źródło zdjęć: **HTTP**.
3. **Wykrywanie** → UTF-8, przecinek (auto).
4. **Mapowanie** → auto-map trafia po kodzie (nagłówki = kody atrybutów);
   `__category__` zostaje jako reserved target.
5. **Reguły** → tryb **UPSERT**, match key **`sku`**, zaznacz
   **„Twórz brakujące opcje (select/multiselect)"**.
6. **Podgląd** → dry-run: 0 błędów blokujących (nieznane opcje select NIE są
   walidowane na dry-run — sprawdzane przy zapisie, gdzie auto-create je tworzy).
7. **Start** → commit → strona sesji (progress przez Mercure).

## Krok 4 — Weryfikacja (smoke test)

- **Network DevTools**: `parse-preview` / `validate-dry-run` /
  `POST /api/import-sessions` = 200/201.
- **Katalog**: produkt `BD-KOW-ZAMSZ-TAUPE` „Botki kowbojki zamszowe beżowe
  Butdam": `cena = 331,50 PLN`, `rozmiar` = 36–41 (6 opcji),
  select `kolor=Beżowy` / `material` / `marka` **z auto-utworzonymi opcjami**,
  number wysokość/obwód, `zdjecia` = 4 assety pobrane z URL (po przejściu
  kolejki workera).
- **DevTools Console**: brak czerwonych błędów.
- **Raport sesji** (`/report.csv`): 1 wiersz, status created/updated, 0 errors.

## Świadome ograniczenia

- Stock per rozmiar, ceny POS/hurt/marketplace, hotspots, `responsible_entity`
  — pomijane (poza modelem PIM dla tego scope).
- Rozmiary jako multiselect, nie warianty — brak osobnych SKU per rozmiar.
- Auto-create kategorii ze ścieżki poza zakresem — kategoria ręcznie.

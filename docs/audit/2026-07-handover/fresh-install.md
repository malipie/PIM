# Fresh-install / from-zero rebuild — raport (#2125, epik GOLIVE)

**Data:** 2026-07-04 · **Blok:** A (plan `Project Plan/15-plan-testow-przedprodukcyjnych.md`, sekcja A5)
**Cel:** zweryfikować trzy ścieżki „od zera", które prod deploy i zewnętrzny software house wykonują pierwszego dnia, oraz spójność między magazynami (Postgres ↔ Meili ↔ `attributes_indexed`).

Powtarzalny harness: [`scripts/fresh-install-verify.sh`](../../../scripts/fresh-install-verify.sh).

## 1. Migracje od pustej bazy

Zweryfikowane w ramach cold-start testu #2119 (raport [`cold-start.md`](cold-start.md)): entrypoint api na świeżym klonie z pustą bazą uruchomił **122 migracje od zera** bez błędu (`doctrine:migrations:migrate` → `Successfully migrated to Version20260703170000`, 1362 zapytania SQL), po czym `audit:schema:update` + fixtures + reindex. Pełna historia migracji jest wykonywalna od zera. ✅

> Uwaga: kanoniczna ścieżka init to entrypoint auto-seed (`pim:dev:ensure-seeded`), NIE dokumentowane komendy ręczne — te padają na roli `pim_app` (luki L5/L6 z #2119 → #2178). Prod deploy musi używać owner-connection dla DDL. `--with-migrations --force` w skrypcie weryfikującym wymusza pełny drop+migrate przez owner url.

## 2. Reindex Meilisearch od zera (`--purge`)

`pim:search:reindex --purge` czyści indeks i buduje go wyłącznie z Postgresa (source of truth).

| Metryka | Przed | Po |
|---|---|---|
| `objects` docs w Meili | **646** | **211** |
| obiekty w Postgres | 211 | 211 |

**Wynik: 646 → 211 = 211 PG.** ✅ Reindex-od-zera zbieżny. Przed purge indeks miał **435 dokumentów-sierot** — pozostałości po operacjach purgujących wiersze Postgresa bez czyszczenia indeksu (m.in. seed/purge load-testowy #2124, wcześniejsze dane XMLF/APIC). To potwierdza, że **`reindex` bez `--purge` tylko upsertuje i nie usuwa sierot** — prod deploy / odtworzenie indeksu MUSI używać `--purge` dla spójności. Wzorzec udokumentowany w harnessie i w `scripts/load/README.md`.

## 3. Rebuild `attributes_indexed` od zera (`--reconcile`)

`pim:catalog:detect-attributes-drift --reconcile` przepisuje denormalizowaną projekcję `attributes_indexed` z kanonicznych `object_values` realnym `AttributesIndexedRebuilder`.

- Pierwszy przebieg: **297/422 obiektów zreconcyliowanych** (cache przepisany z kanonu).
- Po rebuildzie 1 obiekt ma pusty `attributes_indexed` — obiekt bez wartości globalnych (asset/kategoria bez atrybutów global-scope); benign.
- **Ponowny `detect` (bez reconcile) nie pokazuje 0 dryfu — zostaje 188/422 `mismatched=currency_code`.** To **false-positive detektora na atrybutach typu `select`**, NIE korupcja danych: `object_values.value = {"option_code":"PLN"}` vs `attributes_indexed = {"provenance":"import","option_code":"PLN"}` — wartość merytoryczna (`option_code=PLN`) zgodna po obu stronach; różni się porównanie wzbogaconego envelope'u. Zgłoszone jako **[#2186](../../../issues/2186)**.

Projekcja jest **poprawna** (wartości zgodne z kanonem, `{value}`-envelope'y konwergują — większość obiektów `drifted=0`). Niekonwergujący jest tylko detektor spójności dla kształtu `{option_code}`.

## 4. Wynik zbiorczy (przebieg `fresh-install-verify.sh`, dev stack)

```
meili_documents = 211
postgres_objects = 211
empty_attributes_indexed = 1
PASS: Meili index count matches Postgres (reindex-from-zero consistent).
residual_drift_after_reconcile = 188 drifted (known false-positive on select values — #2186)
```

## Wnioski

1. **Migracje od zera i reindex Meili od zera: zdrowe.** Pełna historia migracji przechodzi na pustej bazie; reindex `--purge` daje idealną zgodność z Postgresem.
2. **Sieroty w Meili to realne ryzyko operacyjne** — każda operacja kasująca wiersze bez `--purge` zostawia dokumenty-widma (tu: 435). Prod/rebuild MUSI purge'ować. Rozważyć okresowy `reindex --purge` w harmonogramie (poza scope tego ticketu).
3. **Rebuild `attributes_indexed` produkuje poprawne dane, ale detektor spójności daje false-positive na `select`** (#2186) — do naprawy zanim „zero drift" stanie się wiarygodną bramką operatora.
4. Harness `scripts/fresh-install-verify.sh` jest powtarzalny (miesięcznie / przed deployem), bezpieczny domyślnie (przebudowuje tylko projekcje), z opcją `--with-migrations --force` dla pełnego drop+migrate.

## Mapa ticketów

[#2186](../../../issues/2186) select-type drift false-positive · (migracje-od-zera / role: [#2178](../../../issues/2178) z #2119) · (messenger_messages fresh-install: [#2177](../../../issues/2177))

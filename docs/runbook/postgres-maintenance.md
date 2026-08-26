# Runbook — konserwacja Postgresa po dużym imporcie

> Dotyczy instancji, które przeszły import katalogu rzędu 100k+ obiektów.
> Świeżo postawiona baza tego nie potrzebuje — autovacuum nadąża od pustej tabeli.

## Kiedy uruchomić

Po wdrożeniu migracji `Version20260826111500` (#3034) **na bazie, która już zawiera
zaimportowany katalog**. Migracja ustawia progi autovacuum na przyszłość, ale nie
odrabia zaległości: `VACUUM` nie działa w transakcji, więc nie może być częścią
migracji Doctrine.

Objaw, który to leczy: widoki modelowania (`/modeling/attributes`,
`/modeling/attribute-groups`) ładują się sekundami zamiast milisekund.

## Diagnoza — czy ta instancja jest dotknięta

```bash
docker exec pim-<kod>-database-1 psql -U pim -d pim_<kod> -c "
SELECT relname,
       n_live_tup,
       n_dead_tup,
       coalesce(last_autovacuum::text, 'NIGDY')  AS last_autovacuum,
       coalesce(last_autoanalyze::text, 'NIGDY') AS last_autoanalyze
FROM pg_stat_user_tables
WHERE relname IN ('object_values', 'objects');"
```

`NIGDY` w `last_autovacuum` przy `n_live_tup` rzędu setek tysięcy = tabela nie ma
mapy widoczności. Każdy Index Only Scan degraduje się wtedy do pełnego odczytu
sterty; na tenancie `harmon` (1,27 mln wierszy) było to **1 347 200 heap fetches**
na jedno zapytanie licznika.

Potwierdzenie w planie zapytania:

```bash
docker exec pim-<kod>-database-1 psql -U pim -d pim_<kod> -c "
EXPLAIN (ANALYZE) SELECT COUNT(DISTINCT object_id) FROM object_values
WHERE attribute_id = (SELECT attribute_id FROM object_values
                      GROUP BY 1 ORDER BY count(*) DESC LIMIT 1);"
```

Szukaj `Heap Fetches:` — wartość > 0 przy Index Only Scan oznacza brak mapy widoczności.

## Zabieg

```bash
docker exec pim-<kod>-database-1 psql -U pim -d pim_<kod> -c "VACUUM (ANALYZE) object_values;"
```

**Bezpieczeństwo**: zwykły `VACUUM` (bez `FULL`) nie bierze blokady wyłącznej —
nie blokuje odczytów ani zapisów, nie przepisuje tabeli, nie zmienia danych.
Można go puścić na działającej produkcji w godzinach pracy.

**Czas**: ~7 s dla 1,27 mln wierszy / 375 MB na CX33.

**Uwaga**: `VACUUM FULL` to co innego — bierze `ACCESS EXCLUSIVE`, przepisuje
całą tabelę i wymaga miejsca na drugą kopię. Nie jest tu potrzebny.

## Weryfikacja

Powtórz `EXPLAIN` z sekcji diagnozy. Zmierzone na tenancie `harmon`
(2026-08-26, 101 909 obiektów / 1 268 172 `object_values`):

| zapytanie | przed | po |
|---|---|---|
| `usage` najgęstszego atrybutu | 6 657 ms | **484 ms** |
| `usage` grupy atrybutów | 772 ms | **546 ms** |
| `Heap Fetches` | 1 347 200 | **0** |

## Dlaczego autovacuum tego nie zrobił sam

Dwie rzeczy nałożyły się na siebie:

1. **Kumulatywne statystyki zniknęły.** `pg_stat_user_tables` pokazywało
   `n_live_tup = 168` przy realnych 1,27 mln wierszy — Postgres porzuca plik
   statystyk przy nieczystym zamknięciu. Autovacuum wylicza progi właśnie z tych
   liczników, więc stracił podstawę do decyzji. Statystyki planera
   (`pg_class.reltuples`) pozostały poprawne, dlatego plany zapytań nie wyglądały
   podejrzanie i problem nie rzucał się w oczy.
2. **Domyślne progi są za luźne dla tej tabeli.** Zarówno
   `autovacuum_vacuum_scale_factor`, jak i osobny próg dla wzrostu insert-only
   `autovacuum_vacuum_insert_scale_factor` mają domyślnie wartość `0.2`.
   Przy 1,3 mln wierszy oznacza to około ćwierć miliona zmian albo nowych
   wierszy, zanim autovacuum ruszy. Migracja z #3034 obniża oba progi vacuum
   per tabela do `0.02`, a próg analyze do `0.01`.

Po zabiegu i po wdrożeniu migracji autovacuum utrzymuje tabelę sam — ten runbook
jest jednorazowy per instancja, chyba że baza znów padnie nieczysto.

# Runbook — `pg_stat_statements` i baseline top queries

Ten runbook służy do porównania kosztu zapytań przed i po wdrożeniu. Każda
instancja klienta ma własny klaster PostgreSQL, więc jej statystyki nie mieszają
się z innymi tenantami.

## Kontrakt uruchomieniowy

PostgreSQL startuje z następującymi ustawieniami we wszystkich topologiach
(dev, tenant, platform):

- `shared_preload_libraries=pg_stat_statements` — wymaga restartu serwera;
- `pg_stat_statements.max=10000` — najwyżej 10 000 fingerprintów; najrzadziej
  wykonywane wpisy są deallokowane po przekroczeniu limitu;
- `pg_stat_statements.track=top` — tylko polecenia najwyższego poziomu;
- `pg_stat_statements.track_utility=off` — DDL i komendy administracyjne nie
  zasłaniają ruchu aplikacji;
- `pg_stat_statements.save=on` — liczniki przeżywają czysty restart.

Bootstrap obrazu wykonuje przy każdym starcie
`CREATE EXTENSION IF NOT EXISTS pg_stat_statements`. To jest celowe: hooki
`docker-entrypoint-initdb.d` działają wyłącznie na świeżym wolumenie, a
istniejące instancje też muszą dostać rozszerzenie. Healthcheck pozostaje
czerwony, dopóki preload, rozszerzenie i widok nie są jednocześnie używalne.

## Bezpieczny snapshot top-20

Domyślny raport zawiera wyłącznie `queryid`, `calls`, `total_exec_time`,
`mean_exec_time`, `rows` i udział w całkowitym czasie. Nie zapisuje tekstu SQL.
Parametry są wprawdzie normalizowane, ale komentarze SQL i zapytania składane
dynamicznie nadal mogą zawierać dane klienta albo sekret.

Lokalnie:

```bash
scripts/postgres-query-stats.sh > /tmp/pim-query-stats-before.md
```

Awaryjnie, gdy nie ma plików compose, można wskazać kontener bezpośrednio:

```bash
scripts/postgres-query-stats.sh --container pim-<kod>-database-1
```

`--database nazwa` wybiera inną bazę w tym samym klastrze, np. izolowaną bazę
testową używaną do lokalnego baseline'u.

Instancja klienta:

```bash
scripts/postgres-query-stats.sh \
  --project pim-<kod> \
  --compose-file docker-compose.tenant.yml \
  --env-file .env.tenant.<kod> \
  > /tmp/pim-<kod>-query-stats-before.md
```

Platforma używa analogicznie `docker-compose.platform.yml` i właściwego pliku
env. Snapshot traktujemy jako dane operacyjne: nie commitujemy go, dopóki nie
zostanie sprawdzony pod kątem danych klienta, mimo że standardowy raport nie
zawiera tekstu SQL.

## Okno pomiarowe przed/po deploy

1. Zapisz bieżący raport jako `before`.
2. Wyzeruj liczniki bez restartu i bez utraty danych biznesowych:

   ```bash
   scripts/postgres-query-stats.sh [opcje instancji] --reset
   ```

3. Wygeneruj reprezentatywny ruch przez ustalone okno (minimum 30 minut albo
   pełny import/eksport będący przedmiotem zmiany).
4. Zapisz `after` tym samym poleceniem bez `--reset`.
5. Porównuj te same `queryid`: calls, total/mean exec time i rows.

`--reset` wywołuje `pg_stat_statements_reset()` dla klastra danej instancji.
Nie restartuje Postgresa, nie kasuje tabel ani danych. Reset traci jednak
historyczny punkt odniesienia, dlatego zawsze najpierw zapisujemy `before`.

## Triage

Do analizy kierujemy fingerprint, gdy spełnia co najmniej jeden warunek:

- przekracza 20% `total_exec_time` w reprezentatywnym oknie;
- `mean_exec_time > 200 ms` przy więcej niż incydentalnej liczbie wywołań;
- liczba `calls` wskazuje na N+1, nawet gdy pojedyncze wykonanie jest szybkie;
- `rows` jest nieproporcjonalne do wyniku widocznego dla użytkownika.

Tekst zapytania odczytujemy dopiero podczas triage na autoryzowanej kopii danych,
po konkretnym `queryid`, bez przeklejania go do issue lub artefaktu CI. Następnie
`EXPLAIN (ANALYZE, BUFFERS)` uruchamiamy na kopii, nie na produkcji klienta.

## Wersjonowany baseline

Pierwszy lokalny pomiar po realnym pakiecie importu strukturalnego znajduje się
w [`docs/operations/baselines/postgres-query-stats-local-2026-08-29.md`](../operations/baselines/postgres-query-stats-local-2026-08-29.md).
Jest punktem kontrolnym działania narzędzia, nie substytutem baseline'u z ruchu
pierwszego klienta.

## Weryfikacja po restarcie

```bash
docker compose exec -T database sh -c '
  psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -Atc "
    SHOW shared_preload_libraries;
    SELECT extname FROM pg_extension WHERE extname = '\''pg_stat_statements'\'';
    SELECT count(*) >= 0 FROM pg_stat_statements;"'
```

Oczekiwane są kolejno: `pg_stat_statements`, `pg_stat_statements`, `t`.

Pełny kontrakt na izolowanym kontenerze (bez restartowania stacka dev):

```bash
scripts/test-postgres-query-stats.sh
```

Test buduje osobny obraz, uruchamia bazę na jednorazowym wolumenie, generuje
trzy identyczne zapytania, restartuje kontener z tym samym wolumenem i wymaga,
aby `stats_reset` oraz licznik `calls=3` przetrwały. Kontener, wolumen i obraz
testowy są usuwane przez `trap` także po błędzie.

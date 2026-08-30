# Runbook — przeniesienie tenanta ze wspólnej bazy na własną instancję

> Jedyna operacja w epiku TNT, w której **da się stracić dane**. Reszta epiku tworzy rzeczy od zera
> albo je zatrzymuje; ta przenosi istniejącego klienta między dwoma światami.
>
> Powstał 2026-08-19 z faktycznego przebiegu na produkcji, nie z teorii.
> Powiązane: ADR-0035 (model izolacji), `tenant-instances.md` (operowanie), `restore.md`.

## Kiedy tego użyć

Gdy klient żyje w bazie współdzielonej (model sprzed ADR-0035), a ma trafić do własnej instancji.
Nie dotyczy klientów **zakładanych** od zera — tych stawia `pim-tenant-new.sh` albo panel.

## Zasada, na której to stoi

Nie wycinamy jednego tenanta ze wspólnej bazy. **Odtwarzamy całą bazę w nowej instancji i kasujemy
w niej wszystkich pozostałych.** Brzmi okrężnie, a jest bezpieczniejsze:

- filtrowany eksport wymagałby przejścia ~40 tabel w kolejności zależności — kodu, którego nikt nie
  napisał i nikt nie przetestował;
- kasowanie tenanta ma już **przetestowaną** implementację (`TenantPurger`, używany przez
  `pim:tenants:purge-deleted`), która zna kolejność `child → parent` i sprząta też magazyn plików;
- UUID tenanta przeżywa odtworzenie, więc ścieżki plików w MinIO i dokumenty w Meilisearch pozostają
  poprawne.

Cena: instancja przez chwilę zawiera dane wszystkich klientów. Dlatego **kasowanie pozostałych jest
krokiem obowiązkowym i weryfikowanym**, zanim instancja zobaczy ruch.

## Przed startem

```bash
HOST=root@167.233.246.116
DC='docker compose --env-file .env.prod -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.site.yml'
KOD=trzeci-tenant           # kod INSTANCJI: tylko [a-z0-9-]
KOD_W_BAZIE=trzeci_tenant   # kod TENANTA w danych — może się różnić, i to jest w porządku
```

> **Podkreślenie nie przejdzie.** Kod instancji trafia do subdomeny, nazwy projektu Compose i nazwy
> bazy, a kontrakt (`TenantSubdomain`, generator środowiska, provisioner) dopuszcza wyłącznie
> `^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$`. Tenant `trzeci_tenant` dostaje instancję `trzeci-tenant`.
> Nazwa widoczna dla użytkownika i kod wewnątrz bazy zostają bez zmian — instancja ma jednego
> tenanta, więc nic tego nie porównuje.

Policz stan wyjściowy — do niego wracasz przy każdej weryfikacji:

```bash
$DC exec -T database psql -U pim -d pim -c \
  "SELECT t.code, t.status, count(o.id) FROM tenants t
     LEFT JOIN objects o ON o.tenant_id=t.id GROUP BY 1,2 ORDER BY 1"
```

## 1. Zrzut wspólnej bazy

```bash
ssh $HOST "cd /opt/pim && $DC exec -T database pgbackrest --stanza=pim --type=full backup"
ssh $HOST "cd /opt/pim && $DC exec -T database pg_dump -U pim -d pim -Fc" > /tmp/wspolna.dump
pg_restore --list /tmp/wspolna.dump | wc -l      # ma być kilkaset pozycji, nie zero
ls -lh /tmp/wspolna.dump
```

**Bez czytelnego `--list` nie idź dalej.** To z tego pliku odtwarzasz dane; jeśli jest uszkodzony,
dowiesz się o tym po skasowaniu źródła, czyli za późno.

## 2. Pusta instancja

```bash
export TMP_PW="$(openssl rand -hex 16)"
bash scripts/pim-tenant-new.sh --code "$KOD" --name "Nazwa Klienta" \
     --owner-email tymczasowy@example.com --owner-password-env TMP_PW \
     --shared-env .env.prod
```

Skrypt kończy się smoke testem (logowanie + `/api/workspaces/current`). **Musi przejść** — inaczej
naprawiasz instancję, zanim wlejesz do niej dane, a nie potem.

Konto właściciela i tenant utworzone tutaj **zostaną zaraz nadpisane** przez odtworzenie. To nie jest
marnotrawstwo: to ta sama, przetestowana ścieżka, którą powstaje każda instancja, więc różnic między
instancją migrowaną a zakładaną od zera nie ma.

## 3. Odtworzenie danych

```bash
dc_t() { docker compose -p "pim-$KOD" --env-file ".env.tenant.$KOD" -f docker-compose.tenant.yml "$@"; }

dc_t stop api worker                       # nikt nie pisze w trakcie odtwarzania
dc_t exec -T database pg_restore -U pim -d "pim_${KOD//-/_}" --clean --if-exists < /tmp/wspolna.dump
```

`pg_restore` wypisze ostrzeżenia o nieistniejących obiektach przy `--clean` — to normalne dla świeżej
bazy. Błędy inne niż `does not exist` czytaj uważnie.

### 3a. Granty roli runtime

```bash
dc_t exec -T database psql -U pim -d "pim_${KOD//-/_}" -c \
  "GRANT USAGE ON SCHEMA public TO pim_app;
   GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO pim_app;
   GRANT USAGE, SELECT ON ALL SEQUENCES IN SCHEMA public TO pim_app;
   ALTER DEFAULT PRIVILEGES IN SCHEMA public
     GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO pim_app;"
```

**Obowiązkowe po każdym `pg_restore`.** Odtworzenie zdejmuje granty, a migracja, która je nadała,
już się nie wykona ponownie. Objaw pominięcia: `permission denied for schema public` przy pierwszym
logowaniu — wygląda jak zepsuta instancja, a jest brakiem jednej komendy.

## 4. Usunięcie pozostałych tenantów

```bash
dc_t start api
dc_t exec -T database psql -U pim -d "pim_${KOD//-/_}" -c \
  "UPDATE tenants SET status='deleted', deleted_at = NOW() - INTERVAL '40 days'
    WHERE code <> '$KOD_W_BAZIE'"
dc_t exec -T api php bin/console pim:tenants:purge-deleted
```

Data wsteczna jest po to, żeby wpisy minęły trzydziestodniowe okno i sweep je faktycznie usunął;
`--retention-days` przyjmuje wyłącznie wartości ≥ 1, więc „zero dni" nie jest opcją.

**Weryfikacja — bez tego ani kroku dalej:**

```bash
dc_t exec -T database psql -U pim -d "pim_${KOD//-/_}" -c \
  "SELECT code, status FROM tenants"                      # DOKŁADNIE jeden wiersz
dc_t exec -T database psql -U pim -d "pim_${KOD//-/_}" -c \
  "SELECT count(*) FROM objects"                          # tyle, ile miał ten klient
```

Dwa wiersze w `tenants` oznaczają, że instancja klienta zawiera dane cudzego klienta. Zatrzymaj się.

## 5. Pliki i wyszukiwarka

```bash
UUID=$(dc_t exec -T database psql -U pim -d "pim_${KOD//-/_}" -tAc "SELECT id FROM tenants LIMIT 1")

# assets/imports/exports: prefiksem jest UUID tenanta, więc ścieżki się nie zmieniają
docker run --rm --network pim_default \
  -e MC_HOST_t="http://$MINIO_ROOT_USER:$MINIO_ROOT_PASSWORD@minio:9000" \
  --entrypoint mc minio/mc:RELEASE.2025-08-13T08-35-41Z@sha256:a7fe349ef4bd8521fb8497f55c6042871b2ae640607cf99d9bede5e9bdf11727 \
  mirror --overwrite "t/pim-assets/$UUID/" "t/$KOD-assets/$UUID/"

dc_t exec -T api php bin/console pim:search:reindex
```

Meilisearch jest **wspólny** dla wszystkich instancji i filtruje po `tenantId` (ADR-0035), więc
reindeks z nowej instancji wystarczy — nie trzeba niczego czyścić po stronie starego stacku.

## 6. Ruch

Dopiero teraz instancja jest gotowa na użytkowników:

```bash
dc_t up -d
curl -sI "https://$KOD.app.harmonpim.pl/api" | head -1
```

Routing działa bez edycji czegokolwiek — blok wildkardowy wyprowadza upstream z nazwy hosta, a
certyfikat wildcard już te nazwy pokrywa (#2908).

## 6a. Rejestr platformy — krok, o którym łatwo zapomnieć

Instancja powstała, ale **panel operatora jej nie widzi**: rejestr żyje w bazie platformy, a migracja
go nie dotknęła. Dopisz wiersz ręcznie:

```sql
INSERT INTO tenants (id, code, name, domain, plan, status, enabled_locales, primary_locale, created_at)
VALUES (gen_random_uuid(), 'trzeci-tenant', 'Trzeci Tenant', 'trzeci-tenant',
        'starter', 'active', '["pl_PL"]', 'pl_PL', NOW())
ON CONFLICT (code) DO NOTHING;
```

`code` i `domain` to kod **instancji** (z myślnikiem), nie kod tenanta w jego własnej bazie.

> **Licznik użytkowników pokaże 0** i tak zostanie. Panel liczy użytkowników w bazie platformy,
> a ci należą do instancji klienta. To ograniczenie modelu, nie usterka: rejestr z definicji nie
> zagląda do cudzych baz.

## 6b. Cron rozliczający zlecenia

Bez niego wznowiony tenant zostaje w panelu jako „zawieszony", bo status wraca dopiero po rozliczeniu
wyniku, a rozliczenie robi się przy podglądzie postępu albo z crona:

```cron
*/2 * * * * root cd /opt/pim && docker compose -p pim-platform --env-file .env.platform \
    -f docker-compose.platform.yml exec -T api php bin/console pim:tenants:reconcile-provisioning >/dev/null 2>&1
```

Plik: `/etc/cron.d/pim-reconcile-provisioning`. Komenda jest idempotentna.

## 7. Kontrola końcowa

| Sprawdzenie | Oczekiwane |
|---|---|
| `SELECT count(*) FROM tenants` w nowej bazie | **1** |
| Liczba obiektów | zgodna z pomiarem sprzed migracji |
| Logowanie właściciela (jego prawdziwym hasłem — przeżyło odtworzenie) | 200 |
| Lista produktów w przeglądarce | dane widoczne |
| Miniatura zdjęcia produktu | ładuje się (dowód, że MinIO zmigrowany) |
| Wyszukiwarka w panelu | zwraca wyniki (dowód reindeksu) |
| `scripts/pim-tenant-isolation-check.sh` wobec innej instancji | kod wyjścia **0** |

## Czego się spodziewać po drodze (zmierzone 2026-08-19)

`pim:tenants:purge-deleted` **wywróci się trzy razy**, zanim przejdzie — to defekt purgera (#2956),
nie migracji. Kolejno zablokują go: zaproszenie z innego tenanta wskazujące usuwanego użytkownika,
tabele agenta i tabele katalogów PDF. Purger zna 46 z 69 tabel z `tenant_id`.

Do czasu naprawy #2956 wyczyść ręcznie **przed** uruchomieniem sweepa: zużyte zaproszenia
(`accepted_at IS NOT NULL`) wskazujące użytkowników obcych tenantów oraz wiersze obcych tenantów
w 27 tabelach spoza listy purgera (agent, integracje, workflow, feedy, katalogi, dashboard,
powiadomienia, audyt) — w kolejności dzieci przed rodzicami.

Czasy z produkcji: instancja od zera **~30 s**, odtworzenie zrzutu 66 MB **48 s**, kopiowanie
153 000 plików w MinIO **~4 min**, reindeks 101 905 obiektów **~2 min**.

## Wycofanie

**Do kroku 6 wspólny stack stoi nietknięty** i nadal obsługuje ruch. Wycofanie to porzucenie nowej
instancji:

```bash
bash scripts/pim-tenant-remove.sh --code "$KOD" --confirm "$KOD" --purge-storage
```

`--purge-storage` jest tu **konieczne**, jeśli zamierzasz próbować ponownie pod tym samym kodem:
repozytorium pgBackRest pamięta kopie poprzedniego klastra i `stanza-create` kończy się błędem 40,
a kopia błędem 51.

Po kroku 6, gdy ruch już poszedł na nową instancję, wycofanie oznacza przywrócenie starego bloku
routingu i ponowne uruchomienie wspólnego stacku — dane zapisane w międzyczasie w nowej instancji
trzeba wtedy przenieść ręcznie. Dlatego kontrola z kroku 7 idzie **przed** przełączeniem ruchu.

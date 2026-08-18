# Runbook — instancje tenantów

Model: **jedna instancja aplikacji na tenanta, własny klaster Postgresa**
(ADR-0035). Wspólne zostają: edge Caddy, bundel SPA, Meilisearch, MinIO,
Gotenberg i monitoring.

Wszystkie polecenia uruchamiaj **z katalogu repozytorium**. Skrypty są trybu
644 — woła się je przez `bash scripts/...`, tak jak robi to CI.

---

## Co jest osobne, a co wspólne

| Warstwa | Per tenant | Wspólne | Skutek awarii |
|---|---|---|---|
| `api`, `worker` | ✅ | | dotyczy jednego klienta |
| `database` + stanza pgBackRest | ✅ | | dotyczy jednego klienta; PITR niezależny |
| `redis`, `mercure` | ✅ | | dotyczy jednego klienta |
| bucket MinIO + użytkownik | ✅ | instancja MinIO | awaria MinIO degraduje **wszystkich** |
| indeks Meilisearch | filtr `tenantId` | instancja + indeks `objects` | awaria Meili degraduje **wszystkich** |
| edge Caddy, bundel SPA, Gotenberg, monitoring | | ✅ | awaria dotyczy **wszystkich** |

Wspólne usługi to świadomy kompromis: osobny MinIO nie dodaje izolacji ponad
politykę dostępu, a osobny indeks Meili wymagałby zmiany kodu (stała
`IndexSettingsTemplate::INDEX_NAME`).

---

## Adresowanie — ustalenie operatora (2026-08-18)

Każda instancja ma **jeden kanoniczny adres**: `<kod>.app.harmonpim.pl`.
Pierwszy klient (`harmon`) nie jest wyjątkiem — jego adresem jest
`harmon.app.harmonpim.pl`.

Stary adres `app.harmonpim.pl` **przekierowuje trwale (301)** na adres
kanoniczny, zachowując ścieżkę i query. Nie jest aliasem: dwa równorzędne
adresy tej samej instancji rozjeżdżają linki w mailach, wpisy SSO i zakładki,
a po kilku klientach nikt nie pamięta, który jest właściwy.

**Warunek włączenia przekierowania:** najpierw `harmon.app.harmonpim.pl` musi
mieć wystawiony certyfikat i potwierdzone logowanie. Włączone wcześniej odcina
jedyną działającą drogę do panelu.

Ryzyko przekierowania dla klientów API sprawdzone przed decyzją: w instancji
`harmon` było **0 tokenów API i 0 kluczy API**, a ruch z tygodnia to wyłącznie
przeglądarki i sonda monitoringu. Gdyby kiedyś doszła integracja wołająca stary
adres — 301 obsługuje ją poprawnie tylko wtedy, gdy klient podąża za
przekierowaniem; przy nowych integracjach podawaj od razu adres kanoniczny.

Dlaczego produkt siedzi pod `app.`, a nie wprost pod `harmonpim.pl`: przestrzeń
nazw domeny firmowej zostaje wolna dla `send` (poczta), `www` i wszystkiego, co
dojdzie później. Nazwa klienta nigdy nie kolidowała z infrastrukturą, a literówka
w adresie trafia w nieistniejącą nazwę zamiast na stronę logowania.

---

## 1. Dodanie tenanta

Przed rozpoczęciem sprawdź:

- [ ] **wolny RAM ≥ 1,5 GB** (`free -m`) — instancja zajmuje 650 MB – 1,1 GB;
- [ ] **DNS** dla `<kod>.app.harmonpim.pl` rozwiązuje się na **wszystkich trzech**
      serwerach nazw (`dns1/2/3.tld.pl`) — Let's Encrypt losuje serwer, a nieudane
      próby liczą się do limitu 5/h/nazwę;
- [ ] **limit Let's Encrypt** nienaruszony (50 certów/tydzień na `harmonpim.pl`).

```bash
export NEW_OWNER_PW='...'                     # min. 12 znaków
bash scripts/pim-tenant-new.sh \
    --code acme \
    --name "Acme Sp. z o.o." \
    --owner-email owner@acme.pl \
    --owner-password-env NEW_OWNER_PW \
    --shared-env .env.prod
```

Hasła **nie podaje się w argumencie** — trafiłoby do historii powłoki i listy
procesów. Skrypt czyta je ze zmiennej, której nazwę podajesz.

Przebieg raportuje linie `STEP|krok|status|szczegół`, a kod wyjścia mówi, co
padło: `2` walidacja · `10` stack · `20` migracje · `30` klucze JWT · `40`
storage · `50` bootstrap · `60` smoke.

**Skrypt kończy się błędem, jeśli smoke nie przejdzie.** Instancja, do której
nikt się nie zaloguje, nie jest instancją gotową.

Po zakończeniu zostają dwa kroki ręczne:

1. **Routing edge Caddy** dla nowego hosta (#2856; zniknie po wprowadzeniu
   routingu dynamicznego, #2908).
2. **Redirect URI SSO** u Google/Microsoft, jeśli tenant korzysta z SSO —
   dostawcy nie mają API, którym dałoby się to zautomatyzować.

Na koniec uruchom dowód izolacji wobec dowolnej istniejącej instancji:

```bash
export ACME_PW='...'
bash scripts/pim-tenant-isolation-check.sh --a acme --b harmon \
    --a-user owner@acme.pl --a-password-env ACME_PW
```

`0` wszystko zaliczone · `1` **izolacja naruszona — nie dokładaj kolejnego
klienta** · `3` część testów pominięta, czyli to nie jest pełny dowód.

---

## 2. Wdrożenie kodu na wszystkie instancje

```bash
bash scripts/pim-deploy-all.sh                    # wszystkie
bash scripts/pim-deploy-all.sh --only acme        # ponowienie po awarii
bash scripts/pim-deploy-all.sh --dry-run          # sam plan
```

Kolejność (zapis tego, czego nauczyła produkcja): zrzut → build → **migracje
z nowego obrazu przed `up -d`** → `up -d` → `cache:clear` **osobno w `api`
i w `worker`** → restart → smoke.

Trzy rzeczy, które łatwo pominąć, a każda kosztowała już czas:

- **Migracja idzie przed `up -d`.** „Ta partia nie ma migracji" bywa nieprawdą,
  jeśli coś zmergowano po poprzednim wdrożeniu.
- **`cache:clear` w workerze to osobne polecenie.** `api` używa
  `/app/var/cache`, worker `/app/var/cache-worker`. Pominięcie oznacza
  konsumenta startującego na kontenerze DI sprzed wdrożenia — objaw bywa
  mylący (dead-letter zamiast prawdziwej przyczyny).
- **Kolejka `failed` po wdrożeniu.** Wiadomości, które padły w oknie
  wdrożenia, po naprawie **wykonają się ponownie**. Skrypt to raportuje;
  przejrzyj `messenger:failed:show` zanim cokolwiek ponowisz.

Przebieg zatrzymuje się na pierwszym błędzie — zła migracja ma zepsuć jednego
klienta, nie wszystkich po kolei.

---

## 3. Odtworzenie danych jednego tenanta

### Wariant A — cofnięcie do punktu w czasie (PITR)

```bash
bash scripts/pim-backup-restore.sh --tenant acme \
    --type time --target "2026-08-18 14:32:00+00"
```

Działa, bo każdy tenant ma **własny klaster i własną stanzę**. Pozostałe
instancje pracują bez przerwy.

### Wariant B — powrót do stanu sprzed wdrożenia

```bash
ls backups/pre-deploy/acme-*.dump          # nazwa zawiera skrót commita
docker compose -p pim-acme --env-file .env.tenant.acme -f docker-compose.tenant.yml \
    exec -T database pg_restore -U pim -d pim_acme --clean --if-exists < <plik>
```

Szybsze niż PITR i wystarczające dla „migracja zepsuła dane".

> **Po każdym `pg_restore` sprawdź uprawnienia roli `pim_app`** na schemacie
> `public`. Odtworzenie potrafi je zdjąć, a migracja, która je nadaje, już się
> nie wykona ponownie. Objaw: `permission denied for schema public`.

---

## 4. Zawieszenie, wznowienie i skasowanie z panelu

Trzy akcje w `/admin/tenants` **dotykają stacku instancji**, a nie tylko wiersza
w rejestrze (#2909). API nigdy nie rozmawia z Dockerem — odkłada zlecenie dla
provisionera i oddaje `202` z jego identyfikatorem, a panel pokazuje postęp.

| Akcja w panelu | Co dzieje się z instancją | Kiedy rejestr się zmienia |
|---|---|---|
| **Zawieś** | `docker compose stop` | **od razu** — odmowa dostępu ma obowiązywać natychmiast |
| **Przywróć** | `start` + odpytywanie o stan zdrowia usługi `api` | **dopiero** gdy instancja się zgłosi; porażka zostawia tenanta zawieszonego |
| **Usuń (soft)** | zrzut końcowy do `backups/final/` + `stop` | od razu (`deleted`), okno odzyskania 30 dni |
| `pim:tenants:purge-deleted` (cron, 03:00 UTC) | `purge` — stack, wolumeny i buckety | wiersz i dane znikają |

Wygaszenie z panelu **nie usuwa ani jednego wolumenu**. Instancja skasowana
przez pomyłkę wraca poleceniem `docker compose -p pim-<kod> --env-file
.env.tenant.<kod> -f docker-compose.tenant.yml start` plus zdjęciem `deleted_at`
w rejestrze. Nieodwracalny jest wyłącznie `purge`.

### Rozliczanie wyników zleceń

Provisioner nie ma sieci, więc nie odeśle wyniku — zostawia go plikiem.
Rejestr dogania stan instancji, gdy panel odpytuje o postęp, ale operator
zamyka przeglądarkę, a instancja wstaje dalej. Pewną ścieżką jest cron na
hoście, obok kopii zapasowych:

```cron
*/2 * * * * cd /opt/pim && docker compose -p pim-platform --env-file .env.platform \
    -f docker-compose.platform.yml exec -T api php bin/console pim:tenants:reconcile-provisioning
```

Komenda jest idempotentna (rozliczone zlecenia są pomijane po znaczniku
`<job>.reconciled` w kolejce) i na instancji **klienta** kończy się bez efektu,
bo tam kolejki nie ma.

> **Tenant utknął w `provisioning` albo `suspended` mimo działającej
> instancji?** Najpierw sprawdź, czy rozliczenie w ogóle biegnie —
> `ls /var/lib/docker/volumes/pim-platform_provisioner_spool/_data/` pokaże
> pliki `*.status.json` bez pary `*.reconciled`. Uruchom komendę ręcznie
> i przeczytaj `docker logs pim-platform-provisioner-1`.

---

## 5. Usunięcie tenanta ręcznie (bez panelu)

```bash
bash scripts/pim-tenant-remove.sh --code acme                      # sam plan
bash scripts/pim-tenant-remove.sh --code acme --confirm acme       # wykonanie
bash scripts/pim-tenant-remove.sh --code acme --confirm acme --purge-storage
```

Zrzut końcowy powstaje **przed** czymkolwiek destrukcyjnym i trafia do
`backups/final/`. Zrzut nieudany albo podejrzanie mały przerywa całość
z niczym nieusuniętym.

Buckety MinIO domyślnie **zostają** — assetów nie da się odtworzyć ze zrzutu
bazy. `--purge-storage` usuwa je razem z użytkownikiem, polityką i katalogiem
kopii w repozytorium pgBackRest.

> **Zakładasz ponownie tenanta o tym samym kodzie?** Musisz był użyć
> `--purge-storage` przy usuwaniu. Inaczej repozytorium pamięta kopie
> poprzedniego klastra i `stanza-create` kończy się błędem 40 („katalog
> niepusty"), a kopia — błędem 51 („is this the correct stanza?"). Ręczne
> sprzątnięcie wymaga `mc rm --recursive --force --versions` — wiadro ma
> włączone wersjonowanie, więc bez `--versions` zostają wersje.

---

## 6. Diagnostyka

| Objaw | Prawdopodobna przyczyna |
|---|---|
| `api` wiecznie `unhealthy` na świeżej instancji | migracje jeszcze nie przeszły — healthcheck uderza w `GET /api`, a listener audytu pisze do nieistniejącej `audit_logs` |
| Logowanie zwraca 500, w logach `bad decrypt` | para kluczy JWT nie pasuje do `JWT_PASSPHRASE` tej instancji; skrypt provisioningu wykrywa to i nadpisuje parę |
| `unable to get address for 'minio-tls'` | baza wypadła z sieci wspólnej — bez niej nie ma kopii zapasowych |
| `archive_mode must be enabled` | instancja wstała bez `archive_mode=on`; bez WAL nie ma PITR |
| Caddy `unhealthy`, ruch działa | osierocone procesy `ssl_client` wyczerpały PID-y; policz zombie zamiast szukać wycieku, `init: true` |
| Alert „instancja nie odpowiada" dla usuniętego klienta | został plik `docker/prometheus/targets/pim-<kod>.yml` |
| Logowanie zwraca 429 mimo restartu redisa | limiter przeżywa restart, bo wolumen redisa ma trwały zapis (RDB). Czyść `redis-cli FLUSHALL` albo `cache:pool:clear --all` w kontenerze api **tej** instancji |
| `TRUNCATE`/`DELETE` „przeszedł", ale nic nie skasował | pod `FORCE ROW LEVEL SECURITY` bez ustawionego `app.current_tenant` polityka odrzuca wiersze **bez błędu**. Brak błędu nie oznacza, że coś się stało — ustaw GUC albo użyj DDL |

---

## Powiązane

- ADR-0035 — model izolacji i odrzucone warianty
- `docs/multi-tenancy.md` — `TenantFilter` i RLS **wewnątrz** instancji
- `docs/runbook/restore.md` — odtwarzanie stacku współdzielonego
- #2912 — przeniesienie tenanta na dedykowany host

# Playbook wdrożeniowy — kolejna zmiana na działającą produkcję

> **Status: żywy dokument, nie regulamin.** To jest pomoc, nie checklista do odhaczenia w 100%.
> Kolejność kroków w sekcji „Przebieg" jest przemyślana i odstępstwo od niej wymaga świadomej decyzji —
> reszta to doświadczenie, które ma oszczędzić czas.
> **Po każdym wdrożeniu, które czegoś nauczyło, dopisz to tutaj.** Dokument bez aktualizacji zestarzeje się
> szybciej niż stack.
>
> Ostatnia aktualizacja: 2026-08-19 (wdrożenie epiku TNT, `0bc63aa0`).
> Pierwsze postawienie hosta: [`../operations/deploy-runbook.md`](../operations/deploy-runbook.md).

## Zanim zaczniesz — co musi być prawdą

- Zmiana jest **na `main`**, z zielonym CI. Wdrażamy stan `main`, nie brancha.
- Wiesz, **co dokładnie** wdrażasz: `git log --oneline <ostatni_wdrożony_sha>..main`.
- Znasz odpowiedź na pytanie „co się stanie, jeśli to trzeba będzie cofnąć".

## Skróty, których używa reszta dokumentu

```bash
HOST=root@167.233.246.116
DC='docker compose --env-file .env.prod -f docker-compose.yml -f docker-compose.prod.yml -f docker-compose.site.yml'
```

Trzy `-f` i `--env-file` to nie ozdoba: bez `--env-file .env.prod` compose przerywa na brakujących zmiennych,
bez `docker-compose.site.yml` nie widzi kontenerów strony (po cutoverze domeny `pim-caddy` obsługuje oba hosty).

## ⚠ Od 2026-08-19 produkcja to N instancji, nie jedna

Migracja rozdzieliła klientów na osobne instancje. **Wdrożenie kodu dotyczy każdej z nich osobno** —
każda ma własną bazę, własny obraz i własne migracje do wykonania.

| Co | Gdzie żyje | Czym się wdraża |
|---|---|---|
| Kod aplikacji dla klientów i panelu | `pim-platform`, `pim-harmon`, `pim-trzeci-tenant`, … | **`scripts/pim-deploy-all.sh`** — po kolei, ze zrzutem przed każdą |
| Bundel SPA | wspólny, montowany do edge | `pnpm --filter @pim/admin build` + `rsync apps/admin/dist` |
| Konfiguracja edge | `docker/caddy/Caddyfile.site` (w repo od #2952) | `scp` + `caddy validate` + `caddy reload` |
| Strona marketingowa | stack `harmon-www` | `sites/harmonpim/serwer/deploy.sh` |
| Usługi wspólne (Meili, MinIO, Prometheus) | stack `pim` | zwykłe `up -d` w tym stacku |

```bash
# całość, platforma pierwsza (trzyma rejestr i zleca provisioning)
bash scripts/pim-deploy-all.sh

# jedna instancja po naprawie
bash scripts/pim-deploy-all.sh --only harmon
```

Orkiestrator dla **każdej** instancji robi: zrzut → build → migracje z nowego obrazu → **stop**
usług aplikacyjnych → `cache:clear` w jednorazowym kontenerze → `up -d --force-recreate` → smoke,
i **przerywa na pierwszym błędzie**, nie ruszając pozostałych. To jest zamierzone: lepiej mieć
trzech klientów na starym kodzie i jednego zepsutego niż wszystkich zepsutych.

Kod wyjścia `70` znaczy „wdrożone, ale smoke ma zastrzeżenia" — niepusta (albo niepoliczalna)
kolejka `failed` lub błędy krytyczne w logach od chwili startu wdrożenia. Kod jest wtedy na
antenie, ale przebieg **nie jest** czysty i nie wolno go tak raportować.

Kroki 1–6 poniżej opisują, co orkiestrator robi pod spodem, i obowiązują, gdy wdrażasz ręcznie
albo diagnozujesz przerwany przebieg.

## Przebieg

### 1. Backup — zanim cokolwiek ruszysz

```bash
ssh $HOST "cd /opt/pim && $DC exec -T database pgbackrest --stanza=pim --type=incr backup"
```

**Zapisz etykietę** z wyjścia (`new backup label = …`). Bez niej „przywróć sprzed wdrożenia" jest życzeniem,
nie planem. Backup inkrementalny trwa ~1,5 s przy 50 MB — nie ma powodu go pomijać.

### 2. Kod na hosta

```bash
SHA=$(git rev-parse --short main)
git archive --format=tar.gz -o /tmp/pim-$SHA.tar.gz main
scp /tmp/pim-$SHA.tar.gz $HOST:/tmp/
ssh $HOST "cd /opt/pim && tar xzf /tmp/pim-$SHA.tar.gz"
```

Rozpakowanie **po wierzchu**, bez czyszczenia katalogu — `.env.prod`, wolumeny i pliki hosta zostają.
Sprawdź, że plik, który miał dojechać, faktycznie dojechał (`ls -la` na czymś nowym z tej paczki).

### 3. Obrazy

```bash
ssh $HOST "cd /opt/pim && $DC build api worker caddy"
```

Produkcja **nie bind-mountuje kodu PHP** — nowy kod trafia do środka wyłącznie przez przebudowany obraz.
To jest źródło pułapki nr 1 poniżej.

`caddy` jest na tej liście od #2908: edge nie stoi już na gotowym `caddy:2-alpine`, tylko na obrazie
budowanym z `docker/caddy/Dockerfile` (Caddy z pluginem DNS Cloudflare — bez niego nie ma certyfikatu
wildcard dla subdomen tenantów). **`up -d` buduje obraz tylko wtedy, gdy go BRAKUJE**, więc zmiana
w tym `Dockerfile` — bump wersji Caddy'ego albo pluginu — przeszłaby bez śladu, a edge zostałby na
starej binarce. Objaw byłby odległy od przyczyny: nie błąd wdrożenia, tylko nieodnowiony certyfikat
kilkadziesiąt dni później, **u wszystkich klientów naraz**.

### 4. Migracje — z NOWEGO obrazu, ale PRZED wypuszczeniem nowego kodu

```bash
ssh $HOST "cd /opt/pim && $DC run --rm --no-deps api php bin/console doctrine:migrations:migrate --no-interaction"
```

To jest **najważniejszy krok całego dokumentu**. `run --rm --no-deps` tworzy jednorazowy kontener z nowego
obrazu (więc widzi nowe migracje), wykonuje je i znika — a działające API dalej chodzi na starym kodzie.
Dzięki temu zachowana jest kolejność **dane przed kodem**: nowy kod nigdy nie startuje na schemacie
sprzed migracji.

**Rób to zawsze**, nawet gdy `git diff` bieżącej partii nie pokazuje migracji — patrz pułapka nr 2.

Po migracji dotykającej uprawnień/ról: policz stan i porównaj z tym sprzed wdrożenia
(sekcja „Co warto zmierzyć").

### 5. Nowy kod na antenie

```bash
ssh $HOST "cd /opt/pim && $DC stop api worker"
ssh $HOST "cd /opt/pim && $DC run --rm --no-deps api    php bin/console cache:clear"
ssh $HOST "cd /opt/pim && $DC run --rm --no-deps worker php bin/console cache:clear"
ssh $HOST "cd /opt/pim && $DC up -d --force-recreate api worker"
```

`cache:clear` w **obu** usługach — katalogi cache bywają osobne (`/app/var/cache/prod` vs
`/app/var/cache-worker/prod`, sprawdzalne przez `debug:container --parameter=kernel.cache_dir`).
Wyczyszczenie tylko w `api` zostawia workera na skompilowanym kontenerze DI sprzed zmiany, co objawia się
później i w zupełnie innym miejscu.

**Kolejność `stop` → `clear` → `up -d --force-recreate` jest istotą tego kroku (#2991).** Wariant
sprzed 2026-08-23 czyścił cache przez `exec` na DZIAŁAJĄCYM procesie i dopiero potem restartował.
`cache:clear` kasuje pliki skompilowanego kontenera DI, a FrankenPHP w trybie worker i konsument
Messengera ładują usługi leniwie — w oknie między czyszczeniem a restartem workery `harmon`
i `trzeci-tenant` zalogowały `CRITICAL Uncaught Error: Failed opening required
/app/var/cache/prod/ContainerNfSihWw/getCatalogIndexFlushSubscriberService.php`. Restart naprawiał proces
chwilę później, ale wiadomość przetwarzana w tym oknie szła do kolejki `failed` i po naprawie wykonywała
się drugi raz. Przerwa w obsłudze ruchu jest ta sama co przy restarcie — nowa jest gwarancja, że nikt nie
czyta skasowanego kontenera. `--force-recreate`, bo kontener zatrzymany w pierwszym poleceniu wstałby
na swoim starym obrazie.

Jeśli frontend się zmienił — przed tym krokiem `pnpm --filter admin build` i `rsync apps/admin/dist`.

### 6. Smoke

Minimum, zawsze:

```bash
for p in /login /forgot-password /dashboard; do
  printf "%-18s %s\n" "$p" "$(curl -s -o /dev/null -w '%{http_code}' https://app.harmonpim.pl$p)"
done
curl -s -o /dev/null -w '%{http_code}\n' https://app.harmonpim.pl/api/products   # 401 bez tokenu
# Uwaga (#2881): odmowa uprawnień loguje się jako `Uncaught PHP Exception
# AccessDeniedHttpException`, więc goły grep po `uncaught` liczył ją jako błąd
# krytyczny. To normalna praca RBAC — odfiltrowana, żeby ten wynik znowu
# odróżniał awarię od odmowy.
ssh $HOST "cd /opt/pim && $DC logs api --since 5m | grep -iE 'critical|emergency|fatal|uncaught' | grep -vic 'AccessDenied'"   # 0
# Same odmowy — informacyjnie, nie jako bramka. Rosnąca liczba po wdrożeniu
# uprawnień znaczy, że komuś zabrano dostęp; zero znaczy, że nikt nie próbował.
ssh $HOST "cd /opt/pim && $DC logs api --since 5m | grep -ic 'AccessDenied'"
ssh $HOST "cd /opt/pim && $DC ps --format '{{.Service}} {{.Status}}'"                                 # healthy
```

Do tego **dowód, że wdrożona zmiana jest żywa** — nie tylko że stack wstał. Zależnie od charakteru zmiany:

- nowa klasa/serwis → `$DC exec -T api php bin/console debug:container <NazwaKlasy>`,
- zmiana w konfiguracji/zasobie → `grep` po pliku **w kontenerze**, nie w repo na hoście,
- nowy endpoint → `debug:router | grep`.

## Czego się nie da zweryfikować i co wtedy zrobić

Zachowanie za cudzym loginem. Jeśli poprawka dotyczy roli, do której konta nie masz hasła — **nie ustawiaj go**.
Zmiana hasła w cudzym koncie to ruszanie danych operatora, a nie test.

Zamiast tego: udowodnij, co się da (kod żywy w kontenerze, dane w bazie nietknięte, zachowanie potwierdzone
na dev na **identycznym** kodzie) i **poproś operatora o kliknięcie**. Napisz wprost, czego nie sprawdziłeś —
„wdrożone i działa" bez tego kliknięcia jest nadużyciem (patrz `CLAUDE.md` § SMOKE TEST RULE).

## Pułapki, które realnie wystąpiły

### 1. „Already at the latest version" w działającym kontenerze (2026-08-13, ADR-0034)

`$DC exec api php bin/console doctrine:migrations:migrate` zgłosił brak nowych migracji, mimo że migracja
istniała. Powód: działający kontener miał **stary obraz**, bez nowego pliku migracji.

Naiwna reakcja — „skoro nie ma migracji, zróbmy `up -d`, a potem zmigrujemy" — uruchamia nowy kod **przed**
przeniesieniem danych. Przy migracji konsolidującej przypisania ról oznaczałoby to odebranie właścicielowi
uprawnień na jego własnej instancji.

**Wniosek:** migracje zawsze przez `run --rm --no-deps` z nowego obrazu, nigdy przez `exec` w działającym.

### 2. „Ta partia nie ma migracji" to za mało (2026-08-13, `346936ca`)

Sprawdziłem `git diff main...HEAD -- apps/api/migrations`, wyszło pusto, ogłosiłem „bez migracji".
W praktyce czekała `Version20260813100000` — merge poszedł **po** poprzednim wdrożeniu.

**Wniosek:** pending migracje wynikają z różnicy `wdrożony_sha..main`, a nie z zawartości ostatniego PR-a.
Krok 4 wykonuj bezwarunkowo; jeśli nie ma czego migrować, kosztuje ~10 s.

### 3. Cache workera żyje własnym życiem — i nie wolno go czyścić pod działającym procesem

Patrz krok 5. Osobne wywołanie `cache:clear` per usługa, zawsze przy zatrzymanych kontenerach
(#2991).

### 4. Limiter logowania blokuje powtarzane smoke'y

Kilka prób logowania pod rząd (curl w pętli, Playwright) wyczerpuje limit 5/IP/15 min i kolejne próby
wyglądają jak zepsuty deploy. Reset: `$DC restart redis api`. Zanim uznasz, że wdrożenie coś zepsuło —
sprawdź, czy to nie własny ruch testowy.

### 5. MinIO potrafi wejść w stan `degraded`

Uploady zaczynają zwracać `SlowDownWrite 503` / `inconsistent drive`. `$DC restart minio` leczy.
Sprawdź storage, zanim zaczniesz obwiniać wdrożony kod.

### 6. Po `pg_restore` rola aplikacji traci granty

Po odtworzeniu bazy `pim_app` dostaje `permission denied for schema public` — migracja nadająca granty
nie uruchamia się ponownie. Trzeba nadać ręcznie. Dotyczy ścieżki odtwarzania, nie zwykłego wdrożenia.

### 7. Front bez zmian w `apps/api` też bywa wdrożeniem (2026-08-17, `d10a769e`)

Trzy zmienione pliki w `apps/admin/src` wystarczą, żeby pominięcie `pnpm --filter @pim/admin build`
+ `rsync apps/admin/dist` zostawiło produkcję na starym bundlu przy w pełni poprawnym backendzie —
objaw byłby identyczny jak „poprawka nie działa".

**Tani dowód, że bundle faktycznie doszedł** — porównaj hash pliku wejściowego po obu stronach:

```bash
curl -s https://app.harmonpim.pl/ | grep -oE 'assets/index-[A-Za-z0-9_-]+\.js' | head -1
grep -oE 'assets/index-[A-Za-z0-9_-]+\.js' apps/admin/dist/index.html | head -1
```

Zgodne = produkcja serwuje to, co zbudowałeś. To sprawdzenie kosztuje sekundę i zamyka całą klasę
„wdrożone, a nie działa".

### 8. Odmowa uprawnień przestała zaśmiecać bramkę logów (2026-08-17, #2881)

Bramka z kroku 6 greppowała m.in. `uncaught`, a **każde 403 loguje się jako**
`Uncaught PHP Exception AccessDeniedHttpException` — więc zdrowa praca RBAC wyglądała na
kilkadziesiąt błędów krytycznych. Do tego `fingers_crossed` nie miał 403 w `excluded_http_codes`,
przez co jedna odmowa zrzucała cały 50-rekordowy bufor.

Jedno i drugie naprawione; bramka odfiltrowuje `AccessDenied` i liczy odmowy osobno. Jeśli kiedyś
znowu zobaczysz „dziesiątki błędów" po zdrowym wdrożeniu — sprawdź najpierw, **czego szuka grep**,
a dopiero potem czego szuka aplikacja.

### 9. Produkcja może czytać plik, którego nie ma w repo (2026-08-19, `0bc63aa0`)

Wdrożenie routingu subdomen przeszło całe: obraz zbudowany, kod żywy w kontenerze, CI zielone —
i **nie zadziałało**, bo edge produkcji montuje `docker/caddy/Caddyfile.site` przez
`docker-compose.site.yml`, a **żadnego z tych plików nie ma w gicie**. Powstały przy cutoverze domeny
i od 12 dni rozjeżdżały się z repozytorium; zmieniony `Caddyfile.prod` nie był przez nikogo czytany.

**Sweep przed wdrożeniem czegokolwiek, co dotyka infrastruktury** — jedno polecenie odpowiada na pytanie
„czy to, co wdrażam, jest tym, co on czyta":

```bash
ssh $HOST "docker inspect pim-caddy-1 --format '{{range .Mounts}}{{.Source}} -> {{.Destination}}{{println}}{{end}}'"
git ls-files docker/caddy/
```

Rozjazd = zatrzymaj się i zdecyduj świadomie, zanim ogłosisz sukces.

**Ten konkretny rozjazd jest już zamknięty** (#2952): `Caddyfile.site` i `docker-compose.site.yml`
są w repozytorium i przy wdrożeniu 2026-08-19 plik w gicie był identyczny co do bajtu z tym, który
serwuje produkcja. Sweep zostaje, bo pilnuje **następnego** takiego pliku, nie tego jednego.

### 9b. `tar`/`scp` z macOS zostawia na hoście pliki `._*` (2026-08-19)

Na produkcji leżało **91 plików `._*`** (AppleDouble — metadane macOS), najstarsze z pierwszych wdrożeń.
Są bezczynne: nie są poprawnym PHP, a autoloading mapuje nazwy klas na nazwy plików, więc nikt ich nie
ładuje. Szkodzą tylko przy porównywaniu drzewa „repo vs produkcja", bo produkcja ma wtedy więcej plików
niż `git ls-files` i różnica wygląda na dryf kodu.

Skąd się biorą: `tar` na macOS domyślnie pakuje rozszerzone atrybuty, a `scp -r` przenosi je razem
z katalogiem. `git archive` ich **nie** tworzy — dlatego wdrożenia wykonywane wg tego playbooka są czyste.

```bash
# przy pakowaniu z Maca (jeśli kiedykolwiek zamiast `git archive`)
COPYFILE_DISABLE=1 tar czf …

# sprzątanie na hoście — wzorzec trafia wyłącznie w śmieci
#   (`git ls-files | grep -E '(^|/)\._'` jest pusty, więc nic prawdziwego się pod niego nie łapie)
ssh $HOST "cd /opt/pim && find . -name '._*' -type f -delete"
```

### 10. Dowodem dla zmiany w edge jest NOWE zachowanie, nie brak regresji (2026-08-19)

Po restarcie Caddy'ego wszystkie istniejące hosty odpowiadały 200 — smoke „strona wstała" był zielony,
a wdrożona funkcja martwa. Jedynym sygnałem była linia z logu:

```bash
ssh $HOST "docker logs pim-caddy-1 2>&1 | grep -oE '\"domains\":\[[^]]*\]' | tail -1"
# przed:  ["www.harmonpim.pl","harmonpim.pl","app.harmonpim.pl","localhost"]
# po:     [...,"*.app.harmonpim.pl"]     ← dopiero to znaczy, że blok został wczytany
```

Reguła ogólna: szukaj potwierdzenia **nowego** zachowania. Brak regresji w starym niczego nie dowodzi,
bo stary blok konfiguracji działa niezależnie od tego, czy nowy w ogóle się wczytał.

### 11. Sekret odrzucony przez narzędzie ≠ zły sekret (2026-08-19)

`caddy-dns/cloudflare` do v0.2.3 walidował **kształt** tokenu po swojej stronie i odrzucał ważny token
w nowym formacie (`cfut_`/`cfat_`) komunikatem „API token appears invalid" — brzmiącym jak literówka.
Zanim zaczniesz podejrzewać sekret, potwierdź go u wystawcy:

```bash
curl -s https://api.cloudflare.com/client/v4/user/tokens/verify -H "Authorization: Bearer $CF_API_TOKEN"
```

`"status":"active"` przy jednoczesnym „invalid" z narzędzia = niezgodność wersji, nie zły token.

## Co warto zmierzyć przy zmianach w uprawnieniach

Przed i po, z tymi samymi zapytaniami — porównanie liczb jest szybsze i pewniejsze niż czytanie logów:

**Policz też coś, co należy do KLIENTA, nie tylko sumy globalne.** Operator pyta „czy moje treści
przeżyją", a suma 101951 obiektów tego nie rozstrzyga — rozstrzyga liczba per tenant, przed i po:

```sql
SELECT t.code, t.status, count(o.id) FROM tenants t
LEFT JOIN objects o ON o.tenant_id = t.id GROUP BY 1, 2 ORDER BY 1;
```

```sql
-- czy ktoś stracił przypisanie
SELECT count(*) FROM user_role_assignments;

-- czy rola nie zgubiła uprawnień — ZAWSZE per (tenant, rola)
SELECT coalesce(t.code, 'GLOBAL') AS tenant, r.code, count(rp.permission_id)
FROM roles r
LEFT JOIN tenants t ON t.id = r.tenant_id
LEFT JOIN role_permissions rp ON rp.role_id = r.id
GROUP BY 1, 2 ORDER BY 1, 2;

-- czy naprawdę są duplikaty grantów (a nie po prostu drugi tenant)
SELECT count(*) AS wierszy, count(DISTINCT (role_id, permission_id)) AS unikalnych
FROM role_permissions;
```

**Agreguj per (tenant, rola), nigdy per sam kod roli.** Każdy tenant ma własny komplet ról PRD, więc `GROUP BY r.code` podwaja wszystkie liczby, gdy tylko powstanie drugi tenant — i wygląda to dokładnie jak zduplikowane role, czyli klasa błędu, którą naprawiały #2832 / #2840. **Zdarzyło się** (2026-08-14): `catalog_manager` pokazał 62 zamiast 31, `tenant_owner` 114 zamiast 57, a wdrożenie z nieodwracalną migracją omal nie zostało przerwane. Rozstrzygające były trzy fakty: `role_permissions` miało tyle samo wierszy co unikalnych par (jest tam unikalny PK, więc duplikaty są niemożliwe), `super_admin` nie drgnął (jest globalny), a `tenants` zawierał drugi wpis z wczorajszą datą.

### 12. Wdrożenie pomijało instancję platformową (2026-08-19)

Orkiestrator wykrywał instancje globem `.env.tenant.*`, a instancja platformowa ma własny plik
środowiska i własny plik Compose — więc była **cicho pomijana**. Klienci dostawali nowy kod, panel
operatora i provisioner zostawały na starym, bez błędu i bez śladu w logu.

Naprawione: platforma jedzie pierwsza. Sprawdzenie po wdrożeniu, że nikt nie został z tyłu:

```bash
ssh $HOST "cd /opt/pim && for p in pim-platform pim-harmon pim-trzeci-tenant; do
  printf '%-20s ' \$p
  docker inspect \$p-api-1 --format '{{.Config.Image}} {{.State.StartedAt}}'
done"
```

Czasy startu powinny pochodzić z tego samego wdrożenia.

## Wycofanie

1. **Kod**: poprzedni obraz jest w lokalnym cache Dockera na hoście — `docker images | grep pim-api`.
   `$DC up -d` z przywróconym drzewem kodu i `build` odtwarza poprzedni stan.
2. **Dane**: `pgbackrest --stanza=pim restore --set=<etykieta z kroku 1>`, procedura w
   [`../runbook/restore.md`](../runbook/restore.md). Pamiętaj o grantach dla `pim_app` (pułapka 6).
3. Migracje robimy **expand-contract**: krok rozszerzający zostawia stare struktury nietknięte, `DROP`
   idzie osobnym ticketem po potwierdzeniu na produkcji. Dzięki temu wycofanie kodu nie wymaga wycofania danych.

## Po wdrożeniu

- Dopisz do `agent/current_status.md`: SHA, etykieta backupu, co poszło, co zweryfikowane, czego **nie**.
- Jeśli coś zaskoczyło — dopisz do tego pliku (sekcja „Pułapki") i do `agent/lessons.md`.
- Jeśli zamykasz issue — dowód z **żywego stacka** w komentarzu (`CLAUDE.md` § CLOSED MEANS CLOSED).

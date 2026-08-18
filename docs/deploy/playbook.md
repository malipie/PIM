# Playbook wdrożeniowy — kolejna zmiana na działającą produkcję

> **Status: żywy dokument, nie regulamin.** To jest pomoc, nie checklista do odhaczenia w 100%.
> Kolejność kroków w sekcji „Przebieg" jest przemyślana i odstępstwo od niej wymaga świadomej decyzji —
> reszta to doświadczenie, które ma oszczędzić czas.
> **Po każdym wdrożeniu, które czegoś nauczyło, dopisz to tutaj.** Dokument bez aktualizacji zestarzeje się
> szybciej niż stack.
>
> Ostatnia aktualizacja: 2026-08-17 (wdrożenie `d10a769e`).
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
ssh $HOST "cd /opt/pim && $DC up -d api worker"
ssh $HOST "cd /opt/pim && $DC exec -T api    php bin/console cache:clear"
ssh $HOST "cd /opt/pim && $DC exec -T worker php bin/console cache:clear"
ssh $HOST "cd /opt/pim && $DC restart api worker"
```

`cache:clear` w **obu** kontenerach — mają osobne katalogi cache (`/app/var/cache/prod` vs
`/app/var/cache-worker/prod`, sprawdzalne przez `debug:container --parameter=kernel.cache_dir`).
Wyczyszczenie tylko w `api` zostawia workera na skompilowanym kontenerze DI sprzed zmiany, co objawia się
później i w zupełnie innym miejscu.

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

### 3. Cache workera żyje własnym życiem

Patrz krok 5. Osobne katalogi cache, osobny `cache:clear`.

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

## Co warto zmierzyć przy zmianach w uprawnieniach

Przed i po, z tymi samymi zapytaniami — porównanie liczb jest szybsze i pewniejsze niż czytanie logów:

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

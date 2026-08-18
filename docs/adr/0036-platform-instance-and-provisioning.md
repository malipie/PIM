# ADR-0036 — Instancja platformowa i model orkiestracji provisioningu

- **Status**: Accepted
- **Data**: 2026-08-18
- **Kontekst ticketu**: #2902 (TNT-P4-01), epik TNT faza M4
- **Powiązane**: ADR-0035 (instancja per tenant), AUD-003 (#1575 — `platform_operator`), `docs/runbook/tenant-instances.md`

## Kontekst

ADR-0035 przeniósł każdego tenanta do własnej instancji z własnym klastrem
Postgresa. Powstała przez to sprzeczność, której nie widać, dopóki patrzy się
na pojedynczą instancję:

**Panel operatora nie może dłużej żyć wewnątrz instancji klienta.** Endpoint
`POST /api/admin/tenants` tworzy wiersz w bazie, do której akurat jest
podłączony. Po wdrożeniu ADR-0035 oznaczałoby to założenie **drugiego tenanta
w bazie pierwszego klienta** — czyli dokładnie to, czemu cały epik ma
zapobiegać. Panel stałby się pułapką: wygląda jak narzędzie do zakładania
klientów, a produkuje naruszenie izolacji.

Druga rzecz: intencja biznesowa mówi, że operator zakłada klienta **z panelu**,
wybiera subdomenę, a reszta dzieje się sama. Zakładanie instancji to
uruchamianie kontenerów, więc coś musi mieć dostęp do demona Dockera. Dostęp do
socketu Dockera jest równoważny uprawnieniom roota na hoście — to decyzja
bezpieczeństwa, nie wygody, i musi być zapisana.

### Co już wiemy z realizacji M0–M2

Cztery defekty złapane przy budowie skryptów wielostackowych (#2922, #2929)
kształtują ten ADR bardziej niż jakakolwiek teoria:

- wyeksportowana zmienna środowiskowa wstrzyknęła nazwę bazy tenanta do
  wywołań dotyczących stacku współdzielonego;
- jedno wywołanie `docker compose` bez jawnego projektu odtworzyło kontener
  cudzego stacku;
- kontener jednorazowy z nadpisanym entrypointem ominął konfigurację i
  odtworzył kopię stacku wspólnego do bazy tenanta;
- `if ! funkcja` zwracał status negacji, więc orkiestrator meldował sukces po
  przerwanym wdrożeniu.

Wszystkie cztery są niegroźne w skrypcie uruchamianym świadomie przez
operatora. W usłudze sieciowej z dostępem do Dockera każdy z nich jest
incydentem.

## Decyzja

### 1. Panel operatora żyje w osobnej instancji platformowej

Dedykowana instancja pod `admin.app.harmonpim.pl`: **ten sam obraz**, mały
stack (`api` + `database`, bez `worker`/`meilisearch`/`minio`), własna baza,
wyłącznie użytkownicy z rolą `platform_operator`.

Ten sam obraz jest istotny — brak osobnej gałęzi kodu do utrzymania, a wdrożenie
platformy idzie tym samym orkiestratorem co reszta.

### 2. Panel operatora znika z instancji tenantów

Przełącznik roli instancji (`PIM_INSTANCE_ROLE=platform|tenant`) sprawia, że
w instancji tenanta trasy `/api/admin/tenants*` i break-glass **zwracają 404 na
poziomie routingu**, niezależnie od uprawnień użytkownika.

404, nie 403: brak trasy nie zdradza, że taka funkcja gdziekolwiek istnieje.
Uprawnienie `platform.tenants.manage` zostaje drugą linią obrony, nie jedyną.

### 3. Rejestr tenantów w bazie platformy jest źródłem prawdy dla provisioningu

Każda instancja tenanta ma u siebie dokładnie **jeden** wiersz `tenants` —
lokalny, potrzebny aplikacji. Rejestr platformy zna wszystkie instancje, ich
subdomeny i stan cyklu życia.

Przy rozjeździe autorytatywny jest **rejestr** dla wszystkiego, co dotyczy
istnienia i adresu instancji, a **wiersz lokalny** dla wszystkiego, co dotyczy
działania aplikacji wewnątrz niej (nazwa wyświetlana, języki, plan).

### 4. Docker dotyka wyłącznie osobna usługa `provisioner`

API **nigdy** nie sięga do socketu Dockera. Zleca typowane zadanie; provisioner
je wykonuje. Wymagania wynikające wprost z defektów wymienionych wyżej:

- każde wywołanie do Dockera z **jawnym** projektem i plikiem środowiska; brak
  któregokolwiek = odmowa, nie wartość domyślna;
- parametry przekazywane per wywołanie, **nigdy** przez środowisko procesu;
- wywołania procesów tablicą argumentów, nigdy przez powłokę;
- allowlista projektów (`pim-<kod>` obecny w rejestrze) sprawdzana **przed**
  wykonaniem;
- brak ekspozycji na zewnątrz — provisioner nie przechodzi przez edge;
- każde zlecenie i jego wynik w logu audytu z identyfikatorem operatora.

## Rozważane opcje

### A. Panel w instancji pierwszego klienta (`harmon`) — odrzucone

Zero nowej infrastruktury. Odrzucone, bo wiąże platformę z konkretnym klientem:
jego awaria, migracja albo wypowiedzenie umowy zabierają narzędzie do
zarządzania wszystkimi pozostałymi. Dodatkowo operator platformy musiałby mieć
konto w bazie klienta.

### B. API z dostępem do socketu Dockera — odrzucone

Najprostsze. Odrzucone, bo aplikacja webowa z powierzchnią ataku obejmującą
uwierzytelnianie, upload plików i integracje dostawałaby uprawnienia
równoważne rootowi na hoście. Pojedynczy błąd w warstwie autoryzacji przestaje
być wyciekiem danych, a staje się przejęciem maszyny.

### C. Provisioning wyłącznie z CLI — odrzucone

Bezpieczne i tanie: żaden komponent sieciowy nie dotyka Dockera. Odrzucone jako
sprzeczne z intencją biznesową (operator zakłada klienta z panelu, bez SSH).
Zostaje jako ścieżka awaryjna — skrypty z M1 działają niezależnie od panelu
i są tym, co provisioner wywołuje pod spodem.

## Konsekwencje

**Pozytywne**

- Zakładanie klienta z panelu, bez SSH — realizacja intencji biznesowej.
- Awaria instancji klienta nie zabiera narzędzia do zarządzania pozostałymi.
- Panel operatora nie istnieje w instancjach klientów, więc nie da się przez
  niego założyć tenanta w cudzej bazie.
- Provisioner jest mały i jednozadaniowy, więc jego audyt jest wykonalny —
  inaczej niż audyt całego API.

**Negatywne**

- Nowy komponent z uprawnieniami roota na hoście. Powierzchnia ataku rośnie
  i wymaga własnego threat modelu (#2910).
- Dodatkowa instancja do wdrażania, monitorowania i odtwarzania.
- Rejestr i wiersz lokalny mogą się rozjechać; potrzebna jasna reguła
  rozstrzygania (punkt 3) i procedura naprawcza (#2911).
- Ścieżka CLI i ścieżka panelu muszą pozostać spójne — provisioner wywołuje te
  same skrypty, więc rozjazd oznacza błąd, nie wariant.

**Czego ta decyzja NIE załatwia**

Nie zmniejsza uprawnień, jakie daje dostęp do Dockera — przenosi je do
komponentu, który da się przejrzeć w całości. Ograniczenie samego API Dockera
(np. przez pośrednika zawężającego dostępne operacje) jest rozważane w #2905
i pozostaje otwarte.

## Linki

- ADR-0035 — instancja per tenant
- `Project Plan/feature-tenant-instances-tickets.md` — backlog epiku, faza M4
- #2905 — provisioner, #2910 — threat model, #2911 — runbook przerwanego provisioningu

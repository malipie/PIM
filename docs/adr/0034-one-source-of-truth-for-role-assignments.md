# ADR-0034 — Jedno źródło prawdy dla przypisań ról

- **Status**: Accepted
- **Data**: 2026-08-13
- **Kontekst ticketu**: #2832 (lista użytkowników pokazuje „brak ról" dla konta z zaproszenia)
- **Powiązane**: ADR-013 (pełen RBAC w MVP), PRD-PIM-rbac §3.2, AUD-029 (#1611), #2830, #2831

## Kontekst

Przypisanie roli użytkownikowi zapisywało się w PIM-ie **dwoma niezależnymi torami**:

| Tor | Tabela | Kto pisze |
|---|---|---|
| Legacy M2M (`User::addRole()`, Sprint 0) | `user_roles` | `UserCreateService`, `UserUpdateController`, `BreakGlassController`, `RescueAdminCommand`, `TenantBootstrapCommand`, `AppFixtures`, bazowe klasy testów API |
| RBAC (encja `UserRole`, ze scope'ami per locale/kanał/grupa atrybutów) | `user_role_assignments` | `InvitationService`, `SsoUserResolver`, `UserCreateService` |

Tory widziały się **w jednym jedynym miejscu** — `PermissionResolver` sumował je zapytaniem `UNION`. Wszędzie indziej rozjazd był widoczny:

- `UserListResponseBuilder` czytał `User::getAssignedRoles()`, czyli wyłącznie legacy, więc konto założone z zaproszenia wyświetlało się jako **„brak ról"**, mając w rzeczywistości rolę Catalog Manager z 31 uprawnieniami;
- `User::getRoles()` (role Symfony trafiające do JWT) scalał kolumnę JSON `roles` z legacy M2M, a tabelę RBAC ignorował.

Stan produkcji w chwili pisania ADR pokazywał oba warianty równocześnie: konto właściciela z bootstrapu miało `user_roles`=2 i `user_role_assignments`=0, konto z zaproszenia odwrotnie.

Dodatkowo AUD-029 (#1611) udokumentował, że sam UNION jest źródłem błędu: legacy nie ma kolumn scope, więc jego wiersze rzutowały `'[]'`, a `mergeScope()` czyta pustą tablicę jako „bez ograniczeń". Rola nadana ze scope'em na jeden język miała to ograniczenie po cichu poszerzane przez własny duplikat z drugiej tabeli. Załatano to wykluczeniem duplikatów w zapytaniu — z komentarzem, że to rozwiązanie tymczasowe do czasu konsolidacji.

Co obniża ryzyko zmiany: **żaden kontroler ani voter nie autoryzuje przez `isGranted('ROLE_*')`**. Cała autoryzacja idzie przez kody uprawnień (`#[RequiresPermission]` + votery), więc `User::getRoles()` jest formalnością wymaganą przez Symfony Security, a nie ścieżką decyzyjną.

## Decyzja

**`user_role_assignments` jest jedynym źródłem prawdy o tym, kto ma jaką rolę. `user_roles` przestaje być czytana i zapisywana.**

Zasady, które z tego wynikają:

- **Jedna brama zapisu.** `User::addRole()` / `removeRole()` operują na przypisaniach RBAC. Wywołujący (bootstrap tenanta, fixtures, break-glass, panel użytkowników, testy) nie zmieniają kodu i nie muszą wiedzieć, która tabela jest pod spodem — ale wszystko, co zapiszą, ląduje w jednym miejscu.
- **Odczyt bez rozgałęzień.** `getAssignedRoles()`, `getRoles()`, projekcja ról w API i `PermissionResolver` czytają ten sam tor. Zapytanie resolvera traci gałąź `UNION` po legacy, a wraz z nią obejście z AUD-029 — scope pochodzi wyłącznie z wiersza, który go faktycznie ma.
- **Scope jest częścią przypisania, nie dodatkiem.** Przypisanie bez ograniczeń to jawne puste zakresy na wierszu RBAC, a nie brak wiersza w tabeli ze scope'ami.

## Migracja: expand teraz, contract osobno

Zmiana idzie w dwóch krokach, świadomie rozdzielonych na osobne wdrożenia:

1. **Expand (ten ticket)** — migracja kopiuje `user_roles` → `user_role_assignments` (`INSERT ... SELECT ... WHERE NOT EXISTS`, puste zakresy), kod przechodzi na jeden tor, mapowanie M2M znika z encji. **Tabela `user_roles` zostaje nietknięta** jako siatka bezpieczeństwa: gdyby migracja czegoś nie przeniosła, dane wciąż tam są i da się je odzyskać bez restore'u z backupu.
2. **Contract (osobny ticket)** — `DROP TABLE user_roles` i usunięcie kolumny `roles` JSON, dopiero po potwierdzeniu na produkcji, że nikt nie stracił uprawnień.

Kolejność wdrożenia na produkcji jest częścią decyzji, nie szczegółem operacyjnym: **backup → migracja → porównanie liczby przypisań przed i po → dopiero potem restart API**. Powód jest konkretny: konto właściciela instancji miało role wyłącznie w torze legacy. Wypuszczenie kodu, który czyta już tylko RBAC, przed przeniesieniem danych odebrałoby operatorowi uprawnienia na jego własnej instancji.

## Konsekwencje

**Dobre:**

- Lista użytkowników, JWT i silnik uprawnień mówią to samo — koniec z kontem, które „nie ma ról", a działa.
- Scope per locale/kanał/grupa atrybutów przestaje być rozmywany przez duplikat bez kolumn scope (AUD-029 zamknięte u źródła, nie obejściem w SQL).
- Każde nowe przypisanie ma z definicji miejsce na ograniczenia — nie da się już nadać roli „obok" modelu scope'ów.

**Cena:**

- Encja `User` zna teraz encję `UserRole` (relacja jeden-do-wielu) zamiast prostego M2M do `Role`; odczyt kodu roli prowadzi przez przypisanie.
- Dwa wdrożenia zamiast jednego, a między nimi w bazie stoi martwa tabela. To celowe — patrz wyżej.
- Do czasu kroku contract migracja pozostaje nieodwracalna „w jedną stronę" tylko na poziomie kodu; dane w `user_roles` są zamrożone i przestają być aktualizowane, więc nie wolno ich traktować jako źródła po wdrożeniu.

## Odrzucone alternatywy

- **Naprawić samą projekcję listy** (żeby czytała obie tabele, jak resolver). Najtańsze i najszybsze, ale utrwalałoby dwa tory i AUD-029 zostałby obejściem w SQL na stałe. Operator świadomie wybrał konsolidację.
- **Konsolidacja w drugą stronę** (`user_role_assignments` → `user_roles`). Odpada: legacy nie ma kolumn scope, więc oznaczałoby to utratę ograniczeń per locale/kanał, które PRD §3.2 wymaga.
- **Expand i contract w jednym wdrożeniu.** Kusząco czyste, ale kasuje siatkę bezpieczeństwa dokładnie w momencie, w którym jest najbardziej potrzebna — przy pierwszym uruchomieniu migracji na realnych danych.

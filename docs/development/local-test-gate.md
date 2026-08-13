# Szybka bramka lokalna (`pnpm ci:local`)

> #2844 — informacja zwrotna **przed** pushem zamiast ~50 minut po nim.

## Po co

CI raportuje najwolniejszy shard na końcu, więc regresja w jednym pliku wychodzi na jaw, gdy wszystko inne jest już zielone. To nie jest problem przepustowości (#2843 skrócił ten shard o połowę) tylko **opóźnienia** — a jedyny sposób na skrócenie czekania to go nie zaczynać.

Realny przykład: PR #2841 — push 14:03, `api-catalog` czerwony **14:28**, jeden test. Ten sam kształt powtórzył się przy #2845.

## Jak używać

```bash
pnpm ci:local            # zmiany względem origin/main (domyślnie)
pnpm ci:local --staged   # tylko to, co w indeksie
pnpm ci:local --all      # wszystko, bez mapowania ścieżek
pnpm ci:local --list     # sam plan, bez uruchamiania
```

Zacznij od `--list` — pokazuje, co zostanie uruchomione, w ułamku sekundy.

## Co robi

Mapuje zmienione ścieżki na testy, które je pokrywają:

| zmiana | uruchamiane |
|---|---|
| `src/<Kontekst>/**` | `tests/Api/<Kontekst>`, `tests/Integration/<Kontekst>` |
| votery, `Identity/Contracts`, zasoby API Platform | testy Identity **+ wszystkie testy `*Permission*` / `*Access*` / `*Voter*`** |
| `migrations/**` | `tests/Integration` |
| `tests/**` | zmieniony plik testu |
| `apps/admin/**` | `tsc --noEmit` + `vitest` |

Konwencja kontekstów (`src/<BC>/` ↔ `tests/{Api,Integration}/<BC>`) jest obsłużona regułą ogólną, więc nowy bounded context nie wymaga edycji skryptu.

Reguła uprawnień jest osobna, bo ryzyko jest tam **cross-context**: voter zmieniony w `Identity` psuje test w `Catalog`. Wciągnięcie całego `tests/Api/Catalog` byłoby poprawne i o wiele za wolne (to ten 9-minutowy shard), więc dobierane są testy uprawnień po nazwie.

## Czego NIE robi

- **Playwright** — potrzebuje zaseedowanego stacka, to minuty.
- **Deptrac i pełny PHPStan** — całoprojektowe, a statyczny leg w CI jest i tak najszybszy (~2,5 min).
- **Migracji od zera** — CI stawia bazę migracjami; przy zmianie w `migrations/` skrypt to sygnalizuje, ale nie sprawdza.

Bramka może mylić się **tylko w jedną stronę**: przepuścić coś, co CI złapie. Zielona znaczy „warto pushować", nie „CI przejdzie".

## Ile to trwa

Zależy od zakresu zmiany. Dla PR-a dotykającego voterów i zasobów API: **173 testy, ~3 min 40 s** — wobec ~50 minut do tej samej informacji z CI.

## Wymagania

Działający stack (`pnpm stack:up`) — testy lecą w kontenerze `api`.

Jeśli **każdy** test uwierzytelniony wywala się na `An error occurred while trying to encode the JWT token`, to nie jest regresja kodu: `APP_ENV=test` dzieli keypair z devem, więc `apps/api/.env.test.local` musi mieć to samo `JWT_PASSPHRASE` co `apps/api/.env.dev`. Nieaktualna wartość nie zgłasza się osobnym błędem — po prostu przewraca wszystkie testy naraz.

# ADR-0033 — Długie zadania same rządzą swoimi granicami transakcji

- **Status**: Accepted
- **Data**: 2026-08-11
- **Kontekst ticketu**: #2815 (import nie zostawia śladu w bazie), #2818 (wycofanie asynchroniczne)
- **Powiązane**: ADR-0019 (silnik importu v2), CLAUDE.md §„Memory management — FrankenPHP worker mode", #2813, #2814

## Kontekst

`config/packages/messenger.yaml` kończy stos middleware domyślnej szyny na `doctrine_transaction`. Opakowuje on **cały handler** w jedną transakcję bazodanową. Dla wiadomości liczonej w sekundach to jest dokładnie to, czego się chce: atomowość za darmo, bez rozsypywania `beginTransaction()` po kodzie.

Dla importu to samo założenie okazało się fałszywe. Zmierzone na dev, 1,5 minuty w trakcie importu 40 000 wierszy:

```
import_sessions:  status pending · total_rows NULL · processed_rows 0 · started_at NULL
objects:          0 zacommitowanych
pg_stat_activity: idle in transaction · 00:03:02 · SAVEPOINT DOCTRINE_22
```

Każdy `flush()` per porcja — mechanizm, którym `ImportRunHandler` ogranicza pamięć — stawał się **savepointem** jednej wielkiej transakcji. Konsekwencje:

1. **Nic nie było widoczne z zewnątrz do końca przebiegu.** Lista sesji czyta bazę i pokazywała „oczekuje · — wierszy · 0" przez 20 minut realnej pracy; ekran szczegółów czyta Mercure (który nie dotyka bazy) i pokazywał prawdę. Dwa ekrany przeczyły sobie nawzajem — to jest zgłoszenie #2815.
2. **Awaria kasowała całą pracę.** `ImportRunHandler` zapisuje checkpointy „żeby wznowienie ruszyło od miejsca przerwania" i degraduje sesję do `partial` „żeby zachować wiersze zacommitowane przez wcześniejsze porcje". Ani jedno, ani drugie nie mogło działać: rollback transakcji cofał też checkpoint. Maszyneria wznawiania nie miała czego wznawiać.
3. **Transakcja otwarta 20+ minut** trzyma wersje wierszy i blokuje `vacuum`.

Kod był pisany pod commit per porcja — mówią o tym jego własne komentarze. Blankietowe opakowanie po cichu tę intencję wyłączało.

## Decyzja

**Zadania długie dostają własną szynę bez `doctrine_transaction` i same odpowiadają za swoje granice transakcji.**

`messenger.bus.long_running` ma identyczny stos middleware co domyślna szyna (rebinding tenanta, GUC dla RLS, drain Meilisearch, idempotencja) **minus** `doctrine_transaction`. Trafiają na nią: `ImportRunMessage` (#2815) i `ImportRollbackMessage` (#2818).

Zasady, które z tego wynikają:

- **Szynę wybiera miejsce dispatchu**, nie handler — envelope niesie `BusNameStamp`, a worker po nim routuje. Wiązanie idzie w `config/services.yaml` przy kontrolerze.
- **Handlera NIE przypinamy** przez `#[AsMessageHandler(bus: …)]`. Obie szyny mają `allow_no_handlers: true`, więc dispatch na niewłaściwą szynę zniknąłby bez śladu. Handler zarejestrowany na obu daje najgorszy przypadek „zadziała ze starą semantyką" zamiast „nie zadziała wcale".
- **Zadanie musi umieć opowiedzieć, gdzie jest.** Wejście na tę szynę zobowiązuje do trwałego postępu (licznik + znacznik czasu ostatniego ruchu) i checkpointu, z którego da się wznowić. Bez tego zamieniamy „niewidoczne, ale atomowe" na „niewidoczne i nieatomowe".

## Konsekwencja dla wycofania importu: atomowość ustępuje ukończeniu

`ImportRollbackService` był jedną transakcją — awaria nie zostawiała nic. #2818 to zmienia świadomie, bo ta gwarancja kosztowała samą operację: wycofanie pełnego katalogu (13 895 obiektów, 51 304 wiersze undo) pracowało ponad 10 minut i padało na zamkniętym połączeniu, a transakcja tej długości blokuje wszystkie dotknięte wiersze. **Siatka bezpieczeństwa, której nie da się rozwinąć, jest warta mniej niż taka, która się rozwija i mówi, dokąd doszła.**

W miejsce atomowości wchodzi:

- **transakcja per porcja** — postęp jest trwały;
- **checkpoint** liczący przejście po undo-logu, więc przerwany przebieg kontynuuje zamiast zaczynać od zera;
- **status `rolling_back`**, który trwa aż ostatni krok się powiedzie. Nigdy `success` (zaprosiłoby drugi pełny replay w połowie zużytego undo-logu) ani `rolled_back` (twierdziłoby, że zrobiono coś, czego nie zrobiono). Stan pośredni jest **jawny**, a nie ukryty — to był warunek postawiony w #2818.

Bezpieczeństwo wznawiania stoi na **idempotencji replaya**: przywrócona wartość niesie znowu swoją sprzed-importową proweniencję, więc druga próba traktuje ją jak edycję ręczną i zostawia w spokoju; obiekty utworzone przez import kasowane są po id, więc powtórka nie ma co kasować.

## Konsekwencje

- Przerwany import zostawia zacommitowane wcześniejsze porcje. To jest zamierzone (`partial`, checkpointy, undo-log z `ON CONFLICT DO NOTHING`), ale każde **nowe** zadanie na tej szynie musi tę semantykę potwierdzić świadomie, a nie odziedziczyć.
- **Testy tego nie wyłapią.** `dama/doctrine-test-bundle` trzyma każdy test w transakcji, więc widoczność commitów bada się wyłącznie live na dev-stacku. Weryfikacja obu zmian to live smoke, nie zielone CI.
- Idempotencja pozostaje nienaruszona: `IdempotencyMiddleware` zapisuje znacznik po powrocie handlera na obu szynach, więc przerwany przebieg jest redostarczany, a nie połykany.
- Otwarte: `AgentRunMessage`, `InboundSyncMessage` i `OutboundSyncMessage` jadą tym samym transportem `import`, ale zostają na domyślnej szynie. Jeśli któreś zacznie chodzić w dziesiątkach minut, ten sam wybór trzeba dla niego powtórzyć — razem z obowiązkiem raportowania postępu.

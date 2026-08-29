# Transporty Messengera w testach — co się naprawdę dzieje z wiadomością

> Powstało po [#3053](https://github.com/malipie/PIM/issues/3053): błąd „naprawiany" dwa razy i wracający,
> bo **żadne środowisko testowe nie potrafiło go odtworzyć**, a jeden test przez długi czas nie sprawdzał tego,
> co deklarował. Ticket porządkujący: [#3056](https://github.com/malipie/PIM/issues/3056).

## Trzy transporty, dwa środowiska, trzy różne zachowania

| Transport | dev / lokalnie (`.env.test`) | CI (`quality-php.yml`) | produkcja |
|---|---|---|---|
| `async` | `sync://` — handler **w locie** | **`in-memory://` — wiadomość tylko do kolejki, handler NIGDY nie startuje** | `doctrine://…queue_name=async` — worker, z opóźnieniem |
| `import` | `sync://` — handler w locie | `sync://` — handler w locie | `doctrine://…` — worker |
| `agent` | `sync://` — handler w locie | `sync://` — handler w locie | `doctrine://…queue_name=agent` — worker |

**Asymetria jest celowa, ale łatwo się na niej przejechać.** CI nadpisuje wyłącznie `MESSENGER_TRANSPORT_DSN`
(czyli `async`) — robią to **wszystkie** joby uruchamiające PHPUnit, łącznie z benchmarkiem. `MESSENGER_IMPORT_TRANSPORT_DSN`
i `MESSENGER_AGENT_TRANSPORT_DSN` zostają takie, jak w `.env.test`.

Konsekwencja, którą trzeba mieć w głowie:

- komentarz „`import` transport is sync:// under test" — **prawdziwy**,
- komentarz „`async` = `sync://` in dev/test" — **fałszywy w CI**.

## Co z tego wynika dla pisania testów

### 1. Skutku na `async` nie zobaczysz bez drenażu

W CI wiadomość leży w kolejce. Test, który dispatchuje na `async` i asertuje skutek, **musi** odegrać to,
co zrobiłby worker:

```php
private function drainAsyncTransport(): void
{
    $transport = self::getContainer()->get('messenger.transport.async');
    if (!$transport instanceof InMemoryTransport) {
        return; // lokalnie sync:// juz to wykonal
    }
    $bus = self::getContainer()->get(MessageBusInterface::class);
    foreach ($transport->getSent() as $envelope) {
        $bus->dispatch($envelope->getMessage(), [new ReceivedStamp('async')]);
    }
}
```

Wzorzec w `ContentValueCommitTest`, `AgentRollbackTest`, `ImportRunHandlerAsyncTest` i kilku innych.

### 2. Groźne są asercje NIEOBECNOŚCI

Asercja **obecności** broni się sama: jeśli przebudowa nie zaszła, test padnie i od razu wiadomo dlaczego.

Asercja **nieobecności** jest trywialnie prawdziwa na stanie, który nigdy nie powstał — i przechodzi
z niewłaściwego powodu.

Dokładnie to zdarzyło się w `AgentRollbackTest`:

```php
// Projection rebuilt from canon (sync:// transport runs it inline).   ← NIEPRAWDA w CI
self::assertStringNotContainsString('"price"', $indexed);
```

Skoro w CI przebudowa po commicie też była tylko kolejkowana, `attributes_indexed` **nigdy nie powstawało**,
a asercja przechodziła na pustym polu. Test nie sprawdzał, że rollback czyści projekcję — sprawdzał, że
projekcja nie powstała. Wyszło to na jaw dopiero wtedy, gdy #3053 zaczęło ją faktycznie zapisywać.

Pilnuje tego bramka `scripts/lint-async-effect-assertions.sh`.

### 3. Wyścigu „zapis synchroniczny + skutek asynchroniczny" nie odtworzysz w testach

Ani `sync://` (natychmiast), ani `in-memory://` (nigdy) nie odwzorowują realnego opóźnienia workera.
**Zielone CI nie jest dowodem, że taki wyścig nie istnieje.** Jedyna weryfikacja to smoke na żywej instancji.

Jeżeli piszesz kod, w którym zapis kanoniczny jest synchroniczny, a jego projekcja/efekt asynchroniczny —
załóż, że czytelnik zobaczy stan sprzed zmiany, i zdecyduj świadomie, czy to akceptowalne.

## Skąd wziąć wartości

- `apps/api/.env.test` — DSN-y w dev i lokalnie,
- `.github/workflows/quality-php.yml` — nadpisania w CI (`MESSENGER_TRANSPORT_DSN`),
- `apps/api/config/packages/messenger.yaml` — routing wiadomość → transport.

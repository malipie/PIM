# PHPUnit lokalnie — suity kernel (Integration / Api)

> GOLIVE #2183 — README „Quality gates" dokumentował pełny `bin/phpunit`, który
> na świeżym klonie sypie setkami błędów (brak test-env) i ginie na limicie
> pamięci. Ten dokument przenosi działający wzorzec z wiedzy plemiennej
> (`agent/lessons.md`) do trackowanych docs.

## TL;DR

| Suita | Lokalnie | Jak |
|---|---|---|
| `--testsuite=unit` (+ `architecture`) | ✅ | `docker compose exec -e APP_ENV=test api php bin/phpunit --testsuite=unit` — działa na świeżym klonie |
| `integration`, `api-*` (kernel) | ⚠️ wymaga setupu | procedura niżej; default = CI (matrix-shard w `quality-php.yml`) |
| pełny `bin/phpunit` jednym strzałem | ❌ | ginie na `memory_limit=256M` (suita architecture); CI dzieli na shardy |

## Dlaczego kernel-suity nie działają „z pudełka"

1. **Test-env JWT** — `APP_ENV=test` wymaga własnych kluczy (`config/jwt/*.pem`,
   gitignored) + `JWT_PASSPHRASE` w `.env.test.local`; bez nich każdy test Api
   pada na `JWTEncodeFailureException`.
2. **Baza testowa** — Foundry `ResetDatabase` robi drop/create schematu, co
   wymaga roli OWNER (`pim`), i koliduje z bazą dev, jeśli wskażesz tę samą.
3. **Pamięć** — pełny run przekracza 256M (`FlushWithoutClearInLoopRuleTest`).

## Procedura: kernel-suity w izolowanym kontenerze

Uruchamia suity na dowolnym drzewie (main checkout albo `git worktree`) w
JEDNORAZOWYM kontenerze — bez ruszania działającego stacka:

```bash
# 0. Jednorazowo: test-env w drzewie roboczym (gitignored, więc świeży klon/worktree ich nie ma)
#    - config/jwt/*.pem — skopiuj z działającego stacka albo wygeneruj:
docker compose exec api php bin/console lexik:jwt:generate-keypair --skip-if-exists
#    - apps/api/.env.test.local z JWT_PASSPHRASE zgodnym z kluczami

# 1. Run (przykład: suita integration; TREE = ścieżka do apps/api testowanego drzewa)
TREE=$PWD/apps/api
docker run --rm --entrypoint sh \
  -v "$TREE":/app -v pim_api_vendor:/app/vendor \
  --network pim_default \
  -e APP_ENV=test -e APP_DEBUG=0 \
  -e "DATABASE_URL=postgresql://pim:<POSTGRES_PASSWORD>@database:5432/pim_localtest?serverVersion=16&charset=utf8" \
  -e "DATABASE_URL_OWNER=postgresql://pim:<POSTGRES_PASSWORD>@database:5432/pim_localtest?serverVersion=16&charset=utf8" \
  -e "MEILI_URL=http://meilisearch:7700" -e "MEILI_KEY=<MEILI_MASTER_KEY>" \
  -e "MERCURE_URL=http://mercure/.well-known/mercure" -e "MERCURE_JWT_SECRET=<MERCURE_JWT_SECRET>" \
  pim-api -c "php bin/console cache:clear --env=test -q && php -d memory_limit=1G vendor/bin/phpunit --testsuite integration"
```

Kluczowe decyzje (każda odkryta boleśnie):

- **`--entrypoint sh`** — domyślny entrypoint obrazu robi doctrine-checki i
  zjada argumenty.
- **Wolumen `pim_api_vendor`** — vendor z obrazu stacka; przy podejrzeniu
  dryfu porównaj `md5 composer.lock` drzewa z tym w kontenerze.
- **DEDYKOWANA nazwa bazy** (`pim_localtest`) na roli owner — ResetDatabase
  drop/create'uje ją swobodnie, zero kolizji z bazą dev i z `pim_test` innej
  sesji.
- **Symulacja env CI**: dołóż `-e MESSENGER_TRANSPORT_DSN=in-memory://`
  (CI pinuje in-memory; lokalny default `sync://` zmienia semantykę testów
  asertujących skutki async).
- Sekrety `<...>` weź z root `.env` — nie hardcoduj ich w komendach
  zapisywanych do plików.

## Odpowiednik dla PHPStan na obcym drzewie

```bash
docker run --rm --entrypoint sh -v "$TREE":/app -v pim_api_vendor:/app/vendor \
  --network pim_default -e APP_ENV=dev pim-api \
  -c "php bin/console cache:warmup --env=dev -q && php vendor/bin/phpstan analyse --memory-limit=512M --no-progress"
```

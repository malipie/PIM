# Deploy runbook — produkcyjny deploy PIM (single-host, docker compose)

> Pierwszy pełny runbook prod (go-live #2138, przygotowanie 2026-08).
> Zakres: od czystego hosta do działającej instancji z danymi demo i smoke-testem.
> Powiązane: `.env.prod.example` (wszystkie zmienne), `docs/runbook/restore.md` (PITR),
> `docs/runbook/disaster-recovery.md`, `docs/operations/secrets-runbook.md`.

## 0. Provisioning hosta (checklist)

- **Wymiarowanie**: minimum 8 GB RAM / 4 vCPU / 80 GB SSD (Postgres + Meilisearch +
  MinIO + 2× worker + FrankenPHP; punkt odniesienia: lokalny stack 50k SKU).
- **OS**: Ubuntu LTS lub Debian stable; `unattended-upgrades` włączone.
- **Docker**: Docker Engine + compose plugin z oficjalnego repo; `docker compose version` ≥ 2.24.
- **Firewall (ufw)**: `allow 22/tcp` (najlepiej ograniczone do IP operatora), `allow 80/tcp`,
  `allow 443/tcp`, `allow 443/udp` (HTTP/3), `deny` reszta. Compose publikuje wyłącznie
  porty Caddy — pozostałe serwisy są tylko w sieci wewnętrznej.
- **SSH**: klucze zamiast haseł (`PasswordAuthentication no`), opcjonalnie fail2ban.
- **DNS**: rekord `A`/`AAAA` dla `DOMAIN` → IP hosta, PRZED pierwszym `up`
  (Let's Encrypt potrzebuje rozwiązywalnej domeny). Dla poczty: SPF/DKIM/DMARC
  na domenie nadawcy (`MAILER_FROM`) — patrz #2139.
- **Restart policy**: serwisy mają `restart: unless-stopped`; po reboot hosta stack
  wstaje sam (sprawdź `docker info | grep 'Live Restore'` — nie wymagane, ale miłe).
- **Dyski**: wolumeny nazwane (postgres/minio/meili/prometheus) żyją w
  `/var/lib/docker/volumes` — monitoruj zapełnienie (Prometheus retention 15d,
  MinIO rośnie z assetami).
- **NIE kopiuj na hosta** katalogów lokalnych operatora: `backups/`, `Zrodla/`,
  dokumentów planistycznych — deploy to `git clone` + `.env.prod`, nic więcej.

## 1. Kod i sekrety

```bash
git clone <repo> pim && cd pim              # docelowy tag: v1.0.0-alpha.1
cp .env.prod.example .env.prod
$EDITOR .env.prod                            # KAŻDY __CHANGE_ME__ → realny sekret
                                             # (komendy generowania w komentarzach)
docker compose --env-file .env.prod \
  -f docker-compose.yml -f docker-compose.prod.yml config -q   # MUSI wyjść 0
```

Fail-loud: brakująca zmienna przerywa `config` z komunikatem wskazującym klucz.
Kompletność template'u pilnuje CI (`scripts/lint-prod-env-example.sh`).

Sekrety aplikacyjne z Symfony Vault (jeśli używane): wstrzyknij
`SYMFONY_DECRYPTION_SECRET` out-of-band — patrz `docs/operations/secrets-runbook.md`.

## 2. Build artefaktów

```bash
# Backend: obraz prod (APP_ENV=prod, composer --no-dev, warmed cache)
docker compose --env-file .env.prod -f docker-compose.yml -f docker-compose.prod.yml build api worker

# Frontend: zbudowany bundle serwowany przez Caddy z ./apps/admin/dist
corepack enable && pnpm install --frozen-lockfile
APP_VERSION=$(git describe --tags) pnpm --filter @pim/admin build

# Docs (opcjonalnie, jeśli pipeline docs nie opublikował jeszcze artefaktu):
# pnpm --filter @pim/docs build   → ./apps/docs/dist (Caddy serwuje pod /docs)
```

## 3. Start stacka + migracje

```bash
docker compose --env-file .env.prod -f docker-compose.yml -f docker-compose.prod.yml up -d
docker compose --env-file .env.prod -f docker-compose.yml -f docker-compose.prod.yml \
  exec -T api php bin/console doctrine:migrations:migrate --no-interaction
```

Migracje seedują: locale + waluty, built-in ObjectTypes, atrybuty systemowe,
kody uprawnień PRD + granty szablonów ról, presety Smart Filter.

## 4. JWT keypair (jednorazowo)

Prod NIGDY nie autogeneruje pary kluczy (gate w entrypoint jest dev/test-only):

```bash
docker compose ... exec -T api php bin/console lexik:jwt:generate-keypair
# passphrase = JWT_PASSPHRASE z .env.prod; klucze lądują w wolumenie api_var? NIE —
# w config/jwt/ wewnątrz kontenera. Trwałość: wygeneruj do wolumenu:
docker compose ... exec -T api sh -c 'ls -la config/jwt/'
```

> Uwaga: `config/jwt/` w obrazie jest efemeryczne — po `up --force-recreate`
> klucze znikną i wszystkie sesje wygasną. Dla trwałości skopiuj parę na hosta
> (`docker compose cp api:/app/config/jwt ./secrets-jwt/`) i przywracaj przy
> odtwarzaniu kontenera, albo dodaj dedykowany named volume na config/jwt.

## 5. Bootstrap tenanta + dane demo

```bash
# Pierwszy tenant + owner (hasło TYLKO przez env, min 12 znaków):
PIM_OWNER_PASSWORD="$(openssl rand -base64 18)" \
docker compose ... exec -T -e PIM_OWNER_PASSWORD api \
  php bin/console pim:tenant:bootstrap --code=demo --name="Demo" \
    --owner-email=owner@twojadomena.pl
# → zanotuj hasło w menedżerze haseł operatora

# Dane demo (1000 produktów elektroniki, fikcyjne marki, obrazy do MinIO):
docker compose ... exec -T api php bin/console pim:demo:seed-electronics --tenant=demo --products=1000

# Indeksy + kompletność:
docker compose ... exec -T api php bin/console pim:search:reindex
docker compose ... exec -T api php bin/console pim:catalog:recalculate-completeness

# Agent AI (opcjonalnie): defaults przepisów treści
docker compose ... exec -T api php bin/console pim:agent:seed-content-defaults
```

## 6. Smoke test go-live (CLOSED-MEANS-CLOSED — z dowodami)

1. `https://DOMAIN/` → login page, konsola przeglądarki czysta, cert Let's Encrypt.
2. Login ownerem → dashboard; lista produktów: 1000 pozycji Z OBRAZKAMI (MinIO OK).
3. Wyszukiwarka (Meilisearch) zwraca wyniki; filtr zaawansowany działa.
4. `POST /api/auth/password-reset` → odpowiedź BEZ `token_dev_only` (APP_ENV=prod)
   i mail dociera na realną skrzynkę (relay + SPF/DKIM), linki w mailu = APP_BASE_URL.
5. Zaproszenie użytkownika → mail + akceptacja przechodzi end-to-end.
6. SSE: edycja produktu w drugiej karcie → live update (Mercure na realnej domenie).
7. `https://DOMAIN/api/metrics` → 404 (blackhole na edge); wewnętrznie
   `docker compose exec prometheus wget -qO- api:80/api/metrics | head` → metryki.
8. Alertmanager: `docker compose exec alertmanager wget -qO- localhost:9093/api/v2/status`
   → skonfigurowany receiver = realny webhook (nie example.invalid); wyślij alert testowy.
9. Backup: `pnpm backup:info` → repo osiągalne; wykonaj `pnpm backup:run` i restore-drill
   wg `docs/runbook/restore.md` zanim wpuścisz realne dane.
10. `docker compose logs api | grep -i 'ensure-seeded'` → BRAK (auto-seed nie działa na prod).
11. Zewnętrzny uptime check (healthchecks.io / UptimeRobot) na `https://DOMAIN/api` —
    blackbox-exporter działa na TYM SAMYM hoście, więc padnięcie hosta widzi tylko
    monitor zewnętrzny.

## 7. Po starcie

- Rotacja sekretów: `docs/operations/secrets-runbook.md` + `credentials-rotation.md`.
- Aktualizacje: `git fetch && git checkout <nowy-tag>` → `build api worker` →
  `up -d` → `doctrine:migrations:migrate` → smoke pkt 1-3. Worker sam przeładuje
  kod w ≤1h (`--time-limit=3600`), ale po zmianach DI zrób `restart worker`.
- Skalowanie workerów: `up -d --scale worker=4`.
- Retencja logów kontenerów: skonfiguruj `log-driver: json-file` z limitami w
  /etc/docker/daemon.json (`max-size=50m`, `max-file=5`) — RODO: logi zawierają e-maile.

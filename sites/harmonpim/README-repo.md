# Strona harmonpim.pl w repozytorium PIM

Do 2026-08-19 strona żyła w osobnym katalogu **poza jakąkolwiek kontrolą wersji** — razem ze swoim
produkcyjnym `Caddyfile`, `Dockerfile` i `docker-compose.yml`. Nie miała historii zmian ani kopii
innej niż dysk operatora. Trafiła tutaj przy okazji #2952, które ujawniło ten sam problem po stronie
konfiguracji edge PIM-a.

## Co gdzie leży

| Ścieżka | Rola |
|---|---|
| `*.dc.html`, `assets/`, `static/`, `screens/` | źródła strony |
| `package.json` → `npm run build` | budowanie do `dist/` |
| `dist/` | to, co faktycznie ląduje na serwerze |
| `serwer/` | `Caddyfile`, `Dockerfile`, `docker-compose.yml`, `deploy.sh` stacku `harmon-www` |

## Jak publikować

**Szybka ścieżka — sama treść** (bez czekania na CI, tak jak dotąd):

```bash
cd sites/harmonpim
npm run build
./serwer/deploy.sh            # rsync dist/ na serwer + reload Caddy'ego
./serwer/deploy.sh --config   # dodatkowo Caddyfile / compose / Dockerfile stacku strony
```

Commit i tak zrób — po to strona tu jest. Szybka ścieżka publikuje, git daje historię.

## Czego CI z tym NIE robi

Strona nie jest aplikacją PIM: nie przechodzi PHPStana, Deptraca, Playwrighta ani bramek RBAC.
Wykluczenia są wpisane w `.github/workflows/*` po ścieżce `sites/**`. Jeśli kiedyś strona dostanie
własne testy, dołóż im osobny job zamiast wpinać ją w istniejące.

## Uwaga o TLS

Ten stack **nie publikuje portów 80/443** — od cutoveru 2026-08-07 trzyma je `pim-caddy`, a ruch
przychodzi przez `reverse_proxy harmon-www-www-1:80` po sieci `pim_default`. Dodanie `ports:`
do `serwer/docker-compose.yml` zdejmie `harmonpim.pl` z sieci błędem „port is already allocated".

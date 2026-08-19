#!/usr/bin/env bash
# Harmon PIM — wgranie strony na serwer produkcyjny.
#
# Użycie:  ./serwer/deploy.sh            (z katalogu głównego repo strony)
#          ./serwer/deploy.sh --config   (dodatkowo odświeża Caddyfile + compose)
#
# Wysyła zawartość dist/ na serwer i przeładowuje Caddy'ego bez restartu
# kontenera. Zmiany w Caddyfile wymagają flagi --config.

set -euo pipefail

SERWER="root@167.233.246.116"
ZDALNY="/srv/harmon-www"
KATALOG="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"

if [[ ! -f "$KATALOG/dist/index.html" ]]; then
	echo "BŁĄD: nie widzę dist/index.html — najpierw 'npm run build'." >&2
	exit 1
fi

echo "→ wysyłam dist/ …"
rsync -az --delete --exclude '.DS_Store' \
	"$KATALOG/dist/" "$SERWER:$ZDALNY/site/"

if [[ "${1:-}" == "--config" ]]; then
	echo "→ wysyłam Caddyfile + docker-compose.yml + Dockerfile …"
	rsync -az "$KATALOG/serwer/Caddyfile" "$KATALOG/serwer/docker-compose.yml" \
		"$KATALOG/serwer/Dockerfile" "$SERWER:$ZDALNY/"
	echo "→ walidacja configu …"
	ssh "$SERWER" "docker exec harmon-www-www-1 frankenphp validate --adapter caddyfile --config /etc/frankenphp/Caddyfile" \
		|| { echo "BŁĄD: Caddyfile nie przechodzi walidacji — nie przeładowuję." >&2; exit 1; }
	echo "→ przebudowa obrazu i restart …"
	ssh "$SERWER" "cd $ZDALNY && docker compose up -d --build --force-recreate"
fi

# Test idzie na publiczny adres przez pim-caddy, bo od cutoveru to on
# terminuje TLS — sprawdzamy realną ścieżkę użytkownika, nie sam kontener.
echo "→ smoke test …"
KOD=$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 https://harmonpim.pl/)
if [[ "$KOD" == "200" ]]; then
	echo "OK — https://harmonpim.pl odpowiada 200."
else
	echo "UWAGA: https://harmonpim.pl zwróciło HTTP $KOD (oczekiwano 200)." >&2
	echo "      502 = pim-caddy nie widzi kontenera; sprawdź sieć pim_default." >&2
	exit 1
fi

#!/usr/bin/env bash
# Bramka: `scripts/pim-tenant-new.sh` podnosi KOMPLET usług tenanta (#3046).
#
# Dlaczego to w ogóle istnieje: skrypt provisioningu jest jedynym kodem, który
# decyduje o kształcie instancji KLIENTA, a jego błąd nie ma żadnej innej
# bramki — instancja wstaje, odpowiada 200 i wygląda zdrowo. Defekt z
# 2026-08-28 był dokładnie tej klasy: skrypt startował pięć z sześciu usług
# zadeklarowanych w `docker-compose.tenant.yml`, więc każdy tenant zakładany z
# panelu miał włączonego agenta i transport `agent` przyjmujący komunikaty,
# ale bez procesu, który tę kolejkę konsumuje. Tury agenta i generowanie
# treści lądowały w `messenger_messages` bez błędu i bez timeoutu.
#
# Asercja jest celowo szersza niż jeden brakujący `agent-worker`: porównuje
# ZBIORY, więc kolejna usługa dopisana do compose'a wywala CI, a nie wychodzi
# u klienta miesiąc później.
#
# Analiza jest statyczna — bez dockera, bez sieci, bez stawiania czegokolwiek.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SKRYPT="${ROOT}/scripts/pim-tenant-new.sh"
COMPOSE="${ROOT}/docker-compose.tenant.yml"

[ -f "$SKRYPT" ]  || { echo "test-tenant-new: brak ${SKRYPT}" >&2; exit 1; }
[ -f "$COMPOSE" ] || { echo "test-tenant-new: brak ${COMPOSE}" >&2; exit 1; }

# Świadomie NIE pomijamy testu przy braku python3 (inaczej niż
# `lint-provisioner.sh`): cicho zielona bramka to ta sama pułapka, którą ten
# test ma łapać. W CI python3 jest zawsze.
command -v python3 >/dev/null 2>&1 \
    || { echo "test-tenant-new: wymagany python3" >&2; exit 1; }

python3 - "$SKRYPT" "$COMPOSE" <<'PY'
import re
import sys

skrypt_path, compose_path = sys.argv[1], sys.argv[2]
skrypt = open(skrypt_path, encoding="utf-8").read()
compose = open(compose_path, encoding="utf-8").read()

# ── Usługi zadeklarowane w compose ──────────────────────────────────────────
# Klucze na dwóch spacjach w bloku `services:`, do pierwszej kolumny bez
# wcięcia (czyli do `networks:` / `volumes:` na końcu pliku).
blok = re.search(r"^services:\n(.*?)(?=^\S)", compose, re.S | re.M)
if blok is None:
    print("BŁĄD: nie znalazłem bloku `services:` w docker-compose.tenant.yml", file=sys.stderr)
    raise SystemExit(1)
zadeklarowane = set(re.findall(r"^  ([A-Za-z0-9_-]+):", blok.group(1), re.M))

# ── Usługi, które skrypt faktycznie podnosi ─────────────────────────────────
# `dc up -d a b c >/dev/null …` — bierzemy tokeny po `-d` aż do pierwszego
# przekierowania, `||`, `&&` albo końca linii. Flagi (`--force-recreate`)
# pomijamy, bo to nie są nazwy usług.
uruchamiane = set()
for linia in re.findall(r"^\s*dc up -d\s+(.*)$", skrypt, re.M):
    ogon = re.split(r"[>|&]", linia, maxsplit=1)[0]
    for token in ogon.split():
        if not token.startswith("-"):
            uruchamiane.add(token)

brakujace = sorted(zadeklarowane - uruchamiane)
nadmiarowe = sorted(uruchamiane - zadeklarowane)

print(f"  zadeklarowane w compose ({len(zadeklarowane)}): {', '.join(sorted(zadeklarowane))}")
print(f"  uruchamiane przez skrypt ({len(uruchamiane)}): {', '.join(sorted(uruchamiane))}")

bledy = 0
if brakujace:
    print(
        "  ✗ usługi z docker-compose.tenant.yml, których pim-tenant-new.sh NIE uruchamia: "
        + ", ".join(brakujace),
        file=sys.stderr,
    )
    print(
        "     Instancja wstanie i będzie odpowiadać, ale ta usługa nie będzie działać u klienta.",
        file=sys.stderr,
    )
    bledy += 1
else:
    print("  ✓ skrypt uruchamia wszystkie usługi zadeklarowane w compose")

if nadmiarowe:
    print(
        "  ✗ skrypt uruchamia usługi, których nie ma w docker-compose.tenant.yml: "
        + ", ".join(nadmiarowe),
        file=sys.stderr,
    )
    print("     Literówka w nazwie usługi albo martwy wpis — `docker compose up` to przemilczy.", file=sys.stderr)
    bledy += 1
else:
    print("  ✓ skrypt nie odwołuje się do nieistniejących usług")

# Regresja wprost pod #3046 — czytelna nazwa w logu CI, gdyby ktoś skrócił
# listę „bo agent i tak jest opcjonalny".
if "agent-worker" not in uruchamiane:
    print("  ✗ regresja #3046: pim-tenant-new.sh nie uruchamia `agent-worker`", file=sys.stderr)
    bledy += 1
else:
    print("  ✓ #3046: `agent-worker` jest uruchamiany przy provisioningu")

raise SystemExit(1 if bledy else 0)
PY

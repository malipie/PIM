#!/usr/bin/env bash
# Bramka: testy walidatora zleceń provisioningu (epik TNT, #2905).
#
# Provisioner jest jedynym komponentem z dostępem do demona Dockera, więc jego
# walidator stoi między żądaniem HTTP a uprawnieniami roota na hoście. Zmiana,
# która go rozluźni — albo rozjedzie listę nazw zastrzeżonych z PHP i skryptem
# — ma tu paść, a nie wyjść na produkcji.
#
# Bez zależności zewnętrznych: sam `unittest` ze standardowej biblioteki.
set -euo pipefail

cd "$(dirname "$0")/.."

if ! command -v python3 >/dev/null 2>&1; then
    echo "SKIP: brak python3 — testy provisionera pominięte." >&2
    exit 0
fi

python3 -m unittest discover -s docker/provisioner -p 'test_*.py' -v 2>&1 | tail -5

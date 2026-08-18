#!/usr/bin/env bash
# TNT-P4-09 (#2910 / ADR-0036) — gniazdo Dockera trzyma DOKŁADNIE jedna usługa.
#
# Montaż `/var/run/docker.sock` jest równoważny uprawnieniom roota na hoście:
# kto może wołać demona, ten może wystartować kontener z podmontowanym `/`.
# Cała separacja z ADR-0036 stoi na tym, że ten dostęp ma wyłącznie
# `provisioner` — usługa mała, bez sieci (`network_mode: none`) i o jednym
# zadaniu — a NIE `api`, które przyjmuje żądania HTTP, uploady plików
# i ruch z integracji.
#
# Ta bramka jest tania i wykrywa dokładnie ten sposób, w jaki ta granica pęka
# w praktyce: ktoś dokłada montaż „na chwilę, do debugowania" albo kopiuje
# blok usługi razem z wolumenami. Przegląd kodu tego nie łapie, bo jedna
# linijka w compose wygląda niegroźnie.
#
# Reguła: w każdym pliku `docker-compose*.yml` montaż `docker.sock` może
# wystąpić wyłącznie w usłudze `provisioner`.
set -eu

ROOT="${ROOT:-$(cd "$(dirname "$0")/.." && pwd)}"
ALLOWED_SERVICE="provisioner"

failures=0

for compose in "$ROOT"/docker-compose*.yml; do
    [ -f "$compose" ] || continue

    # Prosty parser stanu: śledzimy, w której usłudze jesteśmy. Wystarczy,
    # bo pliki tego projektu mają płaską, dwuspacjową listę usług — a użycie
    # `yq` dokładałoby zależność do bramki, która ma być tania.
    service=""
    line_no=0
    while IFS= read -r line; do
        line_no=$((line_no + 1))

        case "$line" in
            "  "[a-zA-Z_]*":"*)
                # Nazwa usługi: dokładnie dwie spacje wcięcia.
                service="${line#  }"
                service="${service%%:*}"
                ;;
        esac

        case "$line" in
            *docker.sock*)
                # Komentarze opisujące ryzyko są w porządku — chodzi o montaże.
                trimmed="${line#"${line%%[![:space:]]*}"}"
                case "$trimmed" in
                    '#'*) continue ;;
                esac

                if [ "$service" != "$ALLOWED_SERVICE" ]; then
                    echo "BŁĄD: $(basename "$compose"):${line_no} — usługa '${service}' montuje gniazdo Dockera." >&2
                    echo "      Dostęp do demona ma mieć wyłącznie '${ALLOWED_SERVICE}' (ADR-0036)." >&2
                    echo "      Linia: ${trimmed}" >&2
                    failures=$((failures + 1))
                fi
                ;;
        esac
    done < "$compose"
done

if [ "$failures" -gt 0 ]; then
    echo "" >&2
    echo "lint-docker-socket: ${failures} naruszen(ia). Gniazdo Dockera = root na hoscie." >&2
    exit 1
fi

echo "lint-docker-socket: gniazdo Dockera montuje wylacznie '${ALLOWED_SERVICE}' — OK."

#!/usr/bin/env bash
# Bramka: asercja NIEOBECNOŚCI na stanie budowanym asynchronicznie musi mieć
# dowód, że ten stan w ogóle powstał (#3056).
#
# Dlaczego to w ogóle istnieje: w CI transport `async` to `in-memory://`
# (patrz docs/testing/messenger-transports.md) — wiadomość trafia do kolejki
# i nikt jej nie konsumuje. Asercja OBECNOŚCI broni się sama: jeśli przebudowa
# nie zaszła, test pada. Asercja NIEOBECNOŚCI jest wtedy trywialnie prawdziwa
# i przechodzi z niewłaściwego powodu.
#
# Tak przez długi czas wyglądał `AgentRollbackTest`: asertował, że po rollbacku
# `attributes_indexed` nie zawiera `price`, na projekcji, która w CI nigdy nie
# powstawała. Test nie sprawdzał tego, co deklarował — wyszło to dopiero, gdy
# #3053 zaczęło tę projekcję faktycznie zapisywać.
#
# Ten sam błąd zdarza się bez udziału transportu: `BulkRelationActionsApiTest`
# asertował "kod relacji nie wyciekł do attributes_indexed" na obiekcie,
# którego projekcja przez cały test była pusta (`[]`). Stąd druga dopuszczalna
# obrona.
#
# Dwie obrony, każda wystarczająca:
#   1. DRENAŻ — test odgrywa to, co zrobiłby worker (`drainAsyncTransport()`),
#      więc stan faktycznie powstaje przed asercją;
#   2. KONTROLA POZYTYWNA — ta sama metoda asertuje OBECNOŚĆ czegoś w tym samym
#      stanie, więc stan dowodnie istnieje i nie jest pusty. Wtedy "tego tam
#      nie ma" jest zdaniem o realnej projekcji, nie o jej braku.
#
# Analiza statyczna: bez PHP, bez bazy, bez sieci.
set -euo pipefail

cd "$(dirname "$0")/.."

TESTY="apps/api/tests"
BASELINE="scripts/lint-async-effect-assertions.baseline"

[ -d "$TESTY" ] || { echo "lint-async-effect-assertions: brak ${TESTY}" >&2; exit 1; }

command -v python3 >/dev/null 2>&1 \
    || { echo "lint-async-effect-assertions: wymagany python3" >&2; exit 1; }

python3 - "$TESTY" "$BASELINE" <<'PY'
import os
import re
import sys

testy, baseline_path = sys.argv[1], sys.argv[2]

# Stan, ktory na produkcji powstaje przez handler na transporcie `async`.
STAN_ASYNC = re.compile(r"attributes_indexed|getAttributesIndexed")
# Asercje mowiace "tego tam NIE ma" — trywialnie prawdziwe na pustym stanie.
NIEOBECNOSC = re.compile(
    r"assert(StringNotContainsString|ArrayNotHasKey|NotContains|Null|Empty)\s*\("
)
# Kontrola pozytywna — jawne "ten stan jest wypelniony". `assertSame` NIE
# liczy sie celowo: `assertSame([], $indexed)` to asercja pustki, czyli
# dokladnie to, przed czym ta bramka broni.
OBECNOSC = re.compile(
    r"assert(StringContainsString|ArrayHasKey|NotEmpty|Contains)\s*\("
)
# Poczatek metody. Powiazanie asercji ze stanem asynchronicznym jest liczone
# w granicach JEDNEJ metody: kontrola pozytywna z sasiedniego testu nie
# dowodzi niczego o obiekcie z tego testu.
METODA = re.compile(
    r"^\s*(?:(?:public|private|protected|static|final|abstract)\s+)*function\s+\w+"
)
DRENAZ = re.compile(r"drainAsyncTransport|InMemoryTransport")

baseline = set()
if os.path.exists(baseline_path):
    with open(baseline_path, encoding="utf-8") as fh:
        for line in fh:
            line = line.split("#", 1)[0].strip()
            if line:
                baseline.add(line)


def naruszenia(linie: list[str]) -> list[int]:
    """Numery linii z asercja nieobecnosci, ktorej nikt nie obronil."""
    granice = [0, *(i for i, linia in enumerate(linie) if METODA.search(linia)), len(linie)]
    trafienia = []
    for start, koniec in zip(granice, granice[1:]):
        cialo = linie[start:koniec]
        if not STAN_ASYNC.search("\n".join(cialo)):
            continue
        braki = [start + i for i, linia in enumerate(cialo) if NIEOBECNOSC.search(linia)]
        if braki and not any(OBECNOSC.search(linia) for linia in cialo):
            trafienia.extend(i + 1 for i in braki)

    return sorted(trafienia)


znalezione = {}
for katalog, _, pliki in os.walk(testy):
    # Testy jednostkowe nie bootuja kernela, wiec nie ma tam transportu.
    if os.sep + "Unit" + os.sep in katalog + os.sep:
        continue
    for nazwa in pliki:
        if not nazwa.endswith("Test.php"):
            continue
        sciezka = os.path.join(katalog, nazwa)
        zrodlo = open(sciezka, encoding="utf-8").read()
        if not STAN_ASYNC.search(zrodlo) or DRENAZ.search(zrodlo):
            continue
        trafienia = naruszenia(zrodlo.splitlines())
        if trafienia:
            znalezione[os.path.relpath(sciezka)] = trafienia

nowe = {p: l for p, l in znalezione.items() if p not in baseline}
znikniete = sorted(baseline - set(znalezione))

if nowe:
    print("lint-async-effect-assertions: asercja NIEOBECNOSCI na stanie asynchronicznym bez dowodu, ze ten stan powstal:", file=sys.stderr)
    for sciezka, linie in sorted(nowe.items()):
        print(f"  {sciezka}: linie {', '.join(map(str, linie))}", file=sys.stderr)
    print("", file=sys.stderr)
    print("  W CI `async` to in-memory:// — wiadomosc lezy w kolejce, handler nie startuje.", file=sys.stderr)
    print("  Taka asercja jest wtedy trywialnie prawdziwa i przechodzi z niewlasciwego powodu.", file=sys.stderr)
    print("  Zrob jedno z trzech:", file=sys.stderr)
    print("    - zawolaj drainAsyncTransport() przed asercja,", file=sys.stderr)
    print("    - doloz kontrole pozytywna: asercje OBECNOSCI na tym samym stanie", file=sys.stderr)
    print("      w tej samej metodzie (assertArrayHasKey / assertStringContainsString),", file=sys.stderr)
    print(f"    - dopisz plik do {baseline_path} z uzasadnieniem.", file=sys.stderr)
    print("  Patrz docs/testing/messenger-transports.md", file=sys.stderr)
    raise SystemExit(1)

if znikniete:
    print("lint-async-effect-assertions: te pliki nie lamia juz reguly — usun je z baseline:", file=sys.stderr)
    for sciezka in znikniete:
        print(f"  {sciezka}", file=sys.stderr)
    raise SystemExit(1)

print(f"lint-async-effect-assertions: OK — {len(baseline)} plikow w baseline, zero nowych naruszen.")
PY

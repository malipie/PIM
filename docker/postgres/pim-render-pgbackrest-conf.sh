#!/usr/bin/env sh
#
# Renderuje sekcję stanzy w /etc/pgbackrest/pgbackrest.conf pod TĘ instancję
# (epik TNT, #2864).
#
# Powód: plik konfiguracyjny jest zapieczony w obrazie z sekcją `[pim]` na
# sztywno. W modelu „instancja per tenant" (ADR-0035) każdy klient ma WŁASNĄ
# stanzę (`pim-<kod>`), bo stanza pgBackRest obejmuje cały klaster i tylko
# osobna stanza daje niezależne odtworzenie do punktu w czasie. Bez tej sekcji
# `pgbackrest --stanza=pim-acme` nie miałby konfiguracji i każda komenda
# kończyłaby się błędem.
#
# Zgodność wstecz: przy braku zmiennych render daje dokładnie to, co było
# w obrazie — stanza `pim`, ścieżka repo `/pim`, baza i użytkownik `pim`.
# Wspólny stack produkcyjny nie zauważa różnicy.
#
# Ścieżki repo są rozdzielone per stanza (`repo1-path=/<stanza>`), więc kopie
# dwóch klientów nigdy nie mieszają się w jednym wiadrze.

set -eu

CONF=/etc/pgbackrest/pgbackrest.conf
STANZA="${PGBACKREST_STANZA:-pim}"
PG_DATABASE="${POSTGRES_DB:-pim}"
PG_USER="${POSTGRES_USER:-pim}"
REPO_PATH="/${STANZA}"

[ -f "$CONF" ] || exit 0

# Wytnij wszystko od pierwszej sekcji stanzy (pierwsza sekcja po [global])
# i dopisz sekcję dla bieżącej stanzy. `[global]` z całą polityką retencji,
# kompresją i archiwizacją zostaje nietknięty.
awk '
    /^\[/ && $0 != "[global]" && seen_global { exit }
    /^\[global\]/ { seen_global = 1 }
    { print }
' "$CONF" > "${CONF}.tmp"

# repo1-path jest w [global]; nadpisz go tak, żeby wskazywał katalog stanzy.
if grep -q '^repo1-path=' "${CONF}.tmp"; then
    sed -i "s|^repo1-path=.*|repo1-path=${REPO_PATH}|" "${CONF}.tmp"
else
    echo "repo1-path=${REPO_PATH}" >> "${CONF}.tmp"
fi

cat >> "${CONF}.tmp" <<EOF

[${STANZA}]
pg1-path=/var/lib/postgresql/data
pg1-port=5432
pg1-user=${PG_USER}
pg1-database=${PG_DATABASE}
EOF

mv "${CONF}.tmp" "$CONF"
chown postgres:postgres "$CONF"
chmod 0640 "$CONF"

echo "[pgbackrest-conf] stanza=${STANZA} repo1-path=${REPO_PATH} db=${PG_DATABASE} user=${PG_USER}"

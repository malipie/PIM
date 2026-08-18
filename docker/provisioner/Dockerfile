# Usługa provisioningu instancji tenantów (epik TNT, #2905, ADR-0036).
#
# JEDYNY komponent w całym wdrożeniu z dostępem do demona Dockera. Dostęp do
# socketu jest równoważny uprawnieniom roota na hoście, więc obraz jest celowo
# minimalny: klient Dockera, Pythona ze standardowej biblioteki i jeden skrypt.
# Chodzi o to, żeby jego audyt dało się wykonać w całości — inaczej niż audyt
# aplikacji z autentykacją, uploadem plików i integracjami.
FROM docker:27-cli

# `docker compose` jako plugin + bash (skrypty provisioningu) + python3
# (walidacja i parsowanie zleceń). Bez menedżera pakietów aplikacji, bez
# serwera HTTP, bez powłoki interaktywnej dla nikogo z zewnątrz.
# openssl — generator sekretów instancji (scripts/pim-tenant-env.sh) losuje
# nimi hasła bazy, klucz Mercure i hasło pary JWT. Jego brak wychodzi dopiero
# przy pierwszym prawdziwym zleceniu: walidacja przechodzi, a provisioning
# przerywa się na pierwszym kroku.
# postgresql-client — zrzuty przy usuwaniu instancji.
RUN apk add --no-cache python3 bash docker-cli-compose postgresql16-client openssl

COPY provisioner.py /usr/local/bin/provisioner.py
RUN chmod 0755 /usr/local/bin/provisioner.py

WORKDIR /workspace

# Bez powłoki jako entrypoint: proces startowy to wprost interpreter skryptu,
# więc nic nie interpretuje ciągów znaków po drodze.
ENTRYPOINT ["python3", "/usr/local/bin/provisioner.py"]

# Obraz strony harmonpim.pl = FrankenPHP + msmtp.
#
# Po co: `mail.php` używa PHP `mail()`, a ta funkcja wymaga binarki sendmaila.
# Alpine ma tylko sendmaila z busyboxa, który nie obsługuje AUTH ani STARTTLS,
# więc wysyłka przez relay jest z nim niemożliwa (efekt: `{"ok":false}` + 500).
# Dokładamy msmtp i przestawiamy na niego `sendmail_path`.
#
# WAŻNE: konfiguracja z hasłem NIE jest częścią obrazu. Plik /etc/msmtprc
# (uprawnienia 600, właściciel root) jest montowany z hosta — patrz
# docker-compose.yml. Dzięki temu klucz API nie trafia do warstw obrazu ani
# do repozytorium.

FROM dunglas/frankenphp:1-php8.4-alpine

RUN apk add --no-cache msmtp ca-certificates \
    && printf 'sendmail_path = "/usr/bin/msmtp -t -i"\n' \
       > /usr/local/etc/php/conf.d/zz-msmtp.ini

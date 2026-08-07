# Self-hosting

::: warning Strona w budowie
Instrukcja instalacji na własnym serwerze powstaje. Poniżej szkielet tego, co
się tu znajdzie — treść dojdzie w kolejnej iteracji.
:::

## Co się tu znajdzie

- **Wymagania** — host, zasoby, system, Docker, otwarte porty, DNS.
- **Instalacja krok po kroku** — pobranie wydania, konfiguracja sekretów,
  budowa obrazów, uruchomienie stosu, migracje bazy.
- **Pierwsze uruchomienie** — utworzenie organizacji i konta właściciela,
  opcjonalny zestaw danych demonstracyjnych, indeks wyszukiwarki.
- **Poczta** — konfiguracja wysyłki (zaproszenia, reset hasła) i rekordy
  SPF/DKIM/DMARC dla domeny nadawcy.
- **Kopie zapasowe** — harmonogram, odtwarzanie do wskazanego momentu,
  próbne przywrócenie.
- **Aktualizacje** — przejście na nowe wydanie bez utraty danych.
- **Monitoring** — metryki, alerty, sprawdzanie dostępności z zewnątrz.

## Zanim to opiszemy

Instalacja opiera się na Dockerze i jednym pliku konfiguracyjnym ze zmiennymi
środowiskowymi. Wszystkie sekrety generuje się na docelowym serwerze — żaden
nie jest zaszyty w kodzie ani w obrazach.

W razie pytań przed publikacją tej instrukcji: [kontakt](https://harmonpim.pl).

# Runbook — przeniesienie tenanta na dedykowany host

Kiedy jeden klient urośnie ponad wspólną maszynę albo zażąda osobnego serwera
w umowie, model z ADR-0035 pozwala go wyprowadzić **bez migracji logicznej**:
instancja jest kompletem kontenerów i wolumenów, więc przenosi się ją, a nie
przepisuje.

Ten dokument opisuje procedurę i **wymaga zmierzenia okna niedostępności**
przed pierwszym użyciem u klienta. „Chwila przerwy" nie jest obietnicą, którą
da się złożyć.

---

## Co się przenosi, a co powstaje na nowo

| Element | Sposób |
|---|---|
| Baza (wolumen `postgres_data`) | transfer wolumenu **albo** zrzut i odtworzenie |
| `api_var`, `api_jwt` | transfer wolumenów — klucze JWT muszą przetrwać, inaczej wszystkie wydane tokeny przestają działać |
| Buckety MinIO | transfer `mc mirror` (na nowym hoście potrzebna własna instancja MinIO) |
| Indeks Meilisearch | **odtworzyć reindeksem**, nie przenosić — szybciej i bez ryzyka rozjazdu formatu |
| Repozytorium pgBackRest | nowa stanza na nowym hoście; stare kopie zostają na starym repozytorium do czasu wygaśnięcia retencji |
| `redis` | nie przenosi się — cache i limiter odtworzą się same |

**Uwaga o zakresie:** na osobnym hoście Meilisearch i MinIO przestają być
współdzielone. Nowy host potrzebuje własnych — to jest realny koszt tej
operacji, nie szczegół.

---

## Procedura

### 1. Przygotowanie (bez przestoju)

```bash
# na starym hoście — kopia bezpieczeństwa niezależna od transferu
bash scripts/pim-tenant-dump.sh --code acme --label pre-move
```

Na nowym hoście: rozpakuj repozytorium, przygotuj wspólne usługi (edge Caddy,
MinIO, Meilisearch) i **skopiuj plik `.env.tenant.acme`** — zawiera hasła bazy,
klucz Mercure i hasło pary JWT. Bez niego przeniesione wolumeny są bezużyteczne.

### 2. Okno niedostępności — start

```bash
# stary host
docker compose -p pim-acme --env-file .env.tenant.acme -f docker-compose.tenant.yml stop
```

Od tej chwili klient nie pracuje. **Zmierz czas do kroku 5.**

### 3. Transfer

```bash
# stary host — spakuj wolumeny
for v in postgres_data api_var api_jwt; do
  docker run --rm -v "pim-acme_${v}:/src:ro" -v "$PWD:/out" alpine \
      tar czf "/out/acme-${v}.tar.gz" -C /src .
done
scp acme-*.tar.gz nowy-host:/opt/pim/

# nowy host — rozpakuj do świeżych wolumenów
for v in postgres_data api_var api_jwt; do
  docker volume create "pim-acme_${v}"
  docker run --rm -v "pim-acme_${v}:/dst" -v "$PWD:/in" alpine \
      tar xzf "/in/acme-${v}.tar.gz" -C /dst
done
```

Assety: `mc mirror stary/acme-assets nowy/acme-assets` (analogicznie `-imports`,
`-exports`).

### 4. Start na nowym hoście

```bash
DC="docker compose -p pim-acme --env-file .env.tenant.acme -f docker-compose.tenant.yml"
# Wolumen `api_var` przyjechał ze starego hosta, więc niesie CUDZY skompilowany
# kontener DI. Czyścimy go, ZANIM wystartują procesy aplikacyjne — `exec
# cache:clear` na działającym api/workerze kasuje pliki, które te procesy
# ładują leniwie (#2991).
$DC up -d database redis
$DC run --rm --no-deps api    php bin/console cache:clear
$DC run --rm --no-deps worker php bin/console cache:clear
$DC up -d
```

Reindeks wyszukiwarki (indeks nie był przenoszony) — zgodnie z bieżącą komendą
reindeksu dla instancji.

**Po odtworzeniu bazy ze zrzutu** (a nie z wolumenu) sprawdź uprawnienia roli
`pim_app` na schemacie `public` — `pg_restore` potrafi je zdjąć, a migracja,
która je nadaje, już się nie wykona ponownie.

### 5. Przepięcie ruchu — koniec okna

Dwa warianty:

- **ten sam adres** (`acme.app.harmonpim.pl`) — zmiana rekordu DNS na nowy host.
  Uwaga na TTL: obniż go **dzień wcześniej**, inaczej okno rozciąga się na czas
  propagacji;
- **własna domena klienta** (`pim.acme.pl`) — rekord po stronie klienta plus
  nowy wpis w edge Caddy nowego hosta i **nowy redirect URI SSO** u dostawcy
  tożsamości.

### 6. Weryfikacja przed ogłoszeniem sukcesu

```bash
bash scripts/pim-tenant-isolation-check.sh --a acme --b <inny-na-nowym-hoscie> \
    --a-user owner@acme.pl --a-password-env ACME_PW
```

Plus smoke: logowanie właściciela, lista produktów, jeden upload assetu
(sprawdza nową instancję MinIO), jeden eksport.

### 7. Wycofanie

**Stary stack zostaje nietknięty** do czasu potwierdzenia smoke'a na nowym
hoście. Wycofanie = przywrócenie poprzedniego rekordu DNS i `up -d` na starym
hoście. Wolumeny na starym hoście kasuje się dopiero po kilku dniach spokojnej
pracy — nie tego samego dnia.

---

## Do zmierzenia przed pierwszym użyciem u klienta

- [ ] **Okno niedostępności** dla instancji o skali ~50k SKU (kroki 2–5),
      zapisane tutaj jako konkretna liczba minut.
- [ ] Czas transferu wolumenów przy realnym łączu między hostami.
- [ ] Czas reindeksu wyszukiwarki dla tej skali.
- [ ] Przebieg przećwiczony na tenancie testowym (#2869), nie tylko opisany.

Dopóki te pola są puste, procedura jest planem, a nie obietnicą.

---

## Powiązane

- ADR-0035 — przenośność jako jeden z powodów osobnego klastra
- `docs/runbook/tenant-instances.md` — codzienna obsługa instancji
- #2869 — próba generalna

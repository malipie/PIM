# Korelacja logów HTTP i Messenger

API przyjmuje opcjonalny nagłówek `X-Request-ID`, zwraca zaufany identyfikator
w tym samym nagłówku odpowiedzi i propaguje go do komunikatów Symfony
Messenger. Processor Monologa dodaje identyfikator do każdego rekordu jako
`extra.correlation_id` (w logach JSON: `"extra":{"correlation_id":"…"}`).

## Kontrakt identyfikatora

- długość: 1–128 znaków,
- pierwszy znak: litera lub cyfra,
- pozostałe znaki: litery, cyfry, `.`, `_`, `:`, `-`,
- pusta, za długa lub niebezpieczna wartość jest zastępowana UUID-em,
- identyfikator nie może zawierać danych osobowych, tokenów ani innych sekretów.

Wartość zwrócona w odpowiedzi jest źródłem prawdy. Dzięki temu klient może
wysłać własny identyfikator, ale nie może wstrzyknąć końca linii ani dowolnej
treści do logów aplikacji.

## Weryfikacja HTTP → worker

Wywołaj operację asynchroniczną, np. import większy niż 50 wierszy:

```bash
CORRELATION_ID="pilot-import-$(date -u +%Y%m%dT%H%M%SZ)"

curl -kisS https://pim.localhost/api/import-sessions \
  -H "Authorization: Bearer $TOKEN" \
  -H "X-Request-ID: $CORRELATION_ID" \
  -F "target_object_type_id=$OBJECT_TYPE_ID" \
  -F "mapping=$MAPPING_JSON" \
  -F "file=@products.csv"
```

Odpowiedź `202` musi zawierać ten sam `X-Request-ID`. Następnie porównaj log
żądania i osobnego workera:

```bash
docker compose logs api    | rg "$CORRELATION_ID"
docker compose logs worker | rg "$CORRELATION_ID"
```

W obu strumieniach rekordy Monologa muszą zawierać `correlation_id` o tej
samej wartości. Log dostępu FrankenPHP pokazuje identyfikator dodatkowo w
`resp_headers.X-Request-Id`. Jeżeli klient wyśle niepoprawną wartość, do
wyszukiwania należy użyć UUID-a z nagłówka odpowiedzi.

Kontekst jest czyszczony po zakończeniu żądania oraz przez mechanizm resetu
serwisów Symfony po pełnym cyklu komunikatu (łącznie z logiem ack/retry/failure).
Brak identyfikatora w kolejnym, niezależnym przepływie nie może odziedziczyć
wartości z poprzedniego workera.

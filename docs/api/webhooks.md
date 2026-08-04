# Webhooki wychodzące — weryfikacja podpisu

> Kontrakt dla systemów subskrybujących zdarzenia PIM (`ApiProfile.webhookUrl`).
> Wersja podpisu: **v2 (timestamp + body)**, wprowadzona w #2741.

## Nagłówki dostawy

| Nagłówek | Znaczenie |
|---|---|
| `x-pim-timestamp` | Unix epoch (sekundy) w momencie podpisania dostawy. |
| `x-pim-signature` | `sha256=<hex>` — HMAC-SHA256 z `"{timestamp}.{body}"`, kluczem jest webhook secret profilu. |
| `x-pim-event` | Nazwa zdarzenia (np. `object.created.product`). |
| `content-type` | Zawsze `application/json`. |

## Jak zweryfikować

1. Odczytaj `x-pim-timestamp` i **surowe** body (bajt w bajt — nie po deserializacji i ponownym zakodowaniu).
2. Zbuduj podpisywany string: `signed = timestamp + "." + body`.
3. Policz `HMAC-SHA256(signed, secret)` i porównaj z wartością po `sha256=` **porównaniem stałoczasowym**.
4. Odrzuć dostawę, jeśli `|now - timestamp|` przekracza Twoje okno tolerancji. Zalecane **5 minut** — chroni przed replayem przechwyconego żądania.

### PHP

```php
$signed = $timestamp.'.'.$rawBody;
$expected = hash_hmac('sha256', $signed, $secret);
$ok = hash_equals($expected, substr($signatureHeader, 7)) // po "sha256="
    && abs(time() - (int) $timestamp) <= 300;
```

### Node.js

```js
const signed = `${timestamp}.${rawBody}`;
const expected = crypto.createHmac('sha256', secret).update(signed).digest('hex');
const ok =
  crypto.timingSafeEqual(Buffer.from(expected), Buffer.from(signatureHeader.slice(7))) &&
  Math.abs(Date.now() / 1000 - Number(timestamp)) <= 300;
```

## Dlaczego timestamp jest w podpisie

Podpis liczony wyłącznie z body pozostaje ważny **na zawsze** — odbiorca nie ma jak odróżnić oryginalnej dostawy od jej powtórzenia przez atakującego, który przechwycił żądanie. Włączenie timestampu do podpisywanego stringa sprawia, że atakujący nie może podmienić czasu bez unieważnienia podpisu, więc okno tolerancji z punktu 4 realnie ogranicza replay.

## Rotacja sekretu

Sekret rotuje się w UI profilu API (`POST /api/api_profiles/{id}/rotate_webhook_secret`) — nowy sekret jest pokazywany **jeden raz**. Do czasu wdrożenia nowego sekretu u odbiorcy dostawy podpisane starym będą odrzucane, więc rotację planuj razem z odbiorcą.

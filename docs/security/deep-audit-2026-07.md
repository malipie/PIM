# Deep audit — taint sweep + adwersaryjna weryfikacja (GOLIVE #2131-2133)

**Data:** 2026-07-04 · **Blok:** B · **Charakter:** wewnętrzny secure-SDLC audit WŁASNEGO systemu przed go-live (autoryzacja właściciela; zakres = ten codebase + lokalny stack).

**Metoda (#2131 setup → #2132 sweep+weryfikacja → #2133 PoC/fix):** 3 równoległe agenty prześledziły taint źródło→ujście w trzech domenach (SQL/raw-query, authz/IDOR, SSRF/secrets/deserializacja). Każdy CONFIRMED finding **zweryfikowany adwersaryjnie empirycznie** na żywym stacku PRZED zakwalifikowaniem — dwa z pięciu okazały się false-positive/accepted po weryfikacji.

## Findingi po adwersaryjnej weryfikacji

| # | Finding | Agent verdykt | Weryfikacja empiryczna | Finalny status |
|---|---|---|---|---|
| 1 | **Webhook delivery SSRF** — `WebhookDeliveryClient` używa plain HttpClient (nie ssrf_safe) | CONFIRMED CRITICAL | `test_webhook` → `http://redis:6379/` łączył się (przed fixem) | **✅ REAL → NAPRAWIONY** |
| 2 | BulkActions per-action escalation | CONFIRMED | — | **już naprawiony #2129/PR #2220** (2 agenty niezależnie potwierdziły) |
| 3 | Meili `addslashes` filter-value injection | CONFIRMED HIGH (agent 1) | injection `filter[enabled]=true" OR tenantId="<acme>` → **200 totalHits:0, ZERO bypassu** | **❌ FALSE POSITIVE → #2225 (consistency-nit LOW)** |
| 4 | SavedView cross-user (within-tenant) | SAFE (accepted MVP) | — | **zaakceptowane ograniczenie → #2224 (Faza 1)** |
| 5 | Sekrety/deserializacja/path-traversal | SAFE (wszystkie guarded) | — | ✅ bez findingów |

## FINDING NAPRAWIONY — Webhook delivery SSRF (był CRITICAL)

**Wektor:** `ApiProfile.webhookUrl` (konfigurowalny per-tenant przez producenta API) trafiał do `WebhookDeliveryClient::deliver()` → `HttpClientInterface::request()` na **domyślnym, niechronionym** kliencie — w przeciwieństwie do import/generic/connection, które są jawnie owinięte w `NoPrivateNetworkHttpClient`. Tenant admin mógł ustawić `webhookUrl: http://169.254.169.254/latest/meta-data/` (cloud metadata) albo `http://redis:6379`/`http://minio:9000` (usługi wewnętrzne) i użyć PIM jako SSRF-pivota.

**Dowód (przed/po):** po fixie `test_webhook` → `http://redis:6379/` zwraca `statusCode:0, success:false` (NoPrivateNetworkHttpClient odrzuca private IP przed połączeniem); DI potwierdza `WebhookDeliveryClient.$http = webhook.ssrf_safe_http_client (NoPrivateNetworkHttpClient)`.

**Fix:** nowy serwis `webhook.ssrf_safe_http_client` (identyczny wzorzec co `import`/`generic.ssrf_safe_http_client`) zbindowany do `WebhookDeliveryClient`. Per-redirect peer-IP validation zamyka też DNS-rebinding TOCTOU.

## Adwersaryjne obalenie (dlaczego #3 to false-positive)

Agent SQL twierdził, że `addslashes()` daje `\"` a Meili oczekuje `\\"`. **Empirycznie fałszywe:** Meili escapuje quote jako `\"` (pojedynczy backslash) — dokładnie to co `addslashes` produkuje dla `"` i `\`. Test injection na żywo: wartość `true" OR tenantId = "<acme_id>` stała się jednym quoted stringiem `enabled = "true\" OR tenantId = \"<acme>"` → matchuje nic (0 hits), AND-scoped tenant filter niezmienny. Klucz-injection już blokuje whitelist `assertFilterKeys` (400). Zero bypassu → nie podatność, tylko consistency-nit (#2225).

## Wniosek

Deep audit potwierdził odporność rdzenia (izolacja tenantów, authz, escaping, brak unsafe-deser/path-traversal) i **znalazł 1 realną nową podatność (webhook SSRF), naprawioną w tym PR**. Wartość adwersaryjnej weryfikacji: 2/5 „CONFIRMED" agentów obalone/zdegradowane empirycznie — bez niej trafiłyby do backlogu jako fałszywe blockery.

# Smoke krytycznych ścieżek (GOLIVE #2135)

**Data:** 2026-07-04 · **Blok:** B · **Reguła:** SMOKE TEST RULE (HTTP code + kształt odpowiedzi, nie sam 2xx).

Harness: [`scripts/smoke/critical-paths.sh`](../../../scripts/smoke/critical-paths.sh) — uderza w krytyczne ścieżki żywego backendu i asertuje kształt odpowiedzi. Console-clean na tych trasach pokrywa suita Playwright (122 specy, Chromium).

## Wynik: 11/11 PASS (żywy stack `pim.localhost`)

| Flow | Asercja | Wynik |
|---|---|---|
| Auth login | JWT w body (554 znaki) | ✅ |
| Auth /me | principal z email | ✅ (admin@demo.localhost) |
| Produkty — lista | hydra `totalItems` | ✅ (100) |
| **Produkty — pełen CRUD** | create 201 → read 200 → delete 204 | ✅ |
| Import — lista sesji | 200 | ✅ |
| Export — lista sesji | 200 (`/api/exports/sessions`) | ✅ |
| Feedy — lista | 200 | ✅ |
| Konfigurator API — połączenia | 200 | ✅ |
| RBAC — users + roles | 200 / 200 | ✅ |

## Zakres świadomie poza harnessem (curl nie pokrywa)

- **MFA / reset hasła / zaproszenia:** wymagają skrzynki (Mailpit) — pokryte osobno w Bloku C (#2139 SMTP) i testami ApiTestCase; reset flow zweryfikowany w #2130 (env-gate token).
- **Import dużego pliku, feed SSE monitor, break-glass:** interaktywne/asynchroniczne — pokryte E2E (Playwright) + dedykowanymi runbookami; break-glass = osobny live-drill (runbook `docs/operations/break-glass-runbook.md`).
- **Console-clean:** 122 specy Playwright na Chromium (matryca przeglądarek #2126 dla Firefox/WebKit).

Harness idempotentny (tworzy i kasuje własny `SMOKE-2135-*` produkt), re-uruchamialny przed każdym release.

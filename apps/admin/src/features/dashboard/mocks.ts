/**
 * VIEW-13 (#2143) — static dashboard mock data ("command center" redesign).
 *
 * Every value mirrors the approved dashboard mock 1:1 (pixel-perfect
 * requirement — do not "improve" the numbers). Interfaces are shaped like
 * the future dashboard DTOs so wiring a block to a live endpoint later is
 * mechanical; the backend backlog lives in
 * Project Plan/UI/Wdrozenie_grafiki/dashboard-do-oprogramowania.md.
 *
 * Wired live so far (mock removed): KPI band + catalog health
 * (GET /api/dashboard/summary, DASH-02/06) and team activity
 * (GET /api/dashboard/activity + /top-edited, DASH-07/08). Still mock
 * below: the action center (DASH-09/10).
 *
 * Do not import this module outside features/dashboard/.
 */

// ---------------------------------------------------------------------------
// Action center
// ---------------------------------------------------------------------------

export type ActionSeverity = 'critical' | 'warning';

export interface ActionCenterItem {
  id: string;
  severity: ActionSeverity;
  title: string;
  /** Meta line 1 tail (after the colored severity word): source · time. */
  source: string;
  when: string;
  /** Meta line 2 — technical detail. */
  detail: string;
  /** CTA button label (per-item mock data, PL like the rest of the seed). */
  cta: string;
}

export const ACTION_CENTER = {
  total: 5,
  critical: 2,
  warnings: 3,
  allCount: 12,
  // Company names below (Mtodo, Stalko, Stal-Met) are sample tenants from
  // the approved mock — not real customers.
  items: [
    {
      id: 'ac1',
      severity: 'critical',
      title: 'Synchronizacja „Mtodo Marketplace” nieudana — 412 rekordów odrzuconych',
      source: 'Konfigurator API',
      when: '13:32',
      detail: 'token OAuth2 wygasł · 401 Unauthorized',
      cta: 'Zobacz log',
    },
    {
      id: 'ac2',
      severity: 'critical',
      title: 'Import „pim-catalog-0630.xlsx” zakończony częściowo — 132 wiersze z błędami',
      source: 'Importy',
      when: 'wczoraj · 15:59',
      detail: 'SKU puste (18) · typ number (114)',
      cta: 'Pobierz raport błędów',
    },
    {
      id: 'ac3',
      severity: 'warning',
      title: 'Feed „B2B — Hurtownia Stalko” — regeneracja przerwana (brak pola <price>)',
      source: 'Feedy XML',
      when: 'dziś · 02:00',
      detail: 'custom · mapowanie 6/9 pól',
      cta: 'Otwórz feed',
    },
    {
      id: 'ac4',
      severity: 'warning',
      title: 'Google Shopping: kompletność spadła do 76% — poniżej progu publikacji (80%)',
      source: 'Zdrowie danych',
      when: 'dziś · 08:10',
      detail: '−3 pkt / 24h · 312 SKU straciło gotowość',
      cta: 'Pokaż produkty',
    },
    {
      id: 'ac5',
      severity: 'warning',
      title: 'Webhook „price.changed → Stal-Met”: 3 dostawy w dead-letter (HTTP 503)',
      source: 'Integracje · webhooki',
      when: '13:02',
      detail: '5× retry exp. backoff wyczerpane',
      cta: 'Otwórz log',
    },
  ] satisfies ActionCenterItem[],
};

/**
 * VIEW-13 (#2143) — static dashboard mock data ("command center" redesign).
 *
 * Every value mirrors the approved dashboard mock 1:1 (pixel-perfect
 * requirement — do not "improve" the numbers). Interfaces are shaped like
 * the future dashboard DTOs so wiring a block to a live endpoint later is
 * mechanical; the backend backlog lives in
 * Project Plan/UI/Wdrozenie_grafiki/dashboard-do-oprogramowania.md.
 *
 * Wired live so far (mock removed): KPI band + catalog health via
 * GET /api/dashboard/summary (DASH-02/06). Still mock below: team
 * activity (DASH-07/08) and the action center (DASH-09/10).
 *
 * Do not import this module outside features/dashboard/.
 */

// ---------------------------------------------------------------------------
// Team activity (chart + most edited)
// ---------------------------------------------------------------------------

export type ActivityRange = '7d' | '30d' | '90d';

export interface ActivityPoint {
  added: number;
  modified: number;
}

/**
 * Deterministic pseudo-noise series — literal enough to stay pixel-stable
 * between renders, jagged enough to read as real team activity.
 */
const buildSeries = (length: number): ActivityPoint[] =>
  Array.from({ length }, (_, i) => {
    const weekend = i % 7 === 5 || i % 7 === 6;
    const drift = i / (length - 1);
    return {
      added: weekend ? 14 + (i % 4) : 24 + ((i * 5) % 11) + Math.round(drift * 10),
      modified: weekend ? 26 + (i % 5) : 40 + ((i * 7) % 15) + Math.round(drift * 16),
    };
  });

export const TEAM_ACTIVITY = {
  addedTotal: '1 354',
  modifiedTotal: '2 141',
  avgPerDay: 117,
  series: {
    '7d': buildSeries(7),
    '30d': buildSeries(30),
    '90d': buildSeries(90),
  } satisfies Record<ActivityRange, ActivityPoint[]>,
  /** X-axis captions per range: [left, middle, right]. */
  axis: {
    '7d': ['6 dni temu', '3 dni temu', 'dziś'],
    '30d': ['29 dni temu', '14 dni temu', 'dziś'],
    '90d': ['89 dni temu', '45 dni temu', 'dziś'],
  } satisfies Record<ActivityRange, [string, string, string]>,
};

export interface MostEditedProduct {
  name: string;
  sku: string;
  category: string;
  completeness: number;
  edits: number;
}

export const MOST_EDITED: MostEditedProduct[] = [
  {
    name: 'Czujnik indukcyjny Festo IS-50 PNP M12',
    sku: 'FES-PNZ-IS50',
    category: 'Czujniki',
    completeness: 96,
    edits: 47,
  },
  {
    name: 'Rura zaciskowa DN50 stal nierdzewna 316L',
    sku: 'KLI-RZP-DN50',
    category: 'Hydraulika',
    completeness: 88,
    edits: 41,
  },
  {
    name: 'Wkrętarka akumulatorowa Bosch GSR 18V-90',
    sku: 'BSC-WKR-18V',
    category: 'Elektronarzędzia',
    completeness: 100,
    edits: 38,
  },
  {
    name: 'Zawór elektromagnetyczny SMC 24V solenoid',
    sku: 'SMC-ZWR-24V',
    category: 'Pneumatyka',
    completeness: 72,
    edits: 34,
  },
  {
    name: 'Plecak fotograficzny Wandrd PRVKE Pro 31L',
    sku: 'WAR-PLE-PRO',
    category: 'Akcesoria',
    completeness: 94,
    edits: 29,
  },
  {
    name: 'Złącze M12 Murr 4-pin IP67 ekranowane',
    sku: 'MKP-TWR-IP67',
    category: 'Złącza',
    completeness: 67,
    edits: 22,
  },
];

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

import type { FilterDsl } from '@/lib/filters/filter-dsl';

import type { SyncDirection } from '../../components/primitives';

/**
 * Shared shapes + constants of the SyncBinding config screen (APIC-P3-11),
 * extracted from SyncConfigScreen (#2640 — AUD-057 max-lines guard).
 */

export type ConflictPolicy = 'lww' | 'pim_wins' | 'remote_wins';

export interface SyncBindingRow {
  id: string;
  connectionId: string;
  objectTypeId: string;
  readEndpointId: string | null;
  writeEndpointId: string | null;
  direction: SyncDirection;
  schedule: string | null;
  conflictPolicy: ConflictPolicy;
  matchKeyMapping: string | null;
  cursor: { field?: string; type?: string; state?: unknown } | null;
  isEnabled: boolean;
  nextRun: string | null;
  outboundFilter: FilterDsl | null; // #2549 — outbound scope (null = send all)
  sourceChannel: string | null; // #2667 — outbound value-source channel code (omitted when null)
  sourceLocale: string | null; // #2667 — outbound value-source SHORT locale (omitted when null)
}

export interface ObjectTypeRow {
  id: string;
  code: string;
  kind?: string;
}

export const SYNC_HUB = '/integrations/api-configurator/connections';

export const CRON_PRESETS = [
  { key: 'every_15m', cron: '*/15 * * * *' },
  { key: 'every_30m', cron: '*/30 * * * *' },
  { key: 'hourly', cron: '0 * * * *' },
  { key: 'daily_2', cron: '0 2 * * *' },
] as const;

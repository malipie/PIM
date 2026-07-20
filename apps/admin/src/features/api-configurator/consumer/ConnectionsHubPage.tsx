import { useList } from '@refinedev/core';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Input } from '@/components/ui/input';
import { TopbarCta } from '@/components/ui-v2/topbar-cta';
import { usePageActions } from '@/layout/page-actions-context';

import { Segmented } from '../components/primitives';
import { ConnectionCard, type ConnectionRow } from './ConnectionCard';

type StatusFilter = 'all' | 'active' | 'paused' | 'error' | 'draft';

/**
 * APIC-P1-07 — consumer connections hub (Połączenia tab). Lists the tenant's
 * external-API connections from `/api/connections` with status counts, search
 * and a status filter. Sync KPIs (syncs/records/issues per 24h) arrive with
 * SyncRun in M3; the create wizard is APIC-P1-08.
 */
export function ConnectionsHubPage() {
  const { t } = useTranslation();

  usePageActions(
    useMemo(
      () => (
        <TopbarCta to="/integrations/api-configurator/connections/new">
          {t('api_configurator.hub.new_connection')}
        </TopbarCta>
      ),
      [t],
    ),
  );
  const { result, query } = useList<ConnectionRow>({
    resource: 'connections',
    pagination: { mode: 'off' },
  });

  const connections = result.data;
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<StatusFilter>('all');

  const counts = useMemo(() => {
    const acc = { all: connections.length, active: 0, paused: 0, error: 0, draft: 0 };
    for (const c of connections) {
      acc[c.status] += 1;
    }
    return acc;
  }, [connections]);

  const filtered = useMemo(() => {
    const needle = search.trim().toLowerCase();
    return connections.filter((c) => {
      if (status !== 'all' && c.status !== status) {
        return false;
      }
      if (needle === '') {
        return true;
      }
      return (
        c.name.toLowerCase().includes(needle) ||
        c.code.toLowerCase().includes(needle) ||
        c.baseUrl.toLowerCase().includes(needle)
      );
    });
  }, [connections, search, status]);

  return (
    <div className="space-y-5">
      {/* #2671 — the visible hub header was removed (topbar breadcrumb names
          the area); the sr-only h1 keeps screen-reader orientation and the
          page-has-heading-one axe rule satisfied. */}
      <h1 className="sr-only">{t('api_configurator.hub.title')}</h1>
      <div className="flex flex-wrap items-center gap-3">
        <Input
          value={search}
          onChange={(e) => setSearch(e.target.value)}
          placeholder={t('api_configurator.hub.search_placeholder')}
          aria-label={t('api_configurator.hub.search_placeholder')}
          className="h-9 max-w-xs"
        />
        <Segmented
          ariaLabel={t('api_configurator.hub.filter_aria')}
          size="sm"
          value={status}
          onChange={setStatus}
          options={[
            { value: 'all', label: t('api_configurator.hub.filter.all', { count: counts.all }) },
            {
              value: 'active',
              label: t('api_configurator.hub.filter.active', { count: counts.active }),
            },
            {
              value: 'paused',
              label: t('api_configurator.hub.filter.paused', { count: counts.paused }),
            },
            {
              value: 'error',
              label: t('api_configurator.hub.filter.error', { count: counts.error }),
            },
          ]}
        />
      </div>

      {query.isLoading ? (
        <p className="text-[13px] text-zinc-500">{t('app.loading')}</p>
      ) : filtered.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-zinc-200 bg-white p-10 text-center">
          <h2 className="text-[15px] font-semibold tracking-tight">
            {connections.length === 0
              ? t('api_configurator.hub.empty_title')
              : t('api_configurator.hub.no_matches_title')}
          </h2>
          <p className="mx-auto mt-1 max-w-md text-[13px] text-zinc-500">
            {connections.length === 0
              ? t('api_configurator.hub.empty_desc')
              : t('api_configurator.hub.no_matches_desc')}
          </p>
        </div>
      ) : (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
          {filtered.map((connection) => (
            <ConnectionCard key={connection.id} connection={connection} />
          ))}
        </div>
      )}
    </div>
  );
}

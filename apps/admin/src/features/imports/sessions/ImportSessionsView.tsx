import { useApiUrl, useCustom, useList } from '@refinedev/core';
import { Search, Upload } from 'lucide-react';
import * as React from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { EmptyState } from '@/components/ui-v2/empty-state';

import { HistoryTable } from './HistoryTable';
import { KpiStrip } from './KpiStrip';
import { LiveSessionCard } from './LiveSessionCard';
import { SessionsFilterPills } from './SessionsFilterPills';
import {
  type FilterValue,
  filterMatches,
  type ImportSessionRow,
  type ThroughputResponse,
} from './types';

function useDebouncedValue<T>(value: T, delay = 300): T {
  const [debounced, setDebounced] = React.useState(value);
  React.useEffect(() => {
    const handle = window.setTimeout(() => setDebounced(value), delay);
    return () => window.clearTimeout(handle);
  }, [value, delay]);
  return debounced;
}

/**
 * VIEW-IMP-01 (#496) — operational hub for import sessions.
 *
 * Replaces the IMP-09 flat data-table with the design's three
 * stacked sections: KPI strip, hero LiveSessionCard for the first
 * running session, and the filterable history table. Throughput
 * polls every 5s — cheap aggregation query on the BE side.
 */
export function ImportSessionsView() {
  const { t } = useTranslation();
  const apiUrl = useApiUrl();
  const [filter, setFilter] = React.useState<FilterValue>('all');
  const [query, setQuery] = React.useState('');
  const debouncedQuery = useDebouncedValue(query, 300);

  const { result, query: refineQuery } = useList<ImportSessionRow>({
    resource: 'import-sessions',
    pagination: { pageSize: 200, currentPage: 1 },
    filters:
      debouncedQuery.length > 0
        ? [{ field: 'q', operator: 'eq', value: debouncedQuery }]
        : undefined,
  });

  const sessions = result.data ?? [];
  const total = result.total ?? sessions.length;
  const isLoading = refineQuery.isLoading;

  const throughputCustom = useCustom<ThroughputResponse>({
    url: `${apiUrl}/import-sessions/throughput?windowMin=5`,
    method: 'get',
    queryOptions: { refetchInterval: 5000, staleTime: 1000 },
  });
  const throughputData = throughputCustom.result?.data;
  const throughput: ThroughputResponse | undefined =
    throughputData !== undefined
      ? {
          rows_per_sec: Number(throughputData.rows_per_sec ?? 0),
          active_sessions: Number(throughputData.active_sessions ?? 0),
          window_min: Number(throughputData.window_min ?? 5),
          sampled_at: String(throughputData.sampled_at ?? ''),
        }
      : undefined;

  const liveSession = sessions.find(
    (s) => s.status === 'running' || s.status === 'paused' || s.status === 'pending',
  );
  const history = sessions.filter((s) => filterMatches(filter, s.status));

  return (
    <div className="space-y-6">
      <KpiStrip sessions={sessions} throughput={throughput} />

      <section className="space-y-3">
        <div className="flex items-center gap-2.5">
          <h3 className="font-display text-[16px] font-semibold tracking-tight">
            {t('imports.sessions.live.heading')}
          </h3>
          <div className="ml-auto text-[11.5px] text-zinc-500">
            {throughput ? (
              <>
                {t('imports.sessions.kpi.throughput')}{' '}
                <span className="font-mono text-zinc-800">
                  {throughput.rows_per_sec.toFixed(0)} {t('imports.sessions.kpi.rows_per_sec')}
                </span>
              </>
            ) : null}
          </div>
        </div>
        {liveSession ? (
          <LiveSessionCard session={liveSession} throughput={throughput} />
        ) : (
          <div className="rounded-2xl border border-dashed border-zinc-200 bg-surface">
            <EmptyState
              icon={<Upload className="size-5" />}
              title={t('imports.sessions.live.empty_title')}
              description={t('imports.sessions.live.empty_subtitle')}
              action={
                <Link
                  to="/integrations/imports/new"
                  className="focus-ring inline-flex h-9 items-center rounded-xl bg-cta px-3.5 text-[13px] font-semibold text-cta-foreground transition hover:bg-accent-hover"
                >
                  {t('imports.sessions.new_import')}
                </Link>
              }
            />
          </div>
        )}
      </section>

      <section className="space-y-3">
        <div className="flex items-center gap-3 flex-wrap">
          <h3 className="font-display text-[16px] font-semibold tracking-tight">
            {t('imports.sessions.history.heading')}
          </h3>
          <span className="text-[12px] text-zinc-500">
            {t('imports.sessions.history.eyebrow', { total })}
          </span>
          <div className="ml-auto flex items-center gap-2 flex-wrap">
            <label className="flex items-center gap-2 bg-white soft-shadow rounded-xl pl-3 pr-2 h-9">
              <Search className="h-4 w-4 text-zinc-500" aria-hidden="true" />
              <input
                value={query}
                onChange={(e) => setQuery(e.target.value)}
                placeholder={t('imports.sessions.history.search_placeholder')}
                className="w-56 bg-transparent outline-none text-[13px] placeholder:text-zinc-400"
                aria-label={t('imports.sessions.history.search_aria')}
              />
            </label>
            <SessionsFilterPills value={filter} onChange={setFilter} />
          </div>
        </div>
        {isLoading ? (
          <div
            className="rounded-2xl border border-zinc-100 bg-white px-5 py-10 text-center text-[13px] text-zinc-500"
            aria-busy="true"
          >
            {t('app.loading')}
          </div>
        ) : (
          <HistoryTable rows={history} total={total} />
        )}
      </section>
    </div>
  );
}

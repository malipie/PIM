import { useGetIdentity } from '@refinedev/core';
import { useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useLocation } from 'react-router';

import { toast } from '@/components/ui/toast';
import { jsonFetch, rawFetch } from '@/lib/http';

import { useExportSessions, useInvalidateExportSessions } from '../hooks/useExportSessions';
import { useExportSessionsStream } from '../hooks/useExportSessionsStream';
import { ActiveSessions, type LiveProgress } from './ActiveSessions';
import { HistoryTable } from './HistoryTable';
import { KpiStrip } from './KpiStrip';

const THIRTY_DAYS_MS = 30 * 24 * 60 * 60 * 1000;

interface Identity {
  id: string;
  name?: string;
  email?: string;
}

/**
 * EXR-08 (#1384) — Sessions tab in the v2 design (screen 1):
 * KPI strip → "W toku" (active sessions with progress) → "Historia"
 * (v2 table with search/segments/pagination). Data: one shared
 * sessions query; Mercure SSE triggers refetch (EXP-17 publisher),
 * 30 s polling fallback until EXR-15.
 */
export function ExportSessionsView(): React.ReactElement {
  const { t } = useTranslation();
  const { data: identity } = useGetIdentity<Identity>();
  const location = useLocation();
  const highlightId =
    (location.state as { highlightSession?: string } | null)?.highlightSession ?? null;

  const sessionsQuery = useExportSessions();
  const invalidate = useInvalidateExportSessions();

  const { lastEvent, connected } = useExportSessionsStream(identity?.id ?? null);
  const [liveProgress, setLiveProgress] = useState<Record<string, LiveProgress>>({});
  const lastTickRef = useRef<Record<string, { rows: number; at: number }>>({});
  useEffect(() => {
    if (lastEvent === null) return;
    if (lastEvent.event === 'progress') {
      // EXR-15 — smooth in-place progress without a REST round-trip;
      // throughput from the delta between consecutive ticks.
      const previous = lastTickRef.current[lastEvent.session_id];
      const now = Date.now();
      const rowsDone = lastEvent.rows_done ?? 0;
      const throughput =
        previous && now > previous.at
          ? ((rowsDone - previous.rows) / (now - previous.at)) * 1000
          : null;
      lastTickRef.current[lastEvent.session_id] = { rows: rowsDone, at: now };
      setLiveProgress((current) => ({
        ...current,
        [lastEvent.session_id]: {
          rowsDone,
          rowsTotal: lastEvent.rows_total ?? 0,
          throughput,
        },
      }));
      return;
    }
    // status events: completed/cancelled/error rows move to history.
    invalidate();
  }, [lastEvent, invalidate]);

  // EXR-15 — degrade to 5 s polling only while the hub is unreachable
  // (the shared query's 30 s refetch stays as the slow baseline).
  useEffect(() => {
    if (connected) return;
    // biome-ignore lint/suspicious/noConsole: spec EXR-15 — degradation is logged to the console, not surfaced in the UI
    console.info('exports: Mercure unavailable — falling back to 5s polling');
    const timer = setInterval(() => invalidate(), 5_000);
    return () => clearInterval(timer);
  }, [connected, invalidate]);

  const all = sessionsQuery.data?.items ?? [];
  const cutoff = Date.now() - THIRTY_DAYS_MS;
  const sessions = all.filter((session) => new Date(session.started_at).getTime() >= cutoff);
  const active = sessions.filter(
    (session) => session.status === 'running' || session.status === 'pending',
  );
  const history = sessions.filter(
    (session) => session.status === 'done' || session.status === 'error',
  );

  const onDownload = async (id: string) => {
    // window.open() drops the Authorization header (JWT-guarded endpoint
    // would 401 in the new tab) — fetch with Bearer + blob anchor instead.
    try {
      // #2743 — rawFetch keeps the raw Response (blob download) while riding
      // the shared 401 → refresh → replay path, so the first download after a
      // hard reload recovers instead of failing.
      const response = await rawFetch(`/api/exports/sessions/${id}/download`, {
        method: 'GET',
        headers: {
          accept:
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet, text/csv, application/json',
        },
      });
      if (!response.ok) {
        throw new Error(`Download failed: HTTP ${response.status}`);
      }
      const blob = await response.blob();
      const filename =
        parseFilename(response.headers.get('content-disposition')) ?? `pim-export-${id}`;
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = filename;
      document.body.appendChild(anchor);
      anchor.click();
      document.body.removeChild(anchor);
      setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch {
      toast.error(t('exports.history.download_failed'));
    }
  };

  const onRerun = async (id: string) => {
    try {
      await jsonFetch(`/api/exports/sessions/${id}/rerun`, {
        method: 'POST',
        accept: 'application/json',
      });
      invalidate();
    } catch {
      toast.error(t('exports.history.rerun_failed'));
    }
  };

  const onDelete = async (id: string) => {
    if (!window.confirm(t('exports.sessions.confirm_delete'))) {
      return;
    }
    try {
      await jsonFetch(`/api/exports/sessions/${id}`, {
        method: 'DELETE',
        accept: 'application/json',
      });
      invalidate();
    } catch {
      toast.error(t('exports.history.delete_failed'));
    }
  };

  if (sessionsQuery.isLoading) {
    return (
      <div className="space-y-4" aria-busy="true">
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-4">
          {[0, 1, 2, 3].map((index) => (
            <div key={index} className="h-28 animate-pulse rounded-2xl bg-zinc-100" />
          ))}
        </div>
        <div className="h-40 animate-pulse rounded-2xl bg-zinc-100" />
      </div>
    );
  }

  return (
    <div className="space-y-6">
      <KpiStrip sessions={sessions} />
      <ActiveSessions
        sessions={active}
        highlightId={highlightId}
        liveProgress={liveProgress}
        onCancelled={invalidate}
      />
      <HistoryTable
        sessions={history}
        userName={identity?.name ?? identity?.email ?? '—'}
        onDownload={(id) => void onDownload(id)}
        onRerun={(id) => void onRerun(id)}
        onDelete={(id) => void onDelete(id)}
      />
    </div>
  );
}

function parseFilename(header: string | null): string | null {
  if (header === null) return null;
  const match = /filename\*?=(?:UTF-8'')?"?([^";]+)"?/i.exec(header);
  return match?.[1] !== undefined ? decodeURIComponent(match[1]) : null;
}

export default ExportSessionsView;

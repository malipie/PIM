import { Upload } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { Button } from '@/components/ui/button';

import {
  FormatPill,
  type ImportStage,
  ModeBadge,
  ProgressBar,
  StagePipeline,
  StatusPill,
} from '../primitives';
import { type ImportSessionRow, processedRowsOf, type ThroughputResponse } from './types';

interface LiveSessionCardProps {
  session: ImportSessionRow;
  throughput?: ThroughputResponse;
}

function detectFormat(fileName: string): 'XLSX' | 'XLS' | 'CSV' | 'JSON' | 'XML' {
  const ext = fileName.toLowerCase().split('.').pop();
  if (ext === 'xlsx') return 'XLSX';
  if (ext === 'xls') return 'XLS';
  if (ext === 'json') return 'JSON';
  if (ext === 'xml') return 'XML';
  return 'CSV';
}

function progressOf(session: ImportSessionRow): number {
  const total = session.total_rows ?? 0;
  if (total === 0) {
    return 0;
  }
  return Math.min(1, processedRowsOf(session) / total);
}

function inferStage(session: ImportSessionRow): ImportStage {
  if (session.status === 'pending') return 'parsing';
  if (session.status === 'success' || session.status === 'partial' || session.status === 'failed') {
    return 'done';
  }
  const total = session.total_rows;
  if (total === null || total === 0) {
    return 'parsing';
  }
  const ratio = processedRowsOf(session) / total;
  if (ratio < 0.05) return 'parsing';
  if (ratio < 0.5) return 'validating';
  return 'writing';
}

function formatBytes(bytes?: number): string {
  if (bytes == null) {
    return '';
  }
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / 1024 / 1024).toFixed(2)} MB`;
}

function formatDuration(seconds: number | null): string {
  if (seconds === null) {
    return '—';
  }
  if (seconds < 60) {
    return `${seconds}s`;
  }
  const minutes = Math.floor(seconds / 60);
  const remainder = seconds % 60;
  return `${minutes}m ${remainder}s`;
}

export function LiveSessionCard({ session, throughput }: LiveSessionCardProps) {
  const { t } = useTranslation();
  const progress = progressOf(session);
  const pct = Math.round(progress * 100);
  const stage = inferStage(session);
  const startedAt = session.started_at
    ? new Intl.DateTimeFormat('pl-PL', { dateStyle: 'short', timeStyle: 'short' }).format(
        new Date(session.started_at),
      )
    : '—';
  const elapsed = session.started_at
    ? Math.max(1, Math.floor((Date.now() - new Date(session.started_at).getTime()) / 1000))
    : null;
  const rowsPerSec = throughput?.rows_per_sec ?? 0;

  return (
    <article className="rounded-2xl border border-zinc-100 bg-white p-5 soft-shadow">
      <div className="grid grid-cols-1 lg:grid-cols-[2fr_1fr] gap-6">
        <div className="min-w-0">
          <div className="flex items-start gap-3">
            <div className="h-10 w-10 rounded-xl bg-emerald-50 text-emerald-700 grid place-items-center shrink-0">
              <Upload className="h-5 w-5" aria-hidden="true" />
            </div>
            <div className="min-w-0 flex-1">
              <div className="flex items-center gap-2 flex-wrap">
                <div className="font-mono text-[13.5px] font-medium text-zinc-900 truncate">
                  {session.file_name}
                </div>
                <FormatPill format={detectFormat(session.file_name)} />
                {session.file_size_bytes ? (
                  <span className="text-[11.5px] text-zinc-500 num">
                    {formatBytes(session.file_size_bytes)}
                  </span>
                ) : null}
                <ModeBadge mode={session.mode ?? 'UPDATE'} />
                <StatusPill
                  status="running"
                  label={t('imports.sessions.live.in_progress', { stage })}
                />
              </div>
              <div className="text-[12px] text-zinc-500 mt-1 truncate">
                {session.profile_name ? (
                  <>
                    {t('imports.sessions.live.profile')}{' '}
                    <span className="font-mono text-zinc-700">{session.profile_name}</span>
                    <span className="mx-2 text-zinc-300">·</span>
                  </>
                ) : null}
                {t('imports.sessions.live.started_at')}{' '}
                <span className="text-zinc-700">{startedAt}</span>
              </div>
            </div>
            <Button asChild variant="outline" size="sm">
              <Link to={`/integrations/imports/${session.id}`}>
                {t('imports.sessions.live.open')}
              </Link>
            </Button>
          </div>

          <div className="mt-5">
            <StagePipeline stage={stage} />
          </div>

          <div className="mt-5">
            <div className="flex items-baseline justify-between mb-1.5">
              <div className="flex items-baseline gap-2">
                <span className="font-display text-[20px] font-semibold tracking-tight num">
                  {processedRowsOf(session).toLocaleString('pl-PL')}
                </span>
                <span className="text-[12px] text-zinc-500">
                  {t('imports.sessions.live.of_rows', {
                    total: session.total_rows?.toLocaleString('pl-PL') ?? '—',
                  })}
                </span>
                <span className="text-[12px] text-zinc-500">·</span>
                <span className="text-[12px] num text-zinc-700 font-medium">{pct}%</span>
              </div>
              <div
                className="text-[11.5px] text-zinc-500 flex items-center gap-3"
                aria-live="polite"
              >
                <span>
                  {t('imports.sessions.live.rate')}{' '}
                  <span className="font-mono text-zinc-800">{rowsPerSec.toFixed(0)}/s</span>
                </span>
                <span className="h-3 w-px bg-zinc-200" />
                <span>
                  {t('imports.sessions.live.elapsed')}{' '}
                  <span className="font-mono text-zinc-800">{formatDuration(elapsed)}</span>
                </span>
              </div>
            </div>
            <ProgressBar
              value={progress}
              height={10}
              animated
              ariaLabel={t('imports.sessions.live.progress_aria', { pct })}
            />
          </div>
        </div>

        <div className="flex flex-col gap-3">
          <div className="grid grid-cols-2 gap-2">
            <div className="rounded-xl bg-amber-50/60 border border-amber-100 p-3">
              <div className="flex items-center gap-1.5">
                <span className="h-2 w-2 rounded-full bg-amber-500" />
                <span className="text-[11px] uppercase tracking-wider text-amber-700 font-semibold">
                  {t('imports.sessions.live.warnings')}
                </span>
              </div>
              <div className="font-display text-[24px] font-semibold tracking-tight text-amber-900 num mt-0.5">
                {session.error_count > 0 && session.status === 'partial' ? session.error_count : 0}
              </div>
            </div>
            <div className="rounded-xl bg-rose-50/60 border border-rose-100 p-3">
              <div className="flex items-center gap-1.5">
                <span className="h-2 w-2 rounded-full bg-rose-500" />
                <span className="text-[11px] uppercase tracking-wider text-rose-700 font-semibold">
                  {t('imports.sessions.live.errors')}
                </span>
              </div>
              <div className="font-display text-[24px] font-semibold tracking-tight text-rose-900 num mt-0.5">
                {session.status === 'failed' || session.status === 'partial'
                  ? session.error_count
                  : 0}
              </div>
            </div>
          </div>
        </div>
      </div>
    </article>
  );
}

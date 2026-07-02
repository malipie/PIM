import { useQuery } from '@tanstack/react-query';
import { AlertTriangle, BadgeCheck, FileCode2, HeartPulse, Sparkles } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Segmented } from '@/features/api-configurator/components/primitives';
import { jsonFetch } from '@/lib/http';
import { cn } from '@/lib/utils';

import { CopyButton } from '../../components/primitives';

interface PreviewResponse {
  sample_count: number;
  xml: string;
  health: Array<{ sku: string | null; slot: string; level: string; message: string }>;
}

interface HealthResponse {
  items_syndicated: number | null;
  last_run: {
    item_count: number;
    skipped_count: number;
    warning_count: number;
    health: string;
  } | null;
}

/**
 * XMLF-P5-04 — wizard step 5 (design feed-preview.jsx): the left column
 * renders the REAL sample XML from GET /api/feeds/{id}/preview (the same
 * engine production uses — §6.11 "what you see == what the crawler gets")
 * with a formatted/raw toggle and a well-formed badge; the right column is
 * the health report from the preview response plus the full-catalog
 * projection from the last run, and the Faza-2 AI-suggestions CTA
 * (deliberately disabled).
 */
export function StepPreview({ feedId, rootPath }: { feedId: string | null; rootPath: string }) {
  const { t } = useTranslation();
  const [mode, setMode] = useState<'formatted' | 'raw'>('formatted');

  const preview = useQuery({
    queryKey: ['xmlf', 'feed-preview', feedId ?? 'none'],
    enabled: feedId !== null,
    queryFn: async () => jsonFetch<PreviewResponse>(`/api/feeds/${feedId}/preview?limit=5`),
  });
  const projection = useQuery({
    queryKey: ['xmlf', 'feed-health', feedId ?? 'none'],
    enabled: feedId !== null,
    queryFn: async () => jsonFetch<HealthResponse>(`/api/feeds/${feedId}/health`),
  });

  const xml = preview.data?.xml ?? '';
  const wellFormed = useMemo(() => {
    if (xml === '') {
      return false;
    }
    return (
      new DOMParser().parseFromString(xml, 'application/xml').querySelector('parsererror') === null
    );
  }, [xml]);

  const health = preview.data?.health ?? [];
  const lastRun = projection.data?.last_run ?? null;

  return (
    <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-[1.4fr_1fr]">
      <div className="overflow-hidden rounded-3xl bg-white soft-shadow">
        <div className="flex flex-wrap items-center gap-2.5 border-b border-zinc-100 px-5 py-3">
          <span className="grid h-7 w-7 place-items-center rounded-xl bg-zinc-100 text-zinc-700">
            <FileCode2 className="h-4 w-4" aria-hidden />
          </span>
          <div className="text-[14.5px] font-semibold tracking-tight">
            {t('api_configurator.feeds.preview.title', { count: preview.data?.sample_count ?? 0 })}
          </div>
          {wellFormed && (
            <span className="inline-flex items-center gap-1 rounded-md bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700">
              <BadgeCheck className="h-3.5 w-3.5" aria-hidden />
              {t('api_configurator.feeds.preview.well_formed')}
            </span>
          )}
          <div className="ml-auto flex items-center gap-2">
            <Segmented<'formatted' | 'raw'>
              value={mode}
              onChange={setMode}
              ariaLabel={t('api_configurator.feeds.preview.mode_aria')}
              options={[
                { value: 'formatted', label: t('api_configurator.feeds.preview.formatted') },
                { value: 'raw', label: t('api_configurator.feeds.preview.raw') },
              ]}
            />
            <CopyButton value={xml} disabled={xml === ''} />
          </div>
        </div>
        <div className="max-h-[520px] overflow-auto bg-zinc-950 p-4">
          {preview.isLoading ? (
            <div className="h-40 animate-pulse rounded-xl bg-zinc-800" />
          ) : mode === 'raw' ? (
            <pre className="whitespace-pre-wrap break-all font-mono text-[11.5px] leading-relaxed text-zinc-200">
              {xml}
            </pre>
          ) : (
            <FormattedXml xml={xml} />
          )}
        </div>
        <div className="border-t border-zinc-100 px-5 py-2 font-mono text-[11px] text-zinc-500">
          {rootPath}
        </div>
      </div>

      <div className="space-y-4">
        <div className="rounded-3xl bg-white p-5 soft-shadow">
          <div className="mb-3 flex items-center gap-2.5">
            <span className="grid h-7 w-7 place-items-center rounded-xl bg-zinc-100 text-zinc-700">
              <HeartPulse className="h-4 w-4" aria-hidden />
            </span>
            <div className="text-[14.5px] font-semibold tracking-tight">
              {t('api_configurator.feeds.preview.health_title')}
            </div>
          </div>
          {health.length === 0 ? (
            <p className="text-[12.5px] text-emerald-700">
              {t('api_configurator.feeds.preview.health_clean')}
            </p>
          ) : (
            <ul className="space-y-1.5">
              {health.slice(0, 20).map((line, index) => (
                <li
                  // biome-ignore lint/suspicious/noArrayIndexKey: preview lines are a static snapshot per response
                  key={`${line.sku}-${line.slot}-${index}`}
                  className="flex items-start gap-2 text-[12px]"
                >
                  <span
                    className={cn(
                      'mt-1 h-2 w-2 shrink-0 rounded-full',
                      line.level === 'error' ? 'bg-rose-500' : 'bg-amber-400',
                    )}
                    aria-hidden
                  />
                  <span className="min-w-0">
                    <span className="font-mono text-[11px] text-zinc-500">
                      {line.sku ?? '—'} · {line.slot}
                    </span>{' '}
                    <span className="text-zinc-700">{line.message}</span>
                  </span>
                </li>
              ))}
            </ul>
          )}
          {health.length > 20 && (
            <p className="mt-2 text-[11.5px] text-zinc-500">
              {t('api_configurator.feeds.preview.health_more', { count: health.length - 20 })}
            </p>
          )}
        </div>

        <div className="rounded-3xl bg-white p-5 soft-shadow">
          <div className="text-[13px] font-semibold tracking-tight">
            {t('api_configurator.feeds.preview.projection_title')}
          </div>
          {lastRun !== null ? (
            <div className="mt-2 grid grid-cols-3 gap-2 text-center">
              <div className="rounded-xl bg-zinc-50 px-2 py-2">
                <div className="num text-[16px] font-semibold text-zinc-900">
                  {lastRun.item_count.toLocaleString('pl-PL')}
                </div>
                <div className="text-[10px] uppercase tracking-wider text-zinc-500">
                  {t('api_configurator.feeds.preview.projection_items')}
                </div>
              </div>
              <div className="rounded-xl bg-zinc-50 px-2 py-2">
                <div className="num text-[16px] font-semibold text-amber-700">
                  {lastRun.skipped_count.toLocaleString('pl-PL')}
                </div>
                <div className="text-[10px] uppercase tracking-wider text-zinc-500">
                  {t('api_configurator.feeds.preview.projection_skipped')}
                </div>
              </div>
              <div className="rounded-xl bg-zinc-50 px-2 py-2">
                <div className="num text-[16px] font-semibold text-zinc-700">
                  {lastRun.warning_count.toLocaleString('pl-PL')}
                </div>
                <div className="text-[10px] uppercase tracking-wider text-zinc-500">
                  {t('api_configurator.feeds.preview.projection_warnings')}
                </div>
              </div>
            </div>
          ) : (
            <p className="mt-2 text-[12.5px] text-zinc-500">
              {t('api_configurator.feeds.preview.projection_none')}
            </p>
          )}
        </div>

        <button
          type="button"
          disabled
          title={t('api_configurator.feeds.preview.ai_soon')}
          className="flex w-full cursor-not-allowed items-center gap-2 rounded-2xl border border-dashed border-zinc-200 bg-zinc-50/60 px-4 py-3 text-left text-[12.5px] text-zinc-400"
        >
          <Sparkles className="h-4 w-4" aria-hidden />
          {t('api_configurator.feeds.preview.ai_cta')}
        </button>

        <div className="flex items-start gap-2.5 rounded-2xl border border-zinc-200 bg-white px-4 py-3 text-[12px] leading-relaxed text-zinc-600">
          <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0 text-zinc-500" aria-hidden />
          <span>{t('api_configurator.feeds.preview.well_formed_note')}</span>
        </div>
      </div>
    </div>
  );
}

/** Tiny tag-level highlighter — enough for a readable preview, no deps. */
function FormattedXml({ xml }: { xml: string }) {
  const parts = useMemo(() => xml.split(/(<[^>]+>)/g).filter((part) => part !== ''), [xml]);
  return (
    <pre className="whitespace-pre-wrap break-all font-mono text-[11.5px] leading-relaxed text-zinc-200">
      {parts.map((part, index) =>
        part.startsWith('<') ? (
          // biome-ignore lint/suspicious/noArrayIndexKey: static split of an immutable string
          <span key={index} className="text-violet-300">
            {part}
          </span>
        ) : (
          // biome-ignore lint/suspicious/noArrayIndexKey: static split of an immutable string
          <span key={index} className="text-emerald-200">
            {part}
          </span>
        ),
      )}
    </pre>
  );
}

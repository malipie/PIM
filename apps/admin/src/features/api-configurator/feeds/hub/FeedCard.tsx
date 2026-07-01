import { Link2, Pause, Play, RefreshCw, Rss } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';
import { useToast } from '@/components/ui/toast';
import { ConnStatusPill } from '@/features/api-configurator/components/primitives';
import { httpErrorDetail } from '@/lib/http';
import { cn } from '@/lib/utils';

import {
  computeCoverage,
  type FeedRow,
  usePauseFeed,
  useRegenerateFeed,
  useResumeFeed,
  useRotateToken,
} from '../api/feeds';
import { CopyButton, CoverageBar, TemplateBadge } from '../components/primitives';

const TEMPLATE_ICON_TONES: Record<string, string> = {
  google_shopping: 'bg-sky-50 text-sky-600',
  ceneo: 'bg-emerald-50 text-emerald-600',
  meta: 'bg-violet-50 text-violet-600',
  custom: 'bg-zinc-100 text-zinc-600',
};

/**
 * XMLF-P5-01 — one configured feed (design feed-hub.jsx FeedCard): identity +
 * template badge, the public URL row (the plaintext token URL exists only
 * right after a mint/rotate — the backend stores a hash, so before rotation
 * the row shows a masked hint), metric grid and the action footer.
 */
export function FeedCard({ feed }: { feed: FeedRow }) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const toast = useToast();
  const regenerate = useRegenerateFeed();
  const pause = usePauseFeed();
  const resume = useResumeFeed();
  const rotate = useRotateToken();
  const [mintedUrl, setMintedUrl] = useState<string | null>(null);

  const coverage = useMemo(
    () => computeCoverage(feed.descriptor, feed.field_mappings),
    [feed.descriptor, feed.field_mappings],
  );

  const url = mintedUrl !== null ? absoluteUrl(mintedUrl) : null;
  const iconTone =
    feed.status === 'error'
      ? 'bg-rose-50 text-rose-600'
      : feed.status === 'paused'
        ? 'bg-zinc-100 text-zinc-500'
        : (TEMPLATE_ICON_TONES[feed.template_kind] ?? TEMPLATE_ICON_TONES.custom);

  function onRotate(): void {
    rotate.mutate(feed.id, {
      onSuccess: (minted) => {
        setMintedUrl(minted.url);
        toast.success(t('api_configurator.feeds.card.rotate_success'));
      },
      onError: (error) => {
        toast.error(httpErrorDetail(error) ?? t('api_configurator.feeds.card.action_error'));
      },
    });
  }

  function onRegenerate(): void {
    regenerate.mutate(feed.id, {
      onSuccess: () => toast.success(t('api_configurator.feeds.card.regenerate_started')),
      onError: (error) => {
        toast.error(httpErrorDetail(error) ?? t('api_configurator.feeds.card.action_error'));
      },
    });
  }

  function onToggle(): void {
    const action = feed.status === 'paused' ? resume : pause;
    action.mutate(feed.id, {
      onError: (error) => {
        toast.error(httpErrorDetail(error) ?? t('api_configurator.feeds.card.action_error'));
      },
    });
  }

  return (
    <div className="group rounded-3xl bg-white p-5 soft-shadow transition hover:soft-shadow-lg">
      <div className="flex items-start gap-3">
        <div className={cn('grid h-11 w-11 shrink-0 place-items-center rounded-xl', iconTone)}>
          <Rss className="h-5 w-5" aria-hidden />
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <div className="text-[15px] font-semibold tracking-tight">{feed.name}</div>
            <TemplateBadge kind={feed.template_kind} />
          </div>
          <div className="mt-1 flex items-center gap-1.5 text-[11.5px] text-zinc-500">
            <span className="font-mono">{feed.code}</span>
            {feed.locale !== null && (
              <>
                <span className="text-zinc-300">·</span>
                <span className="font-mono uppercase">{feed.locale}</span>
              </>
            )}
            {feed.publication_channel !== null && (
              <>
                <span className="text-zinc-300">·</span>
                <span>{feed.publication_channel}</span>
              </>
            )}
          </div>
        </div>
        <ConnStatusPill
          status={feed.status}
          label={t(`api_configurator.feeds.status.${feed.status}`)}
        />
      </div>

      <div className="mt-4 flex items-center gap-2">
        <Link2 className="h-4 w-4 shrink-0 text-zinc-500" aria-hidden />
        <code className="min-w-0 flex-1 truncate rounded-lg border border-zinc-100 bg-zinc-50 px-2.5 py-1.5 font-mono text-[11.5px] text-zinc-600">
          {url ??
            t(
              feed.has_token
                ? 'api_configurator.feeds.card.url_masked'
                : 'api_configurator.feeds.card.url_none',
            )}
        </code>
        <CopyButton value={url ?? ''} disabled={url === null} />
        <button
          type="button"
          onClick={onRotate}
          disabled={rotate.isPending}
          aria-label={t('api_configurator.feeds.card.rotate')}
          title={t('api_configurator.feeds.card.rotate')}
          className="grid h-7 w-7 place-items-center rounded-lg border border-zinc-200 bg-white text-zinc-500 transition hover:text-zinc-700 disabled:opacity-40"
        >
          <RefreshCw
            className={cn('h-3.5 w-3.5', rotate.isPending && 'animate-spin')}
            aria-hidden
          />
        </button>
      </div>

      <div className="mt-4 grid grid-cols-2 gap-x-5 gap-y-3 text-[12px]">
        <div>
          <div className="text-[10px] uppercase tracking-wider text-zinc-500">
            {t('api_configurator.feeds.card.items')}
          </div>
          <div className="num mt-1 font-semibold text-zinc-900">
            {feed.cached_item_count === null ? '—' : feed.cached_item_count.toLocaleString('pl-PL')}
          </div>
        </div>
        <div>
          <div className="text-[10px] uppercase tracking-wider text-zinc-500">
            {t('api_configurator.feeds.card.schedule')}
          </div>
          <div className="mt-1 font-mono text-[11px] text-zinc-700">
            {feed.schedule_cron ?? t('api_configurator.feeds.card.schedule_manual')}
          </div>
        </div>
        <div>
          <div className="text-[10px] uppercase tracking-wider text-zinc-500">
            {t('api_configurator.feeds.card.coverage')}
          </div>
          <div className="mt-1.5">
            <CoverageBar mapped={coverage.mapped} total={coverage.total} />
          </div>
        </div>
        <div>
          <div className="text-[10px] uppercase tracking-wider text-zinc-500">
            {t('api_configurator.feeds.card.last_pull')}
          </div>
          <div className="mt-1 text-zinc-700">
            {feed.last_pulled_at !== null
              ? new Date(feed.last_pulled_at).toLocaleString('pl-PL')
              : '—'}
          </div>
        </div>
      </div>

      <div className="mt-4 flex items-center gap-3 border-t border-zinc-100 pt-3">
        <div className="min-w-0 flex-1 text-[11.5px]">
          <span className="mr-1 text-[10px] uppercase tracking-wider text-zinc-500">
            {t('api_configurator.feeds.card.last_regen')}
          </span>
          <span className="text-zinc-700">
            {feed.cached_at !== null ? new Date(feed.cached_at).toLocaleString('pl-PL') : '—'}
          </span>
        </div>
        <button
          type="button"
          onClick={onRegenerate}
          disabled={regenerate.isPending}
          className="flex h-8 items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-2.5 text-[12px] font-medium text-zinc-700 hover:bg-zinc-50 disabled:opacity-40"
        >
          <RefreshCw
            className={cn('h-3.5 w-3.5', regenerate.isPending && 'animate-spin')}
            aria-hidden
          />
          {t('api_configurator.feeds.card.regenerate')}
        </button>
        <button
          type="button"
          onClick={onToggle}
          disabled={pause.isPending || resume.isPending}
          aria-label={t(
            feed.status === 'paused'
              ? 'api_configurator.feeds.card.resume'
              : 'api_configurator.feeds.card.pause',
          )}
          title={t(
            feed.status === 'paused'
              ? 'api_configurator.feeds.card.resume'
              : 'api_configurator.feeds.card.pause',
          )}
          className={cn(
            'grid h-8 w-8 place-items-center rounded-lg border border-zinc-200 bg-white disabled:opacity-40',
            feed.status === 'paused'
              ? 'text-emerald-600 hover:bg-emerald-50'
              : 'text-zinc-500 hover:bg-zinc-50',
          )}
        >
          {feed.status === 'paused' ? (
            <Play className="h-3.5 w-3.5" aria-hidden />
          ) : (
            <Pause className="h-3.5 w-3.5" aria-hidden />
          )}
        </button>
        <button
          type="button"
          onClick={() => void navigate(`/integrations/api-configurator/feeds/${feed.id}`)}
          className="h-8 rounded-lg bg-zinc-900 px-3 text-[12px] font-medium text-white hover:bg-zinc-800"
        >
          {t('api_configurator.feeds.card.open')}
        </button>
      </div>
    </div>
  );
}

function absoluteUrl(path: string): string {
  return path.startsWith('http') ? path : `${window.location.origin}${path}`;
}

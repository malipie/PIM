import { ArrowRight, Download, Plus, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';

import { TopbarCta } from '@/components/ui-v2/topbar-cta';
import { Segmented } from '@/features/api-configurator/components/primitives';
import { usePageActions } from '@/layout/page-actions-context';

import { type FeedStatus, filterFeeds, useFeedKpi, useFeeds } from '../api/feeds';
import { FeedsSubnav } from '../components/FeedsSubnav';
import { FeedCard } from './FeedCard';
import { FeedKpiStrip } from './FeedKpiStrip';

type StatusFilter = 'all' | FeedStatus;

/**
 * XMLF-P5-01 — the feeds hub (design feed-hub.jsx FeedHub): KPI strip, search
 * + status filter toolbar, the feed card grid with the NewFeedCard CTA and the
 * ad-hoc XML note pointing at the export wizard (mode 2 — one-off dump).
 */
export function FeedsHubPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const feedsQuery = useFeeds();
  const kpiQuery = useFeedKpi();
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<StatusFilter>('all');

  const feeds = feedsQuery.data ?? [];
  const filtered = useMemo(() => filterFeeds(feeds, search, status), [feeds, search, status]);

  usePageActions(
    useMemo(
      () => (
        <TopbarCta to="/integrations/api-configurator/feeds/new">
          {t('api_configurator.feeds.hub.new_feed')}
        </TopbarCta>
      ),
      [t],
    ),
  );

  return (
    <div className="space-y-6">
      <FeedsSubnav active="hub" />
      <FeedKpiStrip kpi={kpiQuery.data} />

      <div className="flex flex-wrap items-center gap-3">
        <h1 className="font-display text-[16px] font-semibold tracking-tight">
          {t('api_configurator.feeds.hub.title')}
        </h1>
        <span className="text-[12px] text-zinc-500">
          {t('api_configurator.feeds.hub.subtitle', { count: feeds.length })}
        </span>
        <div className="ml-auto flex items-center gap-2">
          <div className="flex h-9 items-center gap-2 rounded-xl bg-white pl-3 pr-2 soft-shadow">
            <Search className="h-4 w-4 text-zinc-500" aria-hidden />
            <input
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder={t('api_configurator.feeds.hub.search_placeholder')}
              aria-label={t('api_configurator.feeds.hub.search_aria')}
              className="w-48 bg-transparent text-[13px] outline-none placeholder:text-zinc-500"
            />
          </div>
          <Segmented<StatusFilter>
            value={status}
            onChange={setStatus}
            ariaLabel={t('api_configurator.feeds.hub.filter_aria')}
            options={[
              { value: 'all', label: t('api_configurator.feeds.hub.filter.all') },
              { value: 'active', label: t('api_configurator.feeds.hub.filter.active') },
              { value: 'paused', label: t('api_configurator.feeds.hub.filter.paused') },
              { value: 'error', label: t('api_configurator.feeds.hub.filter.error') },
            ]}
          />
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-2">
        {filtered.map((feed) => (
          <FeedCard key={feed.id} feed={feed} />
        ))}
        <button
          type="button"
          onClick={() => void navigate('/integrations/api-configurator/feeds/new')}
          className="group flex min-h-[240px] flex-col items-center justify-center rounded-3xl border-2 border-dashed border-zinc-200 p-5 text-zinc-500 transition hover:border-zinc-400 hover:bg-zinc-50/50 hover:text-zinc-900"
        >
          <div className="grid h-12 w-12 place-items-center rounded-2xl bg-zinc-100 transition group-hover:bg-zinc-900 group-hover:text-white">
            <Plus className="h-5 w-5" aria-hidden />
          </div>
          <div className="mt-3 text-[14px] font-medium">
            {t('api_configurator.feeds.hub.new_feed')}
          </div>
          <div className="mt-1 max-w-[280px] text-center text-[12px] leading-relaxed text-zinc-500">
            {t('api_configurator.feeds.hub.new_feed_hint')}
          </div>
        </button>
      </div>

      <Link
        to="/exports"
        className="group block rounded-2xl border border-zinc-200 bg-white px-5 py-4 transition hover:border-zinc-300 hover:bg-zinc-50/60"
      >
        <div className="flex items-center gap-4">
          <div className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-zinc-100 text-zinc-600">
            <Download className="h-4.5 w-4.5" aria-hidden />
          </div>
          <div className="min-w-0 flex-1">
            <div className="flex items-center gap-2">
              <div className="text-[13.5px] font-semibold tracking-tight">
                {t('api_configurator.feeds.hub.adhoc_title')}
              </div>
              <span className="rounded-md border border-zinc-300 px-1.5 py-0.5 font-mono text-[10.5px] text-zinc-600">
                XML
              </span>
            </div>
            <div className="mt-0.5 text-[12px] leading-relaxed text-zinc-500">
              {t('api_configurator.feeds.hub.adhoc_hint')}
            </div>
          </div>
          <ArrowRight
            className="h-4 w-4 shrink-0 text-zinc-300 transition group-hover:text-zinc-600"
            aria-hidden
          />
        </div>
      </Link>
    </div>
  );
}

import { useList } from '@refinedev/core';
import { Box, Filter, Globe, Info, Lock } from 'lucide-react';
import { lazy, Suspense } from 'react';
import { useTranslation } from 'react-i18next';

import type { FilterDslState } from '@/lib/filters/use-filter-dsl-state';

import { useTenantLocales } from '../../api/templates';

const AdvancedFilterPanel = lazy(() =>
  import('@/components/catalog/advanced-filter-panel').then((m) => ({
    default: m.AdvancedFilterPanel,
  })),
);

interface ChannelRow {
  id: string;
  code: string;
  name: string;
}

const CURRENCIES = ['PLN', 'EUR', 'USD', 'GBP', 'CZK'] as const;

/**
 * XMLF-P5-02 — wizard step 2 (design feed-wizard.jsx StepScope): the value
 * scope card (locked ObjectType, locale, currency, channel overlay + the
 * flat-variants note) and the assortment filter reusing the shared
 * AdvancedFilterPanel with a live preflight count. The publication-profile
 * select from the design is deliberately absent: the backend does not expose
 * ChannelPublicationProfile over the API yet — the select lands with that
 * endpoint (ADR-0018 follow-up).
 */
export function StepScope({
  locale,
  onLocale,
  currency,
  onCurrency,
  channelId,
  onChannelId,
  filterState,
  resultCount,
  countLoading,
}: {
  locale: string;
  onLocale: (value: string) => void;
  currency: string | null;
  onCurrency: (value: string | null) => void;
  channelId: string | null;
  onChannelId: (value: string | null) => void;
  filterState: FilterDslState;
  resultCount: number | null;
  countLoading: boolean;
}) {
  const { t } = useTranslation();
  const locales = useTenantLocales();
  const channels = useList<ChannelRow>({ resource: 'channels', pagination: { mode: 'off' } });

  const selectClass =
    'mt-1.5 h-10 w-full rounded-xl border border-zinc-200 bg-white px-3 text-[13px] outline-none focus:border-zinc-400';

  return (
    <div className="space-y-4">
      <div className="rounded-3xl bg-white p-6 soft-shadow">
        <div className="flex items-center gap-2.5">
          <span className="grid h-7 w-7 place-items-center rounded-xl bg-zinc-100 text-zinc-700">
            <Globe className="h-4 w-4" aria-hidden />
          </span>
          <div className="text-[14.5px] font-semibold tracking-tight">
            {t('api_configurator.feeds.wizard.scope.title')}
          </div>
          <span className="text-[11.5px] text-zinc-500">
            {t('api_configurator.feeds.wizard.scope.one_locale')}
          </span>
        </div>

        <div className="mt-4 grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <div>
            <span className="text-[12px] font-medium text-zinc-700">
              {t('api_configurator.feeds.wizard.scope.object_type')}
            </span>
            <div className="mt-1.5 flex h-10 items-center gap-2 rounded-xl border border-zinc-200 bg-zinc-50/60 px-3 text-[13px] text-zinc-600">
              <Box className="h-4 w-4 text-zinc-500" aria-hidden />
              {t('api_configurator.feeds.wizard.scope.product')}
              <Lock
                className="ml-auto h-3.5 w-3.5 text-zinc-400"
                aria-label={t('api_configurator.feeds.wizard.scope.locked')}
              />
            </div>
          </div>
          <label className="block">
            <span className="text-[12px] font-medium text-zinc-700">
              {t('api_configurator.feeds.wizard.scope.locale')}
              <span className="ml-0.5 text-rose-600">*</span>
            </span>
            <select
              value={locale}
              onChange={(event) => onLocale(event.target.value)}
              className={selectClass}
            >
              {(locales.data ?? []).map((row) => (
                <option key={row.code} value={row.code}>
                  {row.label} · {row.code}
                </option>
              ))}
              {locales.data?.some((row) => row.code === locale) !== true && (
                <option value={locale}>{locale}</option>
              )}
            </select>
          </label>
          <label className="block">
            <span className="text-[12px] font-medium text-zinc-700">
              {t('api_configurator.feeds.wizard.scope.currency')}
            </span>
            <select
              value={currency ?? ''}
              onChange={(event) =>
                onCurrency(event.target.value === '' ? null : event.target.value)
              }
              className={selectClass}
            >
              <option value="">{t('api_configurator.feeds.wizard.scope.none')}</option>
              {CURRENCIES.map((value) => (
                <option key={value} value={value}>
                  {value}
                </option>
              ))}
            </select>
          </label>
          <label className="block">
            <span className="text-[12px] font-medium text-zinc-700">
              {t('api_configurator.feeds.wizard.scope.channel')}
              <span className="ml-1.5 text-[11px] font-normal text-zinc-500">
                {t('api_configurator.feeds.wizard.scope.channel_hint')}
              </span>
            </span>
            <select
              value={channelId ?? ''}
              onChange={(event) =>
                onChannelId(event.target.value === '' ? null : event.target.value)
              }
              className={selectClass}
            >
              <option value="">{t('api_configurator.feeds.wizard.scope.none')}</option>
              {(channels.result?.data ?? []).map((channel) => (
                <option key={channel.id} value={channel.id}>
                  {channel.name} · {channel.code}
                </option>
              ))}
            </select>
          </label>
        </div>

        <div className="mt-4 flex items-start gap-2 rounded-xl border border-zinc-200 bg-zinc-50/60 px-3 py-2 text-[12px] leading-relaxed text-zinc-600">
          <Info className="mt-0.5 h-4 w-4 shrink-0 text-zinc-500" aria-hidden />
          <span>{t('api_configurator.feeds.wizard.scope.variants_note')}</span>
        </div>
      </div>

      <div className="rounded-3xl bg-white p-6 soft-shadow">
        <div className="flex flex-wrap items-center gap-2.5">
          <span className="grid h-7 w-7 place-items-center rounded-xl bg-zinc-100 text-zinc-700">
            <Filter className="h-4 w-4" aria-hidden />
          </span>
          <div className="text-[14.5px] font-semibold tracking-tight">
            {t('api_configurator.feeds.wizard.scope.assortment')}
          </div>
          <div className="ml-auto inline-flex h-8 items-center gap-2 rounded-full bg-emerald-50 px-3 text-[12px] font-medium text-emerald-800">
            <span className="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden />
            {countLoading
              ? t('api_configurator.feeds.wizard.scope.counting')
              : t('api_configurator.feeds.wizard.scope.in_feed', {
                  count: resultCount ?? 0,
                })}
          </div>
        </div>
        <div className="mt-1 text-[12px] text-zinc-500">
          {t('api_configurator.feeds.wizard.scope.filter_hint')}
        </div>
        <div className="mt-4">
          <Suspense fallback={<div className="h-24 animate-pulse rounded-xl bg-zinc-100" />}>
            <AdvancedFilterPanel
              open
              conditions={filterState.conditions}
              setConditions={filterState.setConditions}
              matchOperator={filterState.matchOperator}
              setMatchOperator={filterState.setMatchOperator}
              onApply={() => {}}
              onClose={() => {}}
              onClear={() => filterState.clear()}
              resultCount={resultCount ?? undefined}
            />
          </Suspense>
        </div>
      </div>
    </div>
  );
}

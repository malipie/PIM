import { useQuery } from '@tanstack/react-query';
import { Globe2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { type FilterScope, normalizeScope } from '@/lib/filters/filter-dsl';
import { jsonFetch } from '@/lib/http';

interface ScopeChannelOption {
  code: string;
  name?: string | null;
}

interface ScopeLocaleRow {
  code: string;
  /** Short language code (`pl`) — matches `ObjectValue.locale` on the BE. */
  language?: string;
  isActive?: boolean;
}

interface AdvancedFilterScopeBarProps {
  scope: FilterScope | null;
  onScopeChange: (scope: FilterScope | null) => void;
}

/**
 * #2673 — value-context bar of the advanced filter panel: the channel/locale
 * every condition is evaluated against (with fallback to the global value).
 * Split out of `advanced-filter-panel.tsx` for the admin max-lines guard.
 */
export function AdvancedFilterScopeBar({ scope, onScopeChange }: AdvancedFilterScopeBarProps) {
  const { t } = useTranslation();

  const { data: scopeChannels } = useQuery({
    queryKey: ['channels', 'filter-scope'],
    staleTime: 60_000,
    queryFn: async (): Promise<ScopeChannelOption[]> => {
      const response = await jsonFetch<{ member?: ScopeChannelOption[] } | ScopeChannelOption[]>(
        '/api/channels',
        { accept: 'application/ld+json' },
      );
      return Array.isArray(response) ? response : (response.member ?? []);
    },
  });
  const { data: scopeLocales } = useQuery({
    queryKey: ['tenant-locales', 'filter-scope'],
    staleTime: 60_000,
    queryFn: async (): Promise<string[]> => {
      const response = await jsonFetch<{ items?: ScopeLocaleRow[] }>('/api/tenant-locales', {
        accept: 'application/json',
      });
      // Short language codes (`pl`), deduped — `ObjectValue.locale` and the
      // BE scope validation both speak short codes, not full `pl_PL`.
      return Array.from(
        new Set(
          (response.items ?? [])
            .filter((row) => row.isActive !== false)
            .map((row) => row.language ?? row.code.split('_')[0] ?? row.code),
        ),
      );
    },
  });

  return (
    <div className="px-5 h-11 flex items-center gap-3 border-b border-zinc-100 bg-zinc-50/60">
      <Globe2 className="size-3.5 text-zinc-500" aria-hidden />
      <span className="text-[11px] uppercase tracking-wider font-semibold text-zinc-500">
        {t('products.advanced_filter.scope_label', { defaultValue: 'Kontekst' })}
      </span>
      <label className="flex items-center gap-1.5 text-[12px] text-zinc-600">
        {t('products.advanced_filter.scope_channel', { defaultValue: 'Kanał' })}
        <select
          value={scope?.channel ?? ''}
          onChange={(e) =>
            onScopeChange(normalizeScope({ ...scope, channel: e.target.value || undefined }))
          }
          aria-label={t('products.advanced_filter.scope_channel_aria', {
            defaultValue: 'Kanał kontekstu filtra',
          })}
          className="h-8 px-2 text-[12.5px] bg-white border border-zinc-200 rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-zinc-900 min-w-[130px]"
        >
          <option value="">
            {t('products.advanced_filter.scope_global', { defaultValue: '(globalny)' })}
          </option>
          {(scopeChannels ?? []).map((channel) => (
            <option key={channel.code} value={channel.code}>
              {channel.name ?? channel.code}
            </option>
          ))}
        </select>
      </label>
      <label className="flex items-center gap-1.5 text-[12px] text-zinc-600">
        {t('products.advanced_filter.scope_locale', { defaultValue: 'Język' })}
        <select
          value={scope?.locale ?? ''}
          onChange={(e) =>
            onScopeChange(normalizeScope({ ...scope, locale: e.target.value || undefined }))
          }
          aria-label={t('products.advanced_filter.scope_locale_aria', {
            defaultValue: 'Język kontekstu filtra',
          })}
          className="h-8 px-2 text-[12.5px] bg-white border border-zinc-200 rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-zinc-900 min-w-[110px] font-mono uppercase"
        >
          <option value="">
            {t('products.advanced_filter.scope_global', { defaultValue: '(globalny)' })}
          </option>
          {(scopeLocales ?? []).map((code) => (
            <option key={code} value={code}>
              {code}
            </option>
          ))}
        </select>
      </label>
      {/* zinc-500 (not 400) — 11px text must clear WCAG AA contrast on the
          zinc-50 bar (exr-16 full-page axe gate). */}
      <span className="text-[11px] text-zinc-500">
        {t('products.advanced_filter.scope_hint', {
          defaultValue: 'Warunki liczone na wartościach tego kontekstu (fallback: globalne).',
        })}
      </span>
    </div>
  );
}

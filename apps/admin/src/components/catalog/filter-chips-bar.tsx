import { Globe2, Link2, X } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { toast } from '@/components/ui/toast';
import type { FilterCondition, FilterScope } from '@/lib/filters/filter-dsl';
import { cn } from '@/lib/utils';

/**
 * VIEW-09 (#535) — Filter chips area pod toolbar.
 *
 * Mockup `list-view-v2.jsx` l. 206-219. Każdy chip pokazuje
 * `Label op value` w czarnym pillu z ✕ kasującym condition.
 *
 * Edycja operator + value przez popover docelowo dostarcza
 * `FilterChip` z popoverem; w VIEW-09 redagowanie idzie przez
 * Advanced filter panel (jeden flow), chip body click otwiera panel
 * (callback `onEditChip`). Inline popover edit → VIEW-09b.
 */

interface FilterChipsBarProps {
  chips: FilterCondition[];
  attrLabelMap: Record<string, string>;
  onRemove: (index: number) => void;
  onClearAll: () => void;
  onEditChip?: (index: number) => void;
  /** #2673 — active value context; renders a leading scope chip. */
  scope?: FilterScope | null;
  onClearScope?: () => void;
}

export function FilterChipsBar({
  chips,
  attrLabelMap,
  onRemove,
  onClearAll,
  onEditChip,
  scope,
  onClearScope,
}: FilterChipsBarProps) {
  const { t } = useTranslation();
  if (chips.length === 0) return null;

  const copyUrl = (): void => {
    if (typeof window === 'undefined' || !navigator?.clipboard) return;
    void navigator.clipboard
      .writeText(window.location.href)
      .then(() => {
        toast.success(
          t('products.filter_chips.copy_url_success', {
            defaultValue: 'URL z filtrami skopiowany',
          }),
        );
      })
      .catch(() => {
        toast.error(
          t('products.filter_chips.copy_url_error', {
            defaultValue: 'Nie udało się skopiować URL — zaznacz pasek adresu ręcznie',
          }),
        );
      });
  };

  return (
    <section
      aria-label={t('products.filter_chips.active_label', { defaultValue: 'Aktywne filtry' })}
      className="flex items-center gap-2 flex-wrap"
    >
      <span className="text-[10.5px] uppercase tracking-wider font-semibold text-zinc-500">
        {t('products.filter_chips.active_label', { defaultValue: 'Aktywne filtry' })}
      </span>
      {(scope?.channel || scope?.locale) && (
        <span className="h-9 pl-3 pr-1.5 rounded-2xl bg-white border border-zinc-300 text-zinc-700 text-[12.5px] font-medium inline-flex items-center gap-1.5">
          <Globe2 className="size-3.5 text-zinc-500" aria-hidden />
          <span>
            {[
              scope.channel
                ? `${t('products.advanced_filter.scope_channel', { defaultValue: 'Kanał' })}: ${scope.channel}`
                : null,
              scope.locale ? scope.locale.toUpperCase() : null,
            ]
              .filter(Boolean)
              .join(' · ')}
          </span>
          {onClearScope && (
            <button
              type="button"
              aria-label={t('products.advanced_filter.scope_clear', {
                defaultValue: 'Wyczyść kontekst',
              })}
              onClick={onClearScope}
              className="ml-1 h-5 w-5 rounded-full hover:bg-zinc-100 grid place-items-center text-zinc-500 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900"
            >
              <X className="size-3" />
            </button>
          )}
        </span>
      )}
      {chips.map((chip, i) => {
        const label = attrLabelMap[chip.attr] ?? chip.attr;
        const valueDisplay = formatValue(chip);
        const chipKey = `${chip.attr}-${chip.op}-${i}`;
        return (
          <span
            key={chipKey}
            className={cn(
              'h-9 pl-3 pr-1.5 rounded-2xl bg-zinc-900 text-white text-[12.5px] font-medium inline-flex items-center gap-1.5',
              !onEditChip && 'cursor-default',
            )}
          >
            <button
              type="button"
              onClick={() => onEditChip?.(i)}
              className="inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900 rounded"
              aria-label={`Edytuj ${label}`}
            >
              <span className="text-white/60">{label}</span>
              <span className="text-white/50 font-mono text-[11px]">{chip.op}</span>
              {valueDisplay && <span className="font-medium">{valueDisplay}</span>}
            </button>
            <button
              type="button"
              aria-label={`Usuń ${label}`}
              onClick={() => {
                onRemove(i);
              }}
              className="ml-1 h-5 w-5 rounded-full hover:bg-white/15 grid place-items-center text-white/70 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900"
            >
              <X className="size-3" />
            </button>
          </span>
        );
      })}
      <button
        type="button"
        onClick={onClearAll}
        className="text-[12px] text-zinc-500 hover:text-zinc-900 underline underline-offset-2 ml-1 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900 rounded"
      >
        {t('products.filter_chips.clear_all', { defaultValue: 'Wyczyść wszystkie' })}
      </button>
      <span className="text-zinc-300">·</span>
      <button
        type="button"
        onClick={copyUrl}
        aria-label={t('products.filter_chips.copy_url', {
          defaultValue: 'Skopiuj URL z filtrami',
        })}
        className="text-[12px] text-zinc-500 hover:text-zinc-900 inline-flex items-center gap-1.5 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900 rounded"
      >
        <Link2 className="size-3.5" />
        <span>
          {t('products.filter_chips.copy_url', { defaultValue: 'Skopiuj URL z filtrami' })}
        </span>
      </button>
    </section>
  );
}

function formatValue(chip: FilterCondition): string {
  if (chip.op === 'IS EMPTY' || chip.op === 'IS NOT EMPTY') return '';
  if (Array.isArray(chip.value)) return chip.value.join(', ');
  if (chip.value === undefined || chip.value === null) return '';
  return String(chip.value);
}

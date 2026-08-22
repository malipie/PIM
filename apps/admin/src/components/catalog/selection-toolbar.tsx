import { ArrowRight } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import type { SelectionMode } from '@/lib/selection/use-selection-state';

/**
 * VIEW-11 (#542) — selection toolbar — BaseLinker pattern.
 *
 * Sits between the toolbar and the grid. Three states (mode):
 *   - `none`     — toolbar hidden;
 *   - `page`     — pill *„{N} zaznaczonych na tej stronie"* + escalate
 *                  link *„Zaznacz wszystkie {matching} pasujących →"*;
 *   - `all-matching` — pill *„{N} wszystkich pasujących zaznaczonych"*
 *                  + (when `capped`) warning that the capacity (10k) was hit.
 *
 * Pixel-perfect mockup `list-view-v2.jsx` l. 226-254.
 */

interface SelectionToolbarProps {
  mode: SelectionMode;
  perPageCount: number;
  matchingCount: number;
  totalMatched?: number;
  capped?: boolean;
  isLoading?: boolean;
  onSelectAllMatching: () => void;
  onClear: () => void;
}

export function SelectionToolbar({
  mode,
  perPageCount,
  matchingCount,
  totalMatched,
  capped,
  isLoading,
  onSelectAllMatching,
  onClear,
}: SelectionToolbarProps) {
  const { t } = useTranslation();
  if (mode === 'none') return null;

  const displayCount = mode === 'all-matching' ? (totalMatched ?? perPageCount) : perPageCount;

  return (
    <div className="rounded-2xl bg-zinc-900 text-white px-4 py-2.5 flex items-center gap-3">
      <span className="h-6 px-2 rounded-md bg-white/10 text-[11.5px] tabular-nums font-mono font-semibold inline-flex items-center">
        {displayCount.toLocaleString('pl-PL')}
      </span>
      {mode === 'page' ? (
        <>
          <span className="text-[12.5px]">
            {t('products.selection.selected_on_page', {
              defaultValue: 'zaznaczonych',
            })}{' '}
            <span className="text-white/60">
              {t('products.selection.on_this_page', { defaultValue: 'na tej stronie' })}
            </span>
          </span>
          {matchingCount > perPageCount && (
            <>
              <span className="h-4 w-px bg-white/20" />
              <button
                type="button"
                onClick={onSelectAllMatching}
                disabled={isLoading}
                className="text-[12.5px] font-medium text-white inline-flex items-center gap-1.5 hover:underline disabled:opacity-50"
              >
                {t('products.selection.select_all_matching', {
                  defaultValue: 'Zaznacz wszystkie',
                })}{' '}
                <span className="font-mono tabular-nums">
                  {matchingCount.toLocaleString('pl-PL')}
                </span>{' '}
                {t('products.selection.matching_suffix', { defaultValue: 'pasujących' })}{' '}
                <ArrowRight className="size-3.5" />
              </button>
            </>
          )}
        </>
      ) : (
        <>
          {/* #2945 — the capped state used to read "10 000 · wszystkie
              pasujące do filtru zaznaczone (cross-page selection) · cap 10k".
              Three problems at once: the number looked like the match count
              rather than a ceiling, "cross-page selection" is developer
              jargon in a Polish UI, and "cap 10k" says nothing about what the
              operator can do next. Say the limit in words, with both numbers,
              so "why did my 50 060 become 10 000" answers itself. */}
          <span className="text-[12.5px]">
            {capped ? (
              <span className="text-amber-300">
                {t('products.selection.capped_explained', {
                  defaultValue:
                    'Zaznaczono {{limit}} z {{total}} — więcej nie można zaznaczyć naraz. Zawęź filtr, żeby objąć resztę.',
                  limit: displayCount.toLocaleString('pl-PL'),
                  total: (matchingCount || displayCount).toLocaleString('pl-PL'),
                })}
              </span>
            ) : (
              <span className="text-white/60">
                {t('products.selection.all_matching_label', {
                  defaultValue: 'zaznaczone we wszystkich stronach wyniku',
                })}
              </span>
            )}
          </span>
        </>
      )}
      <button
        type="button"
        onClick={onClear}
        className="ml-auto text-[12px] text-white/60 hover:text-white"
      >
        {t('products.selection.clear', { defaultValue: 'Wyczyść' })}
      </button>
    </div>
  );
}

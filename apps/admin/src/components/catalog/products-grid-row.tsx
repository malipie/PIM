import { ChevronRight } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { CompletenessBadge } from '@/components/catalog/completeness-badge';
import { ProductRowActions } from '@/components/catalog/product-row-actions';
import { type SyncAggregate, SyncAggregateIcon } from '@/components/catalog/sync-aggregate-icon';
import { GridAttributeCell, gridCellAlignment } from '@/lib/grid/grid-attribute-cell';
import type { GridColumn } from '@/lib/grid/types';
import { cn } from '@/lib/utils';

import { isVariant, type ProductsGridRow } from './products-grid';

/**
 * GRID-P1-03 (#2387) — one list row: structural cells (selection,
 * thumbnail slot, actions) plus the model-driven data cells. Extracted
 * from products-grid.tsx to keep both files under the max-lines guard.
 */

type OptionLabelIndex = Record<string, Record<string, Record<string, string>>>;

const SYNC_LABEL_TONE: Record<SyncAggregate, string> = {
  green: 'text-emerald-700',
  yellow: 'text-amber-700',
  red: 'text-rose-700',
  gray: 'text-zinc-500',
};

interface RowViewProps {
  row: ProductsGridRow;
  columns: GridColumn[];
  optionLabels: OptionLabelIndex;
  showMediaColumn: boolean;
  template: string;
  locale: string;
  isSelected: boolean;
  onToggleSelect: (id: string) => void;
  isExpanded: boolean;
  onToggleExpand: (id: string) => void;
  variantsCount: number;
  forceExpandable?: boolean;
  onChangedRow: () => void;
  detailPathFor: (id: string) => string;
}

export function ProductsGridRowView({
  row,
  columns,
  optionLabels,
  showMediaColumn,
  template,
  locale,
  isSelected,
  onToggleSelect,
  isExpanded,
  onToggleExpand,
  variantsCount,
  forceExpandable = false,
  onChangedRow,
  detailPathFor,
}: RowViewProps) {
  const { t } = useTranslation();
  const variant = isVariant(row);
  const hasVariants = !variant && (variantsCount > 0 || forceExpandable);
  const style: CSSProperties = { gridTemplateColumns: template };

  const identifierCell = (
    <div className="px-3 py-2 font-mono text-[12px] flex items-center gap-1.5">
      {hasVariants ? (
        <button
          type="button"
          onClick={() => {
            onToggleExpand(row.id);
          }}
          aria-expanded={isExpanded}
          aria-label={
            isExpanded
              ? t('products.row.collapse_variants_aria', {
                  sku: row.sku,
                  defaultValue: 'Zwiń warianty {{sku}}',
                })
              : t('products.row.expand_variants_aria', {
                  sku: row.sku,
                  defaultValue: 'Rozwiń warianty {{sku}}',
                })
          }
          className="-ml-1 size-5 rounded grid place-items-center text-zinc-500 hover:text-zinc-900 hover:bg-zinc-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900"
        >
          <ChevronRight
            className={cn('size-3.5 transition-transform', isExpanded && 'rotate-90')}
            aria-hidden="true"
          />
        </button>
      ) : null}
      {variant ? (
        <span className="font-medium text-zinc-700">{row.sku}</span>
      ) : (
        <Link
          to={detailPathFor(row.id)}
          className="font-medium text-zinc-700 hover:text-zinc-900 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900 rounded"
        >
          {row.sku}
        </Link>
      )}
    </div>
  );

  const nameCell = (
    <div className="px-3 py-2.5 min-w-0 flex items-center gap-2">
      {variant ? (
        <span className="text-[13.5px] font-medium tracking-tight truncate text-left text-zinc-700">
          {row.name}
        </span>
      ) : (
        <Link
          to={detailPathFor(row.id)}
          className="text-[13.5px] font-medium tracking-tight truncate text-left hover:underline focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900 rounded"
        >
          {row.name}
        </Link>
      )}
      {hasVariants ? (
        <span className="text-[10px] font-mono px-1.5 py-0.5 rounded bg-orange-50 text-orange-700">
          {t('products.variants.count', {
            count: variantsCount,
            defaultValue: '{{count}} wariantów',
          })}
        </span>
      ) : null}
      {variant && row.variantAxis !== null ? (
        <span className="text-[10.5px] text-zinc-500 font-mono">{row.variantAxis}</span>
      ) : null}
    </div>
  );

  function dataCell(column: GridColumn): ReactNode {
    switch (column.key) {
      case 'code':
        return identifierCell;
      case 'name':
      case '__name':
        return nameCell;
      case '__categories':
        return (
          <div className="px-3 py-2 flex items-center gap-1 flex-wrap">
            {row.categories !== null && row.categories.length > 0 ? (
              <>
                {row.categories.slice(0, 2).map((cat) => (
                  <span
                    key={cat}
                    className="text-[10.5px] px-1.5 py-0.5 rounded bg-zinc-100 text-zinc-700"
                  >
                    {cat}
                  </span>
                ))}
                {row.categories.length > 2 ? (
                  <span className="text-[10.5px] text-zinc-500">+{row.categories.length - 2}</span>
                ) : null}
              </>
            ) : (
              <span className="text-[12px] text-zinc-500">—</span>
            )}
          </div>
        );
      case 'completeness':
        return (
          <div className="px-3 py-2.5">
            <CompletenessBadge pct={row.completenessPct} />
          </div>
        );
      case '__sync':
        return (
          <div className="px-3 py-2 flex items-center gap-2.5">
            <SyncAggregateIcon status={row.syncStatusAggregate} />
            <span
              className={cn('text-[10.5px] font-medium', SYNC_LABEL_TONE[row.syncStatusAggregate])}
            >
              {t(`products.sync_label.${row.syncStatusAggregate}`, {
                defaultValue: defaultSyncLabel(row.syncStatusAggregate),
              })}
            </span>
          </div>
        );
      case '__price':
        return (
          <div className="px-3 py-2 text-[13px] font-medium tabular-nums">
            {row.price !== null ? (
              <>
                {row.price.amount.toLocaleString(locale, { maximumFractionDigits: 2 })}
                <span className="text-zinc-500 ml-1 text-[11px]">{row.price.currency}</span>
              </>
            ) : (
              <span className="text-zinc-500">—</span>
            )}
          </div>
        );
      case '__variant':
        return (
          <div className="px-3 py-2 text-[11px] font-mono text-zinc-500">
            {row.variantAxis ?? '—'}
          </div>
        );
      case 'status':
        return (
          <div className="px-3 py-2 text-[12px] text-zinc-600">
            {row.status !== null
              ? t(`products.status.${row.status}`, { defaultValue: row.status })
              : '—'}
          </div>
        );
      case 'updatedAt':
        return (
          <div className="px-3 py-2 text-[12px] text-zinc-500 whitespace-nowrap">
            {formatDate(row.updatedAt, locale)}
          </div>
        );
      default:
        return (
          <div
            className={cn(
              'px-3 py-2 min-w-0 text-[12.5px] text-zinc-700 flex items-center',
              gridCellAlignment(column) === 'right' && 'justify-end',
            )}
            data-testid={`grid-cell-${column.key}`}
          >
            <GridAttributeCell
              column={column}
              attributesIndexed={row.attributesIndexed}
              optionLabels={optionLabels}
            />
          </div>
        );
    }
  }

  return (
    <div
      data-testid={`products-grid-row-${row.sku}`}
      className={cn(
        'group relative grid items-center text-[13px] border-b border-zinc-50 last:border-b-0 transition',
        isSelected ? 'bg-zinc-200/70' : variant ? 'bg-zinc-50/40' : 'hover:bg-zinc-50/60',
      )}
      style={style}
    >
      <div className="px-3 py-2.5 pl-4">
        {variant ? (
          <span className="inline-block size-4" />
        ) : (
          <input
            type="checkbox"
            checked={isSelected}
            onChange={() => {
              onToggleSelect(row.id);
            }}
            aria-label={t('products.actions.select_row', {
              sku: row.sku,
              defaultValue: 'Zaznacz {{sku}}',
            })}
            className="size-4 cursor-pointer accent-zinc-900"
          />
        )}
      </div>

      {showMediaColumn ? (
        <div className="px-3 py-2 flex items-center gap-1">
          {variant ? <span className="ml-1 text-zinc-300">└</span> : null}
        </div>
      ) : null}

      {columns.map((column) => (
        <div key={column.key} className="contents">
          {dataCell(column)}
        </div>
      ))}

      <div className="px-3 py-2 text-zinc-500 hover:text-zinc-900 opacity-0 group-hover:opacity-100 focus-within:opacity-100">
        {variant ? null : <ProductRowActions productId={row.id} onChanged={onChangedRow} />}
      </div>
    </div>
  );
}

function formatDate(raw: string | null, locale: string): string {
  if (raw === null) return '—';
  const parsed = new Date(raw);
  if (Number.isNaN(parsed.getTime())) return '—';
  return new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).format(parsed);
}

function defaultSyncLabel(status: SyncAggregate): string {
  if (status === 'green') return 'OK';
  if (status === 'yellow') return 'Częściowo';
  if (status === 'red') return 'Błąd';
  return '—';
}

import { ChevronRight } from 'lucide-react';
import type { CSSProperties } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { CompletenessBadge } from '@/components/catalog/completeness-badge';
import { ProductRowActions } from '@/components/catalog/product-row-actions';
import { type SyncAggregate, SyncAggregateIcon } from '@/components/catalog/sync-aggregate-icon';
import { cn } from '@/lib/utils';

export interface ProductsGridRow {
  id: string;
  sku: string;
  name: string;
  categories: string[] | null;
  price: { amount: number; currency: string } | null;
  completenessPct: number;
  syncStatusAggregate: SyncAggregate;
  enabled: boolean;
  status: string | null;
  parentId: string | null;
  variantAxis: string | null;
}

interface ProductsGridProps {
  rows: ProductsGridRow[];
  selected: Set<string>;
  onToggleSelect: (id: string) => void;
  onToggleSelectAll: () => void;
  expandedMasters: Set<string>;
  onToggleExpand: (id: string) => void;
  variantsByMasterCount: Map<string, number>;
  onChangedRow: () => void;
  isLoading: boolean;
  /**
   * When true the chevron is rendered on every master row even when
   * the inline `variantsByMasterCount` does not know whether the row
   * has variants yet — the parent list lazy-loads variants on click
   * (#514). Without this the chevron only shows in flat mode where
   * variants live in the same Refine page as the master.
   */
  alwaysShowChevronOnMasters?: boolean;
  /**
   * UP-06 (#1024) — per-row detail route builder. Defaults to
   * `/products/{id}` to keep the legacy /products list unchanged when
   * unspecified; UniversalListPage overrides this with
   * `/objects/{slug}/{id}` for custom kinds.
   */
  detailPathFor?: (id: string) => string;
}

const GRID_TPL = '44px 52px 150px minmax(260px,1.6fr) minmax(160px,1fr) 170px 150px 120px 44px';

const COL_KEYS = [
  'sel',
  'img',
  'sku',
  'name',
  'cats',
  'compl',
  'channels',
  'price',
  'more',
] as const;

const SYNC_LABEL_TONE: Record<SyncAggregate, string> = {
  green: 'text-emerald-700',
  yellow: 'text-amber-700',
  red: 'text-rose-700',
  gray: 'text-zinc-500',
};

/**
 * VIEW-05 (#411) — pixel-perfect 12-column grid for the products list,
 * matching the prototype mockup `produkty/list-view.jsx` lines 220–376.
 * Replaces the previous shadcn `<Table>` + dual-mode (table/excel)
 * layout with a single CSS grid that follows the mockup's exact column
 * widths, hover/selected/variant tones, and tree-expand chevron + axis
 * label rendering for variants. Cells without backend coverage in MVP
 * render `—` placeholders (categories / price fall back when
 * `attributesIndexed` doesn't expose them yet — see VIEW-05.1
 * follow-up).
 */
export function ProductsGrid({
  rows,
  selected,
  onToggleSelect,
  onToggleSelectAll,
  expandedMasters,
  onToggleExpand,
  variantsByMasterCount,
  onChangedRow,
  isLoading,
  alwaysShowChevronOnMasters = false,
  detailPathFor = (id: string) => `/products/${id}`,
}: ProductsGridProps) {
  const { t } = useTranslation();
  const masterIds = rows.filter((r) => r.parentId === null).map((r) => r.id);
  const allSelected = masterIds.length > 0 && masterIds.every((id) => selected.has(id));

  const headerStyle: CSSProperties = { gridTemplateColumns: GRID_TPL };

  return (
    // NUI-13 — no `role="grid"`: the ARIA grid pattern mandates grid keyboard
    // navigation we do not implement, and the CSS-grid markup cannot satisfy
    // the required row/gridcell structure. A labelled <section> keeps the
    // landmark; every interactive control carries its own accessible name.
    <section
      aria-label={t('products.grid.aria_label', { defaultValue: 'Lista produktów' })}
      data-testid="products-grid"
      className="w-full overflow-hidden rounded-2xl border border-zinc-100 bg-white shadow-sm"
    >
      <div
        className="grid items-center text-[11px] uppercase tracking-wider text-zinc-500 font-semibold border-b border-zinc-100 bg-zinc-50/60"
        style={headerStyle}
      >
        {COL_KEYS.map((key, idx) => (
          <div key={key} className={cn('px-3 py-2.5', idx === 0 && 'pl-4')}>
            {key === 'sel' ? (
              <input
                type="checkbox"
                checked={allSelected}
                onChange={() => onToggleSelectAll()}
                aria-label={t('products.actions.select_all', {
                  defaultValue: 'Zaznacz wszystkie',
                })}
                className="size-4 cursor-pointer accent-zinc-900"
              />
            ) : (
              t(`products.fields.${key}`, { defaultValue: defaultLabelFor(key) })
            )}
          </div>
        ))}
      </div>

      {isLoading ? (
        <SkeletonRows />
      ) : rows.length === 0 ? (
        <div className="px-4 py-12 text-center text-sm text-zinc-500">
          {t('products.grid.empty', { defaultValue: 'Brak wyników.' })}
        </div>
      ) : (
        <div>
          {rows.map((row) => (
            <ProductsGridRowView
              key={row.id}
              row={row}
              isSelected={!isVariant(row) && selected.has(row.id)}
              onToggleSelect={onToggleSelect}
              isExpanded={expandedMasters.has(row.id)}
              onToggleExpand={onToggleExpand}
              variantsCount={variantsByMasterCount.get(row.id) ?? 0}
              forceExpandable={alwaysShowChevronOnMasters && row.parentId === null}
              onChangedRow={onChangedRow}
              detailPathFor={detailPathFor}
            />
          ))}
        </div>
      )}
    </section>
  );
}

function defaultLabelFor(key: (typeof COL_KEYS)[number]): string {
  switch (key) {
    case 'sel':
    case 'img':
    case 'more':
      return '';
    case 'sku':
      return 'SKU';
    case 'name':
      return 'Nazwa';
    case 'cats':
      return 'Kategorie';
    case 'compl':
      return 'Completeness';
    case 'channels':
      return 'Kanały';
    case 'price':
      return 'Cena';
  }
}

function isVariant(row: ProductsGridRow): boolean {
  return row.parentId !== null;
}

interface RowViewProps {
  row: ProductsGridRow;
  isSelected: boolean;
  onToggleSelect: (id: string) => void;
  isExpanded: boolean;
  onToggleExpand: (id: string) => void;
  variantsCount: number;
  forceExpandable?: boolean;
  onChangedRow: () => void;
  detailPathFor: (id: string) => string;
}

function ProductsGridRowView({
  row,
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
  const style: CSSProperties = { gridTemplateColumns: GRID_TPL };

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

      <div className="px-3 py-2 flex items-center gap-1">
        {variant ? <span className="ml-1 text-zinc-300">└</span> : null}
      </div>

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

      <div className="px-3 py-2.5">
        <CompletenessBadge pct={row.completenessPct} />
      </div>

      <div className="px-3 py-2 flex items-center gap-2.5">
        <SyncAggregateIcon status={row.syncStatusAggregate} />
        <span className={cn('text-[10.5px] font-medium', SYNC_LABEL_TONE[row.syncStatusAggregate])}>
          {t(`products.sync_label.${row.syncStatusAggregate}`, {
            defaultValue: defaultSyncLabel(row.syncStatusAggregate),
          })}
        </span>
      </div>

      <div className="px-3 py-2 text-[13px] font-medium tabular-nums">
        {row.price !== null ? (
          <>
            {row.price.amount.toLocaleString('pl-PL', { maximumFractionDigits: 2 })}
            <span className="text-zinc-500 ml-1 text-[11px]">{row.price.currency}</span>
          </>
        ) : (
          <span className="text-zinc-500">—</span>
        )}
      </div>

      <div className="px-3 py-2 text-zinc-500 hover:text-zinc-900 opacity-0 group-hover:opacity-100 focus-within:opacity-100">
        {variant ? null : <ProductRowActions productId={row.id} onChanged={onChangedRow} />}
      </div>
    </div>
  );
}

function SkeletonRows() {
  const skeletonKeys = ['s0', 's1', 's2', 's3', 's4', 's5', 's6', 's7'] as const;
  return (
    <div>
      {skeletonKeys.map((sk) => (
        <div
          key={sk}
          className="grid items-center border-b border-zinc-50 last:border-b-0"
          style={{ gridTemplateColumns: GRID_TPL }}
        >
          {COL_KEYS.map((key) => (
            <div key={key} className="px-3 py-3">
              <div className="h-4 w-3/4 animate-pulse rounded bg-zinc-100" />
            </div>
          ))}
        </div>
      ))}
    </div>
  );
}

function defaultSyncLabel(status: SyncAggregate): string {
  if (status === 'green') return 'OK';
  if (status === 'yellow') return 'Częściowo';
  if (status === 'red') return 'Błąd';
  return '—';
}

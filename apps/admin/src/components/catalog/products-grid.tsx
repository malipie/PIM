import type { CSSProperties } from 'react';
import { useTranslation } from 'react-i18next';

import type { SyncAggregate } from '@/components/catalog/sync-aggregate-icon';
import type { GridColumn } from '@/lib/grid/types';
import { ProductsGridRowView } from './products-grid-row';

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
  updatedAt: string | null;
  /** Raw envelope map from the API — attribute columns read it directly. */
  attributesIndexed: Record<string, unknown> | null;
}

type OptionLabelIndex = Record<string, Record<string, Record<string, string>>>;

interface ProductsGridProps {
  rows: ProductsGridRow[];
  /**
   * GRID-P1-03 (#2387) — resolved data columns (visible only) from
   * `useGridColumns`. Structural cells (selection checkbox, thumbnail
   * slot, row actions) stay fixed and are NOT part of the model.
   */
  columns: GridColumn[];
  /** attrCode → optionCode → label map for select/multiselect cells. */
  optionLabels?: OptionLabelIndex;
  /** ObjectType capability — hides the thumbnail slot for media-less kinds. */
  showMediaColumn?: boolean;
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

/** Default grid-template width per well-known key; attribute types below. */
const WIDTH_BY_KEY: Record<string, string> = {
  code: '150px',
  name: 'minmax(260px,1.6fr)',
  __name: 'minmax(260px,1.6fr)',
  __categories: 'minmax(160px,1fr)',
  completeness: '170px',
  __sync: '150px',
  __price: '120px',
  __variant: '110px',
  status: '110px',
  updatedAt: '150px',
};

const WIDTH_BY_TYPE: Record<string, string> = {
  number: '110px',
  metric: '110px',
  price: '120px',
  boolean: '90px',
  date: '120px',
  datetime: '160px',
  select: '150px',
  multiselect: '190px',
  textarea: '220px',
  wysiwyg: '220px',
  asset: '70px',
  relation: '160px',
};

function widthFor(column: GridColumn): string {
  if (column.width !== undefined) return `${column.width}px`;
  return WIDTH_BY_KEY[column.key] ?? WIDTH_BY_TYPE[column.type] ?? '160px';
}

function gridTemplate(columns: GridColumn[], showMediaColumn: boolean): string {
  const parts = ['44px'];
  if (showMediaColumn) parts.push('52px');
  for (const column of columns) parts.push(widthFor(column));
  parts.push('44px');
  return parts.join(' ');
}

function pickLabel(label: Record<string, string>, locale: string, fallback: string): string {
  return label[locale] ?? label.en ?? label.pl ?? Object.values(label)[0] ?? fallback;
}

/**
 * VIEW-05 (#411) — pixel-perfect CSS grid for the products list.
 * GRID-P1-03 (#2387) — data columns are no longer hardcoded: they come
 * from the resolved `GridColumn[]` model (list-schema + user overrides),
 * so every ObjectType renders its own column set and attribute columns
 * display through the shared cell registry (GRID-P1-02).
 */
export function ProductsGrid({
  rows,
  columns,
  optionLabels = {},
  showMediaColumn = true,
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
  const { t, i18n } = useTranslation();
  const locale = i18n.language.split('-')[0] ?? i18n.language;
  const masterIds = rows.filter((r) => r.parentId === null).map((r) => r.id);
  const allSelected = masterIds.length > 0 && masterIds.every((id) => selected.has(id));

  const template = gridTemplate(columns, showMediaColumn);
  const headerStyle: CSSProperties = { gridTemplateColumns: template };

  return (
    // NUI-13 — no `role="grid"`: the ARIA grid pattern mandates grid keyboard
    // navigation we do not implement, and the CSS-grid markup cannot satisfy
    // the required row/gridcell structure. A labelled <section> keeps the
    // landmark; every interactive control carries its own accessible name.
    <section
      aria-label={t('products.grid.aria_label', { defaultValue: 'Lista produktów' })}
      data-testid="products-grid"
      className="w-full overflow-x-auto rounded-2xl border border-zinc-100 bg-white shadow-sm"
    >
      <div
        className="grid items-center text-[11px] uppercase tracking-wider text-zinc-500 font-semibold border-b border-zinc-100 bg-zinc-50/60"
        style={headerStyle}
      >
        <div className="px-3 py-2.5 pl-4">
          <input
            type="checkbox"
            checked={allSelected}
            onChange={() => onToggleSelectAll()}
            aria-label={t('products.actions.select_all', {
              defaultValue: 'Zaznacz wszystkie',
            })}
            className="size-4 cursor-pointer accent-zinc-900"
          />
        </div>
        {showMediaColumn ? <div className="px-3 py-2.5" /> : null}
        {columns.map((column) => (
          <div key={column.key} className="px-3 py-2.5" data-testid={`grid-header-${column.key}`}>
            {pickLabel(column.label, locale, column.key)}
          </div>
        ))}
        <div className="px-3 py-2.5" />
      </div>

      {isLoading ? (
        <SkeletonRows template={template} cellCount={columns.length + (showMediaColumn ? 3 : 2)} />
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
              columns={columns}
              optionLabels={optionLabels}
              showMediaColumn={showMediaColumn}
              template={template}
              locale={locale}
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

export function isVariant(row: ProductsGridRow): boolean {
  return row.parentId !== null;
}

function SkeletonRows({ template, cellCount }: { template: string; cellCount: number }) {
  const skeletonKeys = ['s0', 's1', 's2', 's3', 's4', 's5', 's6', 's7'] as const;
  const cells = Array.from({ length: cellCount }, (_, i) => `c${i}`);
  return (
    <div>
      {skeletonKeys.map((sk) => (
        <div
          key={sk}
          className="grid items-center border-b border-zinc-50 last:border-b-0"
          style={{ gridTemplateColumns: template }}
        >
          {cells.map((key) => (
            <div key={key} className="px-3 py-3">
              <div className="h-4 w-3/4 animate-pulse rounded bg-zinc-100" />
            </div>
          ))}
        </div>
      ))}
    </div>
  );
}

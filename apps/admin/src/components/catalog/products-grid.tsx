import type { CSSProperties, PointerEvent as ReactPointerEvent } from 'react';
import { useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

import type { SyncAggregate } from '@/components/catalog/sync-aggregate-icon';
import { extractGridCellValue, stringifyGridCellValue } from '@/lib/grid/cell-value';
import { autoFitWidth, clampColumnWidth } from '@/lib/grid/overrides';
import type { GridColumn } from '@/lib/grid/types';
import type { GridDensity } from '@/lib/grid/use-density';
import { cn } from '@/lib/utils';
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
  /** GRID-P2-03 — compact shrinks row height ~30% for spreadsheet-style work. */
  density?: GridDensity;
  /** GRID-P2-02 — drag-resize commit (key = model column key, width px). */
  onColumnResize?: (key: string, width: number) => void;
  /** GRID-P5-03 — active sort (null = default id order). */
  sort?: { key: string; dir: 'asc' | 'desc' } | null;
  /** GRID-P5-03 — header click cycles asc → desc → off. Absent = sorting disabled (e.g. search mode). */
  onSortChange?: (key: string) => void;
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

function gridTemplate(
  columns: GridColumn[],
  showMediaColumn: boolean,
  liveWidths: Record<string, number>,
): string {
  const parts = ['44px'];
  if (showMediaColumn) parts.push('52px');
  for (const column of columns) {
    const live = liveWidths[column.key];
    parts.push(live !== undefined ? `${live}px` : widthFor(column));
  }
  parts.push('44px');
  return parts.join(' ');
}

/** Sticky offsets for the leading structural + identifier cells (px). */
export function stickyOffsets(showMediaColumn: boolean): { img: number; code: number } {
  return { img: 44, code: showMediaColumn ? 96 : 44 };
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
  density = 'normal',
  onColumnResize,
  sort = null,
  onSortChange,
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

  // GRID-P2-02 — uncommitted drag widths live here so pointermove does not
  // round-trip through localStorage/overrides on every frame.
  const [liveWidths, setLiveWidths] = useState<Record<string, number>>({});
  const dragRef = useRef<{ key: string; startX: number; startWidth: number } | null>(null);
  const template = gridTemplate(columns, showMediaColumn, liveWidths);
  const headerStyle: CSSProperties = { gridTemplateColumns: template };
  const offsets = stickyOffsets(showMediaColumn);
  const compact = density === 'compact';

  const autoFitSamples = useMemo(() => {
    const byKey: Record<string, string[]> = {};
    for (const column of columns) {
      if (column.source !== 'attribute') continue;
      byKey[column.key] = rows
        .slice(0, 25)
        .map((row) =>
          stringifyGridCellValue(extractGridCellValue(row.attributesIndexed?.[column.key], locale)),
        );
    }
    return byKey;
  }, [columns, rows, locale]);

  const startResize = (key: string, event: ReactPointerEvent<HTMLButtonElement>): void => {
    const headerCell = (event.currentTarget as HTMLElement).parentElement;
    if (headerCell === null) return;
    dragRef.current = { key, startX: event.clientX, startWidth: headerCell.offsetWidth };
    (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
  };
  const moveResize = (event: ReactPointerEvent<HTMLButtonElement>): void => {
    const drag = dragRef.current;
    if (drag === null) return;
    const width = clampColumnWidth(drag.startWidth + (event.clientX - drag.startX));
    setLiveWidths((prev) => (prev[drag.key] === width ? prev : { ...prev, [drag.key]: width }));
  };
  const endResize = (): void => {
    const drag = dragRef.current;
    if (drag === null) return;
    dragRef.current = null;
    setLiveWidths((prev) => {
      const width = prev[drag.key];
      if (width !== undefined) onColumnResize?.(drag.key, width);
      const { [drag.key]: _committed, ...rest } = prev;
      return rest;
    });
  };

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
        <div
          className={cn('sticky left-0 z-20 bg-zinc-50 px-3 pl-4', compact ? 'py-1.5' : 'py-2.5')}
        >
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
        {showMediaColumn ? (
          <div
            className={cn('sticky z-20 bg-zinc-50 px-3', compact ? 'py-1.5' : 'py-2.5')}
            style={{ left: offsets.img }}
          />
        ) : null}
        {columns.map((column, index) => (
          // biome-ignore lint/a11y/useAriaPropsSupportedByRole: NUI-13 keeps
          // this a CSS-grid (no role="grid"/"columnheader" — those mandate
          // grid keyboard nav / focusability we do not implement); aria-sort
          // still communicates the active sort direction to assistive tech.
          <div
            key={column.key}
            className={cn(
              'relative px-3',
              compact ? 'py-1.5' : 'py-2.5',
              index === 0 && 'sticky z-20 bg-zinc-50 shadow-[inset_-1px_0_0_0_rgba(228,228,231,1)]',
            )}
            style={index === 0 ? { left: offsets.code } : undefined}
            data-testid={`grid-header-${column.key}`}
            aria-sort={
              sort?.key === column.key
                ? sort.dir === 'asc'
                  ? 'ascending'
                  : 'descending'
                : undefined
            }
          >
            {column.sortable && onSortChange !== undefined ? (
              <button
                type="button"
                onClick={() => onSortChange(column.key)}
                data-testid={`grid-sort-${column.key}`}
                className="inline-flex items-center gap-0.5 uppercase tracking-wider hover:text-zinc-800 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900 rounded"
                aria-label={t('grid.sort_aria', {
                  label: pickLabel(column.label, locale, column.key),
                  defaultValue: 'Sortuj po: {{label}}',
                })}
              >
                {pickLabel(column.label, locale, column.key)}
                {sort?.key === column.key ? (
                  <span aria-hidden="true">{sort.dir === 'asc' ? '\u2191' : '\u2193'}</span>
                ) : null}
              </button>
            ) : (
              pickLabel(column.label, locale, column.key)
            )}
            {onColumnResize !== undefined ? (
              <button
                type="button"
                aria-label={t('grid.resize_aria', {
                  label: pickLabel(column.label, locale, column.key),
                  defaultValue: 'Zmień szerokość kolumny {{label}}',
                })}
                data-testid={`grid-resize-${column.key}`}
                onPointerDown={(event) => startResize(column.key, event)}
                onPointerMove={moveResize}
                onPointerUp={endResize}
                onPointerCancel={endResize}
                onDoubleClick={() =>
                  onColumnResize(
                    column.key,
                    autoFitWidth(
                      pickLabel(column.label, locale, column.key),
                      autoFitSamples[column.key] ?? [],
                    ),
                  )
                }
                className="absolute -right-1 top-0 z-10 h-full w-2 cursor-col-resize touch-none hover:bg-zinc-300/60"
              />
            ) : null}
          </div>
        ))}
        <div className={cn('px-3', compact ? 'py-1.5' : 'py-2.5')} />
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
              density={density}
              stickyCodeLeft={offsets.code}
              stickyImgLeft={offsets.img}
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

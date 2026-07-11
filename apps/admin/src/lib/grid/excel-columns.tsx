import type { ReactNode } from 'react';

import type { ExcelColumn } from '@/components/catalog/excel-like-grid';
import type { ProductsGridRow } from '@/components/catalog/products-grid';
import { SyncAggregateIcon } from '@/components/catalog/sync-aggregate-icon';

import { extractGridCellValue, stringifyGridCellValue } from './cell-value';
import { GridAttributeCell } from './grid-attribute-cell';
import type { GridColumn } from './types';

/**
 * GRID-P1-04 (#2388) — adapter: resolved `GridColumn[]` → the Excel
 * view's `ExcelColumn[]` + row records. Attribute columns are read-only
 * until GRID-P6-02 ships typed editors; their cells render through the
 * shared registry (P1-02) while clipboard/TSV reads the plain-text
 * projection stored on the row under `attr:{code}`.
 */

export type ExcelObjectRow = ProductsGridRow & Record<string, unknown>;

/** Prefix keeps attribute projections from shadowing row fields (sku/name/…). */
export const EXCEL_ATTR_KEY_PREFIX = 'attr:';

type OptionLabelIndex = Record<string, Record<string, Record<string, string>>>;

function pickLabel(label: Record<string, string>, locale: string, fallback: string): string {
  return label[locale] ?? label.en ?? label.pl ?? Object.values(label)[0] ?? fallback;
}

function optionLabelFor(
  optionLabels: OptionLabelIndex,
  attrCode: string,
  locale: string,
): (code: string) => string {
  return (code) => {
    const label = optionLabels[attrCode]?.[code];
    return label !== undefined ? pickLabel(label, locale, code) : code;
  };
}

const EXCEL_WIDTH_BY_TYPE: Record<string, number> = {
  number: 110,
  metric: 110,
  price: 120,
  boolean: 90,
  date: 120,
  datetime: 160,
  select: 150,
  multiselect: 190,
  textarea: 240,
  wysiwyg: 240,
  asset: 90,
};

interface SystemExcelCell {
  key: string;
  type: ExcelColumn<ExcelObjectRow>['type'];
  width: number;
  readOnly: boolean;
  renderDisplay?: (row: ExcelObjectRow) => ReactNode;
}

/**
 * System/view columns map onto the flat row fields the list page already
 * derives (`buildRow`); `code` targets the `sku` field so the existing
 * SKU/name inline-edit commit path keeps working unchanged.
 */
function systemExcelCell(column: GridColumn, locale: string): SystemExcelCell | null {
  switch (column.key) {
    case 'code':
      return { key: 'sku', type: 'text', width: 160, readOnly: true };
    case '__name':
      return { key: 'name', type: 'text', width: 280, readOnly: false };
    case 'completeness':
      return { key: 'completenessPct', type: 'number', width: 110, readOnly: true };
    case 'status':
      return { key: 'status', type: 'text', width: 110, readOnly: true };
    case 'updatedAt':
      return {
        key: 'updatedAtDisplay',
        type: 'text',
        width: 150,
        readOnly: true,
      };
    case '__categories':
      return {
        key: 'categoriesDisplay',
        type: 'text',
        width: 180,
        readOnly: true,
        renderDisplay: (row) => (row.categories ?? []).join(', ') || '—',
      };
    case '__sync':
      return {
        key: 'syncDisplay',
        type: 'text',
        width: 110,
        readOnly: true,
        renderDisplay: (row) => (
          <span className="inline-flex items-center">
            <SyncAggregateIcon status={row.syncStatusAggregate} />
          </span>
        ),
      };
    case '__price':
      return {
        key: 'priceDisplay',
        type: 'text',
        width: 120,
        readOnly: true,
        renderDisplay: (row) =>
          row.price !== null
            ? `${new Intl.NumberFormat(locale, { maximumFractionDigits: 2 }).format(row.price.amount)} ${row.price.currency}`
            : '—',
      };
    case '__variant':
      return { key: 'variantAxis', type: 'text', width: 120, readOnly: true };
    default:
      return null;
  }
}

export function toExcelColumns(
  columns: GridColumn[],
  locale: string,
  optionLabels: OptionLabelIndex,
): ExcelColumn<ExcelObjectRow>[] {
  const out: ExcelColumn<ExcelObjectRow>[] = [];
  for (const column of columns) {
    if (column.hidden) continue;
    const label = pickLabel(column.label, locale, column.key);
    if (column.source === 'attribute' && !column.key.startsWith('__')) {
      out.push({
        key: `${EXCEL_ATTR_KEY_PREFIX}${column.key}`,
        modelKey: column.key,
        label,
        type: 'text',
        width: column.width ?? EXCEL_WIDTH_BY_TYPE[column.type] ?? 170,
        readOnly: true, // typed editors land in GRID-P6-02
        renderDisplay: (row) => (
          <GridAttributeCell
            column={column}
            attributesIndexed={row.attributesIndexed as Record<string, unknown> | null}
            optionLabels={optionLabels}
          />
        ),
      });
      continue;
    }
    const system = systemExcelCell(column, locale);
    if (system === null) continue;
    out.push({
      key: system.key,
      modelKey: column.key,
      label,
      type: system.type,
      width: column.width ?? system.width,
      readOnly: system.readOnly,
      ...(system.renderDisplay !== undefined ? { renderDisplay: system.renderDisplay } : {}),
    });
  }
  return out;
}

/**
 * Projects one row for the Excel view: flat display strings for the
 * attribute columns (clipboard TSV reads `String(row[key])`) plus
 * derived display fields for the read-only system cells.
 */
export function toExcelRow(
  row: ProductsGridRow,
  columns: GridColumn[],
  locale: string,
  optionLabels: OptionLabelIndex,
): ExcelObjectRow {
  const updatedAtDate = row.updatedAt !== null ? new Date(row.updatedAt) : null;
  const out: ExcelObjectRow = {
    ...row,
    updatedAtDisplay:
      updatedAtDate !== null && !Number.isNaN(updatedAtDate.getTime())
        ? new Intl.DateTimeFormat(locale, { dateStyle: 'medium' }).format(updatedAtDate)
        : '',
    categoriesDisplay: (row.categories ?? []).join(', '),
    priceDisplay: row.price !== null ? `${row.price.amount} ${row.price.currency}` : '',
    syncDisplay: row.syncStatusAggregate,
  };
  const attrs = (row as ExcelObjectRow).attributesIndexed as
    | Record<string, unknown>
    | null
    | undefined;
  for (const column of columns) {
    if (column.hidden || column.source !== 'attribute' || column.key.startsWith('__')) continue;
    const cell = extractGridCellValue(attrs?.[column.key], locale);
    out[`${EXCEL_ATTR_KEY_PREFIX}${column.key}`] = stringifyGridCellValue(
      cell,
      optionLabelFor(optionLabels, column.key, locale),
    );
  }
  return out;
}

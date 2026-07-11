import { useQueries } from '@tanstack/react-query';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';

import { jsonFetch } from '@/lib/http';

import { extractGridCellValue, stringifyGridCellValue } from './cell-value';
import type { GridColumn } from './types';

/**
 * GRID-P1-02 (#2386) — per-attribute-type cell renderers for the list
 * views. One shared component so the grid view (P1-03) and the Excel
 * view (P1-04) display identical readings.
 *
 * Deliberately display-only: editing (typed editors) is GRID-P6-02, and
 * relation/asset cells render what `attributesIndexed` already carries —
 * no per-row fetches (backlog M1 "poza zakresem").
 */

const EM_DASH = '—';
const MULTI_CHIP_LIMIT = 3;

/** Column types whose cells right-align (numeric reading). */
export function gridCellAlignment(column: Pick<GridColumn, 'type'>): 'left' | 'right' {
  return column.type === 'number' || column.type === 'metric' || column.type === 'price'
    ? 'right'
    : 'left';
}

interface OptionRow {
  code: string;
  label: Record<string, string>;
}

type OptionLabelIndex = Record<string, Record<string, Record<string, string>>>;

/**
 * Batch-fetches option catalogues for the visible select/multiselect
 * columns (`GET /api/attributes/{code}/options`, same queryKey family as
 * the modeling values editor) and returns
 * `attrCode → optionCode → label map`. Missing catalogues degrade to the
 * raw option code in the cell.
 */
export function useAttributeOptionLabels(columns: GridColumn[]): OptionLabelIndex {
  const selectCodes = useMemo(
    () =>
      columns
        .filter(
          (column) =>
            column.source === 'attribute' &&
            (column.type === 'select' || column.type === 'multiselect') &&
            !column.hidden,
        )
        .map((column) => column.key)
        .sort(),
    [columns],
  );

  const results = useQueries({
    queries: selectCodes.map((code) => ({
      queryKey: ['attribute_options', code] as const,
      staleTime: 5 * 60 * 1000,
      queryFn: async () => {
        const response = await jsonFetch<{ member?: OptionRow[]; 'hydra:member'?: OptionRow[] }>(
          `/api/attributes/${code}/options`,
          { accept: 'application/json' },
        );
        return response.member ?? response['hydra:member'] ?? [];
      },
    })),
  });

  // biome-ignore lint/correctness/useExhaustiveDependencies: `results` is a new array each render; index by the stable data references instead.
  return useMemo(() => {
    const index: OptionLabelIndex = {};
    selectCodes.forEach((code, i) => {
      const rows = results[i]?.data;
      if (!rows) return;
      const byOption: Record<string, Record<string, string>> = {};
      for (const row of rows) {
        if (typeof row?.code === 'string') byOption[row.code] = row.label ?? {};
      }
      index[code] = byOption;
    });
    return index;
  }, [selectCodes, ...results.map((result) => result.data)]);
}

function pickLabel(label: Record<string, string> | undefined, locale: string): string | undefined {
  if (!label) return undefined;
  return label[locale] ?? label.en ?? label.pl ?? Object.values(label)[0];
}

function OptionChips({
  codes,
  column,
  optionLabels,
  locale,
}: {
  codes: string[];
  column: GridColumn;
  optionLabels: OptionLabelIndex;
  locale: string;
}) {
  const labelFor = (code: string): string =>
    pickLabel(optionLabels[column.key]?.[code], locale) ?? code;

  if (column.type !== 'multiselect' && codes.length === 1) {
    return <span className="truncate">{labelFor(codes[0] ?? '')}</span>;
  }
  const shown = codes.slice(0, MULTI_CHIP_LIMIT);
  const overflow = codes.length - shown.length;
  return (
    <span className="flex min-w-0 items-center gap-1">
      {shown.map((code) => (
        <span
          key={code}
          className="max-w-[9rem] truncate rounded-full bg-zinc-100 px-2 py-0.5 text-xs text-zinc-700 dark:bg-zinc-800 dark:text-zinc-300"
        >
          {labelFor(code)}
        </span>
      ))}
      {overflow > 0 ? (
        <span
          className="shrink-0 text-xs text-zinc-500"
          title={codes
            .slice(MULTI_CHIP_LIMIT)
            .map((code) => labelFor(code))
            .join(', ')}
        >
          {/* numeric symbol, deliberately not a translation key */}
          {`+${overflow}`}
        </span>
      ) : null}
    </span>
  );
}

/**
 * Renders one attribute cell. `attributesIndexed` may be the raw API map
 * or an already-unwrapped one — extraction tolerates both.
 */
export function GridAttributeCell({
  column,
  attributesIndexed,
  optionLabels = {},
}: {
  column: GridColumn;
  attributesIndexed: Record<string, unknown> | null | undefined;
  optionLabels?: OptionLabelIndex;
}) {
  const { t, i18n } = useTranslation();
  const locale = i18n.language.split('-')[0] ?? i18n.language;
  const cell = extractGridCellValue(attributesIndexed?.[column.key], locale);

  if (cell.kind === 'empty') {
    return <span className="text-zinc-400">{EM_DASH}</span>;
  }

  switch (column.type) {
    case 'number':
    case 'metric': {
      const numeric =
        cell.kind === 'number'
          ? cell.value
          : cell.kind === 'text'
            ? Number(cell.value)
            : Number.NaN;
      if (!Number.isFinite(numeric))
        return <span className="truncate">{stringifyGridCellValue(cell)}</span>;
      return <span className="tabular-nums">{new Intl.NumberFormat(locale).format(numeric)}</span>;
    }
    case 'price': {
      if (cell.kind !== 'price')
        return <span className="truncate">{stringifyGridCellValue(cell)}</span>;
      const formatted =
        cell.currency.length === 3
          ? new Intl.NumberFormat(locale, { style: 'currency', currency: cell.currency }).format(
              cell.amount,
            )
          : `${new Intl.NumberFormat(locale, { minimumFractionDigits: 2 }).format(cell.amount)} ${cell.currency}`.trim();
      return <span className="tabular-nums">{formatted}</span>;
    }
    case 'boolean': {
      const truthy = cell.kind === 'boolean' ? cell.value : stringifyGridCellValue(cell) === 'true';
      return (
        <span
          className={
            truthy
              ? 'rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-medium text-emerald-700 dark:bg-emerald-900/40 dark:text-emerald-300'
              : 'rounded-full bg-zinc-100 px-2 py-0.5 text-xs font-medium text-zinc-600 dark:bg-zinc-800 dark:text-zinc-400'
          }
        >
          {truthy
            ? t('grid.cell.yes', { defaultValue: 'Tak' })
            : t('grid.cell.no', { defaultValue: 'Nie' })}
        </span>
      );
    }
    case 'date':
    case 'datetime': {
      const raw = stringifyGridCellValue(cell);
      const parsed = new Date(raw);
      if (Number.isNaN(parsed.getTime())) return <span className="truncate">{raw}</span>;
      const formatted = new Intl.DateTimeFormat(locale, {
        dateStyle: 'medium',
        ...(column.type === 'datetime' ? { timeStyle: 'short' as const } : {}),
      }).format(parsed);
      return <span className="whitespace-nowrap">{formatted}</span>;
    }
    case 'select':
    case 'multiselect': {
      const codes = cell.kind === 'options' ? cell.codes : [stringifyGridCellValue(cell)];
      return (
        <OptionChips codes={codes} column={column} optionLabels={optionLabels} locale={locale} />
      );
    }
    case 'asset': {
      // No per-row asset fetch in M1: a fixed-size placeholder keeps the
      // layout stable; real thumbnails need the asset preview endpoint.
      const raw = stringifyGridCellValue(cell);
      if (/^https?:\/\//.test(raw)) {
        return (
          <img src={raw} alt="" loading="lazy" className="h-8 w-8 shrink-0 rounded object-cover" />
        );
      }
      const count = cell.kind === 'options' ? cell.codes.length : 1;
      return (
        <span className="inline-flex h-8 min-w-8 shrink-0 items-center justify-center rounded bg-zinc-100 px-1 text-xs text-zinc-500 dark:bg-zinc-800 dark:text-zinc-400">
          {t('grid.cell.assets', { count, defaultValue: '{{count}} plik(i)' })}
        </span>
      );
    }
    default: {
      // text / textarea / wysiwyg / identifier / email / color / relation
      // and any future type: safe string with a full-value tooltip.
      const raw = stringifyGridCellValue(cell);
      return (
        <span className="truncate" title={raw}>
          {raw}
        </span>
      );
    }
  }
}

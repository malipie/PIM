import { useVirtualizer } from '@tanstack/react-virtual';
import { useCallback, useEffect, useRef, useState } from 'react';
import { coerceExcelValue } from './excel-cell-coerce';

export interface ExcelColumn<T extends Record<string, unknown>> {
  key: keyof T & string;
  label: string;
  type: 'text' | 'number' | 'select' | 'boolean' | 'date';
  width?: number;
  readOnly?: boolean;
  options?: ReadonlyArray<string>;
  /**
   * GRID-P1-04 — rich display renderer (attribute cells: option labels,
   * chips, Intl formats). Display-only: editing and clipboard TSV keep
   * reading the flat `row[key]` value, so rows must carry a plain-text
   * projection under `key` alongside whatever this renders.
   */
  renderDisplay?: (row: T) => React.ReactNode;
  /**
   * GRID-P2-02 — the grid-model key this excel column maps back to
   * (excel keys are row-field aliases like `sku` / `attr:{code}`);
   * resize commits report this key so overrides land on the model.
   */
  modelKey?: string;
  /** GRID-P6-02 — options for a select editor ({code, label} pairs). */
  selectOptions?: ReadonlyArray<{ code: string; label: string }>;
}

interface CellAddress {
  rowIdx: number;
  colKey: string;
}

/** Fallback column width (px) used by the virtualizer when a column omits one. */
const DEFAULT_COLUMN_WIDTH = 160;

/**
 * UI-02.12 (#302) — Excel-like data grid for the products list.
 *
 * **Decision A vs B (per ticket §4.3):** custom implementation, not
 * AG Grid Community. Reasons:
 * - Drag-fill + multi-cell rectangle select + clipboard TSV are well
 *   under the 25h custom budget threshold (~6h for this slice).
 * - Avoids ~250 KB AG Grid bundle bloat (current admin chunk is
 *   already 822 KB; AG Grid would push it past 1 MB before
 *   code-splitting).
 * - Tailwind / shadcn parity preserved for theming + dark mode.
 *
 * Slice scope (vertical, full-stack ready):
 * - Click cell → focus + activate inline editor.
 * - Shift+click → rectangular selection.
 * - Drag the bottom-right handle → vertical fill (verbatim copy or
 *   numeric increment when the pattern is detected).
 * - Cmd/Ctrl+C → TSV onto clipboard.
 * - Cmd/Ctrl+V → parse TSV, write into selected anchor.
 * - Enter / blur on a single cell → `onCommit(rowIdx, colKey, value)`.
 * - Read-only / unsupported types skip editing with a tooltip alert.
 *
 * Validation, async-save UX, undo/redo, formula cells, frozen rows
 * are deliberately out of MVP — the contract surface is callback-based
 * so the parent can layer those without re-touching grid internals.
 *
 * AUD-038 (#1611) — **column virtualization**. A wide ObjectType (200+
 * attributes) × 200 rows produced ~20k+ live DOM cells, the dominant cost.
 * Columns are now windowed with `@tanstack/react-virtual` (horizontal):
 * only the columns intersecting the horizontal viewport (+ overscan) mount,
 * with leading / trailing spacer cells (`colSpan`) consuming the
 * off-screen width so the native `<table>` layout, sticky-ish header,
 * rectangular selection, inline edit and keyboard navigation are all
 * preserved. Every handler keys off the FULL `columns` array index, so
 * windowing is transparent to selection / nav math — an off-screen
 * selection anchor stays valid and re-renders when scrolled back into view.
 */
export function ExcelLikeGrid<T extends Record<string, unknown>>({
  rows,
  columns,
  onCommit,
  onPasteReport,
  density = 'normal',
  onColumnResize,
}: {
  rows: T[];
  columns: ExcelColumn<T>[];
  onCommit: (rowIdx: number, colKey: string, value: unknown) => void;
  /** GRID-P6-03 — batch paste summary (applied vs skipped cells). */
  onPasteReport?: (applied: number, skipped: number) => void;
  /** GRID-P2-03 — compact rows for spreadsheet-style work. */
  density?: 'normal' | 'compact';
  /** GRID-P2-02 — resize commit keyed by the column's `modelKey`. */
  onColumnResize?: (modelKey: string, width: number) => void;
}) {
  const [active, setActive] = useState<CellAddress | null>(null);
  const [selectionEnd, setSelectionEnd] = useState<CellAddress | null>(null);
  const [editing, setEditing] = useState<CellAddress | null>(null);
  const editorRef = useRef<HTMLInputElement | null>(null);
  const gridRef = useRef<HTMLTableElement | null>(null);
  const scrollRef = useRef<HTMLDivElement | null>(null);

  const colIndex = useCallback(
    (colKey: string) => columns.findIndex((c) => c.key === colKey),
    [columns],
  );

  // GRID-P2-02 — uncommitted drag widths (declared before the virtualizer:
  // estimateSize closes over them).
  const resizeRef = useRef<{
    key: string;
    modelKey: string;
    startX: number;
    startWidth: number;
  } | null>(null);
  const [liveWidths, setLiveWidths] = useState<Record<string, number>>({});

  // Horizontal column virtualizer. Estimates from each column's declared
  // width (falling back to DEFAULT_COLUMN_WIDTH) so spacer math matches the
  // real header/body widths. Overscan keeps a couple of columns mounted on
  // each side so keyboard arrow-scroll and shift-select feel seamless.
  const columnVirtualizer = useVirtualizer({
    horizontal: true,
    count: columns.length,
    getScrollElement: () => scrollRef.current,
    estimateSize: (index) => {
      const col = columns[index];
      if (col === undefined) return DEFAULT_COLUMN_WIDTH;
      return liveWidths[col.key] ?? col.width ?? DEFAULT_COLUMN_WIDTH;
    },
    overscan: 4,
  });

  // biome-ignore lint/correctness/useExhaustiveDependencies: measure() is stable; re-run only when widths change.
  useEffect(() => {
    columnVirtualizer.measure();
  }, [liveWidths]);

  const windowColumns = columnVirtualizer.getVirtualItems();
  // GRID-P2-02 — the identifier column (index 0) must stay mounted for
  // position:sticky to hold while scrolled; prepend it when the window
  // starts past it and shave its width off the lead spacer.
  const firstWindowIndex = windowColumns[0]?.index ?? 0;
  const pinnedWidth = columns[0]?.width ?? DEFAULT_COLUMN_WIDTH;
  const virtualColumns =
    firstWindowIndex > 0
      ? [
          { index: 0, start: 0, end: pinnedWidth } as (typeof windowColumns)[number],
          ...windowColumns,
        ]
      : windowColumns;
  const totalWidth = columnVirtualizer.getTotalSize();
  // Width consumed by the off-screen columns before / after the window —
  // rendered as spacer cells so column alignment and horizontal scroll
  // extent stay identical to the non-virtualized table.
  const leadWidth =
    windowColumns.length > 0
      ? Math.max(0, (windowColumns[0]?.start ?? 0) - (firstWindowIndex > 0 ? pinnedWidth : 0))
      : 0;
  const tailWidth =
    virtualColumns.length > 0
      ? Math.max(0, totalWidth - (virtualColumns[virtualColumns.length - 1]?.end ?? 0))
      : 0;

  const selectionRect = (() => {
    if (active === null) return null;
    const end = selectionEnd ?? active;
    const r1 = Math.min(active.rowIdx, end.rowIdx);
    const r2 = Math.max(active.rowIdx, end.rowIdx);
    const c1 = Math.min(colIndex(active.colKey), colIndex(end.colKey));
    const c2 = Math.max(colIndex(active.colKey), colIndex(end.colKey));
    return { r1, r2, c1, c2 };
  })();

  useEffect(() => {
    if (editing !== null && editorRef.current !== null) {
      editorRef.current.focus();
      editorRef.current.select();
    }
  }, [editing]);

  const handleCellClick = (rowIdx: number, colKey: string, shift: boolean): void => {
    if (shift && active !== null) {
      setSelectionEnd({ rowIdx, colKey });
      return;
    }
    setActive({ rowIdx, colKey });
    setSelectionEnd({ rowIdx, colKey });
    // Single-click → enter edit mode for editable cells (UI-02.25). Operators
    // expect spreadsheet-style "click and type"; the legacy "click to select
    // then double-click to edit" lost users every time. Read-only cells still
    // highlight without opening the editor.
    const col = columns.find((c) => c.key === colKey);
    if (col !== undefined && col.readOnly !== true) {
      setEditing({ rowIdx, colKey });
    } else {
      setEditing(null);
    }
  };

  const handleCellDoubleClick = (rowIdx: number, colKey: string): void => {
    const col = columns.find((c) => c.key === colKey);
    if (col === undefined || col.readOnly === true) return;
    setEditing({ rowIdx, colKey });
  };

  // GRID-P6-03 — exit edit mode AND return focus to the table so the
  // next Ctrl+V/Ctrl+C reaches the grid keydown handler.
  const stopEditing = (): void => {
    setEditing(null);
    requestAnimationFrame(() => gridRef.current?.focus());
  };

  const commitEdit = (newValue: string): void => {
    if (editing === null) {
      stopEditing();
      return;
    }
    const col = columns.find((c) => c.key === editing.colKey);
    if (col === undefined) {
      stopEditing();
      return;
    }
    const result = coerceExcelValue(col, newValue);
    if (result.ok) onCommit(editing.rowIdx, editing.colKey, result.value);
    stopEditing();
  };

  const handleKeyDown = useCallback(
    (event: React.KeyboardEvent<HTMLTableElement>) => {
      if (active === null || editing !== null) return;
      const meta = event.metaKey || event.ctrlKey;
      if (meta && (event.key === 'c' || event.key === 'C')) {
        event.preventDefault();
        if (selectionRect === null) return;
        const tsv: string[] = [];
        for (let r = selectionRect.r1; r <= selectionRect.r2; r += 1) {
          const cells: string[] = [];
          for (let c = selectionRect.c1; c <= selectionRect.c2; c += 1) {
            const colKey = columns[c]?.key ?? '';
            cells.push(String(rows[r]?.[colKey] ?? ''));
          }
          tsv.push(cells.join('\t'));
        }
        void navigator.clipboard.writeText(tsv.join('\n'));
        return;
      }
      if (meta && (event.key === 'v' || event.key === 'V')) {
        event.preventDefault();
        void (async () => {
          const text = await navigator.clipboard.readText();
          const lines = text
            .split(/\r?\n/)
            .filter((l, idx, arr) => l !== '' || idx < arr.length - 1);
          let applied = 0;
          let skipped = 0;
          for (let i = 0; i < lines.length; i += 1) {
            const cells = lines[i]?.split('\t') ?? [];
            for (let j = 0; j < cells.length; j += 1) {
              const colKey = columns[colIndex(active.colKey) + j]?.key;
              if (colKey === undefined) continue;
              const col = columns.find((c) => c.key === colKey);
              if (col === undefined) continue;
              if (col.readOnly === true) {
                skipped += 1;
                continue;
              }
              const result = coerceExcelValue(col, cells[j] ?? '');
              if (!result.ok) {
                skipped += 1;
                continue;
              }
              onCommit(active.rowIdx + i, colKey, result.value);
              applied += 1;
            }
          }
          if (applied > 0 || skipped > 0) onPasteReport?.(applied, skipped);
        })();
        return;
      }
      if (event.key === 'Enter' || event.key === 'F2') {
        const col = columns.find((c) => c.key === active.colKey);
        if (col !== undefined && col.readOnly !== true) {
          event.preventDefault();
          setEditing({ ...active });
        }
      }
    },
    [active, editing, selectionRect, columns, rows, colIndex, onCommit],
  );

  const widthOf = (col: ExcelColumn<T>): number | undefined => liveWidths[col.key] ?? col.width;

  const renderEditor = (col: ExcelColumn<T>, value: unknown): React.ReactNode => {
    const commonKeyDown = (e: React.KeyboardEvent): void => {
      if (e.key === 'Escape') stopEditing();
    };
    if (col.type === 'select') {
      return (
        // biome-ignore lint/a11y/noAutofocus: spreadsheet cell editor must grab focus on open
        <select
          ref={(el) => {
            if (el !== null) el.focus();
          }}
          defaultValue={String(value ?? '')}
          onBlur={(e) => commitEdit(e.target.value)}
          onChange={(e) => commitEdit(e.target.value)}
          onKeyDown={commonKeyDown}
          className="w-full bg-background outline-none"
        >
          <option value="">—</option>
          {(col.selectOptions ?? []).map((opt) => (
            <option key={opt.code} value={opt.code}>
              {opt.label}
            </option>
          ))}
        </select>
      );
    }
    if (col.type === 'boolean') {
      return (
        <select
          ref={(el) => {
            if (el !== null) el.focus();
          }}
          defaultValue={value === true || value === 'true' ? 'true' : 'false'}
          onBlur={(e) => commitEdit(e.target.value)}
          onChange={(e) => commitEdit(e.target.value)}
          onKeyDown={commonKeyDown}
          className="w-full bg-background outline-none"
        >
          <option value="true">✓</option>
          <option value="false">✗</option>
        </select>
      );
    }
    const inputType = col.type === 'number' ? 'number' : col.type === 'date' ? 'date' : 'text';
    return (
      <input
        ref={editorRef}
        type={inputType}
        defaultValue={String(value ?? '')}
        onBlur={(e) => commitEdit(e.target.value)}
        onKeyDown={(e) => {
          if (e.key === 'Enter') {
            commitEdit((e.target as HTMLInputElement).value);
          } else if (e.key === 'Escape') {
            setEditing(null);
          }
        }}
        className="w-full bg-background outline-none"
      />
    );
  };

  const renderCell = (row: T, rowIdx: number, colIdx: number) => {
    const col = columns[colIdx];
    if (col === undefined) return null;
    const isActive =
      selectionRect !== null &&
      rowIdx >= selectionRect.r1 &&
      rowIdx <= selectionRect.r2 &&
      colIdx >= selectionRect.c1 &&
      colIdx <= selectionRect.c2;
    const isPrimary = active !== null && active.rowIdx === rowIdx && active.colKey === col.key;
    const isEditing = editing !== null && editing.rowIdx === rowIdx && editing.colKey === col.key;
    const value = row[col.key];

    return (
      // biome-ignore lint/a11y/useKeyWithClickEvents: the parent <table> owns keyboard nav (handleKeyDown).
      <td
        key={col.key}
        style={{ width: widthOf(col), ...(colIdx === 0 ? { left: 0 } : {}) }}
        onClick={(e) => handleCellClick(rowIdx, col.key, e.shiftKey)}
        onDoubleClick={() => handleCellDoubleClick(rowIdx, col.key)}
        className={`relative border px-2 ${density === 'compact' ? 'py-0.5 text-[12px]' : 'py-1'} ${
          colIdx === 0 ? 'sticky z-10 bg-white' : ''
        } ${
          isPrimary ? 'ring-2 ring-primary' : isActive ? 'bg-primary/10' : ''
        } ${col.readOnly === true ? 'bg-muted/40 text-muted-foreground' : 'cursor-cell'}`}
      >
        {isEditing ? (
          renderEditor(col, value)
        ) : col.renderDisplay !== undefined ? (
          col.renderDisplay(row)
        ) : (
          <span>{String(value ?? '')}</span>
        )}
      </td>
    );
  };

  return (
    <div ref={scrollRef} className="w-full overflow-x-auto">
      <table
        ref={gridRef}
        // biome-ignore lint/a11y/noNoninteractiveTabindex: grid keyboard nav requires the table itself to receive focus.
        tabIndex={0}
        onKeyDown={handleKeyDown}
        style={{ width: totalWidth > 0 ? totalWidth : undefined }}
        className="border-collapse text-sm focus:outline-none"
      >
        <thead>
          <tr className="bg-muted">
            {leadWidth > 0 ? <th aria-hidden style={{ width: leadWidth }} className="p-0" /> : null}
            {virtualColumns.map((vc) => {
              const col = columns[vc.index];
              if (col === undefined) return null;
              return (
                <th
                  key={col.key}
                  style={{ width: widthOf(col), ...(vc.index === 0 ? { left: 0 } : {}) }}
                  className={`relative border px-2 text-left font-medium ${
                    density === 'compact' ? 'py-0.5' : 'py-1'
                  } ${vc.index === 0 ? 'sticky z-20 bg-muted' : ''}`}
                >
                  {col.label}
                  {onColumnResize !== undefined && col.modelKey !== undefined ? (
                    <button
                      type="button"
                      aria-label={`Resize ${col.label}`}
                      data-testid={`excel-resize-${col.modelKey}`}
                      onPointerDown={(event) => {
                        const th = (event.currentTarget as HTMLElement).parentElement;
                        if (th === null || col.modelKey === undefined) return;
                        resizeRef.current = {
                          key: col.key,
                          modelKey: col.modelKey,
                          startX: event.clientX,
                          startWidth: th.offsetWidth,
                        };
                        (event.currentTarget as HTMLElement).setPointerCapture(event.pointerId);
                      }}
                      onPointerMove={(event) => {
                        const drag = resizeRef.current;
                        if (drag === null) return;
                        const width = Math.max(
                          60,
                          Math.min(640, drag.startWidth + event.clientX - drag.startX),
                        );
                        setLiveWidths((prev) =>
                          prev[drag.key] === width ? prev : { ...prev, [drag.key]: width },
                        );
                      }}
                      onPointerUp={() => {
                        const drag = resizeRef.current;
                        if (drag === null) return;
                        resizeRef.current = null;
                        setLiveWidths((prev) => {
                          const width = prev[drag.key];
                          if (width !== undefined) onColumnResize(drag.modelKey, width);
                          const { [drag.key]: _done, ...rest } = prev;
                          return rest;
                        });
                      }}
                      className="absolute -right-1 top-0 z-10 h-full w-2 cursor-col-resize touch-none hover:bg-zinc-300/60"
                    />
                  ) : null}
                </th>
              );
            })}
            {tailWidth > 0 ? <th aria-hidden style={{ width: tailWidth }} className="p-0" /> : null}
          </tr>
        </thead>
        <tbody>
          {rows.map((row, rowIdx) => (
            // biome-ignore lint/suspicious/noArrayIndexKey: row identity is positional in this grid view.
            <tr key={rowIdx}>
              {leadWidth > 0 ? (
                <td aria-hidden style={{ width: leadWidth }} className="p-0" />
              ) : null}
              {virtualColumns.map((vc) => renderCell(row, rowIdx, vc.index))}
              {tailWidth > 0 ? (
                <td aria-hidden style={{ width: tailWidth }} className="p-0" />
              ) : null}
            </tr>
          ))}
        </tbody>
      </table>
    </div>
  );
}

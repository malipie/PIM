import {
  closestCenter,
  DndContext,
  type DragEndEvent,
  KeyboardSensor,
  PointerSensor,
  useSensor,
  useSensors,
} from '@dnd-kit/core';
import {
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { ChevronDown, ChevronUp, Columns3, GripVertical, Search } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Sheet, SheetContent, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { LOCKED_COLUMN_KEY, moveColumn, toggleColumnHidden } from '@/lib/grid/overrides';
import type { GridColumn } from '@/lib/grid/types';
import { cn } from '@/lib/utils';

/**
 * GRID-P2-01 (#2389) — the "Kolumny" manager for the universal list
 * views. Operates on the FULL resolved model (hidden included): a
 * checkbox toggles visibility, drag (pointer + dnd-kit keyboard sensor)
 * or the explicit up/down buttons reorder, "Reset" clears quick-prefs.
 *
 * Same @dnd-kit sensor setup as the export wizard's ColumnPickerV2 —
 * the two-pane picker itself is NOT extracted/reused: this surface is a
 * single flat list and sharing internals would couple the export wizard
 * to list-grid churn (deliberate deviation, documented in the PR).
 */

interface ColumnManagerProps {
  columns: GridColumn[];
  onChange: (next: GridColumn[]) => void;
  onReset: () => void;
  /** Locale used to pick display labels from the i18n maps. */
  locale: string;
}

function pickLabel(label: Record<string, string>, locale: string, fallback: string): string {
  return label[locale] ?? label.en ?? label.pl ?? Object.values(label)[0] ?? fallback;
}

export function ColumnManager({ columns, onChange, onReset, locale }: ColumnManagerProps) {
  const { t } = useTranslation();
  const [search, setSearch] = useState('');

  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 4 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  const query = search.trim().toLowerCase();
  const filtered = useMemo(
    () =>
      query.length === 0
        ? columns
        : columns.filter(
            (column) =>
              pickLabel(column.label, locale, column.key).toLowerCase().includes(query) ||
              column.key.toLowerCase().includes(query),
          ),
    [columns, query, locale],
  );

  const handleDragEnd = (event: DragEndEvent): void => {
    const { active, over } = event;
    if (over === null || active.id === over.id) return;
    const from = columns.findIndex((column) => column.key === active.id);
    const to = columns.findIndex((column) => column.key === over.id);
    if (from === -1 || to === -1 || to === 0 || from === 0) return;
    const next = [...columns];
    const [moved] = next.splice(from, 1);
    if (moved === undefined) return;
    next.splice(to, 0, moved);
    onChange(next.map((column, position) => ({ ...column, position })));
  };

  return (
    <Sheet>
      <SheetTrigger asChild>
        <Button
          variant="outline"
          className="h-11 rounded-2xl px-4 text-[12.5px] font-medium"
          data-testid="column-manager-trigger"
        >
          <Columns3 className="size-4" />
          {t('grid.manager.trigger', { defaultValue: 'Kolumny' })}
        </Button>
      </SheetTrigger>
      <SheetContent
        side="right"
        className="flex w-96 flex-col gap-0 p-0"
        data-testid="column-manager-panel"
      >
        <div className="border-b border-zinc-100 p-4">
          <SheetTitle className="text-sm font-semibold">
            {t('grid.manager.title', { defaultValue: 'Zarządzaj kolumnami' })}
          </SheetTitle>
        </div>
        <div className="border-b border-zinc-100 p-3">
          <div className="relative">
            <Search className="pointer-events-none absolute left-2.5 top-1/2 size-3.5 -translate-y-1/2 text-zinc-400" />
            <input
              type="search"
              value={search}
              onChange={(event) => setSearch(event.target.value)}
              placeholder={t('grid.manager.search_placeholder', {
                defaultValue: 'Szukaj kolumny…',
              })}
              aria-label={t('grid.manager.search_placeholder', {
                defaultValue: 'Szukaj kolumny…',
              })}
              className="h-9 w-full rounded-lg border border-zinc-200 bg-white pl-8 pr-2 text-[12.5px] outline-none focus-visible:ring-2 focus-visible:ring-zinc-900"
            />
          </div>
        </div>
        <ul
          className="min-h-0 flex-1 overflow-y-auto p-1.5"
          aria-label={t('grid.manager.list_aria', { defaultValue: 'Kolumny listy' })}
        >
          <DndContext
            sensors={sensors}
            collisionDetection={closestCenter}
            onDragEnd={handleDragEnd}
          >
            <SortableContext
              items={filtered.map((column) => column.key)}
              strategy={verticalListSortingStrategy}
            >
              {filtered.map((column) => (
                <ColumnRow
                  key={column.key}
                  column={column}
                  locale={locale}
                  isFirst={columns[0]?.key === column.key}
                  isLast={columns[columns.length - 1]?.key === column.key}
                  onToggle={() => onChange(toggleColumnHidden(columns, column.key))}
                  onMove={(delta) => onChange(moveColumn(columns, column.key, delta))}
                />
              ))}
            </SortableContext>
          </DndContext>
        </ul>
        <div className="border-t border-zinc-100 p-2">
          <Button
            variant="ghost"
            className="h-8 w-full rounded-lg text-[12px] text-zinc-600"
            onClick={onReset}
            data-testid="column-manager-reset"
          >
            {t('grid.manager.reset', { defaultValue: 'Resetuj do domyślnych' })}
          </Button>
        </div>
      </SheetContent>
    </Sheet>
  );
}

function ColumnRow({
  column,
  locale,
  isFirst,
  isLast,
  onToggle,
  onMove,
}: {
  column: GridColumn;
  locale: string;
  isFirst: boolean;
  isLast: boolean;
  onToggle: () => void;
  onMove: (delta: -1 | 1) => void;
}) {
  const { t } = useTranslation();
  const locked = column.key === LOCKED_COLUMN_KEY;
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: column.key,
    disabled: locked,
  });
  const label = pickLabel(column.label, locale, column.key);

  return (
    <li
      ref={setNodeRef}
      style={{ transform: CSS.Transform.toString(transform), transition }}
      className={cn(
        'flex items-center gap-1.5 rounded-lg px-1.5 py-1 text-[12.5px]',
        isDragging && 'z-10 bg-zinc-100 shadow-sm',
      )}
      data-testid={`column-manager-row-${column.key}`}
    >
      <button
        type="button"
        {...attributes}
        {...listeners}
        disabled={locked}
        aria-label={t('grid.manager.drag_aria', {
          label,
          defaultValue: 'Przeciągnij, aby zmienić kolejność: {{label}}',
        })}
        className={cn(
          'grid size-6 shrink-0 cursor-grab place-items-center rounded text-zinc-400 hover:text-zinc-600',
          locked && 'invisible',
        )}
      >
        <GripVertical className="size-3.5" />
      </button>
      <label className="flex min-w-0 flex-1 cursor-pointer items-center gap-2">
        <input
          type="checkbox"
          checked={!column.hidden}
          disabled={locked}
          onChange={onToggle}
          className="size-3.5 shrink-0 cursor-pointer accent-zinc-900 disabled:cursor-not-allowed"
        />
        <span className={cn('truncate', column.hidden && 'text-zinc-400')}>{label}</span>
        {locked ? (
          <span className="ml-auto shrink-0 text-[10px] uppercase tracking-wide text-zinc-400">
            {t('grid.manager.locked', { defaultValue: 'stała' })}
          </span>
        ) : null}
      </label>
      <div className="flex shrink-0 items-center">
        <button
          type="button"
          onClick={() => onMove(-1)}
          disabled={locked || isFirst}
          aria-label={t('grid.manager.move_up_aria', {
            label,
            defaultValue: 'Przesuń wyżej: {{label}}',
          })}
          className="grid size-6 place-items-center rounded text-zinc-400 hover:text-zinc-700 disabled:opacity-30"
          data-testid={`column-manager-up-${column.key}`}
        >
          <ChevronUp className="size-3.5" />
        </button>
        <button
          type="button"
          onClick={() => onMove(1)}
          disabled={locked || isLast}
          aria-label={t('grid.manager.move_down_aria', {
            label,
            defaultValue: 'Przesuń niżej: {{label}}',
          })}
          className="grid size-6 place-items-center rounded text-zinc-400 hover:text-zinc-700 disabled:opacity-30"
          data-testid={`column-manager-down-${column.key}`}
        >
          <ChevronDown className="size-3.5" />
        </button>
      </div>
    </li>
  );
}

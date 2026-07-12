import { useQuery } from '@tanstack/react-query';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { RelationCreateField } from '@/components/objects/relation-create-field';
import { Input } from '@/components/ui/input';
import type { AttributeMeta } from '@/features/catalog/products/components/types';
import { jsonFetch } from '@/lib/http';
import { cn } from '@/lib/utils';

/**
 * VIEW-25b (#556) — typed value input adapter.
 *
 * Współdzielony między BulkWizard (akcje zbiorcze) a AdvancedFilterPanel
 * (#2311 — filtr zaawansowany honoruje typ atrybutu). Renderuje odpowiedni
 * picker zależnie od typu atrybutu:
 *  - `text` → standardowy <Input>
 *  - `number` / `metric` → <Input inputMode=decimal>
 *  - `date` → <Input type=date>
 *  - `datetime` → <Input type=datetime-local>
 *  - `boolean` → toggle Tak/Nie
 *  - `color` → <input type=color> + hex <Input>
 *  - `select` → dropdown z `/api/attributes/{code}/options`
 *  - `multiselect` → chips picker z tej samej listy options
 *  - `relation` → picker obiektów docelowych (#2314, reuse RelationCreateField)
 *  - typ undefined → fallback do `text`
 *
 * Wartość zwracana w `onChange` jest typowana per atrybut:
 *  - `text` → string
 *  - `number` / `metric` → number | ''
 *  - `boolean` → boolean
 *  - `select` → string (kod opcji)
 *  - `multiselect` → string[]
 */

/** #2526 — editorial states, in lifecycle order, for the `status` filter field. */
const EDITORIAL_STATUS_CODES = ['draft', 'review', 'published', 'archived'] as const;

export interface BulkValueInputProps {
  attrCode: string;
  attrType: string | undefined;
  value: unknown;
  onChange: (next: unknown) => void;
  /** Optional placeholder dla text/number wariantu. */
  placeholder?: string;
}

interface AttributeOptionRow {
  id?: string;
  code: string;
  label?: Record<string, string> | string | null;
}

interface OptionsListResponse {
  'hydra:member'?: AttributeOptionRow[];
  member?: AttributeOptionRow[];
}

function labelOf(row: AttributeOptionRow): string {
  const label = row.label;
  if (label === null || label === undefined) return row.code;
  if (typeof label === 'string') return label;
  return label.pl ?? label.en ?? Object.values(label)[0] ?? row.code;
}

interface AttributeApiRow {
  id: string;
  code: string;
  label?: Record<string, string> | string | null;
  relationTargetObjectTypeIds?: string[];
  relationCardinality?: 'one' | 'many' | null;
}

/**
 * #2314 — relation attributes pick target objects, not free text. Fetch the
 * attribute's relation config (allowed target ObjectTypes + cardinality) and
 * reuse the detail-form picker; the emitted value is a target object UUID
 * (cardinality=one) or a UUID list (many) — exactly what the BE bulk
 * relation lane expects.
 */
function BulkRelationValueInput({
  attrCode,
  value,
  onChange,
}: {
  attrCode: string;
  value: unknown;
  onChange: (next: unknown) => void;
}) {
  const { t } = useTranslation();
  const metaQuery = useQuery({
    queryKey: ['attributes', 'relation-meta', attrCode],
    staleTime: 60_000,
    enabled: attrCode !== '',
    queryFn: async (): Promise<AttributeMeta | null> => {
      const res = await jsonFetch<{
        'hydra:member'?: AttributeApiRow[];
        member?: AttributeApiRow[];
      }>('/api/attributes?itemsPerPage=200');
      const rows = res['hydra:member'] ?? res.member ?? [];
      const row = rows.find((r) => r.code === attrCode);
      if (row === undefined) return null;
      return {
        id: row.id,
        code: row.code,
        type: 'relation',
        label:
          typeof row.label === 'object' && row.label !== null
            ? row.label
            : { pl: row.label ?? row.code },
        is_system: false,
        position: 0,
        is_required_in_group: false,
        relation_target_object_type_ids: row.relationTargetObjectTypeIds ?? [],
        relation_cardinality: row.relationCardinality ?? 'many',
      };
    },
  });

  if (metaQuery.isLoading) {
    return (
      <p className="text-[12px] text-zinc-500">
        {t('bulk_value.loading', { defaultValue: 'Ładuję…' })}
      </p>
    );
  }
  if (metaQuery.data == null) {
    return (
      <p className="text-[12px] text-zinc-500">
        {t('bulk_value.relation_meta_missing', {
          defaultValue: 'Nie udało się wczytać konfiguracji relacji',
        })}
      </p>
    );
  }

  return <RelationCreateField attribute={metaQuery.data} value={value} onChange={onChange} />;
}

export function BulkValueInput({
  attrCode,
  attrType,
  value,
  onChange,
  placeholder,
}: BulkValueInputProps) {
  const { t } = useTranslation();
  const [options, setOptions] = useState<AttributeOptionRow[]>([]);
  const [optionsLoading, setOptionsLoading] = useState(false);

  const isSelect = attrType === 'select' || attrType === 'multiselect';

  useEffect(() => {
    if (!isSelect || attrCode === '') {
      setOptions([]);
      return;
    }
    // #2526 — `status` is the editorial-state system field (a fixed enum),
    // not an Attribute, so it has no /api/attributes/status/options row.
    // Serve the four states inline, localised via the existing workflow keys.
    if (attrCode === 'status') {
      setOptions(
        EDITORIAL_STATUS_CODES.map((code) => ({
          code,
          label: t(`workflow.place.${code}`, { defaultValue: code }),
        })),
      );
      setOptionsLoading(false);
      return;
    }
    let cancelled = false;
    setOptionsLoading(true);
    const load = async (): Promise<void> => {
      try {
        const response = await jsonFetch<OptionsListResponse>(
          `/api/attributes/${encodeURIComponent(attrCode)}/options`,
        );
        const rows = response['hydra:member'] ?? response.member ?? [];
        if (!cancelled) setOptions(rows);
      } catch {
        if (!cancelled) setOptions([]);
      } finally {
        if (!cancelled) setOptionsLoading(false);
      }
    };
    void load();
    return () => {
      cancelled = true;
    };
  }, [attrCode, isSelect, t]);

  if (attrType === 'relation') {
    return <BulkRelationValueInput attrCode={attrCode} value={value} onChange={onChange} />;
  }

  if (attrType === 'boolean') {
    const boolValue = value === true || value === 'true';
    return (
      <div className="inline-flex items-center gap-2">
        <button
          type="button"
          onClick={() => onChange(true)}
          className={cn(
            'h-9 px-3 rounded-lg text-[12px] font-medium border',
            boolValue
              ? 'bg-zinc-900 text-white border-zinc-900'
              : 'bg-white text-zinc-700 border-zinc-200 hover:border-zinc-300',
          )}
        >
          {t('bulk_value.true', { defaultValue: 'Tak' })}
        </button>
        <button
          type="button"
          onClick={() => onChange(false)}
          className={cn(
            'h-9 px-3 rounded-lg text-[12px] font-medium border',
            !boolValue
              ? 'bg-zinc-900 text-white border-zinc-900'
              : 'bg-white text-zinc-700 border-zinc-200 hover:border-zinc-300',
          )}
        >
          {t('bulk_value.false', { defaultValue: 'Nie' })}
        </button>
      </div>
    );
  }

  if (attrType === 'date') {
    return (
      <Input
        type="date"
        value={typeof value === 'string' ? value : ''}
        onChange={(e) => onChange(e.target.value)}
        className="font-mono"
      />
    );
  }

  if (attrType === 'datetime') {
    return (
      <Input
        type="datetime-local"
        value={typeof value === 'string' ? value : ''}
        onChange={(e) => onChange(e.target.value)}
        className="font-mono"
      />
    );
  }

  if (attrType === 'color') {
    const colorValue = typeof value === 'string' && value !== '' ? value : '#000000';
    return (
      <div className="inline-flex items-center gap-2">
        <input
          type="color"
          aria-label={t('bulk_value.color_picker', { defaultValue: 'Wybierz kolor' })}
          value={colorValue}
          onChange={(e) => onChange(e.target.value)}
          className="h-9 w-12 cursor-pointer rounded-lg border border-zinc-200 bg-white p-1"
        />
        <Input
          value={typeof value === 'string' ? value : ''}
          onChange={(e) => onChange(e.target.value)}
          placeholder="#RRGGBB"
          className="font-mono w-28"
        />
      </div>
    );
  }

  if (attrType === 'number' || attrType === 'metric') {
    return (
      <Input
        // text + inputMode=decimal because native type=number swallows
        // the Polish comma decimal separator and reports an empty
        // `e.target.value`, so the operator typing `101,99` lost the
        // filter value mid-keystroke.
        type="text"
        inputMode="decimal"
        value={typeof value === 'string' || typeof value === 'number' ? String(value) : ''}
        onChange={(e) => {
          const raw = e.target.value;
          if (raw === '') {
            onChange('');
            return;
          }
          const parsed = Number(raw.replace(',', '.'));
          onChange(Number.isFinite(parsed) ? parsed : raw);
        }}
        placeholder={placeholder ?? 'np. 49,99'}
        className="font-mono"
      />
    );
  }

  if (attrType === 'select') {
    return (
      <select
        value={typeof value === 'string' ? value : ''}
        onChange={(e) => onChange(e.target.value)}
        disabled={optionsLoading}
        className="h-9 w-full rounded-lg border border-zinc-200 px-2 text-[13px] focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900"
      >
        <option value="">
          {optionsLoading
            ? t('bulk_value.loading', { defaultValue: 'Ładuję…' })
            : t('bulk_value.select_placeholder', { defaultValue: '— wybierz —' })}
        </option>
        {options.map((row) => (
          <option key={row.code} value={row.code}>
            {labelOf(row)} ({row.code})
          </option>
        ))}
      </select>
    );
  }

  if (attrType === 'multiselect') {
    const currentList = Array.isArray(value)
      ? (value as string[])
      : typeof value === 'string' && value.trim() !== ''
        ? value.split(',').map((s) => s.trim())
        : [];
    const toggle = (code: string): void => {
      const next = currentList.includes(code)
        ? currentList.filter((c) => c !== code)
        : [...currentList, code];
      onChange(next);
    };
    return (
      <div className="rounded-lg border border-zinc-200 bg-white max-h-[180px] overflow-y-auto">
        {optionsLoading ? (
          <div className="px-3 py-2 text-[12px] text-zinc-500">
            {t('bulk_value.loading', { defaultValue: 'Ładuję…' })}
          </div>
        ) : options.length === 0 ? (
          <div className="px-3 py-2 text-[12px] text-zinc-500">
            {t('bulk_value.no_options', { defaultValue: 'Brak opcji dla tego atrybutu' })}
          </div>
        ) : (
          options.map((row) => {
            const checked = currentList.includes(row.code);
            return (
              <label
                key={row.code}
                className={cn(
                  'flex items-center gap-2 px-3 py-1.5 text-[12.5px] cursor-pointer',
                  checked ? 'bg-emerald-50/60' : 'hover:bg-zinc-50',
                )}
              >
                <input
                  type="checkbox"
                  checked={checked}
                  onChange={() => toggle(row.code)}
                  className="size-3.5"
                />
                <span className="font-mono text-[11px] text-zinc-500 min-w-[100px] truncate">
                  {row.code}
                </span>
                <span className="flex-1 truncate">{labelOf(row)}</span>
              </label>
            );
          })
        )}
      </div>
    );
  }

  return (
    <Input
      value={typeof value === 'string' ? value : ''}
      onChange={(e) => onChange(e.target.value)}
      placeholder={placeholder ?? 'np. Festo'}
    />
  );
}

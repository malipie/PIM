import { useQuery } from '@tanstack/react-query';
import { Globe2, Link2, Plus, Trash2, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { AttributePicker } from '@/components/catalog/attribute-picker';
import { BulkValueInput } from '@/components/catalog/bulk-wizard/bulk-value-input';
import { Button } from '@/components/ui/button';
import {
  CORE_OPERATORS,
  FILTER_OPERATORS_BY_TYPE,
  type FilterCondition,
  type FilterConditionValue,
  type FilterOperator,
  type FilterScope,
  normalizeScope,
} from '@/lib/filters/filter-dsl';
import { jsonFetch } from '@/lib/http';
import { cn } from '@/lib/utils';

import {
  FIRST_PANEL_ATTR,
  PANEL_ATTRS,
  type PanelAttr,
  SYSTEM_PANEL_ATTRS,
  toFilterType,
} from './advanced-filter-panel-attrs';

/**
 * VIEW-09 — push-down sticky-collapsible advanced filter panel.
 * Replaces Sheet-based `AdvancedFilterBuilder` (UI-02.9 / #299).
 *
 * Grid mode only — Query / „Power" mode was removed (2026-05-14)
 * because the operator workflow stayed in the grid path; the recursive
 * AND/OR/NOT editor produced no benefit and added a parsing surface
 * (BE base64 blob) that has to be maintained.
 */

export type { PanelAttr };

/**
 * #2312 — Polish labels for the comparison operators. The select used to
 * render the raw canonical tokens (`after`, `IS EMPTY`, `contains`…), which
 * were English. Comparison symbols (`=`, `!=`, `<`, `>=`) are universal and
 * stay as-is; only the word operators are translated.
 */
const OPERATOR_I18N: Record<string, { key: string; pl: string }> = {
  between: { key: 'op_between', pl: 'pomiędzy' },
  'IS EMPTY': { key: 'op_is_empty', pl: 'puste' },
  'IS NOT EMPTY': { key: 'op_is_not_empty', pl: 'niepuste' },
  'starts with': { key: 'op_starts_with', pl: 'zaczyna się od' },
  'ends with': { key: 'op_ends_with', pl: 'kończy się na' },
  contains: { key: 'op_contains', pl: 'zawiera' },
  'not contains': { key: 'op_not_contains', pl: 'nie zawiera' },
  IN: { key: 'op_in', pl: 'jest jednym z' },
  'NOT IN': { key: 'op_not_in', pl: 'nie jest żadnym z' },
  after: { key: 'op_after', pl: 'po' },
  before: { key: 'op_before', pl: 'przed' },
  '= TRUE': { key: 'op_is_true', pl: '= Tak' },
  '= FALSE': { key: 'op_is_false', pl: '= Nie' },
};

function operatorLabel(
  op: string,
  t: (key: string, options?: { defaultValue?: string }) => string,
): string {
  const entry = OPERATOR_I18N[op];
  if (entry === undefined) return op;
  return t(`products.advanced_filter.${entry.key}`, { defaultValue: entry.pl });
}

interface AttributeApiRow {
  id: string;
  code: string;
  label?: Record<string, string> | string | null;
  type?: string;
  filterable?: boolean;
}

interface AdvancedFilterPanelProps {
  open: boolean;
  conditions: FilterCondition[];
  setConditions: (conditions: FilterCondition[]) => void;
  matchOperator: 'AND' | 'OR';
  setMatchOperator: (op: 'AND' | 'OR') => void;
  onApply: () => void;
  onClose: () => void;
  onClear: () => void;
  onSaveAsView?: () => void;
  onSaveAsPreset?: () => void;
  resultCount?: number;
  /**
   * #2640 — live draft feed. Fired on every draft edit (add/update/remove
   * condition, operator flip, open-seed) with the NORMALISED conditions
   * (numeric strings coerced like at apply time), so hosts can render a live
   * match count without changing the apply-gated commit semantics.
   */
  onDraftChange?: (
    conditions: FilterCondition[],
    operator: 'AND' | 'OR',
    scope?: FilterScope | null,
  ) => void;
  /**
   * UP-09 (#1027) — per-ObjectType attribute catalog. When supplied,
   * replaces the hardcoded product-flavoured PANEL_ATTRS so custom
   * kinds (`samochody`, `vacancies`) see their own attributes in the
   * picker. Operators on /products keep the legacy list (`undefined`
   * prop) so brand/price/category retain their richer type inference
   * without a schema round-trip on every render.
   */
  panelAttrs?: ReadonlyArray<PanelAttr>;
  /**
   * #2673 — panel-wide value context (channel/locale) the conditions
   * evaluate against on the backend (fallback to global). Committed on
   * „Zastosuj filtr" like the conditions. Both props required for the
   * scope bar to render — hosts without scope support are unaffected.
   */
  scope?: FilterScope | null;
  setScope?: (scope: FilterScope | null) => void;
}

interface ScopeChannelOption {
  code: string;
  name?: string | null;
}

interface ScopeLocaleRow {
  code: string;
  /** Short language code (`pl`) — matches `ObjectValue.locale` on the BE. */
  language?: string;
  isActive?: boolean;
}

export function AdvancedFilterPanel({
  open,
  conditions,
  setConditions,
  matchOperator,
  setMatchOperator,
  onApply,
  onClose,
  onClear,
  onSaveAsView,
  onSaveAsPreset,
  resultCount,
  onDraftChange,
  panelAttrs,
  scope,
  setScope,
}: AdvancedFilterPanelProps) {
  const { t } = useTranslation();
  const scopeEnabled = setScope !== undefined;

  // #2673 — scope sources; fetched only when the host wires the scope bar.
  const { data: scopeChannels } = useQuery({
    queryKey: ['channels', 'filter-scope'],
    enabled: scopeEnabled,
    staleTime: 60_000,
    queryFn: async (): Promise<ScopeChannelOption[]> => {
      const response = await jsonFetch<{ member?: ScopeChannelOption[] } | ScopeChannelOption[]>(
        '/api/channels',
        { accept: 'application/ld+json' },
      );
      return Array.isArray(response) ? response : (response.member ?? []);
    },
  });
  const { data: scopeLocales } = useQuery({
    queryKey: ['tenant-locales', 'filter-scope'],
    enabled: scopeEnabled,
    staleTime: 60_000,
    queryFn: async (): Promise<string[]> => {
      const response = await jsonFetch<{ items?: ScopeLocaleRow[] }>('/api/tenant-locales', {
        accept: 'application/json',
      });
      // Short language codes (`pl`), deduped — `ObjectValue.locale` and the
      // BE scope validation both speak short codes, not full `pl_PL`.
      return Array.from(
        new Set(
          (response.items ?? [])
            .filter((row) => row.isActive !== false)
            .map((row) => row.language ?? row.code.split('_')[0] ?? row.code),
        ),
      );
    },
  });

  // #1354 — strict filterable catalog. The panel offers ONLY attributes
  // flagged `is_filterable=true`; this drives the type-badge / operator
  // inference for the conditions, while the AttributePicker below applies
  // the same gate (`filterableOnly`) to its dropdown. An explicit
  // `panelAttrs` prop (per-ObjectType override) still wins; the hardcoded
  // PANEL_ATTRS only acts as the loading/empty fallback so the panel never
  // renders a dead picker mid-fetch.
  const { data: liveFilterableAttrs } = useQuery({
    queryKey: ['attributes', 'filterable-panel'],
    staleTime: 5 * 60 * 1000,
    queryFn: async (): Promise<PanelAttr[]> => {
      const res = await jsonFetch<{
        'hydra:member'?: AttributeApiRow[];
        member?: AttributeApiRow[];
      }>('/api/attributes?itemsPerPage=200');
      const rows = res['hydra:member'] ?? res.member ?? [];
      return rows
        .filter((r) => r.filterable === true)
        .map<PanelAttr>((r) => ({
          code: r.code,
          name: typeof r.label === 'string' ? r.label : (r.label?.pl ?? r.label?.en ?? r.code),
          type: toFilterType(r.type),
        }));
    },
  });

  const effectivePanelAttrs: ReadonlyArray<PanelAttr> =
    panelAttrs && panelAttrs.length > 0
      ? panelAttrs
      : liveFilterableAttrs && liveFilterableAttrs.length > 0
        ? [
            ...liveFilterableAttrs,
            ...SYSTEM_PANEL_ATTRS.filter(
              (sys) => !liveFilterableAttrs.some((attr) => attr.code === sys.code),
            ),
          ]
        : PANEL_ATTRS;
  const firstPanelAttr = effectivePanelAttrs[0] ?? FIRST_PANEL_ATTR;
  // VIEW-22a (#553) — draft state. Panel edits go into draftConditions and
  // are committed to the parent (and thereby to the search) ONLY when the
  // operator clicks „Zastosuj filtr". This stops the previous auto-apply
  // behaviour where picking an attribute alone wiped the list to 0 hits.
  const [draftConditions, setDraftConditions] = useState<FilterCondition[]>(conditions);
  const [draftMatchOperator, setDraftMatchOperator] = useState<'AND' | 'OR'>(matchOperator);
  // #2673 — scope drafts alongside the conditions; committed on apply.
  const [draftScope, setDraftScope] = useState<FilterScope | null>(scope ?? null);

  // VIEW-22a — re-seed draft only when the panel transitions from closed
  // → open. While open we keep the local draft and ignore parent prop
  // drift (e.g. a smart preset apply behind the panel) so the operator's
  // edits are not silently overwritten.
  // biome-ignore lint/correctness/useExhaustiveDependencies: intentional — only seed on open flip
  useEffect(() => {
    if (!open) return;
    setDraftConditions(conditions);
    setDraftMatchOperator(matchOperator);
    setDraftScope(scope ?? null);
  }, [open]);

  // Numeric fields keep the raw string (`"101,99"`) in the draft so the
  // operator can type the Polish decimal comma without the input resetting
  // mid-keystroke. Normalize to numbers at apply time (and for the live draft
  // feed) so the DSL serializer + Meili filter expression see the right type.
  const normaliseDraft = (conds: FilterCondition[]): FilterCondition[] =>
    conds.map((cond) => {
      const meta = effectivePanelAttrs.find((a) => a.code === cond.attr);
      const isNumeric = meta?.type === 'number' || meta?.type === 'metric';
      if (!isNumeric || typeof cond.value !== 'string') return cond;
      const trimmed = cond.value.replace(',', '.');
      const parsed = Number(trimmed);
      return Number.isFinite(parsed) ? { ...cond, value: parsed } : cond;
    });

  // #2640 — live draft feed for host-rendered match counters. Fires on every
  // draft edit; commit semantics (apply-gated) are untouched.
  // biome-ignore lint/correctness/useExhaustiveDependencies: notify on draft edits only
  useEffect(() => {
    if (!open) return;
    onDraftChange?.(
      normaliseDraft(draftConditions),
      draftMatchOperator,
      normalizeScope(draftScope),
    );
  }, [open, draftConditions, draftMatchOperator, draftScope]);

  if (!open) return null;

  const updateCondition = (idx: number, patch: Partial<FilterCondition>): void => {
    setDraftConditions(draftConditions.map((c, i) => (i === idx ? { ...c, ...patch } : c)));
  };
  const removeCondition = (idx: number): void => {
    setDraftConditions(draftConditions.filter((_, i) => i !== idx));
  };
  const addCondition = (): void => {
    setDraftConditions([...draftConditions, { attr: firstPanelAttr.code, op: '=', value: '' }]);
  };

  const commitAndApply = (): void => {
    setConditions(normaliseDraft(draftConditions));
    setMatchOperator(draftMatchOperator);
    setScope?.(normalizeScope(draftScope));
    onApply();
  };

  return (
    <section
      aria-label={t('products.advanced_filter.title', { defaultValue: 'Filtr zaawansowany' })}
      className="rounded-3xl bg-white shadow-md border border-zinc-100 overflow-hidden"
    >
      {/* Header */}
      <div className="px-5 h-12 flex items-center gap-3 border-b border-zinc-100">
        <span className="text-[11px] uppercase tracking-wider font-semibold text-zinc-500">
          {t('products.advanced_filter.title', { defaultValue: 'Filtr zaawansowany' })}
        </span>
        <div className="ml-auto flex items-center gap-2">
          <span className="text-[11.5px] text-zinc-500 tabular-nums">
            {t('products.advanced_filter.condition_count', {
              count: draftConditions.length,
              defaultValue: `${draftConditions.length} warunków`,
            })}
          </span>
          <button
            type="button"
            onClick={onClear}
            className="text-[12px] text-zinc-500 hover:text-zinc-900 px-2 h-7 rounded-lg hover:bg-zinc-100"
          >
            {t('products.advanced_filter.clear', { defaultValue: 'Wyczyść' })}
          </button>
          <button
            type="button"
            aria-label={t('app.close', { defaultValue: 'Close' })}
            onClick={onClose}
            className="h-7 w-7 grid place-items-center rounded-lg text-zinc-500 hover:bg-zinc-100"
          >
            <X className="size-4" />
          </button>
        </div>
      </div>

      {/* #2673 — value-context bar: the channel/locale every condition is
          evaluated against (with fallback to the global value). */}
      {scopeEnabled && (
        <div className="px-5 h-11 flex items-center gap-3 border-b border-zinc-100 bg-zinc-50/60">
          <Globe2 className="size-3.5 text-zinc-500" aria-hidden />
          <span className="text-[11px] uppercase tracking-wider font-semibold text-zinc-500">
            {t('products.advanced_filter.scope_label', { defaultValue: 'Kontekst' })}
          </span>
          <label className="flex items-center gap-1.5 text-[12px] text-zinc-600">
            {t('products.advanced_filter.scope_channel', { defaultValue: 'Kanał' })}
            <select
              value={draftScope?.channel ?? ''}
              onChange={(e) =>
                setDraftScope(
                  normalizeScope({ ...draftScope, channel: e.target.value || undefined }),
                )
              }
              aria-label={t('products.advanced_filter.scope_channel_aria', {
                defaultValue: 'Kanał kontekstu filtra',
              })}
              className="h-8 px-2 text-[12.5px] bg-white border border-zinc-200 rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-zinc-900 min-w-[130px]"
            >
              <option value="">
                {t('products.advanced_filter.scope_global', { defaultValue: '(globalny)' })}
              </option>
              {(scopeChannels ?? []).map((channel) => (
                <option key={channel.code} value={channel.code}>
                  {channel.name ?? channel.code}
                </option>
              ))}
            </select>
          </label>
          <label className="flex items-center gap-1.5 text-[12px] text-zinc-600">
            {t('products.advanced_filter.scope_locale', { defaultValue: 'Język' })}
            <select
              value={draftScope?.locale ?? ''}
              onChange={(e) =>
                setDraftScope(
                  normalizeScope({ ...draftScope, locale: e.target.value || undefined }),
                )
              }
              aria-label={t('products.advanced_filter.scope_locale_aria', {
                defaultValue: 'Język kontekstu filtra',
              })}
              className="h-8 px-2 text-[12.5px] bg-white border border-zinc-200 rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-zinc-900 min-w-[110px] font-mono uppercase"
            >
              <option value="">
                {t('products.advanced_filter.scope_global', { defaultValue: '(globalny)' })}
              </option>
              {(scopeLocales ?? []).map((code) => (
                <option key={code} value={code}>
                  {code}
                </option>
              ))}
            </select>
          </label>
          <span className="text-[11px] text-zinc-400">
            {t('products.advanced_filter.scope_hint', {
              defaultValue: 'Warunki liczone na wartościach tego kontekstu (fallback: globalne).',
            })}
          </span>
        </div>
      )}

      {/* Body */}
      <div className="p-5">
        <div className="space-y-2">
          {draftConditions.map((cond, idx) => {
            const attrMeta =
              effectivePanelAttrs.find((a) => a.code === cond.attr) ?? firstPanelAttr;
            const ops = FILTER_OPERATORS_BY_TYPE[attrMeta.type] ?? CORE_OPERATORS;
            // #2311 — the value control's shape follows the attribute type,
            // not a bare text box. Operators that encode the value in the
            // operator itself (empty checks, boolean truthiness) take no
            // value input; `IN`/`NOT IN` collect several option codes, so the
            // control switches to the multiselect variant regardless of the
            // attribute's own single/multi flavour.
            const noValueOp =
              cond.op === 'IS EMPTY' ||
              cond.op === 'IS NOT EMPTY' ||
              cond.op === '= TRUE' ||
              cond.op === '= FALSE';
            const isEmpty = noValueOp;
            const valueInputType =
              cond.op === 'IN' || cond.op === 'NOT IN' ? 'multiselect' : attrMeta.type;

            return (
              // Index-based key is acceptable here: the editor mutates
              // conditions in-place by index, so a stable identity would
              // require a synthetic id field on every condition. The
              // surrounding controls already key off the index too.
              // biome-ignore lint/suspicious/noArrayIndexKey: see comment
              <div key={`cond-${idx}`} className="flex items-center gap-2">
                {idx === 0 ? (
                  <span className="text-[11px] uppercase tracking-wider font-semibold text-zinc-500 w-12">
                    {t('products.advanced_filter.where_label', { defaultValue: 'Gdzie' })}
                  </span>
                ) : (
                  <select
                    value={draftMatchOperator}
                    onChange={(e) => setDraftMatchOperator(e.target.value as 'AND' | 'OR')}
                    aria-label="Conjunction"
                    className="h-9 w-12 text-[11px] uppercase tracking-wider font-semibold text-zinc-500 bg-zinc-50 rounded-lg px-1 outline-none focus-visible:ring-2 focus-visible:ring-zinc-900 border-0"
                  >
                    <option value="AND">AND</option>
                    <option value="OR">OR</option>
                  </select>
                )}

                <AttributePicker
                  value={cond.attr}
                  filterableOnly
                  systemFields={SYSTEM_PANEL_ATTRS}
                  onChange={(picked) => {
                    if (picked === null) return;
                    const nextAttrMeta = effectivePanelAttrs.find((a) => a.code === picked.code);
                    const inferredType =
                      nextAttrMeta?.type ??
                      (picked.type as undefined | keyof typeof FILTER_OPERATORS_BY_TYPE) ??
                      firstPanelAttr.type;
                    const nextOps = FILTER_OPERATORS_BY_TYPE[inferredType] ?? CORE_OPERATORS;
                    updateCondition(idx, {
                      attr: picked.code,
                      op: nextOps[0] ?? '=',
                      value: '',
                    });
                  }}
                  className="min-w-[200px]"
                />

                <span className="text-[10px] font-mono px-1.5 py-0.5 rounded bg-zinc-100 text-zinc-500">
                  {attrMeta.type}
                </span>

                <select
                  value={cond.op}
                  onChange={(e) => updateCondition(idx, { op: e.target.value as FilterOperator })}
                  aria-label="Operator"
                  className="h-9 px-2.5 text-[12.5px] bg-white border border-zinc-200 rounded-lg outline-none focus-visible:ring-2 focus-visible:ring-zinc-900 font-mono min-w-[120px]"
                >
                  {ops.map((o) => (
                    <option key={o} value={o}>
                      {operatorLabel(o, t)}
                    </option>
                  ))}
                </select>

                {!isEmpty && (
                  <div className="flex-1">
                    <BulkValueInput
                      attrCode={cond.attr}
                      attrType={valueInputType}
                      value={cond.value}
                      // BulkValueInput is generic (`unknown`) but only ever
                      // emits string | number | boolean | string[] per the
                      // attribute type — all valid FilterConditionValue.
                      onChange={(next) =>
                        updateCondition(idx, { value: next as FilterConditionValue })
                      }
                      placeholder={
                        attrMeta.type === 'number' || attrMeta.type === 'metric'
                          ? 'np. 101,99'
                          : 'wpisz wartość'
                      }
                    />
                  </div>
                )}
                {isEmpty && <div className="flex-1" />}

                <button
                  type="button"
                  onClick={() => removeCondition(idx)}
                  aria-label={t('products.advanced_filter.remove_condition')}
                  className="h-9 w-9 grid place-items-center text-zinc-500 hover:text-rose-600 hover:bg-rose-50 rounded-lg"
                >
                  <Trash2 className="size-4" />
                </button>
              </div>
            );
          })}
        </div>

        <button
          type="button"
          onClick={addCondition}
          className="mt-3 text-[12.5px] text-zinc-500 hover:text-zinc-900 inline-flex items-center gap-1.5 h-8 px-2.5 rounded-lg hover:bg-zinc-100 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900"
        >
          <Plus className="size-3.5" />
          {t('products.advanced_filter.add_condition', { defaultValue: 'Dodaj warunek' })}
        </button>
      </div>

      {/* Footer */}
      <div className="px-5 h-12 flex items-center gap-3 border-t border-zinc-100 bg-zinc-50/50">
        <div className="inline-flex items-center gap-2 text-[11.5px] text-zinc-500">
          <span>{t('products.advanced_filter.match_label', { defaultValue: 'Dopasuj:' })}</span>
          <div className="h-6 rounded-lg bg-white border border-zinc-200 inline-flex items-center p-0.5">
            <button
              type="button"
              onClick={() => setDraftMatchOperator('AND')}
              className={cn(
                'h-5 px-2 rounded-md text-[11px]',
                draftMatchOperator === 'AND'
                  ? 'bg-zinc-900 text-white font-medium'
                  : 'text-zinc-500',
              )}
            >
              {t('products.advanced_filter.match_all', { defaultValue: 'Wszystkie (AND)' })}
            </button>
            <button
              type="button"
              onClick={() => setDraftMatchOperator('OR')}
              className={cn(
                'h-5 px-2 rounded-md text-[11px]',
                draftMatchOperator === 'OR'
                  ? 'bg-zinc-900 text-white font-medium'
                  : 'text-zinc-500',
              )}
            >
              {t('products.advanced_filter.match_any', { defaultValue: 'Dowolne (OR)' })}
            </button>
          </div>
        </div>

        {draftConditions.length > 0 && (
          <span className="text-[11.5px] text-zinc-500 inline-flex items-center gap-1.5">
            <Link2 className="size-3.5" />
            <span>
              {t('products.advanced_filter.url_updated', { defaultValue: 'URL zaktualizowany' })}
              {resultCount !== undefined && (
                <span className="ml-1 tabular-nums">— {resultCount} wyników</span>
              )}
            </span>
          </span>
        )}

        <div className="ml-auto flex items-center gap-2">
          {onSaveAsView && (
            <Button
              variant="ghost"
              type="button"
              onClick={onSaveAsView}
              className="h-9 text-[12.5px]"
            >
              {t('products.advanced_filter.save_as_view', {
                defaultValue: 'Zapisz jako Saved View',
              })}
            </Button>
          )}
          {onSaveAsPreset && (
            <Button
              variant="ghost"
              type="button"
              onClick={onSaveAsPreset}
              disabled={draftConditions.length === 0}
              className="h-9 text-[12.5px]"
            >
              {t('products.advanced_filter.save_as_preset', {
                defaultValue: 'Zapisz jako Smart Preset',
              })}
            </Button>
          )}
          <Button
            type="button"
            onClick={commitAndApply}
            disabled={draftConditions.length === 0}
            className="h-9 px-4 rounded-xl bg-zinc-900 text-white text-[12.5px] font-medium hover:bg-zinc-800"
          >
            {t('products.advanced_filter.apply', { defaultValue: 'Zastosuj filtr' })}
          </Button>
        </div>
      </div>
    </section>
  );
}

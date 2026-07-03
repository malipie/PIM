import { useQuery } from '@tanstack/react-query';
import { Plus, Scale, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { jsonFetch } from '@/lib/http';

/**
 * DP-07 (#2037, ADR-0025) — builder for the ObjectType-level cross-field
 * rules (`object_types.validation_rules`). Two rule kinds:
 *   - compare:      [left attr] [op] [right attr] — numeric attributes only,
 *   - require_when: Gdy [attr] równa się [value] → wymagaj [attr].
 * State is local; one PATCH per explicit "Zapisz" via the page's
 * handlePatch({ validationRules }). Attribute options come from the global
 * library (tenant-wide validation contract — same source the backend guard
 * checks against).
 */

export interface CrossFieldRuleShape {
  type: string;
  [key: string]: unknown;
}

interface AttributeOption {
  id: string;
  code: string;
  type: string;
  system?: boolean;
}

const COMPARE_OPS = [
  { value: 'lt', label: '<' },
  { value: 'lte', label: '≤' },
  { value: 'gt', label: '>' },
  { value: 'gte', label: '≥' },
  { value: 'eq', label: '=' },
  { value: 'neq', label: '≠' },
] as const;

const NUMERIC_TYPES = new Set(['number', 'metric', 'price']);

export function ValidationRulesCard({
  rules,
  locked,
  onSave,
}: {
  rules: CrossFieldRuleShape[];
  locked?: boolean;
  onSave: (rules: CrossFieldRuleShape[]) => Promise<boolean>;
}) {
  const { t } = useTranslation();
  const [draft, setDraft] = useState<CrossFieldRuleShape[]>(rules);
  const [saving, setSaving] = useState(false);

  const attributesQuery = useQuery<AttributeOption[]>({
    queryKey: ['validation-rules-card', 'attributes'],
    queryFn: () =>
      jsonFetch<AttributeOption[]>('/api/attributes?itemsPerPage=200', {
        accept: 'application/json',
      }),
    staleTime: 60_000,
  });
  const attributes = (attributesQuery.data ?? []).filter((a) => a.system !== true);
  const numericAttributes = attributes.filter((a) => NUMERIC_TYPES.has(a.type));

  const dirty = JSON.stringify(draft) !== JSON.stringify(rules);

  const updateRule = (index: number, patch: Record<string, unknown>) => {
    setDraft((prev) => prev.map((rule, i) => (i === index ? { ...rule, ...patch } : rule)));
  };

  const removeRule = (index: number) => {
    setDraft((prev) => prev.filter((_, i) => i !== index));
  };

  const save = async () => {
    setSaving(true);
    const ok = await onSave(draft);
    setSaving(false);
    if (!ok) return;
  };

  return (
    <Card>
      <CardContent className="space-y-3 p-6">
        <div className="flex items-center gap-2">
          <Scale className="size-4 text-zinc-500" aria-hidden />
          <div className="text-[11px] font-medium uppercase tracking-wider text-zinc-500">
            {t('object_types.validation_rules_section', {
              defaultValue: 'Reguły walidacji (między polami)',
            })}
          </div>
        </div>
        <p className="text-[12px] text-muted-foreground">
          {t('object_types.validation_rules_hint', {
            defaultValue:
              'Reguły egzekwowane przy każdym zapisie wartości (ręcznym, imporcie, agencie) — na poziomie całego obiektu, np. waga netto ≤ waga brutto albo pole wymagane warunkowo.',
          })}
        </p>

        {draft.length === 0 ? (
          <p className="rounded-xl border border-dashed border-zinc-200 px-4 py-5 text-center text-[12.5px] text-muted-foreground">
            {t('object_types.validation_rules_empty', { defaultValue: 'Brak reguł.' })}
          </p>
        ) : (
          <div className="space-y-2">
            {draft.map((rule, index) => (
              <div
                // biome-ignore lint/suspicious/noArrayIndexKey: rows are positional edits of a JSON list — no stable identity exists
                key={`${rule.type}-${index}`}
                className="flex flex-wrap items-center gap-2 rounded-xl border border-zinc-200 px-3 py-2"
              >
                {rule.type === 'compare' ? (
                  <>
                    <AttrSelect
                      id={`rule-${index}-left`}
                      value={String(rule.left ?? '')}
                      options={numericAttributes}
                      onChange={(v) => updateRule(index, { left: v })}
                      disabled={locked}
                    />
                    <select
                      aria-label={t('object_types.validation_rules_op', {
                        defaultValue: 'Operator',
                      })}
                      value={String(rule.op ?? 'lte')}
                      onChange={(e) => updateRule(index, { op: e.target.value })}
                      disabled={locked}
                      className="h-9 rounded-md border border-input bg-background px-2 font-mono text-sm"
                    >
                      {COMPARE_OPS.map((op) => (
                        <option key={op.value} value={op.value}>
                          {op.label}
                        </option>
                      ))}
                    </select>
                    <AttrSelect
                      id={`rule-${index}-right`}
                      value={String(rule.right ?? '')}
                      options={numericAttributes}
                      onChange={(v) => updateRule(index, { right: v })}
                      disabled={locked}
                    />
                  </>
                ) : (
                  <RequireWhenRow
                    rule={rule}
                    attributes={attributes}
                    disabled={locked}
                    onChange={(patch) => updateRule(index, patch)}
                  />
                )}
                <button
                  type="button"
                  onClick={() => removeRule(index)}
                  disabled={locked}
                  aria-label={t('app.remove', { defaultValue: 'Usuń' })}
                  className="ml-auto grid size-7 place-items-center rounded-lg text-zinc-400 hover:bg-red-50 hover:text-red-600 disabled:opacity-40"
                >
                  <Trash2 className="size-3.5" />
                </button>
              </div>
            ))}
          </div>
        )}

        <div className="flex flex-wrap items-center gap-2 pt-1">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            disabled={locked}
            onClick={() =>
              setDraft((prev) => [...prev, { type: 'compare', left: '', op: 'lte', right: '' }])
            }
            className="h-8 rounded-lg px-2.5 text-[12px]"
          >
            <Plus className="size-3.5" />
            {t('object_types.validation_rules_add_compare', { defaultValue: 'Porównanie pól' })}
          </Button>
          <Button
            type="button"
            variant="ghost"
            size="sm"
            disabled={locked}
            onClick={() =>
              setDraft((prev) => [
                ...prev,
                {
                  type: 'require_when',
                  if: { field: '', operator: 'equals', value: '' },
                  // biome-ignore lint/suspicious/noThenProperty: `then` is the backend DSL key (ADR-0025), not a thenable
                  then: { required: '' },
                },
              ])
            }
            className="h-8 rounded-lg px-2.5 text-[12px]"
          >
            <Plus className="size-3.5" />
            {t('object_types.validation_rules_add_require', {
              defaultValue: 'Wymagane warunkowo',
            })}
          </Button>
          {dirty ? (
            <Button
              type="button"
              size="sm"
              disabled={saving || locked}
              onClick={() => void save()}
              className="ml-auto h-8 rounded-xl bg-zinc-900 px-3 text-[12px] hover:bg-zinc-800"
            >
              {t('app.save', { defaultValue: 'Zapisz' })}
            </Button>
          ) : null}
        </div>
      </CardContent>
    </Card>
  );
}

function AttrSelect({
  id,
  value,
  options,
  onChange,
  disabled,
  allTypes,
}: {
  id: string;
  value: string;
  options: AttributeOption[];
  onChange: (v: string) => void;
  disabled?: boolean;
  allTypes?: boolean;
}) {
  const { t } = useTranslation();
  return (
    <select
      id={id}
      aria-label={t('object_types.validation_rules_attribute', { defaultValue: 'Atrybut' })}
      value={value}
      onChange={(e) => onChange(e.target.value)}
      disabled={disabled}
      className="h-9 min-w-[150px] rounded-md border border-input bg-background px-2 font-mono text-sm"
    >
      <option value="">
        {t('object_types.validation_rules_pick', { defaultValue: '— wybierz —' })}
      </option>
      {options.map((attr) => (
        <option key={attr.id} value={attr.code}>
          {attr.code}
          {allTypes ? ` (${attr.type})` : ''}
        </option>
      ))}
    </select>
  );
}

function RequireWhenRow({
  rule,
  attributes,
  disabled,
  onChange,
}: {
  rule: CrossFieldRuleShape;
  attributes: AttributeOption[];
  disabled?: boolean;
  onChange: (patch: Record<string, unknown>) => void;
}) {
  const { t } = useTranslation();
  const condition = (rule.if ?? {}) as Record<string, unknown>;
  const then = (rule.then ?? {}) as Record<string, unknown>;

  const setCondition = (patch: Record<string, unknown>) =>
    onChange({ if: { operator: 'equals', ...condition, ...patch } });

  return (
    <>
      <span className="text-[12.5px] text-zinc-600">
        {t('object_types.validation_rules_when', { defaultValue: 'Gdy' })}
      </span>
      <AttrSelect
        id="rule-condition-field"
        value={String(condition.field ?? '')}
        options={attributes}
        onChange={(v) => setCondition({ field: v })}
        disabled={disabled}
        allTypes
      />
      <span className="text-[12.5px] text-zinc-600">
        {t('object_types.validation_rules_equals', { defaultValue: 'równa się' })}
      </span>
      <Input
        aria-label={t('object_types.validation_rules_value', { defaultValue: 'Wartość' })}
        value={valueToInput(condition.value)}
        onChange={(e) => setCondition({ value: inputToValue(e.target.value) })}
        disabled={disabled}
        placeholder="np. true / 5 / bulb"
        className="h-9 w-36 font-mono text-sm"
      />
      <span className="text-[12.5px] text-zinc-600">
        {t('object_types.validation_rules_then_require', { defaultValue: '→ wymagaj' })}
      </span>
      <AttrSelect
        id="rule-required-target"
        value={String(then.required ?? '')}
        options={attributes}
        // biome-ignore lint/suspicious/noThenProperty: `then` is the backend DSL key (ADR-0025), not a thenable
        onChange={(v) => onChange({ then: { required: v } })}
        disabled={disabled}
        allTypes
      />
    </>
  );
}

function valueToInput(value: unknown): string {
  if (value === undefined || value === null) return '';
  if (typeof value === 'string') return value;
  return JSON.stringify(value);
}

/** `true`/`false`/numbers get their JSON types back; everything else stays a string. */
function inputToValue(raw: string): unknown {
  const trimmed = raw.trim();
  if (trimmed === 'true') return true;
  if (trimmed === 'false') return false;
  if (trimmed !== '' && !Number.isNaN(Number(trimmed))) return Number(trimmed);
  return raw;
}

import { CheckCircle2, XCircle } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

/**
 * DP-05 (#2035) — operator-facing editor for the per-type
 * `Attribute.validationRules` JSONB. The engine (AttributeValueValidator +
 * TypeValidator/*) has enforced these keys since the write-path landed;
 * until now they were settable only via raw PATCH / seeders.
 *
 * Renders only the keys the matching backend validator actually reads —
 * the source of truth is `docs/api/jsonb-schemas.md` + the validator
 * classes, NOT wishful parity with the docs (e.g. price exposes
 * `min_amount` but not `max_amount`, which the engine ignores today).
 * Unknown keys already present in the JSONB (e.g. relation
 * `advanced_fields`) pass through untouched — the editor spreads over the
 * previous object instead of replacing it.
 */

export type ValidationRules = Record<string, unknown>;

interface Props {
  type: string;
  value: ValidationRules;
  onChange: (next: ValidationRules) => void;
  disabled?: boolean;
}

const IDENTIFIER_FORMATS = ['', 'ean13', 'gtin14', 'isbn13', 'isbn10'] as const;
// DP-06 (#2036) — text carries its own format family (TextValidator).
const TEXT_FORMATS = ['', 'url', 'iso_country'] as const;
const COLOR_FORMATS = ['hex', 'rgb'] as const;

/** Per-type map of which editors render. Types absent here have no configurable rules. */
const TYPE_FIELDS: Record<string, readonly string[]> = {
  text: ['min_length', 'max_length', 'text_format', 'pattern'],
  textarea: ['min_length', 'max_length'],
  wysiwyg: ['max_length'],
  email: ['pattern'],
  identifier: ['format', 'pattern'],
  number: ['min', 'max', 'decimal_precision'],
  metric: ['min', 'max', 'decimal_precision', 'units'],
  price: ['min_amount', 'max_amount', 'currencies'],
  date: ['min', 'max'],
  datetime: ['min', 'max'],
  multiselect: ['min_count', 'max_count'],
  tags: ['min_count', 'max_count'],
  color: ['color_format'],
};

export function hasValidationRulesUi(type: string): boolean {
  return (TYPE_FIELDS[type]?.length ?? 0) > 0;
}

export function ValidationRulesSection({ type, value, onChange, disabled }: Props) {
  const { t } = useTranslation();
  const fields = TYPE_FIELDS[type] ?? [];
  if (fields.length === 0) return null;

  const set = (key: string, raw: unknown) => {
    const next = { ...value };
    if (raw === undefined || raw === '' || (Array.isArray(raw) && raw.length === 0)) {
      delete next[key];
    } else {
      next[key] = raw;
    }
    onChange(next);
  };

  return (
    <div className="grid gap-4 sm:grid-cols-2">
      {fields.includes('min_length') ? (
        <NumberField
          id="rule-min-length"
          label={t('attributes.rules.min_length', { defaultValue: 'Min. długość' })}
          value={value.min_length}
          onChange={(n) => set('min_length', n)}
          disabled={disabled}
          integer
        />
      ) : null}
      {fields.includes('max_length') ? (
        <NumberField
          id="rule-max-length"
          label={t('attributes.rules.max_length', { defaultValue: 'Maks. długość' })}
          value={value.max_length}
          onChange={(n) => set('max_length', n)}
          disabled={disabled}
          integer
        />
      ) : null}
      {fields.includes('min') ? (
        <RuleField
          id="rule-min"
          label={t('attributes.rules.min', { defaultValue: 'Min' })}
          placeholder={type === 'date' || type === 'datetime' ? 'np. 2020-01-01' : 'np. 0'}
          value={value.min}
          onChange={(v) => set('min', type === 'date' || type === 'datetime' ? v : toNumber(v))}
          disabled={disabled}
        />
      ) : null}
      {fields.includes('max') ? (
        <RuleField
          id="rule-max"
          label={t('attributes.rules.max', { defaultValue: 'Maks' })}
          placeholder={type === 'date' || type === 'datetime' ? 'np. 2030-12-31' : 'np. 100'}
          value={value.max}
          onChange={(v) => set('max', type === 'date' || type === 'datetime' ? v : toNumber(v))}
          disabled={disabled}
        />
      ) : null}
      {fields.includes('decimal_precision') ? (
        <NumberField
          id="rule-precision"
          label={t('attributes.rules.decimal_precision', {
            defaultValue: 'Miejsca dziesiętne (maks)',
          })}
          value={value.decimal_precision}
          onChange={(n) => set('decimal_precision', n)}
          disabled={disabled}
          integer
        />
      ) : null}
      {fields.includes('min_amount') ? (
        <RuleField
          id="rule-min-amount"
          label={t('attributes.rules.min_amount', { defaultValue: 'Min. kwota' })}
          placeholder="np. 0"
          value={value.min_amount}
          onChange={(v) => set('min_amount', toNumber(v))}
          disabled={disabled}
        />
      ) : null}
      {fields.includes('max_amount') ? (
        <RuleField
          id="rule-max-amount"
          label={t('attributes.rules.max_amount', { defaultValue: 'Maks. kwota' })}
          placeholder="np. 9999"
          value={value.max_amount}
          onChange={(v) => set('max_amount', toNumber(v))}
          disabled={disabled}
        />
      ) : null}
      {fields.includes('min_count') ? (
        <NumberField
          id="rule-min-count"
          label={t('attributes.rules.min_count', { defaultValue: 'Min. liczba wyborów' })}
          value={value.min_count}
          onChange={(n) => set('min_count', n)}
          disabled={disabled}
          integer
        />
      ) : null}
      {fields.includes('max_count') ? (
        <NumberField
          id="rule-max-count"
          label={t('attributes.rules.max_count', { defaultValue: 'Maks. liczba wyborów' })}
          value={value.max_count}
          onChange={(n) => set('max_count', n)}
          disabled={disabled}
          integer
        />
      ) : null}
      {fields.includes('units') ? (
        <ListField
          id="rule-units"
          label={t('attributes.rules.units', { defaultValue: 'Dozwolone jednostki' })}
          placeholder="np. kg, g, mg"
          value={value.units}
          onChange={(list) => set('units', list)}
          disabled={disabled}
        />
      ) : null}
      {fields.includes('currencies') ? (
        <ListField
          id="rule-currencies"
          label={t('attributes.rules.currencies', { defaultValue: 'Dozwolone waluty (ISO 4217)' })}
          placeholder="np. PLN, EUR, USD"
          value={value.currencies}
          onChange={(list) =>
            set(
              'currencies',
              list.map((c) => c.toUpperCase()),
            )
          }
          disabled={disabled}
        />
      ) : null}
      {fields.includes('format') ? (
        <div>
          <Label className="text-[11.5px] font-medium text-muted-foreground" htmlFor="rule-format">
            {t('attributes.rules.format', { defaultValue: 'Format (suma kontrolna)' })}
          </Label>
          <select
            id="rule-format"
            value={typeof value.format === 'string' ? value.format : ''}
            onChange={(e) => set('format', e.target.value)}
            disabled={disabled}
            className="mt-1.5 h-10 w-full rounded-md border border-input bg-background px-3 font-mono text-sm"
          >
            {IDENTIFIER_FORMATS.map((f) => (
              <option key={f} value={f}>
                {f === ''
                  ? t('attributes.rules.format_none', { defaultValue: '— brak —' })
                  : f.toUpperCase()}
              </option>
            ))}
          </select>
        </div>
      ) : null}
      {fields.includes('text_format') ? (
        <div>
          <Label
            className="text-[11.5px] font-medium text-muted-foreground"
            htmlFor="rule-text-format"
          >
            {t('attributes.rules.text_format', { defaultValue: 'Format wartości' })}
          </Label>
          <select
            id="rule-text-format"
            value={typeof value.format === 'string' ? value.format : ''}
            onChange={(e) => {
              const next = { ...value };
              if (e.target.value === '') {
                delete next.format;
                delete next.require_https;
              } else {
                next.format = e.target.value;
                if (e.target.value !== 'url') delete next.require_https;
              }
              onChange(next);
            }}
            disabled={disabled}
            className="mt-1.5 h-10 w-full rounded-md border border-input bg-background px-3 font-mono text-sm"
          >
            {TEXT_FORMATS.map((f) => (
              <option key={f} value={f}>
                {f === ''
                  ? t('attributes.rules.format_none', { defaultValue: '— brak —' })
                  : f === 'url'
                    ? t('attributes.rules.text_format_url', { defaultValue: 'URL' })
                    : t('attributes.rules.text_format_iso_country', {
                        defaultValue: 'Kod kraju (ISO 3166-1)',
                      })}
              </option>
            ))}
          </select>
          {value.format === 'url' ? (
            <label className="mt-2 flex items-center gap-2 text-[12px] text-zinc-700">
              <input
                type="checkbox"
                checked={value.require_https === true}
                onChange={(e) => set('require_https', e.target.checked ? true : undefined)}
                disabled={disabled}
                className="size-3.5 accent-zinc-900"
              />
              {t('attributes.rules.require_https', { defaultValue: 'Wymagaj https' })}
            </label>
          ) : null}
        </div>
      ) : null}
      {fields.includes('color_format') ? (
        <div>
          <Label
            className="text-[11.5px] font-medium text-muted-foreground"
            htmlFor="rule-color-format"
          >
            {t('attributes.rules.color_format', { defaultValue: 'Format koloru' })}
          </Label>
          <select
            id="rule-color-format"
            value={typeof value.color_format === 'string' ? value.color_format : 'hex'}
            onChange={(e) => set('color_format', e.target.value)}
            disabled={disabled}
            className="mt-1.5 h-10 w-full rounded-md border border-input bg-background px-3 font-mono text-sm"
          >
            {COLOR_FORMATS.map((f) => (
              <option key={f} value={f}>
                {f}
              </option>
            ))}
          </select>
        </div>
      ) : null}
      {fields.includes('pattern') ? (
        <PatternField
          value={typeof value.pattern === 'string' ? value.pattern : ''}
          onChange={(v) => set('pattern', v)}
          disabled={disabled}
        />
      ) : null}
    </div>
  );
}

/**
 * Regex editor with a live tester: the operator types a sample value and
 * sees green/red instantly — no round-trip to the API. Mirrors the PCRE
 * pattern the backend runs; JS RegExp covers the shared subset (the
 * jsonb-schemas contract requires JS-compatible patterns).
 */
function PatternField({
  value,
  onChange,
  disabled,
}: {
  value: string;
  onChange: (v: string) => void;
  disabled?: boolean;
}) {
  const { t } = useTranslation();
  const [sample, setSample] = useState('');

  const verdict = useMemo(() => {
    if (value === '' || sample === '') return null;
    try {
      return new RegExp(value).test(sample);
    } catch {
      return 'invalid' as const;
    }
  }, [value, sample]);

  return (
    <div className="sm:col-span-2">
      <Label className="text-[11.5px] font-medium text-muted-foreground" htmlFor="rule-pattern">
        {t('attributes.rules.pattern', { defaultValue: 'Wzorzec (regex)' })}
      </Label>
      <Input
        id="rule-pattern"
        value={value}
        onChange={(e) => onChange(e.target.value)}
        placeholder="np. ^\d{13}$"
        disabled={disabled}
        className="mt-1.5 h-10 font-mono"
      />
      {value !== '' ? (
        <div className="mt-2 flex items-center gap-2">
          <Input
            value={sample}
            onChange={(e) => setSample(e.target.value)}
            placeholder={t('attributes.rules.pattern_sample', {
              defaultValue: 'Przetestuj przykładową wartość…',
            })}
            disabled={disabled}
            className="h-8 max-w-xs text-[12.5px]"
          />
          {verdict === true ? (
            <span className="inline-flex items-center gap-1 text-[12px] font-medium text-emerald-600">
              <CheckCircle2 className="size-3.5" />
              {t('attributes.rules.pattern_match', { defaultValue: 'pasuje' })}
            </span>
          ) : verdict === false ? (
            <span className="inline-flex items-center gap-1 text-[12px] font-medium text-red-600">
              <XCircle className="size-3.5" />
              {t('attributes.rules.pattern_no_match', { defaultValue: 'nie pasuje' })}
            </span>
          ) : verdict === 'invalid' ? (
            <span className="text-[12px] font-medium text-amber-600">
              {t('attributes.rules.pattern_invalid', { defaultValue: 'niepoprawny regex' })}
            </span>
          ) : null}
        </div>
      ) : null}
    </div>
  );
}

function RuleField({
  id,
  label,
  placeholder,
  value,
  onChange,
  disabled,
}: {
  id: string;
  label: string;
  placeholder?: string;
  value: unknown;
  onChange: (raw: string) => void;
  disabled?: boolean;
}) {
  return (
    <div>
      <Label className="text-[11.5px] font-medium text-muted-foreground" htmlFor={id}>
        {label}
      </Label>
      <Input
        id={id}
        value={value === undefined || value === null ? '' : String(value)}
        onChange={(e) => onChange(e.target.value)}
        placeholder={placeholder}
        disabled={disabled}
        className="mt-1.5 h-10 font-mono"
      />
    </div>
  );
}

function NumberField({
  id,
  label,
  value,
  onChange,
  disabled,
  integer,
}: {
  id: string;
  label: string;
  value: unknown;
  onChange: (n: number | undefined) => void;
  disabled?: boolean;
  integer?: boolean;
}) {
  return (
    <div>
      <Label className="text-[11.5px] font-medium text-muted-foreground" htmlFor={id}>
        {label}
      </Label>
      <Input
        id={id}
        type="number"
        step={integer ? 1 : 'any'}
        min={0}
        value={value === undefined || value === null ? '' : String(value)}
        onChange={(e) => {
          const raw = e.target.value;
          if (raw === '') {
            onChange(undefined);
            return;
          }
          const parsed = integer ? Number.parseInt(raw, 10) : Number(raw);
          onChange(Number.isNaN(parsed) ? undefined : parsed);
        }}
        disabled={disabled}
        className="mt-1.5 h-10 font-mono"
      />
    </div>
  );
}

/** Comma-separated list editor mapped to a string[] rule (units, currencies). */
function ListField({
  id,
  label,
  placeholder,
  value,
  onChange,
  disabled,
}: {
  id: string;
  label: string;
  placeholder?: string;
  value: unknown;
  onChange: (list: string[]) => void;
  disabled?: boolean;
}) {
  const [draft, setDraft] = useState(() =>
    Array.isArray(value) ? value.map(String).join(', ') : '',
  );
  return (
    <div>
      <Label className="text-[11.5px] font-medium text-muted-foreground" htmlFor={id}>
        {label}
      </Label>
      <Input
        id={id}
        value={draft}
        onChange={(e) => {
          setDraft(e.target.value);
          onChange(
            e.target.value
              .split(',')
              .map((s) => s.trim())
              .filter((s) => s !== ''),
          );
        }}
        placeholder={placeholder}
        disabled={disabled}
        className="mt-1.5 h-10 font-mono"
      />
    </div>
  );
}

function toNumber(raw: string): number | undefined {
  if (raw.trim() === '') return undefined;
  const parsed = Number(raw);
  return Number.isNaN(parsed) ? undefined : parsed;
}

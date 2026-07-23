import { Copy, CornerDownRight, Link2, Lock, Sparkles } from 'lucide-react';
import { lazy, Suspense } from 'react';
import { useTranslation } from 'react-i18next';
import { RelationCreateField } from '@/components/objects/relation-create-field';
import { type Provenance, ProvenanceBadge } from '@/components/provenance-badge';
import { Combobox, type ComboboxOption } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { MultiSelect, type MultiSelectOption } from '@/components/ui/multi-select';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

import { AssetField } from './asset-field';
import { isAttributeRequired } from './product-detail-helpers';
import { RelationInlineEditor } from './relation-inline-editor';
import type { AttributeMeta, AttributeOptionMeta, ProductLocale } from './types';

// AUD-071 (#1614) — the WYSIWYG editor drags in the entire @udecode/plate
// (Slate) stack + dompurify, which dominated the product-detail-page chunk
// even though it only renders for `wysiwyg`-typed attributes. Code-split it
// so the editor stack downloads on demand the first time a rich-text field
// appears, instead of on every product detail load.
const WysiwygEditor = lazy(() =>
  import('@/components/catalog/wysiwyg-editor').then((m) => ({ default: m.WysiwygEditor })),
);

// Minimal placeholder while the editor chunk loads — sized to the editor's
// idle height so the form doesn't jump.
function WysiwygFallback() {
  return (
    <div
      className="min-h-[7.5rem] w-full animate-pulse rounded-xl border border-zinc-200 bg-zinc-50"
      aria-hidden
    />
  );
}

export interface AttrRowProps {
  attribute: AttributeMeta;
  value: unknown;
  provenance: Provenance;
  /** AGENT-P6-05 (#1978) — agent run id for the badge tooltip. */
  provenanceSource?: string | null;
  /**
   * AICG-P5-02 (#2340) — content-generation audit for the badge tooltip:
   * the attribute codes the copy was generated from + the recipe id
   * (provenance_meta.source_attributes / recipe_id, jsonb-schemas §5).
   */
  provenanceSourceAttributes?: string[] | null;
  provenanceRecipeId?: string | null;
  /**
   * AICG-P5-01 (#2339) — when present, an "Ask AI" affordance renders in
   * the RHS slot (next to the provenance badge). The page supplies it
   * only for editable text-family attributes.
   */
  onAskAI?: () => void;
  locale: ProductLocale;
  isEditing: boolean;
  isLocked: boolean;
  onChange: (next: unknown) => void;
  /**
   * VIEW-07.3 (#432) — when present, the RHS slot renders a Copy
   * action instead of the ProvenanceBadge. Used by `VariantsTabHost`
   * to let operators broadcast a single attribute value to every
   * other variant. The provenance signal is preserved in the button's
   * tooltip so we don't lose it for Faza 2 inheritance work.
   */
  onCopyToOthers?: () => void;
  /**
   * MODRC-05 (#1084) — when supplied and the attribute is `type='relation'`,
   * AttrRow renders the inline relation editor (picker + grid) instead of
   * a plain text input. The parent passes the current object id so the
   * editor can fetch `/api/objects/{id}/relations` and reuse the same
   * cache key as the dedicated Relations tab.
   */
  relationContextProductId?: string;
  /**
   * #1102 — opt-in: in the object create flow (`UniversalCreatePage`)
   * there is no productId yet, so a relation attribute renders
   * `RelationCreateField` (search + multi-select of target objects).
   * The chosen ids land in the parent's dirty-fields dict and are
   * sent through PUT `/api/objects/{newId}/relations/{attributeCode}`
   * after the main POST succeeds.
   */
  createMode?: boolean;
  /**
   * #1220 — active channel code (e.g. "shopify"). When provided and
   * the attribute has `is_scopable=true`, a sky-coloured channel chip
   * is rendered next to the label — mirroring the locale chip for
   * `is_localizable` attributes.
   */
  channel?: string | null;
  /**
   * #1222 — when true the value shown is inherited from a fallback
   * locale (not an exact match for the requested locale).
   */
  isInherited?: boolean;
  /**
   * #1222 — the locale code the value was inherited from
   * (e.g. "en" when requested "de" fell back to "en").
   */
  inheritedFrom?: string | null;
  /**
   * #1350 — set by the detail page when a save was blocked because this
   * required attribute is empty: red highlight + "Pole wymagane" note.
   */
  requiredError?: boolean;
  /**
   * Set when the backend rejected this attribute's value (min/max, pattern, …)
   * on the last save: same red highlight as {@link requiredError}, no note (the
   * server message is surfaced as a toast).
   */
  validationError?: boolean;
}

/**
 * VIEW-07 (#420) — single attribute row mirrored from
 * `Zrodla/Front_Claude_Design/design_handoff_modelowanie/src/produkty/detail-view.jsx`
 * lines 25–61. Layout grid `[180px_1fr_auto]`, hover surface, inline
 * input/textarea when the page is in edit mode and the field is not
 * locked or system-owned.
 */
export function AttrRow({
  attribute,
  value,
  provenance,
  provenanceSource,
  provenanceSourceAttributes = null,
  provenanceRecipeId = null,
  onAskAI,
  locale,
  isEditing,
  isLocked,
  onChange,
  onCopyToOthers,
  relationContextProductId,
  createMode = false,
  channel = null,
  isInherited = false,
  inheritedFrom = null,
  requiredError = false,
  validationError = false,
}: AttrRowProps) {
  const { t, i18n } = useTranslation();
  const lang = i18n.language === 'pl' ? 'pl' : 'en';
  // #1262 — option labels are part of the VALUE, so they must follow the
  // active value locale (the `locale` prop set by the locale toolbar), not
  // the interface language. Falls back to the interface lang when no scope
  // is active.
  const valueLang = locale ?? lang;
  // #1352 — the attribute NAME on the card now follows the selected display
  // language too: previously it was pinned to the UI chrome `lang` (pl/en),
  // so a DE name never showed and the EN name appeared to "have no effect"
  // when another locale was selected. Resolve against the selected locale
  // first, then degrade through the UI language and any available
  // translation before the raw code.
  const label =
    attribute.label[valueLang] ??
    attribute.label[lang] ??
    attribute.label.en ??
    attribute.label.pl ??
    Object.values(attribute.label)[0] ??
    attribute.code;
  const editable = isEditing && !isLocked;
  const stringValue = typeof value === 'string' ? value : value == null ? '' : String(value);
  const localeChip = isLocaleScoped(attribute) ? locale : null;
  const channelChip = attribute.is_scopable === true && channel ? channel : null;
  const isSelectLike = attribute.type === 'select' || attribute.type === 'multiselect';
  const selectOptions = isSelectLike ? (attribute.options ?? []) : [];

  return (
    <div
      className={cn(
        'grid grid-cols-[180px_minmax(0,1fr)_auto] items-start gap-3 rounded-xl px-3 py-2.5',
        'group transition-colors hover:bg-white/60',
        (requiredError || validationError) && 'bg-rose-50/60 ring-1 ring-rose-300',
      )}
    >
      <div className="flex items-center gap-1.5 pt-1.5 text-[13px] font-medium text-zinc-600">
        <span>{label}</span>
        {attribute.type === 'relation' ? (
          <span
            role="img"
            aria-label={relationTooltip(attribute, t)}
            title={relationTooltip(attribute, t)}
            className="inline-flex size-3.5 items-center justify-center text-sky-500"
          >
            <Link2 className="size-3" aria-hidden />
          </span>
        ) : null}
        {localeChip ? (
          <span
            title={t('products.detail.field.locale_aria', {
              locale: localeChip.toUpperCase(),
              defaultValue: 'Locale {{locale}}',
            })}
            className="rounded bg-zinc-100 px-1 py-0.5 font-mono text-[9px] uppercase text-zinc-500"
          >
            {localeChip}
          </span>
        ) : null}
        {channelChip ? (
          <span
            title={t('products.detail.field.channel_aria', {
              channel: channelChip.toUpperCase(),
              defaultValue: 'Kanał {{channel}}',
            })}
            className="rounded bg-sky-100 px-1 py-0.5 font-mono text-[9px] uppercase text-sky-600"
          >
            {channelChip}
          </span>
        ) : null}
        {isInherited && inheritedFrom ? (
          <span
            title={t('products.detail.field.inherited_from', {
              locale: inheritedFrom.toUpperCase(),
              defaultValue: 'Wartość z [{{locale}}]',
            })}
            className="inline-flex items-center text-amber-400"
          >
            <CornerDownRight
              className="size-3"
              aria-hidden
              aria-label={t('products.detail.field.inherited_from', {
                locale: inheritedFrom.toUpperCase(),
                defaultValue: 'Wartość z [{{locale}}]',
              })}
            />
          </span>
        ) : null}
        {/* #1207 — system attributes (created_at/by, updated_at/by) stay
            read-only but are treated as normal fields: no lock chrome. */}
        {isLocked && !attribute.is_system ? (
          <Lock className="size-3 text-zinc-300" aria-hidden />
        ) : null}
        {/* #1350 reopen #2 / #1673 — asterisk + save-guard share one rule
            (isAttributeRequired): global OR group-level required, never for
            booleans (unchecked = the value `false`). */}
        {isAttributeRequired(attribute) ? (
          <span
            className="text-rose-500"
            title={t('app.required', { defaultValue: 'wymagane' })}
            aria-hidden
          >
            *
          </span>
        ) : null}
        {requiredError ? (
          <span className="text-[11px] font-medium text-rose-600" role="alert">
            {t('products.detail.validation.field_required', { defaultValue: 'Pole wymagane' })}
          </span>
        ) : null}
      </div>

      <div className="min-w-0">
        {/* Issue #1094 — relation attrs render their full inline editor
            regardless of the page-level Edytuj toggle. RelationInlineEditor
            is self-contained: own query (`['objects', productId, 'relations']`),
            modal-driven picker, mutations against /api/objects/{id}/relations.
            It does not participate in the dirty-fields flow, so it should
            never live behind `editable`. Pre-#1093 the synthetic Relations
            tab rendered RelationsTab standalone, also bypassing the page
            toggle; this preserves that UX for the inline path. */}
        {attribute.type === 'asset' ? (
          // #1138 — asset attrs render a library picker + thumbnail
          // instead of a plain text input. AssetField is self-contained
          // (own preview query, modal picker) and handles both the edit
          // and read-only states via `isEditing`.
          <AssetField
            value={value}
            isEditing={editable}
            onChange={(next) => onChange(next)}
            ariaLabel={label}
          />
        ) : attribute.type === 'relation' &&
          typeof relationContextProductId === 'string' &&
          relationContextProductId.length > 0 ? (
          <RelationInlineEditor
            productId={relationContextProductId}
            attributeId={attribute.id}
            attributeCode={attribute.code}
          />
        ) : attribute.type === 'relation' && createMode && editable ? (
          <RelationCreateField attribute={attribute} value={value} onChange={onChange} />
        ) : editable ? (
          attribute.type === 'wysiwyg' ? (
            <Suspense fallback={<WysiwygFallback />}>
              <WysiwygEditor
                value={stringValue}
                onChange={(next) => onChange(next)}
                ariaLabel={label}
              />
            </Suspense>
          ) : attribute.type === 'richtext' || attribute.type === 'textarea' ? (
            <Textarea
              id={`attr-${attribute.code}`}
              aria-label={label}
              rows={3}
              value={stringValue}
              onChange={(event) => onChange(event.target.value)}
              className="w-full rounded-xl border-zinc-200 bg-white px-3 py-2 text-[13.5px]"
            />
          ) : attribute.type === 'number' ? (
            <Input
              id={`attr-${attribute.code}`}
              aria-label={label}
              type="number"
              value={typeof value === 'number' ? value : stringValue}
              onChange={(event) => {
                const parsed = Number.parseFloat(event.target.value);
                onChange(Number.isNaN(parsed) ? null : parsed);
              }}
              className="w-full rounded-xl border-zinc-200 bg-white px-3 py-2 text-[13.5px]"
            />
          ) : attribute.type === 'boolean' ? (
            <input
              id={`attr-${attribute.code}`}
              aria-label={label}
              type="checkbox"
              checked={value === true}
              onChange={(event) => onChange(event.target.checked)}
              className="size-4 rounded"
            />
          ) : attribute.type === 'date' ? (
            <Input
              id={`attr-${attribute.code}`}
              aria-label={label}
              type="date"
              value={readDateValue(value)}
              onChange={(event) => onChange(event.target.value === '' ? null : event.target.value)}
              className="w-full rounded-xl border-zinc-200 bg-white px-3 py-2 text-[13.5px]"
            />
          ) : attribute.type === 'datetime' ? (
            <Input
              id={`attr-${attribute.code}`}
              aria-label={label}
              type="datetime-local"
              value={readDatetimeValue(value)}
              onChange={(event) => onChange(event.target.value === '' ? null : event.target.value)}
              className="w-full rounded-xl border-zinc-200 bg-white px-3 py-2 text-[13.5px]"
            />
          ) : attribute.type === 'color' ? (
            // #1177 — native colour picker paired with a hex text field so
            // operators can paste an exact `#RRGGBB` or pick visually. The
            // picker falls back to black when the stored value is not a
            // valid hex (e.g. empty / legacy rgb), but the text field keeps
            // showing the raw value for editing.
            <div className="flex items-center gap-2">
              <input
                id={`attr-${attribute.code}`}
                type="color"
                value={isHexColor(stringValue) ? stringValue : '#000000'}
                onChange={(event) => onChange(event.target.value)}
                className="h-9 w-12 cursor-pointer rounded-lg border border-zinc-200 bg-white p-1"
                aria-label={label}
              />
              <Input
                type="text"
                aria-label={label}
                value={stringValue}
                onChange={(event) => onChange(event.target.value)}
                placeholder="#RRGGBB"
                className="w-32 rounded-xl border-zinc-200 bg-white px-3 py-2 font-mono text-[13px]"
              />
            </div>
          ) : attribute.type === 'email' ? (
            <Input
              id={`attr-${attribute.code}`}
              aria-label={label}
              type="email"
              value={stringValue}
              onChange={(event) => onChange(event.target.value)}
              placeholder="name@example.com"
              className="w-full rounded-xl border-zinc-200 bg-white px-3 py-2 text-[13.5px]"
            />
          ) : attribute.type === 'identifier' ? (
            // #1179 — EAN/GTIN/ISBN/SKU. Monospace so codes are easy to scan;
            // uniqueness per ObjectType is enforced server-side (409 on save).
            <Input
              id={`attr-${attribute.code}`}
              aria-label={label}
              type="text"
              inputMode="text"
              value={stringValue}
              onChange={(event) => onChange(event.target.value)}
              className="w-full rounded-xl border-zinc-200 bg-white px-3 py-2 font-mono text-[13px]"
            />
          ) : attribute.type === 'select' ? (
            <Combobox
              options={toComboboxOptions(selectOptions, valueLang)}
              value={typeof value === 'string' && value !== '' ? value : null}
              onChange={(next) => onChange(next)}
              placeholder={t('products.detail.field.select_placeholder', {
                defaultValue: 'Wybierz…',
              })}
              className="rounded-xl text-[13.5px]"
            />
          ) : attribute.type === 'multiselect' ? (
            <MultiSelect
              options={toMultiSelectOptions(selectOptions, valueLang)}
              value={readMultiselectValue(value)}
              onChange={(next) => onChange(next)}
              placeholder={t('products.detail.field.multiselect_placeholder', {
                defaultValue: 'Wybierz…',
              })}
              className="rounded-xl text-[13.5px]"
            />
          ) : attribute.type === 'price' ? (
            // #1881 — a price is a plain numeric amount. Currency was a
            // mockup relic (#394) that the product UI never collected, so
            // render + emit exactly like `number`; the backend canonicaliser
            // wraps the bare amount into the `{amount}` envelope.
            <Input
              id={`attr-${attribute.code}`}
              aria-label={label}
              type="number"
              inputMode="decimal"
              value={readPriceAmount(value)}
              onChange={(event) => {
                const parsed = Number.parseFloat(event.target.value);
                onChange(Number.isNaN(parsed) ? null : parsed);
              }}
              className="w-full rounded-xl border-zinc-200 bg-white px-3 py-2 text-[13.5px]"
            />
          ) : (
            <Input
              id={`attr-${attribute.code}`}
              aria-label={label}
              type="text"
              value={stringValue}
              onChange={(event) => onChange(event.target.value)}
              className="w-full rounded-xl border-zinc-200 bg-white px-3 py-2 text-[13.5px]"
            />
          )
        ) : attribute.type === 'wysiwyg' ? (
          <Suspense fallback={<WysiwygFallback />}>
            <WysiwygEditor value={stringValue} onChange={() => undefined} readOnly />
          </Suspense>
        ) : attribute.type === 'color' && isHexColor(stringValue) ? (
          <div className="flex items-center gap-2 px-3 py-2 text-[13.5px]">
            <span
              className="size-4 rounded border border-zinc-200"
              style={{ background: stringValue }}
              aria-hidden
            />
            <span className="font-mono text-[13px] text-ink">{stringValue}</span>
          </div>
        ) : attribute.type === 'email' && stringValue !== '' ? (
          <div className="px-3 py-2 text-[13.5px]">
            <a href={`mailto:${stringValue}`} className="text-accent-blue hover:underline">
              {stringValue}
            </a>
          </div>
        ) : (
          <div
            className={cn(
              'rounded-xl border border-transparent px-3 py-2 text-[13.5px]',
              isLocked ? 'cursor-default text-zinc-700' : 'text-ink',
            )}
          >
            {renderReadOnlyValue(attribute, value, selectOptions, valueLang) ?? (
              <span className="italic text-zinc-500">
                {t('products.detail.field.empty', { defaultValue: '—' })}
              </span>
            )}
          </div>
        )}
      </div>

      <div className="flex items-center gap-1 pt-1.5">
        {onAskAI !== undefined && (
          <button
            type="button"
            onClick={onAskAI}
            title={t('aicg.ask_title', {
              defaultValue: 'Zapytaj AI — wygeneruj treść z atrybutów produktu',
            })}
            aria-label={t('aicg.ask_aria', {
              defaultValue: 'Wygeneruj treść pola {{label}} z atrybutów produktu',
              label,
            })}
            data-testid={`ask-ai-${attribute.code}`}
            className="rounded-md p-1.5 text-purple-500 transition-colors hover:bg-purple-100 hover:text-purple-700"
          >
            <Sparkles className="size-3.5" aria-hidden />
          </button>
        )}
        {onCopyToOthers !== undefined ? (
          <button
            type="button"
            onClick={onCopyToOthers}
            title={t('products.detail.variants.copy_to_others', {
              defaultValue: 'Kopiuj do innych wariantów · Source: {{provenance}}',
              provenance: t(`provenance.${provenance}`, { defaultValue: provenance }),
            })}
            aria-label={t('products.detail.variants.copy_to_others_aria', {
              defaultValue: 'Kopiuj wartość {{label}} do innych wariantów',
              label,
            })}
            className="rounded-md p-1.5 text-zinc-500 transition-colors hover:bg-zinc-100 hover:text-zinc-700"
          >
            <Copy className="size-3.5" aria-hidden />
          </button>
        ) : (
          <ProvenanceBadge
            provenance={provenance}
            source={provenanceSource ?? undefined}
            sourceAttributes={provenanceSourceAttributes ?? undefined}
            recipeId={provenanceRecipeId ?? undefined}
          />
        )}
      </div>
    </div>
  );
}

/**
 * MODR-05 (#927) — short text shown next to the link icon on relation
 * attributes. Resolves to a list of target ObjectType IDs (codes are
 * not yet on `AttributeMeta`; the ID list is enough for a defensible
 * tooltip until MODR-08 adds richer target metadata).
 */
function relationTooltip(
  attribute: AttributeMeta,
  t: (key: string, options?: { defaultValue?: string; count?: number }) => string,
): string {
  const ids = attribute.relation_target_object_type_ids ?? [];
  if (ids.length === 0) {
    return t('products.detail.relation_tooltip_generic', {
      defaultValue: 'Pole linkuje do innych obiektów',
    });
  }
  return t('products.detail.relation_tooltip_with_count', {
    defaultValue: 'Pole linkuje do obiektów ({{count}} typ(y))',
    count: ids.length,
  });
}

function isLocaleScoped(attribute: AttributeMeta): boolean {
  // #1150 — the backend now ships the real `is_localizable` flag (#1151),
  // so the locale chip reflects whether the value is actually per-locale
  // instead of guessing from the code suffix / type.
  return attribute.is_localizable === true;
}

function optionLabel(option: AttributeOptionMeta, lang: string): string {
  return option.label[lang] ?? option.label.en ?? option.label.pl ?? option.code;
}

function toComboboxOptions(options: AttributeOptionMeta[], lang: string): ComboboxOption[] {
  return options.map((o) => ({
    value: o.code,
    label: optionLabel(o, lang),
  }));
}

function toMultiSelectOptions(options: AttributeOptionMeta[], lang: string): MultiSelectOption[] {
  return options.map((o) => ({
    value: o.code,
    label: optionLabel(o, lang),
    color: o.color ?? null,
    deprecated: o.is_deprecated === true,
  }));
}

function readMultiselectValue(value: unknown): string[] {
  if (!Array.isArray(value)) return [];
  return value.filter((entry): entry is string => typeof entry === 'string');
}

/**
 * `<input type="date">` only accepts the `YYYY-MM-DD` slice. Backend
 * may send the same shape (preferred) or a full ISO 8601 string
 * (legacy seeds) — strip the time component either way and reject
 * anything that does not look like a valid date.
 */
function readDateValue(value: unknown): string {
  if (typeof value !== 'string') return '';
  const head = value.slice(0, 10);
  return /^\d{4}-\d{2}-\d{2}$/.test(head) ? head : '';
}

/**
 * `<input type="datetime-local">` accepts `YYYY-MM-DDTHH:mm` (no
 * timezone). Strip seconds + zone if backend ships full ISO.
 */
function readDatetimeValue(value: unknown): string {
  if (typeof value !== 'string') return '';
  const head = value.slice(0, 16);
  return /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(head) ? head : '';
}

/**
 * `<input type="color">` only accepts a `#rrggbb` value. #1177 — used to
 * decide whether the stored colour can drive the native picker / swatch.
 */
function isHexColor(value: string): boolean {
  return /^#[0-9a-fA-F]{6}$/.test(value);
}

/**
 * #1881 — read the numeric amount of a `price` value. The stored envelope is
 * `{amount, currency?}` (currency optional), but a freshly typed value is a
 * bare number; tolerate both. Returns `''` so the controlled number input
 * renders empty when there is no amount.
 */
function readPriceAmount(value: unknown): number | '' {
  if (typeof value === 'number') return value;
  if (value !== null && typeof value === 'object') {
    const amount = (value as Record<string, unknown>).amount;
    if (typeof amount === 'number') return amount;
  }
  return '';
}

/**
 * Read-only display: map option codes to their localized labels so the
 * detail page never shows raw codes (`red`, `new`) when an option label
 * exists. Falls back to the raw value for non-select-like types.
 */
function renderReadOnlyValue(
  attribute: AttributeMeta,
  value: unknown,
  options: AttributeOptionMeta[],
  lang: string,
): string | null {
  if (attribute.type === 'select') {
    if (typeof value !== 'string' || value === '') return null;
    const match = options.find((o) => o.code === value);
    return match ? optionLabel(match, lang) : value;
  }
  if (attribute.type === 'multiselect') {
    const codes = readMultiselectValue(value);
    if (codes.length === 0) return null;
    const labels = codes.map((code) => {
      const match = options.find((o) => o.code === code);
      return match ? optionLabel(match, lang) : code;
    });
    return labels.join(', ');
  }
  if (attribute.type === 'date') {
    const head = readDateValue(value);
    return head === '' ? null : head;
  }
  if (attribute.type === 'datetime') {
    const head = readDatetimeValue(value);
    return head === '' ? null : head.replace('T', ' ');
  }
  if (attribute.type === 'price') {
    // #1881 — render the numeric amount; legacy values may still carry a
    // currency, so append it when present instead of "[object Object]".
    const amount = readPriceAmount(value);
    if (amount === '') return null;
    const currency =
      value !== null && typeof value === 'object'
        ? (value as Record<string, unknown>).currency
        : null;
    return typeof currency === 'string' && currency !== ''
      ? `${amount} ${currency}`
      : String(amount);
  }
  if (value === null || value === undefined || value === '') return null;
  return typeof value === 'string' ? value : String(value);
}

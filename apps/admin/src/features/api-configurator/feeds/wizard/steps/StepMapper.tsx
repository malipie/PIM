import { AlertTriangle, CheckCircle2 } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Segmented } from '@/features/api-configurator/components/primitives';
import { cn } from '@/lib/utils';

import {
  type AttributeOption,
  attributeLabel,
  fetchSamplePreview,
  type MappingSlotView,
  type MappingView,
  type SlotMapping,
  sampleValuesFromXml,
} from '../../api/mapping';
import { CoverageBar } from '../../components/primitives';

const NODE_TONES: Record<string, string> = {
  element: 'bg-zinc-100 text-zinc-600',
  attribute: 'bg-sky-50 text-sky-700',
  repeatable: 'bg-violet-50 text-violet-700',
  keyvalue: 'bg-amber-50 text-amber-800',
};

export type SkipPolicy = 'skip_invalid' | 'include_with_warning';

/**
 * XMLF-P5-03 — the mapper (wizard step 3, design feed-mapper.jsx): the
 * slot ↔ PIM-attribute table over the P3-01 view model. Each row shows the
 * slot identity (node badge, required / one-of badges, fmt hint), a source
 * picker (attribute | static | template), the closed transform list and the
 * live sample column resolved from a one-item draft preview rendered by the
 * backend writer. Rows with an unmapped required slot go rose; type
 * mismatches surfaced by the backend go amber.
 */
export function StepMapper({
  view,
  mappings,
  onMappings,
  skipPolicy,
  onSkipPolicy,
  descriptor,
  objectTypeId,
  locale,
  filter,
}: {
  view: MappingView;
  mappings: SlotMapping[];
  onMappings: (next: SlotMapping[]) => void;
  skipPolicy: SkipPolicy;
  onSkipPolicy: (policy: SkipPolicy) => void;
  descriptor: Record<string, unknown>;
  objectTypeId: string;
  locale: string | null;
  filter: unknown;
}) {
  const { t } = useTranslation();
  const [sample, setSample] = useState<Map<string, string>>(new Map());
  const seqRef = useRef(0);

  const byndex = useMemo(() => {
    const map = new Map<string, SlotMapping>();
    for (const mapping of mappings) {
      map.set(mapping.slot, mapping);
    }
    return map;
  }, [mappings]);

  const mappingsKey = JSON.stringify(mappings);
  // biome-ignore lint/correctness/useExhaustiveDependencies: mappings tracked via its JSON key
  useEffect(() => {
    const seq = ++seqRef.current;
    const timer = setTimeout(() => {
      fetchSamplePreview({ descriptor, mappings, objectTypeId, locale, filter })
        .then((preview) => {
          if (seqRef.current === seq) {
            setSample(sampleValuesFromXml(preview.xml, view.slots));
          }
        })
        .catch(() => {
          if (seqRef.current === seq) {
            setSample(new Map());
          }
        });
    }, 500);
    return () => clearTimeout(timer);
  }, [mappingsKey, objectTypeId, locale]);

  function setSlotMapping(target: string, patch: Partial<SlotMapping>): void {
    const current = byndex.get(target) ?? { slot: target, source: null, transform: null };
    const next = { ...current, ...patch };
    const others = mappings.filter((mapping) => mapping.slot !== target);
    onMappings([...others, next]);
  }

  const typeWarnings = view.slots.filter((slot) => slot.type_warning !== null).length;

  return (
    <div className="space-y-4">
      <div className="rounded-3xl bg-white p-5 soft-shadow">
        <div className="flex flex-wrap items-center gap-4">
          <div>
            <div className="text-[14.5px] font-semibold tracking-tight">
              {t('api_configurator.feeds.mapper.title')}
            </div>
            <div className="mt-0.5 text-[12px] text-zinc-500">
              {t('api_configurator.feeds.mapper.subtitle')}
            </div>
          </div>
          <div className="ml-auto flex flex-wrap items-center gap-4">
            <div className="text-right">
              <div className="text-[10px] uppercase tracking-wider text-zinc-500">
                {t('api_configurator.feeds.mapper.required_coverage')}
              </div>
              <div className="mt-1">
                <CoverageBar
                  mapped={view.coverage.required_mapped}
                  total={view.coverage.required_total}
                />
              </div>
            </div>
            <div className="text-right">
              <div className="text-[10px] uppercase tracking-wider text-zinc-500">
                {t('api_configurator.feeds.mapper.type_warnings')}
              </div>
              <div
                className={cn(
                  'num mt-0.5 text-[15px] font-semibold',
                  typeWarnings > 0 ? 'text-amber-700' : 'text-emerald-700',
                )}
              >
                {typeWarnings}
              </div>
            </div>
            <Segmented<SkipPolicy>
              value={skipPolicy}
              onChange={onSkipPolicy}
              ariaLabel={t('api_configurator.feeds.mapper.skip_policy_aria')}
              options={[
                {
                  value: 'skip_invalid',
                  label: t('api_configurator.feeds.mapper.skip_invalid'),
                },
                {
                  value: 'include_with_warning',
                  label: t('api_configurator.feeds.mapper.include_with_warning'),
                },
              ]}
            />
          </div>
        </div>
      </div>

      <div className="overflow-hidden rounded-3xl bg-white soft-shadow">
        <div className="grid grid-cols-[1.1fr_1.3fr_0.8fr_1fr] gap-3 border-b border-zinc-100 px-5 py-2.5 text-[10.5px] font-medium uppercase tracking-wider text-zinc-500">
          <div>{t('api_configurator.feeds.mapper.col.slot')}</div>
          <div>{t('api_configurator.feeds.mapper.col.source')}</div>
          <div>{t('api_configurator.feeds.mapper.col.transform')}</div>
          <div>{t('api_configurator.feeds.mapper.col.sample')}</div>
        </div>
        {view.slots.map((slot) => (
          <SlotRow
            key={slot.target}
            slot={slot}
            attributes={view.attributes}
            transforms={view.transforms}
            mapping={byndex.get(slot.target) ?? null}
            sample={sample.get(slot.target) ?? null}
            onChange={(patch) => setSlotMapping(slot.target, patch)}
          />
        ))}
      </div>

      <p className="text-[12px] leading-relaxed text-zinc-500">
        {t('api_configurator.feeds.mapper.category_note')}
      </p>
    </div>
  );
}

function SlotRow({
  slot,
  attributes,
  transforms,
  mapping,
  sample,
  onChange,
}: {
  slot: MappingSlotView;
  attributes: AttributeOption[];
  transforms: string[];
  mapping: SlotMapping | null;
  sample: string | null;
  onChange: (patch: Partial<SlotMapping>) => void;
}) {
  const { t } = useTranslation();
  const source = mapping?.source ?? null;
  const sourceKind = source?.kind ?? 'none';
  const transformKind =
    typeof mapping?.transform?.kind === 'string' ? mapping.transform.kind : 'none';

  const requiredMissing = slot.required && !slot.mapped;
  const tooLong = slot.max_length !== null && sample !== null && sample.length > slot.max_length;

  function pickSource(value: string): void {
    if (value === 'none') {
      onChange({ source: null });
    } else if (value === 'static' || value === 'template') {
      onChange({ source: { kind: value, value: source?.value ?? '' } });
    } else {
      onChange({ source: { kind: 'attribute', ref: value } });
    }
  }

  function pickTransform(kind: string): void {
    if (kind === 'none') {
      onChange({ transform: null });
    } else if (kind === 'truncate') {
      onChange({ transform: { kind, to: slot.max_length ?? 100 } });
    } else {
      onChange({ transform: { kind } });
    }
  }

  const selectClass =
    'h-9 w-full rounded-lg border border-zinc-200 bg-white px-2 text-[12.5px] outline-none focus:border-zinc-400';

  return (
    <div
      className={cn(
        'grid grid-cols-[1.1fr_1.3fr_0.8fr_1fr] items-start gap-3 border-b border-zinc-50 px-5 py-3',
        requiredMissing && 'border-l-2 border-l-rose-400 bg-rose-50/30',
        !requiredMissing &&
          slot.type_warning !== null &&
          'border-l-2 border-l-amber-400 bg-amber-50/30',
      )}
    >
      <div className="min-w-0">
        <div className="flex flex-wrap items-center gap-1.5">
          <span className="font-mono text-[12.5px] font-semibold text-zinc-800">{slot.target}</span>
          {slot.required && (
            <span className="rounded bg-rose-100 px-1 py-0.5 text-[9px] font-semibold uppercase text-rose-700">
              {t('api_configurator.feeds.mapper.required')}
            </span>
          )}
          {slot.required_one_of.length > 0 && (
            <span
              className="rounded bg-amber-100 px-1 py-0.5 text-[9px] font-semibold uppercase text-amber-800"
              title={slot.required_one_of.join(' | ')}
            >
              {t('api_configurator.feeds.mapper.one_of', { count: slot.required_one_of.length })}
            </span>
          )}
          <span
            className={cn(
              'rounded px-1 py-0.5 text-[9px] font-semibold uppercase',
              NODE_TONES[slot.node] ?? NODE_TONES.element,
            )}
          >
            {slot.node}
          </span>
        </div>
        <div className="mt-1 font-mono text-[10.5px] text-zinc-500">
          {slot.format}
          {slot.max_length !== null && ` · ≤${slot.max_length}`}
          {slot.enums.length > 0 && ` · enum(${slot.enums.length})`}
        </div>
      </div>

      <div className="space-y-1.5">
        <select
          value={sourceKind === 'attribute' ? (source?.ref ?? 'none') : sourceKind}
          onChange={(event) => pickSource(event.target.value)}
          aria-label={t('api_configurator.feeds.mapper.source_aria', { slot: slot.target })}
          className={selectClass}
        >
          <option value="none">{t('api_configurator.feeds.mapper.source_none')}</option>
          <optgroup label={t('api_configurator.feeds.mapper.source_attribute')}>
            {attributes.map((attribute) => (
              <option key={attribute.code} value={attribute.code}>
                {attributeLabel(attribute)} · {attribute.code} ({attribute.type})
              </option>
            ))}
          </optgroup>
          <optgroup label={t('api_configurator.feeds.mapper.source_other')}>
            <option value="static">{t('api_configurator.feeds.mapper.source_static')}</option>
            <option value="template">{t('api_configurator.feeds.mapper.source_template')}</option>
          </optgroup>
        </select>
        {(sourceKind === 'static' || sourceKind === 'template') && (
          <input
            value={source?.value ?? ''}
            onChange={(event) =>
              onChange({ source: { kind: sourceKind, value: event.target.value } })
            }
            placeholder={
              sourceKind === 'template'
                ? '{store_url}/p/{url_slug}'
                : t('api_configurator.feeds.mapper.static_placeholder')
            }
            aria-label={t('api_configurator.feeds.mapper.value_aria', { slot: slot.target })}
            className="h-9 w-full rounded-lg border border-zinc-200 bg-white px-2 font-mono text-[12px] outline-none focus:border-zinc-400"
          />
        )}
        {slot.type_warning !== null && (
          <div className="flex items-start gap-1.5 text-[11px] text-amber-800">
            <AlertTriangle className="mt-0.5 h-3 w-3 shrink-0" aria-hidden />
            {slot.type_warning}
          </div>
        )}
      </div>

      <div>
        <select
          value={transformKind}
          onChange={(event) => pickTransform(event.target.value)}
          aria-label={t('api_configurator.feeds.mapper.transform_aria', { slot: slot.target })}
          className={selectClass}
        >
          {transforms.map((kind) => (
            <option key={kind} value={kind}>
              {kind}
            </option>
          ))}
        </select>
      </div>

      <div className="min-w-0">
        {sample !== null ? (
          <div className="flex items-start gap-1.5">
            <CheckCircle2 className="mt-0.5 h-3.5 w-3.5 shrink-0 text-emerald-600" aria-hidden />
            <code
              className={cn(
                'block truncate rounded-lg border border-zinc-100 bg-zinc-50 px-2 py-1 font-mono text-[11.5px]',
                tooLong ? 'border-amber-300 text-amber-800' : 'text-zinc-700',
              )}
              title={sample}
            >
              {sample}
            </code>
          </div>
        ) : requiredMissing ? (
          <div className="flex items-center gap-1.5 text-[11.5px] font-medium text-rose-700">
            <AlertTriangle className="h-3.5 w-3.5" aria-hidden />
            {t('api_configurator.feeds.mapper.missing_value')}
          </div>
        ) : (
          <span className="text-[11.5px] text-zinc-400">—</span>
        )}
        {tooLong && (
          <div className="mt-1 text-[10.5px] text-amber-700">
            {t('api_configurator.feeds.mapper.too_long', { max: slot.max_length })}
          </div>
        )}
      </div>
    </div>
  );
}

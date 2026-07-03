import { useInvalidate } from '@refinedev/core';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Check, FolderPlus, FolderTree, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';

import { CreateGroupInlineDialog } from '@/components/modeling/create-group-inline-dialog';
import { LocaleTabsField } from '@/components/modeling/locale-tabs-field';
import { PickGroupsForAttributeDialog } from '@/components/modeling/pick-groups-for-attribute-dialog';
import {
  RelationConfigPanel,
  type RelationConfigValue,
} from '@/components/modeling/relation-config-panel';
import { SettingToggleRow } from '@/components/modeling/setting-toggle-row';
import {
  hasValidationRulesUi,
  type ValidationRules,
  ValidationRulesSection,
} from '@/components/modeling/validation-rules-section';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { CREATABLE_ATTRIBUTE_TYPES } from '@/lib/attribute-types';
import { HttpError, jsonFetch } from '@/lib/http';
import { useCurrentWorkspace } from '@/lib/use-current-workspace';
import { cn } from '@/lib/utils';

/**
 * VIEW-02 (#374) — pixel-perfect rebuild of `NewAttributeView`
 * (`attributes.jsx:352–448`).
 *
 * Layout:
 *   - Back link "Wstecz do biblioteki Attributes".
 *   - Header (flex justify-between): caption "Nowy Attribute" + big
 *     mono live `attribute_code` + description; right stack
 *     "Anuluj | + Utwórz atrybut".
 *   - Grid 1fr+320px:
 *     - Left Card (p-6 space-y-6) with three sections:
 *       * Identyfikacja: Code input + Nazwa PL/EN + Opis (PL/EN).
 *       * Typ danych: 4-col tile picker (10 enum values), font-mono.
 *       * Walidacja: 3 SettingToggleRow (Required / Unique / Indexed).
 *     - Right aside: Card "Podgląd" (live code + TypeBadge + name)
 *       + Card "Następnie" (3 next steps).
 *
 * POSTs `/api/attributes` (#381) and redirects to the show page on
 * success. The "Indexed" toggle is non-persistent in MVP — backend
 * has no `is_indexed` column; flag stays in form for visual parity.
 */

interface CreatePayload {
  code: string;
  // #1352 — attribute name is a JSONB i18n map keyed by every configured
  // locale (pl/en/de/…), driven by the workspace's enabled locales rather
  // than a hardcoded pl/en pair.
  label: Record<string, string>;
  helpPl: string;
  helpEn: string;
  type: string;
  required: boolean;
  filterable: boolean;
}

const EMPTY: CreatePayload = {
  code: '',
  label: {},
  helpPl: '',
  helpEn: '',
  type: 'text',
  required: false,
  filterable: false,
};

/**
 * Default relation config (#949) — operator picks targets + cardinality
 * inline via `RelationConfigPanel`. `many` is the most common shape
 * (cross_sell, related, accessory all default to many on the seeded
 * built-in relation attributes).
 */
const DEFAULT_RELATION_CONFIG: RelationConfigValue = {
  targetObjectTypeIds: [],
  cardinality: 'many',
  advanced: false,
  advancedFields: [],
  previewFields: [],
};

interface ObjectTypePickerRow {
  id: string;
  code: string;
  kind: string;
  label?: Record<string, string> | string | null;
}

// #1210 follow-up — shared with the create dialogs so the type grid never
// drifts again (this page lagged the dialogs on textarea/datetime/color/email/
// identifier).
const TYPES = CREATABLE_ATTRIBUTE_TYPES;

export function AttributeCreatePage() {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const invalidate = useInvalidate();
  const queryClient = useQueryClient();
  // #1352 — drive the name field's locale tabs from the tenant's enabled
  // locales (Settings → Locales). Falls back to pl/en before the workspace
  // resolves so the form is never blank.
  const workspace = useCurrentWorkspace();
  const enabledLocales = workspace.data?.enabledLocales ?? ['pl', 'en'];
  const primaryLocale = workspace.data?.primaryLocale ?? 'pl';
  const [values, setValues] = useState<CreatePayload>(EMPTY);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Reverse-direction "+Z grupy" / "+Stwórz grupę" — operator picks (or
  // creates) groups while building the attribute. After POST /api/attributes
  // succeeds we POST bulk-attach for each picked group.
  const [pickedGroupCodes, setPickedGroupCodes] = useState<Set<string>>(new Set());
  const [pickerOpen, setPickerOpen] = useState(false);
  const [createGroupOpen, setCreateGroupOpen] = useState(false);

  // #949 — relation attribute config (only meaningful when type=relation).
  // Backend validator (RelationAttributeConfigValidator) rejects POST with
  // 422 if `relationCardinality` is null on a relation attribute; we now
  // render `RelationConfigPanel` inline so the operator can pick targets +
  // cardinality before submit.
  const [relationConfig, setRelationConfig] =
    useState<RelationConfigValue>(DEFAULT_RELATION_CONFIG);

  // DP-05 (#2035) — per-type validation rules. Reset on type switch: keys
  // are type-specific (min_length for text vs min for number) and stale
  // keys from a previous type would 422 or silently no-op.
  const [validationRules, setValidationRules] = useState<ValidationRules>({});
  const objectTypesQuery = useQuery<ObjectTypePickerRow[]>({
    queryKey: ['relation-config', 'object_types'],
    queryFn: async () => {
      const data = await jsonFetch<{ member?: ObjectTypePickerRow[] }>(
        '/api/object_types?itemsPerPage=200',
      );
      return data.member ?? [];
    },
    staleTime: 60_000,
    enabled: values.type === 'relation',
  });

  const handleSubmit = async () => {
    setError(null);
    setSubmitting(true);
    try {
      const body: Record<string, unknown> = {
        code: values.code,
        label: stripEmpty(values.label),
        type: values.type,
        required: values.required,
        filterable: values.filterable,
      };
      const help = stripEmpty({ pl: values.helpPl, en: values.helpEn });
      if (Object.keys(help).length > 0) body.help = help;

      // #949 — relation config fields go through the same POST. Filter
      // empty advanced-field rows (the validator 422s them; the UX is
      // friendlier when we drop them upfront).
      if (values.type === 'relation') {
        body.relationTargetObjectTypeIds = relationConfig.targetObjectTypeIds;
        body.relationCardinality = relationConfig.cardinality;
        body.relationAdvanced = relationConfig.advanced;
        if (relationConfig.advanced) {
          body.validationRules = {
            advanced_fields: relationConfig.advancedFields.filter((f) => f.code.trim() !== ''),
          };
        }
        const preview = relationConfig.previewFields.map((c) => c.trim()).filter((c) => c !== '');
        if (preview.length > 0) body.relationPreviewFields = preview;
      } else if (Object.keys(validationRules).length > 0) {
        // DP-05 (#2035) — per-type rules configured in the Walidacja section.
        body.validationRules = validationRules;
      }

      const response = await jsonFetch<{ id?: string }>('/api/attributes', {
        method: 'POST',
        contentType: 'application/ld+json',
        accept: 'application/ld+json',
        body,
      });

      // Reverse-direction attach: for each picked group, fan out a bulk-attach
      // call with this single new attribute. Sequential to keep BE audit log
      // ordering deterministic; failures bubble up as the same error message.
      if (pickedGroupCodes.size > 0) {
        const groupsList = await jsonFetch<{
          member?: Array<{ id: string; code: string }>;
        }>('/api/attribute_groups?itemsPerPage=200');
        const codeToId = new Map<string, string>();
        for (const g of groupsList.member ?? []) codeToId.set(g.code, g.id);
        for (const code of pickedGroupCodes) {
          const groupId = codeToId.get(code);
          if (groupId === undefined) continue;
          await jsonFetch(`/api/attribute_groups/${groupId}/attributes/bulk-attach`, {
            method: 'POST',
            contentType: 'application/json',
            accept: 'application/json',
            body: { attributeCodes: [values.code] },
          });
        }
      }

      // Drop the cached list so the new row shows up after redirect.
      // Belt-and-suspenders: Refine's useInvalidate covers its own cache
      // keys (`['data','attributes','list']`); the queryClient fallback
      // wipes any custom React Query keys (e.g. usage prefetch caches)
      // that might also be holding a stale snapshot. Both are awaited so
      // the navigate below races with a fresh refetch, not a pending one.
      await Promise.all([
        invalidate({ resource: 'attributes', invalidates: ['list', 'many'] }),
        queryClient.invalidateQueries({
          predicate: (query) => {
            const key = query.queryKey;
            if (!Array.isArray(key)) return false;
            return key.includes('attributes') || key[0] === 'attribute_options';
          },
        }),
      ]);
      if (typeof response.id === 'string' && response.id !== '') {
        navigate(`/modeling/attributes/${response.id}`, { replace: true });
      } else {
        navigate('/modeling/attributes', { replace: true });
      }
    } catch (err) {
      if (err instanceof HttpError) {
        const detail =
          err.body && typeof err.body === 'object' && 'detail' in err.body
            ? String((err.body as Record<string, unknown>).detail)
            : null;
        setError(detail ?? `HTTP ${err.status}`);
      } else {
        setError(t('attributes.create_error', { defaultValue: 'Nie udało się utworzyć atrybutu' }));
      }
    } finally {
      setSubmitting(false);
    }
  };

  const valid =
    values.code.trim().length > 0 &&
    // #1352 — the name is required in the primary locale (was hardcoded to
    // PL); other locales remain optional translations.
    (values.label[primaryLocale]?.trim().length ?? 0) > 0 &&
    (values.type !== 'relation' || relationConfig.targetObjectTypeIds.length > 0);

  return (
    <div className="space-y-6">
      <Link
        to="/modeling/attributes"
        className="inline-flex items-center gap-1.5 text-[12.5px] font-medium text-muted-foreground hover:text-foreground"
      >
        <ArrowLeft className="size-4" />
        {t('attributes.back_to_library', { defaultValue: 'Wstecz do biblioteki Attributes' })}
      </Link>

      <div className="flex flex-wrap items-start justify-between gap-6">
        <div className="flex-1 min-w-0">
          <div className="text-[13px] font-medium text-muted-foreground">
            {t('attributes.create_caption', { defaultValue: 'Nowy Attribute' })}
          </div>
          <h1 className="font-display font-mono text-[28px] font-semibold tracking-tight">
            {values.code.trim().length > 0
              ? values.code.trim()
              : t('attributes.create_code_placeholder', { defaultValue: 'attribute_code' })}
          </h1>
          <p className="mt-1 max-w-2xl text-[13px] text-muted-foreground">
            {t('attributes.create_description', {
              defaultValue:
                'Atrybut to typowane pole, które można dołączać do ObjectType lub Attribute Group. Po utworzeniu pojawi się w globalnej bibliotece.',
            })}
          </p>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          <Button asChild variant="ghost" className="h-9 rounded-xl px-3 text-[13px]">
            <Link to="/modeling/attributes">{t('app.cancel')}</Link>
          </Button>
          <Button
            type="button"
            disabled={!valid || submitting}
            onClick={() => {
              void handleSubmit();
            }}
            className="h-9 rounded-xl bg-zinc-900 px-4 text-[13px] hover:bg-zinc-800"
          >
            <Check className="size-4" />
            {t('attributes.create_submit', { defaultValue: 'Utwórz atrybut' })}
          </Button>
        </div>
      </div>

      <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
        <Card className="space-y-6 p-6">
          <Section title={t('attributes.identification_title', { defaultValue: 'Identyfikacja' })}>
            <div className="space-y-4">
              <div>
                <Label className="text-[11.5px] font-medium text-muted-foreground" htmlFor="code">
                  {t('attributes.fields.code', { defaultValue: 'Code' })}
                </Label>
                <Input
                  id="code"
                  value={values.code}
                  onChange={(e) => setValues({ ...values, code: e.target.value })}
                  pattern="[a-z][a-z0-9_]*"
                  placeholder="np. warranty_months"
                  className="mt-1.5 h-10 font-mono"
                />
                <p className="mt-1 text-[11px] text-muted-foreground">
                  {t('attributes.code_helper', {
                    defaultValue: 'snake_case · niezmienialny po utworzeniu',
                  })}
                </p>
              </div>
              <div>
                <Label className="text-[11.5px] font-medium text-muted-foreground">
                  {t('attributes.fields.name', { defaultValue: 'Nazwa' })}
                </Label>
                <div className="mt-1.5">
                  <LocaleTabsField
                    values={values.label}
                    enabledLocales={enabledLocales}
                    primaryLocale={primaryLocale}
                    onChange={(next) => setValues({ ...values, label: next })}
                    placeholder={t('attributes.fields.name_placeholder', {
                      defaultValue: 'np. Gwarancja (msc)',
                    })}
                  />
                </div>
              </div>
              <div>
                <Label className="text-[11.5px] font-medium text-muted-foreground">
                  {t('attributes.fields.help', { defaultValue: 'Opis (opcjonalny)' })}
                </Label>
                <div className="mt-1.5 grid gap-2 sm:grid-cols-2">
                  <Textarea
                    rows={2}
                    value={values.helpPl}
                    onChange={(e) => setValues({ ...values, helpPl: e.target.value })}
                    placeholder="PL · krótki opis dla zespołu"
                  />
                  <Textarea
                    rows={2}
                    value={values.helpEn}
                    onChange={(e) => setValues({ ...values, helpEn: e.target.value })}
                    placeholder="EN · short description for the team"
                  />
                </div>
              </div>
            </div>
          </Section>

          <Section title={t('attributes.type_title', { defaultValue: 'Typ danych' })}>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
              {TYPES.map((type) => (
                <button
                  key={type}
                  type="button"
                  onClick={() => {
                    setValues({ ...values, type });
                    setValidationRules({});
                    // MODR-07 (#929) — picking `relation` pre-selects the
                    // built-in "Powiązania" group as the default home for
                    // the new attribute. Operator can still remove it from
                    // the picker below; the choice is a leniwa ścieżka, not
                    // a constraint.
                    if (type === 'relation') {
                      setPickedGroupCodes((prev) => {
                        if (prev.has('relations')) return prev;
                        const next = new Set(prev);
                        next.add('relations');
                        return next;
                      });
                    }
                  }}
                  className={cn(
                    'h-10 rounded-xl font-mono text-[12px] font-medium transition',
                    values.type === type
                      ? 'bg-zinc-900 text-white'
                      : 'border border-zinc-200 bg-white text-muted-foreground hover:bg-zinc-50',
                  )}
                >
                  {type}
                </button>
              ))}
            </div>
            {values.type === 'relation' ? (
              <p className="mt-2 text-[11.5px] text-muted-foreground">
                {t('attributes.relation_default_group_hint', {
                  defaultValue:
                    'Domyślnie w zakładce Powiązania; możesz przenieść do dowolnej grupy poniżej.',
                })}
              </p>
            ) : null}
          </Section>

          {/* #949 — relation config inline. The backend validator requires
              `relationCardinality` + at least one target ObjectType on POST
              for `type=relation`, so we surface the same controls the edit
              page uses (MOD-13 #905) right after type pick. */}
          {values.type === 'relation' ? (
            <Section
              title={t('attributes.relation_config_section', {
                defaultValue: 'Konfiguracja relacji',
              })}
            >
              <RelationConfigPanel
                value={relationConfig}
                objectTypes={objectTypesQuery.data ?? []}
                disabled={submitting}
                onChange={setRelationConfig}
              />
              {relationConfig.targetObjectTypeIds.length === 0 ? (
                <p className="mt-2 text-[12px] text-amber-600">
                  {t('attributes.relation_targets_required_hint', {
                    defaultValue: 'Wybierz co najmniej jeden ObjectType celu relacji.',
                  })}
                </p>
              ) : null}
            </Section>
          ) : null}

          <Section title={t('attributes.validation_title', { defaultValue: 'Walidacja i flagi' })}>
            <div className="space-y-3">
              <SettingToggleRow
                label={t('attributes.flags.required_label', { defaultValue: 'Required' })}
                description={t('attributes.flags.required_desc', {
                  defaultValue: 'Pole musi być wypełnione',
                })}
                checked={values.required}
                onChange={(next) => setValues({ ...values, required: next })}
              />
              {/* #1355 / #1356 — the "Unique" and "Indexed" toggles were
                  form-only (no backend column / enforcement) and only
                  misled operators into thinking they did something. Removed
                  until a real uniqueness validator / Meilisearch indexing
                  flag is implemented as a dedicated feature. */}
              <SettingToggleRow
                label={t('attributes.flags.filterable_label', { defaultValue: 'Filtrowalny' })}
                description={t('attributes.flags.filterable_desc', {
                  defaultValue:
                    'Pojawia się w panelu Filtruj zaawansowane. Reindex wymaga zmian ustawień Meilisearch.',
                })}
                checked={values.filterable}
                onChange={(next) => setValues({ ...values, filterable: next })}
              />
            </div>
            {hasValidationRulesUi(values.type) ? (
              <div className="mt-4 border-t border-zinc-100 pt-4">
                <p className="mb-3 text-[11.5px] text-muted-foreground">
                  {t('attributes.rules.section_hint', {
                    defaultValue:
                      'Reguły walidacji wartości — egzekwowane przy każdym zapisie (ręcznym, imporcie, agencie).',
                  })}
                </p>
                <ValidationRulesSection
                  type={values.type}
                  value={validationRules}
                  onChange={setValidationRules}
                  disabled={submitting}
                />
              </div>
            ) : null}
          </Section>

          <Section
            title={t('modeling.attributes.attach_groups_title', { defaultValue: 'Dołącz do grup' })}
          >
            <div className="space-y-3">
              <div className="flex flex-wrap items-center gap-2">
                <Button
                  type="button"
                  variant="ghost"
                  size="sm"
                  onClick={() => setPickerOpen(true)}
                  className="h-8 rounded-lg px-2.5 text-[12px]"
                >
                  <FolderTree className="size-3.5" />
                  {t('modeling.attributes.attach_from_groups_action', {
                    defaultValue: 'Z grupy',
                  })}
                </Button>
                <Button
                  type="button"
                  size="sm"
                  onClick={() => setCreateGroupOpen(true)}
                  className="h-8 rounded-lg bg-orange-50 px-2.5 text-[12px] text-orange-700 hover:bg-orange-100"
                >
                  <FolderPlus className="size-3.5" />
                  {t('modeling.attributes.attach_create_group_action', {
                    defaultValue: 'Stwórz grupę',
                  })}
                </Button>
              </div>
              {pickedGroupCodes.size === 0 ? (
                <p className="text-[11.5px] text-muted-foreground">
                  {t('modeling.attributes.attach_groups_hint', {
                    defaultValue:
                      'Opcjonalnie — wybierz lub utwórz grupy do których atrybut zostanie dołączony po utworzeniu.',
                  })}
                </p>
              ) : (
                <div className="flex flex-wrap gap-1.5">
                  {Array.from(pickedGroupCodes).map((code) => (
                    <span
                      key={code}
                      className="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-zinc-50 px-2 py-1 font-mono text-[11.5px] text-zinc-700"
                    >
                      {code}
                      <button
                        type="button"
                        onClick={() => {
                          setPickedGroupCodes((prev) => {
                            const next = new Set(prev);
                            next.delete(code);
                            return next;
                          });
                        }}
                        aria-label={t('app.remove', { defaultValue: 'Usuń' })}
                        className="grid size-3.5 place-items-center rounded text-zinc-500 hover:bg-zinc-200 hover:text-zinc-700"
                      >
                        <X className="size-3" />
                      </button>
                    </span>
                  ))}
                </div>
              )}
            </div>
          </Section>

          {error !== null ? (
            <p className="rounded-md border border-destructive/50 bg-destructive/5 px-3 py-2 text-sm text-destructive">
              {error}
            </p>
          ) : null}
        </Card>

        <aside className="space-y-3">
          <Card className="p-5">
            <div className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
              {t('attributes.preview_title', { defaultValue: 'Podgląd' })}
            </div>
            <div className="mt-3 space-y-1.5">
              <div className="flex items-center gap-2">
                <span className="font-mono text-[13px] font-semibold">
                  {values.code.trim().length > 0 ? values.code.trim() : 'code…'}
                </span>
                <span className="rounded-md bg-muted px-2 py-0.5 text-[11px] font-medium uppercase text-muted-foreground">
                  {values.type}
                </span>
              </div>
              <div className="text-[12px] text-muted-foreground">
                {values.label[primaryLocale]?.trim() ||
                  Object.values(values.label)
                    .find((v) => v.trim() !== '')
                    ?.trim() ||
                  'Nazwa atrybutu…'}
              </div>
            </div>
          </Card>
          <Card className="p-5">
            <div className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
              {t('attributes.next_title', { defaultValue: 'Następnie' })}
            </div>
            <ul className="mt-3 space-y-1.5 text-[12px] text-muted-foreground">
              <li>1. {t('attributes.next_step_1', { defaultValue: 'Utwórz atrybut' })}</li>
              <li>
                2.{' '}
                {t('attributes.next_step_2', {
                  defaultValue: 'Dołącz do Attribute Group lub ObjectType',
                })}
              </li>
              <li>
                3.{' '}
                {t('attributes.next_step_3', {
                  defaultValue: 'Ustaw mapowania na kanały (Shopify, Allegro)',
                })}
              </li>
            </ul>
          </Card>
        </aside>
      </div>

      <PickGroupsForAttributeDialog
        open={pickerOpen}
        onOpenChange={setPickerOpen}
        initialPicked={pickedGroupCodes}
        onConfirm={(codes) => setPickedGroupCodes(codes)}
        locale={i18n.language}
      />
      <CreateGroupInlineDialog
        open={createGroupOpen}
        onOpenChange={setCreateGroupOpen}
        onCreated={(group) => {
          setPickedGroupCodes((prev) => {
            const next = new Set(prev);
            next.add(group.code);
            return next;
          });
        }}
      />
    </div>
  );
}

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <div>
      <div className="mb-4 text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
        {title}
      </div>
      {children}
    </div>
  );
}

function stripEmpty(record: Record<string, string>): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [k, v] of Object.entries(record)) {
    if (v.trim() !== '') out[k] = v;
  }
  return out;
}

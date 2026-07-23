import { useOne } from '@refinedev/core';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import {
  ArrowLeft,
  FolderPlus,
  FolderTree,
  Layers,
  Lock,
  Pencil,
  Save,
  Shield,
  Trash2,
  TriangleAlert,
  X,
  Zap,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams } from 'react-router';

import { AuditLogIndicator } from '@/components/modeling/audit-log-indicator';
import { BuiltInLockBadge } from '@/components/modeling/built-in-lock-badge';
import { CreateGroupInlineDialog } from '@/components/modeling/create-group-inline-dialog';
import { LocaleTabsField } from '@/components/modeling/locale-tabs-field';
import { PickGroupsForAttributeDialog } from '@/components/modeling/pick-groups-for-attribute-dialog';
import {
  type RelationAdvancedField,
  RelationConfigPanel,
  type RelationConfigValue,
} from '@/components/modeling/relation-config-panel';
import {
  hasValidationRulesUi,
  type ValidationRules,
  ValidationRulesSection,
} from '@/components/modeling/validation-rules-section';
import { WhereUsedList } from '@/components/modeling/where-used-list';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { toast } from '@/components/ui/toast';
import { HttpError, jsonFetch } from '@/lib/http';
import { useCurrentWorkspace } from '@/lib/use-current-workspace';
import { cn } from '@/lib/utils';

import { resolveLabel } from './list';

interface AttributeDetail {
  id: string;
  code: string;
  label?: Record<string, string> | string | null;
  help?: Record<string, string> | string | null;
  type: string;
  required?: boolean;
  localizable?: boolean;
  scopable?: boolean;
  unique?: boolean;
  filterable?: boolean;
  system?: boolean;
  // ADR-014 / MOD-05 (#897) relation config fields surfaced by the API.
  relationTargetObjectTypeIds?: string[];
  relationCardinality?: 'one' | 'many' | null;
  relationAdvanced?: boolean;
  // MODR-08 (#930) — list of target attribute codes shown in the
  // relation widget's preview card. Empty list → default name+code.
  relationPreviewFields?: string[];
  validationRules?: Record<string, unknown> | null;
}

/**
 * VIEW-02 (#374) — pixel-perfect AttributeDetail with edit-in-place
 * pattern (mockup `attributes.jsx:114–245`):
 *
 *   - Sticky header with shield/zap icon, mono code 26px, lock badge
 *     (system) + TypeBadge + label/unit subtitle. Right stack: Manage
 *     Values (orange, select/multiselect), Migrate Type (amber,
 *     non-system), Edit (zinc-900).
 *   - Card "Definicja": grid with locked Code + editable Nazwa
 *     PL/EN + locked Type + 3-column FlagPill row (Localizable /
 *     Scopable / Unique).
 *   - Card "UI Configuration": locked Widget + editable Helper text
 *     + live Preview row.
 *   - Card "Where used": existing <WhereUsedList>.
 *   - Sticky bottom bar visible when dirty: "{N} pól zmienionych"
 *     left, "Anuluj | Zapisz zmiany" right. PATCHes /api/attributes/{id}.
 *
 * System attributes (`is_system=true`) get all editable fields
 * disabled at the UI level — backend already rejects structural
 * changes with 422.
 */
export function AttributeShowPage() {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const params = useParams<{ id: string }>();
  const id = params.id ?? '';
  const { result, query } = useOne<AttributeDetail>({
    resource: 'attributes',
    id,
    queryOptions: { enabled: id.length > 0 },
  });

  if (query.isLoading || !result) {
    return <p className="text-sm text-muted-foreground">{t('app.loading')}</p>;
  }

  const attribute = result;

  return (
    <Editor
      key={attribute.id}
      attribute={attribute}
      locale={i18n.language}
      onSaved={() => {
        queryClient.invalidateQueries({ queryKey: ['attributes', attribute.id] });
        queryClient.invalidateQueries({ queryKey: ['attributes'] });
      }}
      onClose={() => navigate('/modeling/attributes')}
    />
  );
}

function Editor({
  attribute,
  locale,
  onSaved,
  onClose,
}: {
  attribute: AttributeDetail;
  locale: string;
  onSaved: () => void;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  // #1352 — the name editor follows the tenant's enabled locales, so the
  // label keeps ALL locale keys (not just pl/en). Help stays pl/en (out of
  // ticket scope).
  const workspace = useCurrentWorkspace();
  const enabledLocales = workspace.data?.enabledLocales ?? ['pl', 'en'];
  const primaryLocale = workspace.data?.primaryLocale ?? 'pl';
  const initialLabel = toFullLocaleMap(attribute.label);
  const initialHelp = toLocaleMap(attribute.help);

  const [labelMap, setLabelMap] = useState<Record<string, string>>(initialLabel);
  const [helpPl, setHelpPl] = useState(initialHelp.pl ?? '');
  const [helpEn, setHelpEn] = useState(initialHelp.en ?? '');
  const [required, setRequired] = useState(attribute.required ?? false);
  const [localizable, setLocalizable] = useState(attribute.localizable ?? false);
  const [scopable, setScopable] = useState(attribute.scopable ?? false);
  const [filterable, setFilterable] = useState(attribute.filterable ?? false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);

  // ADR-014 / MOD-13 (#905) — relation config state, hydrated from the
  // attribute payload. Initial JSONB shape: validation_rules.advanced_fields.
  const initialRelationFields: RelationAdvancedField[] = ((): RelationAdvancedField[] => {
    const rules = attribute.validationRules;
    if (!rules || typeof rules !== 'object') return [];
    const raw = (rules as Record<string, unknown>).advanced_fields;
    if (!Array.isArray(raw)) return [];
    const out: RelationAdvancedField[] = [];
    for (const entry of raw) {
      if (typeof entry !== 'object' || entry === null) continue;
      const e = entry as Record<string, unknown>;
      const code = typeof e.code === 'string' ? e.code : '';
      const type =
        e.type === 'text' || e.type === 'number' || e.type === 'boolean' ? e.type : 'text';
      const label =
        typeof e.label === 'object' && e.label !== null ? (e.label as Record<string, string>) : {};
      const required = Boolean(e.required);
      if (code !== '') out.push({ code, type, label, required });
    }
    return out;
  })();
  const [relationConfig, setRelationConfig] = useState<RelationConfigValue>({
    targetObjectTypeIds: attribute.relationTargetObjectTypeIds ?? [],
    cardinality: (attribute.relationCardinality as 'one' | 'many') ?? 'many',
    advanced: attribute.relationAdvanced ?? false,
    advancedFields: initialRelationFields,
    previewFields: attribute.relationPreviewFields ?? [],
  });

  // DP-05 (#2035) — per-type validation rules editor. Holds the FULL
  // validationRules object (unknown keys like advanced_fields pass through
  // untouched); only rendered for non-relation types, so it never races the
  // relation save path that also writes validationRules.
  const initialRules: ValidationRules = { ...(attribute.validationRules ?? {}) };
  const [rules, setRules] = useState<ValidationRules>(initialRules);
  const rulesDirty =
    attribute.type !== 'relation' && JSON.stringify(rules) !== JSON.stringify(initialRules);

  // MODR-08 follow-up — fetch `relationPreviewFields` from a dedicated
  // endpoint because ApiPlatform's GET /api/attributes/{id} omits the
  // property from its JSON-LD response (PropertyInfo discovery quirk —
  // see AttributeRelationPreviewFieldsController for context).
  const previewFieldsQuery = useQuery<{
    attributeId: string;
    relationPreviewFields: string[];
  }>({
    queryKey: ['attributes', attribute.id, 'relation_preview_fields'],
    queryFn: () =>
      jsonFetch<{ attributeId: string; relationPreviewFields: string[] }>(
        `/api/attributes/${attribute.id}/relation_preview_fields`,
        { accept: 'application/json' },
      ),
    enabled: attribute.type === 'relation',
    staleTime: 30_000,
  });
  useEffect(() => {
    if (previewFieldsQuery.data === undefined) return;
    setRelationConfig((prev) => {
      if (
        prev.previewFields.length === previewFieldsQuery.data?.relationPreviewFields.length &&
        prev.previewFields.every((v, i) => v === previewFieldsQuery.data?.relationPreviewFields[i])
      ) {
        return prev;
      }
      return { ...prev, previewFields: previewFieldsQuery.data.relationPreviewFields };
    });
  }, [previewFieldsQuery.data]);

  // Fetch tenant ObjectTypes for the multi-select. Cheap query, cached.
  const objectTypesQuery = useQuery<
    Array<{
      id: string;
      code: string;
      kind: string;
      label?: Record<string, string> | string | null;
    }>
  >({
    queryKey: ['relation-config', 'object_types'],
    queryFn: () =>
      jsonFetch<
        Array<{
          id: string;
          code: string;
          kind: string;
          label?: Record<string, string> | string | null;
        }>
      >('/api/object_types', { accept: 'application/json' }),
    staleTime: 60_000,
    enabled: attribute.type === 'relation',
  });

  // Reset form when attribute changes (id-keyed Editor remounts on different id).
  useEffect(() => {
    setError(null);
  }, [attribute.id]);

  const initialRelationSnapshot = JSON.stringify({
    targetObjectTypeIds: attribute.relationTargetObjectTypeIds ?? [],
    cardinality: (attribute.relationCardinality as 'one' | 'many') ?? 'many',
    advanced: attribute.relationAdvanced ?? false,
    advancedFields: initialRelationFields,
    previewFields: previewFieldsQuery.data?.relationPreviewFields ?? [],
  });
  const relationDirty =
    attribute.type === 'relation' && JSON.stringify(relationConfig) !== initialRelationSnapshot;

  const dirtyFields = [
    JSON.stringify(stripEmpty(labelMap)) !== JSON.stringify(initialLabel),
    helpPl !== (initialHelp.pl ?? '') || helpEn !== (initialHelp.en ?? ''),
    required !== (attribute.required ?? false),
    localizable !== (attribute.localizable ?? false),
    scopable !== (attribute.scopable ?? false),
    filterable !== (attribute.filterable ?? false),
    relationDirty,
    rulesDirty,
  ].filter(Boolean).length;
  const dirty = dirtyFields > 0;

  const isOption = attribute.type === 'select' || attribute.type === 'multiselect';
  const isSystem = attribute.system === true;

  const cancel = () => {
    setLabelMap(initialLabel);
    setHelpPl(initialHelp.pl ?? '');
    setHelpEn(initialHelp.en ?? '');
    setRequired(attribute.required ?? false);
    setLocalizable(attribute.localizable ?? false);
    setScopable(attribute.scopable ?? false);
    setFilterable(attribute.filterable ?? false);
    setRules(initialRules);
    setError(null);
  };

  const save = async (): Promise<boolean> => {
    setSaving(true);
    setError(null);
    try {
      const body: Record<string, unknown> = {};
      const nextLabel = stripEmpty(labelMap);
      if (JSON.stringify(nextLabel) !== JSON.stringify(initialLabel)) body.label = nextLabel;
      const nextHelp = stripEmpty({ pl: helpPl, en: helpEn });
      if (JSON.stringify(nextHelp) !== JSON.stringify(initialHelp)) body.help = nextHelp;
      if (!isSystem) {
        // #1350 — `required` is now editable in-place (the edit form was
        // missing the toggle even though PATCH already accepted it).
        if (required !== (attribute.required ?? false)) body.required = required;
        if (localizable !== (attribute.localizable ?? false)) body.localizable = localizable;
        if (scopable !== (attribute.scopable ?? false)) body.scopable = scopable;
        if (filterable !== (attribute.filterable ?? false)) body.filterable = filterable;
      }
      if (attribute.type === 'relation' && relationDirty) {
        body.relationTargetObjectTypeIds = relationConfig.targetObjectTypeIds;
        body.relationCardinality = relationConfig.cardinality;
        body.relationAdvanced = relationConfig.advanced;
        // MOD-08 schema lives in validation_rules.advanced_fields. Strip
        // empty-code rows defensively (the validator would 422 them but the
        // UX is friendlier when we drop them upfront).
        const fields = relationConfig.advancedFields.filter((f) => f.code.trim() !== '');
        body.validationRules = relationConfig.advanced
          ? { ...(attribute.validationRules ?? {}), advanced_fields: fields }
          : { ...(attribute.validationRules ?? {}), advanced_fields: [] };
        // MODR-08 follow-up — preview fields list. Strip empty rows;
        // backend setter dedups anyway but a clean payload reads better.
        body.relationPreviewFields = relationConfig.previewFields
          .map((c) => c.trim())
          .filter((c) => c !== '');
      }
      if (rulesDirty) {
        // DP-05 (#2035) — full-object write: the editor preserved any keys
        // it does not render, so no merge is needed here.
        body.validationRules = rules;
      }
      await jsonFetch(`/api/attributes/${attribute.id}`, {
        method: 'PATCH',
        contentType: 'application/merge-patch+json',
        body,
      });
      onSaved();
      return true;
    } catch (err) {
      if (err instanceof HttpError) {
        const detail =
          err.body && typeof err.body === 'object' && 'detail' in err.body
            ? String((err.body as Record<string, unknown>).detail)
            : null;
        setError(detail ?? `HTTP ${err.status}`);
      } else {
        setError(t('app.save_error', { defaultValue: 'Nie udało się zapisać' }));
      }
      return false;
    } finally {
      setSaving(false);
    }
  };

  // Top-right primary action: when there are pending edits PATCHes the
  // attribute and exits to the list; otherwise just exits. Mounting the
  // list page triggers `refetchOnMount: 'always'` so the user always sees
  // the freshest data — this is the cache-bust path operators rely on.
  const saveAndExit = async () => {
    if (dirty) {
      const ok = await save();
      if (!ok) return;
    }
    onClose();
  };

  // Destructive: deletes the attribute and returns to the library. The BE
  // rejects system attributes (422) and in-use attributes (409); on the
  // latter we surface the human-readable `detail` so the operator knows to
  // detach / migrate first. Success bubbles up via `onClose` → the list
  // refetches on mount and the row is gone.
  const deleteAttribute = async () => {
    setDeleting(true);
    try {
      await jsonFetch(`/api/attributes/${attribute.id}`, {
        method: 'DELETE',
        accept: 'application/json',
      });
      setDeleteOpen(false);
      toast.success(t('attributes.delete.success', { defaultValue: 'Atrybut został usunięty.' }));
      onClose();
    } catch (err) {
      setDeleteOpen(false);
      if (err instanceof HttpError) {
        const detail =
          err.body && typeof err.body === 'object' && 'detail' in err.body
            ? String((err.body as Record<string, unknown>).detail)
            : null;
        toast.error(
          detail ??
            t('attributes.delete.error_in_use', {
              defaultValue:
                'Nie można usunąć atrybutu, który jest w użyciu. Odepnij go lub zmigruj.',
            }),
        );
      } else {
        toast.error(t('app.delete_error', { defaultValue: 'Nie udało się usunąć' }));
      }
    } finally {
      setDeleting(false);
    }
  };

  return (
    <div className="space-y-6">
      <Dialog open={deleteOpen} onOpenChange={(next) => (!next ? setDeleteOpen(false) : undefined)}>
        <DialogContent>
          <div className="space-y-2">
            <DialogTitle>
              {t('attributes.delete.confirm_title', { defaultValue: 'Usunąć atrybut?' })}
            </DialogTitle>
            <DialogDescription>
              {t('attributes.delete.confirm_body', {
                defaultValue:
                  'Atrybut "{{code}}" zostanie trwale usunięty wraz z jego wartościami słownikowymi. Jeśli atrybut nie jest już przypisany do żadnego typu, grupy ani kategorii, jego osierocone wartości na obiektach również zostaną usunięte. Tej operacji nie można cofnąć.',
                code: attribute.code,
              })}
            </DialogDescription>
          </div>
          <div className="mt-4 flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setDeleteOpen(false)} disabled={deleting}>
              {t('app.cancel', { defaultValue: 'Anuluj' })}
            </Button>
            <Button
              variant="destructive"
              disabled={deleting}
              onClick={() => {
                void deleteAttribute();
              }}
            >
              {t('attributes.delete.confirm_submit', { defaultValue: 'Usuń atrybut' })}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <div className="flex items-center justify-between">
        <Button asChild variant="ghost" size="sm" className="-ml-3">
          <Link to="/modeling/attributes">
            <ArrowLeft className="size-4" />
            {t('attributes.back_to_library', { defaultValue: 'Wstecz do biblioteki Attributes' })}
          </Link>
        </Button>
        <AuditLogIndicator />
      </div>

      {/* Sticky action bar (ObjectType-style): the persistent Save + Anuluj
          live here, so there is no separate fixed bottom dirty-bar. */}
      <div className="sticky top-0 z-20 flex flex-wrap items-start gap-4 rounded-2xl border border-zinc-200 bg-white/95 px-4 py-3 backdrop-blur">
        <div className="grid h-14 w-14 shrink-0 place-items-center rounded-2xl border border-zinc-200 bg-white text-zinc-600">
          {isSystem ? <Shield className="size-5" /> : <Zap className="size-5" />}
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="font-display font-mono text-[26px] font-semibold tracking-tight">
              {attribute.code}
            </h1>
            {isSystem ? <BuiltInLockBadge /> : null}
            <span className="rounded-md bg-muted px-2 py-0.5 text-[11px] font-medium uppercase text-muted-foreground">
              {attribute.type}
            </span>
          </div>
          <div className="mt-1 text-[13px] text-muted-foreground">
            {resolveLabel(attribute.label, locale)}
          </div>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          {isOption ? (
            <Button
              asChild
              size="sm"
              variant="outline"
              className="h-9 rounded-xl bg-orange-50 text-orange-700 hover:bg-orange-100"
            >
              <Link to={`/modeling/attributes/${attribute.id}/values`}>
                <Layers className="size-4" />
                {t('attributes.manage_values', { defaultValue: 'Zarządzaj wartościami' })}
              </Link>
            </Button>
          ) : null}
          {!isSystem ? (
            <Button
              asChild
              size="sm"
              variant="outline"
              className="h-9 rounded-xl bg-amber-50 text-amber-700 hover:bg-amber-100"
            >
              <Link to={`/modeling/attributes/${attribute.id}/migrate-type`}>
                <TriangleAlert className="size-4" />
                {t('modeling.attributes.migration.action_label', { defaultValue: 'Migruj typ' })}
              </Link>
            </Button>
          ) : null}
          {!isSystem ? (
            <Button
              type="button"
              size="sm"
              variant="outline"
              aria-label={t('attributes.delete.action', { defaultValue: 'Usuń atrybut' })}
              onClick={() => setDeleteOpen(true)}
              className="h-9 rounded-xl bg-rose-50 text-rose-700 hover:bg-rose-100"
            >
              <Trash2 className="size-4" />
              {t('attributes.delete.action', { defaultValue: 'Usuń atrybut' })}
            </Button>
          ) : null}
          {!isSystem ? (
            <>
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={cancel}
                disabled={saving || !dirty}
                className="h-9 rounded-xl"
              >
                {t('app.cancel', { defaultValue: 'Anuluj' })}
              </Button>
              <Button
                size="sm"
                disabled={saving || !dirty}
                onClick={() => {
                  void saveAndExit();
                }}
                className={cn(
                  'h-9 rounded-xl',
                  dirty && !saving
                    ? 'bg-zinc-900 text-white hover:bg-zinc-800'
                    : 'bg-zinc-200 text-zinc-500',
                )}
              >
                <Save className="size-4" />
                {t('attributes.save_changes', { defaultValue: 'Zapisz zmiany' })}
              </Button>
            </>
          ) : null}
        </div>
      </div>

      <div className="space-y-6">
        <Card className="p-6">
          <SectionTitle>
            {t('attributes.definition_title', { defaultValue: 'Definicja' })}
          </SectionTitle>
          <div className="grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2">
            <FieldRow label={t('attributes.fields.code', { defaultValue: 'Code' })} mono lock>
              <span className="font-mono">{attribute.code}</span>
            </FieldRow>
            <FieldRow label={t('attributes.fields.type', { defaultValue: 'Type' })} mono lock>
              <span className="font-mono">{attribute.type}</span>
            </FieldRow>
            <FieldRow label={t('attributes.fields.name', { defaultValue: 'Nazwa' })}>
              <LocaleTabsField
                values={labelMap}
                enabledLocales={enabledLocales}
                primaryLocale={primaryLocale}
                onChange={setLabelMap}
                readOnly={isSystem}
              />
            </FieldRow>
          </div>

          <div className="mt-6 border-t border-zinc-100 pt-6">
            <div className="mb-3 text-[11.5px] font-medium text-muted-foreground">
              {t('attributes.flags_label', { defaultValue: 'Flagi' })}
            </div>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
              <FlagPill
                on={required}
                label={t('attributes.flags.required_label', { defaultValue: 'Wymagany' })}
                desc={t('attributes.flags.required_desc', {
                  defaultValue: 'pole musi być wypełnione',
                })}
                onChange={isSystem ? undefined : setRequired}
              />
              <FlagPill
                on={localizable}
                label={t('attributes.flags.localizable_label', { defaultValue: 'Localizable' })}
                desc={t('attributes.flags.localizable_desc', {
                  defaultValue: 'per locale (PL/EN/DE)',
                })}
                onChange={isSystem ? undefined : setLocalizable}
              />
              <FlagPill
                on={scopable}
                label={t('attributes.flags.scopable_label', { defaultValue: 'Scopable' })}
                desc={t('attributes.flags.scopable_desc', {
                  defaultValue: 'per channel (Shopify/Allegro)',
                })}
                onChange={isSystem ? undefined : setScopable}
              />
              {/* #1355 — "Unique" removed: it was a form-only no-op (no
                  backend uniqueness flag/validator). Re-add when a real
                  uniqueness feature ships. */}
              <FlagPill
                on={filterable}
                label={t('attributes.flags.filterable_label', { defaultValue: 'Filtrowalny' })}
                desc={t('attributes.flags.filterable_desc', {
                  defaultValue: 'pojawia się w filtrach',
                })}
                onChange={isSystem ? undefined : setFilterable}
              />
            </div>
          </div>

          {hasValidationRulesUi(attribute.type) ? (
            <div className="mt-6 border-t border-zinc-100 pt-6">
              <div className="mb-3 text-[11.5px] font-medium text-muted-foreground">
                {t('attributes.rules.section_title', { defaultValue: 'Reguły walidacji' })}
              </div>
              <p className="mb-3 text-[11.5px] text-muted-foreground">
                {t('attributes.rules.section_hint', {
                  defaultValue:
                    'Reguły walidacji wartości — egzekwowane przy każdym zapisie (ręcznym, imporcie, agencie).',
                })}
              </p>
              <ValidationRulesSection
                type={attribute.type}
                value={rules}
                onChange={setRules}
                disabled={isSystem || saving}
              />
            </div>
          ) : null}
        </Card>

        {isOption ? <AllowedValuesCard attribute={attribute} locale={locale} /> : null}

        <Card className="p-6">
          <SectionTitle>
            {t('attributes.ui_configuration_title', { defaultValue: 'UI Configuration' })}
          </SectionTitle>
          <div className="grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2">
            <FieldRow label={t('attributes.fields.widget', { defaultValue: 'Widget' })} mono lock>
              <span className="font-mono text-[13px]">{deriveWidget(attribute.type)}</span>
            </FieldRow>
            <FieldRow label={t('attributes.fields.helper_pl', { defaultValue: 'Helper (PL)' })}>
              <Textarea
                rows={2}
                value={helpPl}
                onChange={(e) => setHelpPl(e.target.value)}
                disabled={isSystem}
              />
            </FieldRow>
            <FieldRow label={t('attributes.fields.helper_en', { defaultValue: 'Helper (EN)' })}>
              <Textarea
                rows={2}
                value={helpEn}
                onChange={(e) => setHelpEn(e.target.value)}
                disabled={isSystem}
              />
            </FieldRow>
          </div>
          <div className="mt-5 rounded-2xl border border-zinc-200 bg-white p-5">
            <div className="mb-2.5 text-[11.5px] font-medium text-muted-foreground">
              {t('attributes.preview_title', { defaultValue: 'Preview' })}
            </div>
            <div className="flex items-center gap-3">
              <Label className="w-24 text-[13px] font-medium text-foreground">
                {labelMap[primaryLocale] || resolveLabel(attribute.label, locale)}
              </Label>
              <div className="flex h-10 flex-1 items-center gap-2 rounded-xl border border-zinc-200 bg-zinc-50 px-3">
                <span className="text-[13px] text-muted-foreground">
                  {previewValue(attribute.type)}
                </span>
              </div>
            </div>
            {(helpPl || initialHelp.pl) && (
              <div className="ml-[6.25rem] mt-2 text-[11.5px] text-muted-foreground">
                {helpPl || initialHelp.pl}
              </div>
            )}
          </div>
        </Card>

        <AttachedGroupsCard attribute={attribute} locale={locale} />

        {attribute.type === 'relation' ? (
          <RelationConfigPanel
            value={relationConfig}
            objectTypes={objectTypesQuery.data ?? []}
            disabled={isSystem || saving}
            onChange={setRelationConfig}
          />
        ) : null}

        <WhereUsedList resource="attributes" id={attribute.id} />
      </div>

      {isSystem ? (
        <p className="text-xs text-muted-foreground">
          {t('modeling.attributes.system_immutable_note')}
        </p>
      ) : null}

      {error !== null ? (
        <p className="text-[12px] text-destructive" role="alert">
          {error}
        </p>
      ) : null}
    </div>
  );
}

function FlagPill({
  on,
  label,
  desc,
  onChange,
}: {
  on: boolean;
  label: string;
  desc: string;
  onChange?: (next: boolean) => void;
}) {
  const interactive = onChange !== undefined;
  return (
    <button
      type="button"
      disabled={!interactive}
      onClick={interactive ? () => onChange(!on) : undefined}
      className={cn(
        'rounded-xl border px-3 py-2.5 text-left transition',
        on ? 'border-emerald-200 bg-emerald-50/50' : 'border-zinc-100 bg-zinc-50',
        interactive ? 'hover:border-zinc-300' : 'cursor-default',
      )}
    >
      <div className="flex items-center gap-2 text-[13px] font-medium">
        {!interactive ? <Lock className="size-3 text-muted-foreground" /> : null}
        {label}
        <span
          className={cn(
            'ml-auto text-[11px] font-mono uppercase',
            on ? 'text-emerald-700' : 'text-muted-foreground',
          )}
        >
          {on ? 'ON' : 'OFF'}
        </span>
      </div>
      <div className="mt-0.5 text-[11.5px] text-muted-foreground">{desc}</div>
    </button>
  );
}

function FieldRow({
  label,
  children,
  mono,
  lock,
}: {
  label: string;
  children: React.ReactNode;
  mono?: boolean;
  lock?: boolean;
}) {
  return (
    <div className="space-y-1.5">
      <div className="flex items-center gap-1.5">
        <span className="text-[11.5px] font-medium text-muted-foreground">{label}</span>
        {lock ? <Lock className="size-3 text-muted-foreground" /> : null}
      </div>
      <div className={cn('text-[13.5px]', mono ? 'font-mono' : '')}>{children}</div>
    </div>
  );
}

function SectionTitle({ children }: { children: React.ReactNode }) {
  return (
    <div className="mb-4 text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
      {children}
    </div>
  );
}

function deriveWidget(type: string): string {
  if (type === 'number' || type === 'metric') return 'number-with-unit';
  return type;
}

function previewValue(type: string): string {
  switch (type) {
    case 'number':
    case 'metric':
    case 'price':
      return '230';
    case 'boolean':
      return 'true';
    case 'date':
    case 'datetime':
      return '2026-01-01';
    case 'asset':
      return '[asset]';
    case 'reference':
    case 'relation':
      return '[ref]';
    default:
      return 'wartość…';
  }
}

function toLocaleMap(value: Record<string, string> | string | null | undefined): {
  pl?: string;
  en?: string;
} {
  if (typeof value === 'string') return { pl: value };
  if (value && typeof value === 'object') {
    const out: { pl?: string; en?: string } = {};
    if (typeof value.pl === 'string') out.pl = value.pl;
    if (typeof value.en === 'string') out.en = value.en;
    return out;
  }
  return {};
}

/**
 * #1352 — unlike {@see toLocaleMap}, preserves EVERY locale key on the
 * JSONB label map (pl/en/de/…) so the editor can surface a DE tab. A bare
 * string (legacy single-locale shape) maps onto the primary `pl` slot.
 */
function toFullLocaleMap(
  value: Record<string, string> | string | null | undefined,
): Record<string, string> {
  if (typeof value === 'string') return { pl: value };
  if (value && typeof value === 'object') {
    const out: Record<string, string> = {};
    for (const [k, v] of Object.entries(value)) {
      if (typeof v === 'string') out[k] = v;
    }
    return out;
  }
  return {};
}

function stripEmpty(record: Record<string, string>): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [k, v] of Object.entries(record)) {
    if (v.trim() !== '') out[k] = v;
  }
  return out;
}

interface OptionPreview {
  id: string;
  code: string;
  label: Record<string, string>;
  color: string | null;
  default: boolean;
  deprecated: boolean;
}

function AllowedValuesCard({ attribute, locale }: { attribute: AttributeDetail; locale: string }) {
  const { t } = useTranslation();
  const { data: options = [] } = useQuery<OptionPreview[]>({
    queryKey: ['attribute_options', attribute.code],
    queryFn: async () => {
      const payload = await jsonFetch<{ member: OptionPreview[] }>(
        `/api/attributes/${attribute.code}/options`,
      );
      return payload.member ?? [];
    },
  });

  const visible = options.slice(0, 12);
  const overflow = Math.max(0, options.length - visible.length);
  const localesUsed = new Set<string>();
  for (const option of options) {
    for (const code of Object.keys(option.label)) localesUsed.add(code);
  }

  return (
    <Card className="p-6">
      <div className="mb-4 flex items-start justify-between gap-3">
        <div>
          <SectionTitle>
            {t('attributes.allowed_values_title', { defaultValue: 'Allowed values' })}
          </SectionTitle>
          <div className="text-[12.5px] text-muted-foreground">
            {t('attributes.allowed_values_subtitle', {
              defaultValue: '{{count}} wartości · z tłumaczeniami w {{locales}} językach',
              count: options.length,
              locales: localesUsed.size > 0 ? localesUsed.size : 1,
            })}
          </div>
        </div>
        <Button asChild size="sm" className="h-9 rounded-xl bg-zinc-900 hover:bg-zinc-800">
          <Link to={`/modeling/attributes/${attribute.id}/values`}>
            <Pencil className="size-4" />
            {t('attributes.manage_values', { defaultValue: 'Zarządzaj wartościami' })}
          </Link>
        </Button>
      </div>

      {options.length === 0 ? (
        <p className="italic text-[12.5px] text-muted-foreground">
          {t('attributes.allowed_values_empty', {
            defaultValue: 'Brak zdefiniowanych wartości — kliknij „Zarządzaj wartościami".',
          })}
        </p>
      ) : (
        <div className="flex flex-wrap gap-1.5">
          {visible.map((option) => {
            const optionLabel =
              option.label[locale.split('-')[0] ?? 'pl'] ??
              option.label.pl ??
              option.label.en ??
              option.code;
            return (
              <span
                key={option.id}
                className="flex items-center gap-1.5 rounded-lg border border-zinc-100 bg-zinc-50 px-2.5 py-1 text-[12px] text-zinc-700"
              >
                {option.color !== null ? (
                  <span
                    className="size-2.5 rounded-full"
                    style={{ background: option.color }}
                    aria-hidden
                  />
                ) : null}
                <span className="font-mono text-[10.5px] text-zinc-500">{option.code}</span>
                <span>{optionLabel}</span>
                {option.default ? (
                  <span className="rounded bg-emerald-100 px-1 text-[9.5px] font-semibold uppercase tracking-wider text-emerald-700">
                    default
                  </span>
                ) : null}
              </span>
            );
          })}
          {overflow > 0 ? (
            <span className="rounded-lg px-2.5 py-1 text-[12px] text-muted-foreground">
              +{overflow} {t('attributes.allowed_values_more', { defaultValue: 'więcej' })}
            </span>
          ) : null}
        </div>
      )}
    </Card>
  );
}

interface GroupRow {
  id: string;
  code: string;
  label?: Record<string, string> | string | null;
  color?: string | null;
  icon?: string | null;
  systemGroup?: boolean;
}

/**
 * Mirror of attribute new.tsx "Dołącz do grup" — same `+ Z grupy` /
 * `+ Stwórz grupę` reverse buttons + chip list, but acting on an existing
 * attribute. Picker confirms → bulk-attach for each newly-picked group;
 * inline create → group is created and attached in the same flow; chip
 * × → DELETE on the junction.
 */
function AttachedGroupsCard({ attribute, locale }: { attribute: AttributeDetail; locale: string }) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [pickerOpen, setPickerOpen] = useState(false);
  const [createOpen, setCreateOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // Fetch all groups, then filter to those the attribute belongs to.
  // The list is bounded (paginationItemsPerPage=200 on /attribute_groups),
  // so a single request avoids per-group N+1 fan-out for membership.
  const { data: allGroups = [] } = useQuery<GroupRow[]>({
    queryKey: ['attribute_groups', 'all'],
    queryFn: async () => {
      const data = await jsonFetch<{ member?: GroupRow[] }>(
        '/api/attribute_groups?itemsPerPage=200',
      );
      return data.member ?? [];
    },
    staleTime: 30_000,
  });

  // Per-group membership probe — `/api/attribute_groups/{id}/attributes`
  // returns the same `members[].attribute.id` shape used elsewhere, so
  // this hits the same already-warm cache used by VIEW-03 detail.
  const memberships = useQuery<Array<{ groupId: string; groupCode: string; row: GroupRow }>>({
    queryKey: ['attribute', attribute.id, 'memberships'],
    queryFn: async () => {
      const out: Array<{ groupId: string; groupCode: string; row: GroupRow }> = [];
      await Promise.all(
        allGroups.map(async (group) => {
          try {
            const data = await jsonFetch<{
              members?: Array<{ attribute: { id: string } }>;
            }>(`/api/attribute_groups/${group.id}/attributes`, {
              accept: 'application/json',
            });
            if ((data.members ?? []).some((m) => m.attribute.id === attribute.id)) {
              out.push({ groupId: group.id, groupCode: group.code, row: group });
            }
          } catch {
            // tolerate one-group failure; continue with the rest
          }
        }),
      );
      return out;
    },
    enabled: allGroups.length > 0,
    staleTime: 30_000,
  });

  const attached = memberships.data ?? [];
  const attachedCodes = new Set(attached.map((m) => m.groupCode));

  const reload = async () => {
    await Promise.all([
      queryClient.invalidateQueries({ queryKey: ['attribute', attribute.id, 'memberships'] }),
      queryClient.invalidateQueries({ queryKey: ['attribute_groups'] }),
    ]);
  };

  const codeToId = new Map(allGroups.map((g) => [g.code, g.id] as const));

  const attachByCodes = async (codes: string[]) => {
    setError(null);
    for (const code of codes) {
      const groupId = codeToId.get(code);
      if (groupId === undefined) continue;
      try {
        await jsonFetch(`/api/attribute_groups/${groupId}/attributes/bulk-attach`, {
          method: 'POST',
          contentType: 'application/json',
          accept: 'application/json',
          body: { attributeCodes: [attribute.code] },
        });
      } catch (err) {
        if (err instanceof HttpError) {
          const detail =
            err.body && typeof err.body === 'object' && 'detail' in err.body
              ? String((err.body as Record<string, unknown>).detail)
              : null;
          setError(detail ?? `HTTP ${err.status}`);
        } else {
          setError(t('app.save_error', { defaultValue: 'Nie udało się dołączyć' }));
        }
      }
    }
    await reload();
  };

  const detach = async (groupId: string) => {
    setError(null);
    try {
      await jsonFetch(`/api/attribute_groups/${groupId}/attributes/${attribute.id}`, {
        method: 'DELETE',
        accept: 'application/json',
      });
      await reload();
    } catch (err) {
      if (err instanceof HttpError) {
        const detail =
          err.body && typeof err.body === 'object' && 'detail' in err.body
            ? String((err.body as Record<string, unknown>).detail)
            : null;
        setError(detail ?? `HTTP ${err.status}`);
      } else {
        setError(t('app.delete_error', { defaultValue: 'Nie udało się usunąć' }));
      }
    }
  };

  return (
    <Card className="p-6">
      <div className="mb-4 flex items-center justify-between gap-3">
        <div className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
          {t('modeling.attributes.attach_groups_title', { defaultValue: 'Dołącz do grup' })}
        </div>
        <div className="flex items-center gap-2">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={() => setPickerOpen(true)}
            className="h-8 rounded-lg px-2.5 text-[12px]"
          >
            <FolderTree className="size-3.5" />
            {t('modeling.attributes.attach_from_groups_action', { defaultValue: 'Z grupy' })}
          </Button>
          <Button
            type="button"
            size="sm"
            onClick={() => setCreateOpen(true)}
            className="h-8 rounded-lg bg-orange-50 px-2.5 text-[12px] text-orange-700 hover:bg-orange-100"
          >
            <FolderPlus className="size-3.5" />
            {t('modeling.attributes.attach_create_group_action', {
              defaultValue: 'Stwórz grupę',
            })}
          </Button>
        </div>
      </div>

      {attached.length === 0 ? (
        <p className="text-[11.5px] text-muted-foreground">
          {t('modeling.attributes.attach_groups_empty', {
            defaultValue: 'Atrybut nie należy do żadnej grupy.',
          })}
        </p>
      ) : (
        <div className="flex flex-wrap gap-1.5">
          {attached.map(({ groupId, row }) => {
            const labelStr = resolveLabel(row.label, locale);
            const color = row.color ?? '#71717a';
            return (
              <span
                key={groupId}
                className="inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-2 py-1 text-[12px] text-zinc-700"
              >
                <span
                  className="grid size-5 place-items-center rounded-md text-[12px]"
                  style={{ background: `${color}18`, color }}
                  aria-hidden
                >
                  {row.icon ?? '📦'}
                </span>
                <span className="font-medium">{labelStr}</span>
                <span className="font-mono text-[10.5px] text-muted-foreground">{row.code}</span>
                {row.systemGroup ? (
                  <BuiltInLockBadge />
                ) : (
                  <button
                    type="button"
                    onClick={() => {
                      void detach(groupId);
                    }}
                    aria-label={t('app.remove', { defaultValue: 'Usuń' })}
                    className="grid size-3.5 place-items-center rounded text-zinc-500 hover:bg-zinc-200 hover:text-zinc-700"
                  >
                    <X className="size-3" />
                  </button>
                )}
              </span>
            );
          })}
        </div>
      )}

      {error !== null ? <p className="mt-3 text-[12px] text-destructive">{error}</p> : null}

      <PickGroupsForAttributeDialog
        open={pickerOpen}
        onOpenChange={setPickerOpen}
        initialPicked={attachedCodes}
        onConfirm={(codes) => {
          const newOnes = Array.from(codes).filter((c) => !attachedCodes.has(c));
          if (newOnes.length > 0) void attachByCodes(newOnes);
          // Codes that were unchecked: detach them.
          const removed = Array.from(attachedCodes).filter((c) => !codes.has(c));
          for (const code of removed) {
            const m = attached.find((a) => a.groupCode === code);
            if (m !== undefined && m.row.systemGroup !== true) void detach(m.groupId);
          }
        }}
        locale={locale}
      />
      <CreateGroupInlineDialog
        open={createOpen}
        onOpenChange={setCreateOpen}
        onCreated={(group) => {
          void attachByCodes([group.code]);
        }}
      />
    </Card>
  );
}

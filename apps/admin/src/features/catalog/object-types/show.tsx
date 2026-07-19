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
  arrayMove,
  SortableContext,
  sortableKeyboardCoordinates,
  useSortable,
  verticalListSortingStrategy,
} from '@dnd-kit/sortable';
import { CSS } from '@dnd-kit/utilities';
import { useInvalidate, useOne } from '@refinedev/core';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Check, Copy, GripVertical, Library, Pencil, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, Navigate, useNavigate, useParams } from 'react-router';

import { AddAttributesToObjectTypeDialog } from '@/components/modeling/add-attributes-to-object-type-dialog';
import { AuditLogIndicator } from '@/components/modeling/audit-log-indicator';
import { AuditTrailCompact } from '@/components/modeling/audit-trail-compact';
import { BuiltInLockBadge } from '@/components/modeling/built-in-lock-badge';
import { CreateAttributeForObjectTypeDialog } from '@/components/modeling/create-attribute-for-object-type-dialog';
import { CreateGroupInlineDialog } from '@/components/modeling/create-group-inline-dialog';
import { DangerZoneCard } from '@/components/modeling/danger-zone-card';
import { DeclareObjectTypeAttributeGroupDialog } from '@/components/modeling/declare-object-type-attribute-group-dialog';
import { FieldDisplay } from '@/components/modeling/field-display';
import {
  type AttachedGroup,
  GroupCard,
  type GroupDisplayMode,
} from '@/components/modeling/group-card';
import { LocaleTabsField } from '@/components/modeling/locale-tabs-field';
import { ObjectTypeIcon } from '@/components/modeling/object-type-icon';
import { SettingToggleRow } from '@/components/modeling/setting-toggle-row';
import { StatBox } from '@/components/modeling/stat-box';
import {
  type CrossFieldRuleShape,
  ValidationRulesCard,
} from '@/components/modeling/validation-rules-card';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { resolveLabel } from '@/features/catalog/attributes/list';
import { HttpError, jsonFetch } from '@/lib/http';
import { isLegacyOptionalSystemGroupCode } from '@/lib/legacy-attribute-groups';
import { useCurrentWorkspace } from '@/lib/use-current-workspace';
import { cn } from '@/lib/utils';

interface ObjectTypeDetail {
  id: string;
  code: string;
  kind: string;
  label?: Record<string, string> | string | null;
  builtIn?: boolean;
  codeImmutable?: boolean;
  deletable?: boolean;
  icon?: string | null;
  color?: string | null;
  schemaVersion?: number;
  hierarchical?: boolean;
  hasVariants?: boolean;
  abstract?: boolean;
  allowedParentTypeIds?: string[];
  completenessRules?: Record<string, unknown> | null;
  validationRules?: CrossFieldRuleShape[] | null;
  exposeToMainMenu?: boolean;
  isCategorizable?: boolean;
  hasMultimedia?: boolean;
}

interface ObjectTypeUsage {
  instanceCount: number;
  attributesAttachedCount: number;
  attributeGroupsAttachedCount: number;
  referencedByApiProfileCount: number;
  referencedByCategoryAttachmentCount: number;
}

interface AttachedAttribute {
  id: string;
  code: string;
  label: Record<string, string> | null;
  type: string;
  required: boolean;
  sortOrder: number;
  isSystem: boolean;
  group: { id: string; code: string } | null;
}

/**
 * #1349 — sortable wrapper around {@link GroupCard}. Adds a drag handle
 * to the left of the card and wires the dnd-kit `useSortable` transform.
 */
function SortableGroupCard({
  group,
  language,
  onEdit,
  onRemove,
  onDisplayModeChange,
}: {
  group: AttachedGroup;
  language: string;
  onEdit?: (group: AttachedGroup) => void;
  onRemove?: (group: AttachedGroup) => void;
  onDisplayModeChange?: (group: AttachedGroup, next: GroupDisplayMode) => void;
}) {
  const { t } = useTranslation();
  const sortable = useSortable({ id: group.id });
  const style = sortable.transform
    ? {
        transform: CSS.Transform.toString(sortable.transform),
        transition: sortable.transition,
      }
    : undefined;

  return (
    <div
      ref={sortable.setNodeRef}
      style={style}
      className={cn('flex items-stretch gap-2', sortable.isDragging && 'opacity-60')}
    >
      <button
        type="button"
        aria-label={t('object_types.group_card_drag', {
          defaultValue: 'Przeciągnij, aby zmienić kolejność',
        })}
        {...sortable.attributes}
        {...sortable.listeners}
        className="mt-1 grid size-7 shrink-0 cursor-grab place-items-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-700 active:cursor-grabbing"
      >
        <GripVertical className="size-4" />
      </button>
      <div className="min-w-0 flex-1">
        <GroupCard
          group={group}
          language={language}
          onEdit={onEdit}
          onRemove={onRemove}
          onDisplayModeChange={onDisplayModeChange}
        />
      </div>
    </div>
  );
}

/**
 * VIEW-01 (#372) — pixel-perfect rebuild of the modeling Object Type
 * Detail view (object-types.jsx 89–244). Sections rendered in the
 * fixed order: header, Identyfikacja, Built-in groups, Custom groups,
 * Settings, Where used, Danger zone, Audit trail, footer.
 */
export function ObjectTypeShowPage() {
  const { t, i18n } = useTranslation();
  const params = useParams<{ id: string }>();
  const navigate = useNavigate();
  const id = params.id ?? '';
  const queryClient = useQueryClient();
  const invalidate = useInvalidate();

  // #1349 — drag-and-drop reordering of attribute groups. Pointer for
  // mouse/touch, keyboard for a11y (arrow keys move the focused row).
  const dndSensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  const { result, query } = useOne<ObjectTypeDetail>({
    resource: 'object_types',
    id,
    queryOptions: { enabled: id.length > 0 },
  });

  const workspace = useCurrentWorkspace();

  const usage = useQuery<ObjectTypeUsage>({
    queryKey: ['object_types', id, 'usage'],
    queryFn: () =>
      jsonFetch<ObjectTypeUsage>(`/api/object_types/${id}/usage`, { accept: 'application/json' }),
    enabled: id.length > 0,
    staleTime: 60_000,
  });

  const groups = useQuery<AttachedGroup[]>({
    queryKey: ['object_types', id, 'attached_groups'],
    queryFn: () =>
      jsonFetch<AttachedGroup[]>(`/api/object_types/${id}/attached_groups`, {
        accept: 'application/json',
      }),
    enabled: id.length > 0,
    staleTime: 30_000,
  });

  const directAttributes = useQuery<AttachedAttribute[]>({
    queryKey: ['object_types', id, 'attached_attributes'],
    queryFn: () =>
      jsonFetch<AttachedAttribute[]>(`/api/object_types/${id}/attached_attributes`, {
        accept: 'application/json',
      }),
    enabled: id.length > 0,
    staleTime: 30_000,
  });

  // #2578 — icon/color are no longer editable; only the name stays inline-editable.
  const [editingField, setEditingField] = useState<'name' | null>(null);
  const [draftLabel, setDraftLabel] = useState<Record<string, string> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [declareGroupOpen, setDeclareGroupOpen] = useState(false);
  const [createGroupOpen, setCreateGroupOpen] = useState(false);
  const [addAttrOpen, setAddAttrOpen] = useState(false);
  const [createAttrOpen, setCreateAttrOpen] = useState(false);

  if (query.isLoading || !result) {
    return <p className="text-sm text-muted-foreground">{t('app.loading')}</p>;
  }

  const objectType = result;
  // UX-05 — Category and Asset ObjectTypes are managed by the system,
  // not the operator. Deep-links land back on the modeling list rather
  // than risking edits that would corrupt the platform-owned schema.
  if (objectType.kind === 'category' || objectType.kind === 'asset') {
    return <Navigate to="/modeling/object-types" replace />;
  }
  const isBuiltIn = objectType.builtIn !== false;
  const labelText = resolveLabel(objectType.label, i18n.language);
  const labelMap =
    objectType.label && typeof objectType.label === 'object' && !Array.isArray(objectType.label)
      ? (objectType.label as Record<string, string>)
      : { [i18n.language]: typeof objectType.label === 'string' ? objectType.label : '' };

  const refreshAll = async () => {
    await Promise.all([
      // Refine's useOne uses its own cache key — invalidate via the SDK
      // helper so the Settings toggles surface the new value immediately.
      invalidate({ resource: 'object_types', id, invalidates: ['detail'] }),
      queryClient.invalidateQueries({ queryKey: ['object_types', id] }),
      queryClient.invalidateQueries({ queryKey: ['object_types', id, 'usage'] }),
      queryClient.invalidateQueries({ queryKey: ['object_types', id, 'attached_groups'] }),
      queryClient.invalidateQueries({ queryKey: ['object_types', id, 'attached_attributes'] }),
      queryClient.invalidateQueries({ queryKey: ['object_types', id, 'audit_log'] }),
      // UX bug fix #1 — list-schema carries the capability flags
      // (has_multimedia / has_variants / is_categorizable) consumed by
      // UniversalDetailPage. Without this invalidate the 5-min staleTime
      // caches the old value, so flipping a capability OFF then back ON
      // does NOT bring the tab back until the cache expires.
      queryClient.invalidateQueries({ queryKey: ['list-schema', id] }),
    ]);
  };

  const refreshGroups = () =>
    queryClient.invalidateQueries({ queryKey: ['object_types', id, 'attached_groups'] });

  const refreshAttributes = () =>
    queryClient.invalidateQueries({ queryKey: ['object_types', id, 'attached_attributes'] });

  const handleAttachGroupAfterCreate = async (group: { id: string; code: string }) => {
    setError(null);
    try {
      if (group.id.length > 0) {
        await jsonFetch(`/api/object_types/${id}/groups/${group.id}`, { method: 'POST' });
      }
      await refreshGroups();
    } catch (e) {
      setError(e instanceof HttpError ? `${e.status}` : e instanceof Error ? e.message : 'unknown');
    }
  };

  // MODR-04 (#926) — PATCH the junction's display_mode in-place. The
  // table cache is invalidated so the segmented control reflects the
  // persisted value on the next render; an error reverts the visible
  // state by leaving the cache untouched.
  const handleDisplayModeChange = async (group: AttachedGroup, next: GroupDisplayMode) => {
    setError(null);
    try {
      await jsonFetch(`/api/object_types/${id}/groups/${group.id}`, {
        method: 'PATCH',
        body: { display_mode: next },
      });
      await refreshGroups();
    } catch (e) {
      setError(e instanceof HttpError ? `${e.status}` : e instanceof Error ? e.message : 'unknown');
    }
  };

  // #1349 — persist a drag-and-drop reorder of the attribute groups. The
  // new order is written to each junction's `position` (PATCH); the
  // EffectiveAttributeGroupResolver orders by `position ASC`, so the
  // left-to-right tab order on the object detail page follows this list.
  const handleReorderGroups = async (event: DragEndEvent, ordered: AttachedGroup[]) => {
    const { active, over } = event;
    if (!over || active.id === over.id) return;
    const oldIndex = ordered.findIndex((g) => g.id === active.id);
    const newIndex = ordered.findIndex((g) => g.id === over.id);
    if (oldIndex < 0 || newIndex < 0) return;

    const reordered = arrayMove(ordered, oldIndex, newIndex);
    const positionById = new Map(reordered.map((g, index) => [g.id, index]));

    // Optimistic: rewrite positions in the cached payload and re-sort it
    // the same way the API does (position ASC, code ASC) so the list and
    // SortableContext reflect the drop without waiting for the refetch.
    queryClient.setQueryData<AttachedGroup[]>(['object_types', id, 'attached_groups'], (prev) =>
      prev === undefined
        ? prev
        : [...prev]
            .map((g) => (positionById.has(g.id) ? { ...g, position: positionById.get(g.id) } : g))
            .sort((a, b) => (a.position ?? 0) - (b.position ?? 0) || a.code.localeCompare(b.code)),
    );

    setError(null);
    try {
      await Promise.all(
        reordered.map((g, index) =>
          jsonFetch(`/api/object_types/${id}/groups/${g.id}`, {
            method: 'PATCH',
            body: { position: index },
          }),
        ),
      );
      await refreshGroups();
    } catch (e) {
      setError(e instanceof HttpError ? `${e.status}` : e instanceof Error ? e.message : 'unknown');
      await refreshGroups();
    }
  };

  const handleDetachGroup = async (group: AttachedGroup) => {
    setError(null);
    try {
      await jsonFetch(`/api/object_types/${id}/groups/${group.id}`, { method: 'DELETE' });
      await refreshGroups();
    } catch (e) {
      setError(e instanceof HttpError ? `${e.status}` : e instanceof Error ? e.message : 'unknown');
    }
  };

  const handleDetachAttribute = async (attributeId: string) => {
    setError(null);
    try {
      await jsonFetch(`/api/object_types/${id}/attributes/${attributeId}`, { method: 'DELETE' });
      await refreshAttributes();
    } catch (e) {
      setError(e instanceof HttpError ? `${e.status}` : e instanceof Error ? e.message : 'unknown');
    }
  };

  const handlePatch = async (payload: Record<string, unknown>): Promise<boolean> => {
    setError(null);
    try {
      await jsonFetch(`/api/object_types/${id}`, {
        method: 'PATCH',
        body: payload,
      });
      await refreshAll();
      return true;
    } catch (e) {
      if (e instanceof HttpError) {
        if (e.status === 403) {
          setError(
            t('object_types.error_forbidden_field', {
              defaultValue: 'To pole jest zablokowane na typie systemowym.',
            }),
          );
        } else {
          setError(`${e.status}`);
        }
      } else {
        setError(e instanceof Error ? e.message : 'unknown');
      }
      return false;
    }
  };

  const handleDelete = async () => {
    try {
      await jsonFetch(`/api/object_types/${id}`, { method: 'DELETE' });
      navigate('/modeling/object-types', { replace: true });
    } catch (e) {
      if (e instanceof HttpError && e.status === 409) {
        setError(
          t('object_types.delete_blocked_message', {
            defaultValue: 'Niemożliwe — {{count}} instancji istnieje. Migruj je lub usuń najpierw.',
            count: usage.data?.instanceCount ?? 0,
          }),
        );
      } else {
        setError(e instanceof Error ? e.message : 'unknown');
      }
    }
  };

  const builtInGroups = (groups.data ?? []).filter((g) => g.system);
  const customGroups = (groups.data ?? []).filter((g) => !g.system);
  // #1100 — the BUILT-IN ATTRIBUTE GROUPS section was removed from
  // this view; only the legacy optional system groups (audit, relations)
  // are still surfaced because they are operator-editable and live
  // inside the CUSTOM ATTRIBUTE GROUPS card alongside true custom ones.
  const legacyOptionalGroups = builtInGroups.filter((g) => isLegacyOptionalSystemGroupCode(g.code));
  const editableGroups = [...legacyOptionalGroups, ...customGroups];
  const enabledLocales = workspace.data?.enabledLocales ?? ['pl', 'en'];
  const primaryLocale = workspace.data?.primaryLocale ?? 'pl';

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <Button
          asChild
          variant="ghost"
          size="sm"
          className="-ml-3 gap-1.5 text-[12.5px] font-medium text-zinc-500 hover:text-zinc-900"
        >
          <Link to="/modeling/object-types">
            <ArrowLeft className="size-3.5" />
            {t('object_types.back_to_list', { defaultValue: 'Wstecz do listy Object Types' })}
          </Link>
        </Button>
        <AuditLogIndicator />
      </div>

      <header className="flex flex-wrap items-start gap-4">
        <ObjectTypeIcon
          kind={objectType.kind}
          icon={objectType.icon}
          color={objectType.color}
          size="lg"
        />
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="display text-[28px] font-semibold tracking-tight">{labelText}</h1>
            {isBuiltIn ? (
              <BuiltInLockBadge />
            ) : (
              <span className="rounded-md bg-emerald-50 px-1.5 py-0.5 text-[10.5px] font-medium uppercase tracking-wide text-emerald-700">
                custom
              </span>
            )}
          </div>
          <p className="mt-1 text-[13px] text-zinc-500">
            <span className="font-mono">{objectType.code}</span>
            <span className="mx-1.5 text-zinc-300">·</span>
            {isBuiltIn
              ? t('object_types.detail_subtitle_system', {
                  defaultValue: 'System type — limited customization',
                })
              : t('object_types.detail_subtitle_custom', {
                  defaultValue: 'Custom type — full control',
                })}
          </p>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          <Button variant="ghost" className="gap-1.5">
            <Copy className="size-3.5" />
            {t('object_types.duplicate_action', { defaultValue: 'Duplikuj' })}
          </Button>
          <Button className="gap-1.5">
            <Pencil className="size-3.5" />
            {t('object_types.edit_action', { defaultValue: 'Edytuj' })}
          </Button>
        </div>
      </header>

      {error ? (
        <div
          role="alert"
          className="rounded-xl border border-rose-100 bg-rose-50 px-4 py-2 text-[13px] text-rose-700"
        >
          {error}
        </div>
      ) : null}

      <Card>
        <CardContent className="space-y-5 p-6">
          <div className="text-[11px] font-medium uppercase tracking-wider text-zinc-500">
            {t('object_types.identification_section', { defaultValue: 'Identyfikacja' })}
          </div>
          <div>
            <div className="mb-2 text-[11.5px] font-medium text-zinc-500">
              {t('object_types.field_name', { defaultValue: 'Nazwa' })}
            </div>
            {editingField === 'name' ? (
              <div className="space-y-2">
                <LocaleTabsField
                  values={draftLabel ?? labelMap}
                  enabledLocales={enabledLocales}
                  primaryLocale={primaryLocale}
                  onChange={setDraftLabel}
                />
                <div className="flex justify-end gap-2">
                  <Button
                    variant="ghost"
                    size="sm"
                    onClick={() => {
                      setEditingField(null);
                      setDraftLabel(null);
                    }}
                  >
                    {t('app.cancel', { defaultValue: 'Anuluj' })}
                  </Button>
                  <Button
                    size="sm"
                    className="gap-1.5"
                    onClick={async () => {
                      const ok = await handlePatch({ label: draftLabel ?? labelMap });
                      if (ok) {
                        setEditingField(null);
                        setDraftLabel(null);
                      }
                    }}
                  >
                    <Check className="size-3.5" />
                    {t('app.save', { defaultValue: 'Zapisz' })}
                  </Button>
                </div>
              </div>
            ) : (
              <button
                type="button"
                onClick={() => setEditingField('name')}
                className="flex w-full items-center gap-2 rounded-xl border border-zinc-100 bg-zinc-50 px-3 py-2 text-left text-[13px] hover:bg-zinc-100"
                aria-label={t('object_types.edit_name', { defaultValue: 'Edytuj nazwę' })}
              >
                <span className="flex-1 truncate">{labelText}</span>
                <Pencil className="size-3.5 text-zinc-500" />
              </button>
            )}
          </div>
          <div className="grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2">
            <FieldDisplay
              label={t('object_types.field_code', { defaultValue: 'Code' })}
              value={objectType.code}
              mono
              locked
            />
            <div>
              <div className="mb-1.5 text-[11.5px] font-medium text-zinc-500">
                {t('object_types.field_icon', { defaultValue: 'Ikona' })}
              </div>
              {/* #2578 — icon/color are display-only now (no picker). */}
              <div className="flex h-10 items-center gap-2 rounded-xl border border-zinc-100 bg-zinc-50 px-3 text-[13px]">
                <ObjectTypeIcon
                  icon={objectType.icon}
                  color={objectType.color}
                  kind={objectType.kind}
                  size="sm"
                />
              </div>
            </div>
            <FieldDisplay
              label={t('object_types.field_tenant', { defaultValue: 'Tenant' })}
              value={workspace.data?.code ?? '—'}
              mono
              locked
            />
          </div>
        </CardContent>
      </Card>

      <Card>
        <CardContent className="space-y-3 p-6">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <span className="text-[11px] font-medium uppercase tracking-wider text-zinc-500">
                {t('object_types.custom_groups_section', {
                  defaultValue: 'Custom attribute groups',
                })}
              </span>
              <span className="text-[11px] text-zinc-500">
                —{' '}
                {t('object_types.custom_groups_tagline', {
                  defaultValue: 'globalne grupy dla wszystkich obiektów typu „{{name}}"',
                  name: labelText,
                })}
              </span>
            </div>
            <div className="flex items-center gap-2">
              <Button
                variant="ghost"
                size="sm"
                className="gap-1.5 text-[12px] font-medium"
                onClick={() => setDeclareGroupOpen(true)}
              >
                <Library className="size-3.5" />
                {t('object_types.attach_group_from_library_action', {
                  defaultValue: 'Z biblioteki',
                })}
              </Button>
              <Button
                size="sm"
                className="gap-1.5 text-[12px] font-medium"
                onClick={() => setCreateGroupOpen(true)}
              >
                <Plus className="size-3.5" />
                {t('object_types.create_new_group_action', { defaultValue: 'Stwórz nowy' })}
              </Button>
            </div>
          </div>
          {legacyOptionalGroups.length > 0 ? (
            <div className="rounded-xl border border-amber-100 bg-amber-50/70 px-3 py-2 text-[12px] text-amber-800">
              {t('object_types.legacy_optional_groups_note', {
                defaultValue:
                  'Legacy system groups (audit, relations) are listed below as removable modeling configuration. The underlying attributes remain system-owned, but their form visibility is no longer automatic.',
              })}
            </div>
          ) : null}
          {editableGroups.length === 0 ? (
            <div className="rounded-2xl border border-dashed border-zinc-200 px-4 py-8 text-center text-[12.5px] text-zinc-500">
              {t('object_types.custom_groups_empty', {
                defaultValue:
                  'Brak custom grup. Dodaj pierwszą — np. Marketing, Pricing, Specyfika.',
              })}
            </div>
          ) : (
            <DndContext
              sensors={dndSensors}
              collisionDetection={closestCenter}
              onDragEnd={(event) => void handleReorderGroups(event, editableGroups)}
            >
              <SortableContext
                items={editableGroups.map((g) => g.id)}
                strategy={verticalListSortingStrategy}
              >
                <div className="space-y-2">
                  {editableGroups.map((g) => (
                    <SortableGroupCard
                      key={g.id}
                      group={g}
                      language={i18n.language}
                      onEdit={
                        g.system ? undefined : () => navigate(`/modeling/attribute-groups/${g.id}`)
                      }
                      onRemove={handleDetachGroup}
                      onDisplayModeChange={handleDisplayModeChange}
                    />
                  ))}
                </div>
              </SortableContext>
            </DndContext>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardContent className="space-y-3 p-6">
          <div className="flex items-center justify-between">
            <div className="flex items-center gap-2">
              <span className="text-[11px] font-medium uppercase tracking-wider text-zinc-500">
                {t('object_types.custom_attributes_section', {
                  defaultValue: 'Custom attribute',
                })}
              </span>
              <span className="text-[11px] text-zinc-500">
                —{' '}
                {t('object_types.custom_attributes_tagline', {
                  defaultValue: 'pojedyncze atrybuty dołączone bezpośrednio (poza grupami)',
                })}
              </span>
            </div>
            <div className="flex items-center gap-2">
              <Button
                variant="ghost"
                size="sm"
                className="gap-1.5 text-[12px] font-medium"
                onClick={() => setAddAttrOpen(true)}
              >
                <Library className="size-3.5" />
                {t('object_types.attach_attribute_from_library_action', {
                  defaultValue: 'Z biblioteki',
                })}
              </Button>
              <Button
                size="sm"
                className="gap-1.5 text-[12px] font-medium"
                onClick={() => setCreateAttrOpen(true)}
              >
                <Plus className="size-3.5" />
                {t('object_types.create_new_attribute_action', { defaultValue: 'Stwórz nowy' })}
              </Button>
            </div>
          </div>
          {(directAttributes.data ?? []).length === 0 ? (
            <div className="rounded-2xl border border-dashed border-zinc-200 px-4 py-8 text-center text-[12.5px] text-zinc-500">
              {t('object_types.custom_attributes_empty', {
                defaultValue:
                  'Brak pojedynczych atrybutów. Dodaj atrybut z biblioteki lub stwórz nowy.',
              })}
            </div>
          ) : (
            <div className="space-y-1.5">
              {(directAttributes.data ?? []).map((a) => (
                <div
                  key={a.id}
                  className="grid grid-cols-[1fr_120px_120px_40px] items-center gap-3 rounded-xl border border-zinc-100 bg-white px-4 py-2.5"
                >
                  <div className="min-w-0">
                    <div className="flex items-center gap-2">
                      <span className="truncate font-mono text-[13px] font-medium">{a.code}</span>
                      {a.required ? (
                        <span className="rounded bg-rose-50 px-1.5 py-0.5 text-[9.5px] font-semibold uppercase tracking-wider text-rose-700">
                          required
                        </span>
                      ) : null}
                      {a.isSystem ? <BuiltInLockBadge /> : null}
                    </div>
                    <div className="truncate text-[11.5px] text-muted-foreground">
                      {resolveLabel(a.label, i18n.language) || a.code}
                    </div>
                  </div>
                  <span className="rounded-md bg-zinc-100 px-2 py-0.5 text-[11px] font-medium uppercase text-zinc-700">
                    {a.type}
                  </span>
                  <span className="truncate text-[11px] text-muted-foreground">
                    {a.group ? (
                      <span className="font-mono">{a.group.code}</span>
                    ) : (
                      <span className="italic">— bez grupy</span>
                    )}
                  </span>
                  <button
                    type="button"
                    aria-label={t('object_types.detach_attribute_action', {
                      defaultValue: 'Odepnij atrybut',
                    })}
                    onClick={() => void handleDetachAttribute(a.id)}
                    className="grid size-8 place-items-center rounded-lg text-zinc-500 transition hover:bg-rose-50 hover:text-rose-600"
                  >
                    <Trash2 className="size-3.5" />
                  </button>
                </div>
              ))}
            </div>
          )}
        </CardContent>
      </Card>

      <Card>
        <CardContent className="space-y-3 p-6">
          <div className="text-[11px] font-medium uppercase tracking-wider text-zinc-500">
            {t('object_types.settings_section', { defaultValue: 'Settings' })}
          </div>
          <SettingToggleRow
            label={t('object_types.setting_variants_label', {
              defaultValue: 'Czy mają warianty?',
            })}
            description={t('object_types.setting_variants_desc', {
              defaultValue:
                'Włącza zakładkę „Warianty" w karcie obiektu (np. Produkt → kolor × rozmiar).',
            })}
            checked={Boolean(objectType.hasVariants)}
            onChange={(next) => void handlePatch({ hasVariants: next })}
          />
          <SettingToggleRow
            label={t('object_types.setting_multimedia_label', {
              defaultValue: 'Czy obiekty tego typu mają zdjęcia i pliki?',
            })}
            description={t('object_types.setting_multimedia_desc', {
              defaultValue:
                'Włącza zakładkę „Multimedia" w karcie obiektu — biblioteka zdjęć i plików przypiętych do obiektu.',
            })}
            checked={Boolean(objectType.hasMultimedia)}
            locked={objectType.kind === 'asset'}
            onChange={(next) => void handlePatch({ hasMultimedia: next })}
          />
          {objectType.kind === 'asset' ? (
            <div className="mt-2 text-[11.5px] text-zinc-500">
              {t('object_types.setting_multimedia_asset_locked', {
                defaultValue:
                  'Zasób sam jest multimediami — nie można rekurencyjnie dodawać kolejnej zakładki Multimedia.',
              })}
            </div>
          ) : null}
          {/* VIEW-08 (#427) — main menu candidacy. Asset is locked because
              /assets has its own DAM page; the generic listing route would
              404 in MVP (ships in B-2). */}
          <div className="border-t border-zinc-100 pt-5">
            <SettingToggleRow
              label={t('object_types.setting_expose_menu_label', {
                defaultValue: 'Udostępnij do głównego menu',
              })}
              description={t('object_types.setting_expose_menu_desc', {
                defaultValue:
                  'Po włączeniu ten ObjectType pojawi się jako dostępna pozycja w Ustawieniach → Menu. Tam zdecydujesz, czy ostatecznie pojawi się w głównym menu i w jakiej kolejności.',
              })}
              checked={Boolean(objectType.exposeToMainMenu)}
              locked={objectType.kind === 'asset'}
              onChange={(next) => void handlePatch({ exposeToMainMenu: next })}
            />
            {objectType.exposeToMainMenu ? (
              <div className="mt-2 text-[11.5px] text-zinc-500">
                {t('object_types.setting_expose_menu_link_prefix', {
                  defaultValue: 'Zarządzaj kolejnością i widocznością w',
                })}{' '}
                <Link
                  to="/settings/menu"
                  className="text-orange-700 underline underline-offset-2 hover:text-orange-700/80"
                >
                  {t('object_types.setting_expose_menu_link', {
                    defaultValue: 'Ustawienia → Menu',
                  })}
                </Link>
                .
              </div>
            ) : null}
            {objectType.kind === 'asset' ? (
              <div className="mt-2 text-[11.5px] text-zinc-500">
                {t('object_types.setting_expose_menu_asset_locked', {
                  defaultValue:
                    'Asset używa dedykowanego widoku /assets — zarządzaj kolejnością przez system item Multimedia.',
                })}
              </div>
            ) : null}
          </div>
          {/* ADR-014 / MOD-11 (#903) — `is_categorizable` toggle. When ON
              the operator MUST pick a primary category when creating
              instances; the form layout is then driven by the primary's
              root→leaf attribute-group overlay (MOD-03). Category kind is
              locked because categories drive the overlay, they don't
              consume it. */}
          <div className="border-t border-zinc-100 pt-5">
            <SettingToggleRow
              label={t('object_types.setting_categorizable_label', {
                defaultValue: 'Czy obiekty mogą być przypisane do drzewa kategorii?',
              })}
              description={t('object_types.setting_categorizable_desc', {
                defaultValue:
                  'Włącza zakładkę „Kategorie" w karcie obiektu. Po włączeniu instancje muszą wybrać kategorię główną przy tworzeniu — jej ścieżka root→leaf w drzewie kategorii dodaje atrybuty kumulatywnie (ADR-014). Wyłącz, by formularz pokazywał tylko bazowe atrybuty ObjectType.',
              })}
              checked={Boolean(objectType.isCategorizable)}
              locked={objectType.kind === 'category'}
              onChange={(next) => void handlePatch({ isCategorizable: next })}
            />
            {objectType.kind === 'category' ? (
              <div className="mt-2 text-[11.5px] text-zinc-500">
                {t('object_types.setting_categorizable_category_locked', {
                  defaultValue:
                    'Kategoria sama definiuje warstwę overlay — nie może być jej konsumentem.',
                })}
              </div>
            ) : null}
          </div>
        </CardContent>
      </Card>

      {/* DP-07 (#2037, ADR-0025) — cross-field rules. Deliberately editable
        on built-in ObjectTypes too: the rules constrain VALUES, not the
        entity model (backend guard mirrors this). */}
      <ValidationRulesCard
        key={`validation-rules-${objectType.schemaVersion ?? 0}`}
        rules={objectType.validationRules ?? []}
        onSave={(rules) => handlePatch({ validationRules: rules })}
      />

      <Card>
        <CardContent className="space-y-3 p-6">
          <div className="text-[11px] font-medium uppercase tracking-wider text-zinc-500">
            {t('object_types.where_used_section', { defaultValue: 'Where used' })}
          </div>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-3">
            <StatBox
              value={usage.data?.instanceCount ?? 0}
              label={t('object_types.stat_instances_label', {
                defaultValue: 'instancji w bazie',
              })}
            />
            <StatBox
              value={usage.data?.referencedByCategoryAttachmentCount ?? 0}
              label={t('object_types.stat_categories_label', {
                defaultValue: 'kategorii używa tego typu',
              })}
            />
            <StatBox
              value={usage.data?.referencedByApiProfileCount ?? 0}
              label={t('object_types.stat_integrations_label', {
                defaultValue: 'integracji odwołuje się',
              })}
            />
          </div>
        </CardContent>
      </Card>

      {!isBuiltIn ? (
        <DangerZoneCard
          title={t('object_types.delete_action', {
            defaultValue: 'Usuń ObjectType „{{name}}"',
            name: labelText,
          })}
          description={
            (usage.data?.instanceCount ?? 0) > 0
              ? t('object_types.delete_blocked_message', {
                  defaultValue:
                    'Niemożliwe — {{count}} instancji istnieje. Migruj je lub usuń najpierw.',
                  count: usage.data?.instanceCount ?? 0,
                })
              : t('object_types.delete_safe_message', {
                  defaultValue: 'Brak instancji — można bezpiecznie usunąć.',
                })
          }
          destructiveLabel={t('object_types.delete_button_safe', { defaultValue: 'Usuń' })}
          blockedLabel={t('object_types.delete_button_blocked', { defaultValue: 'Zablokowane' })}
          blocked={(usage.data?.instanceCount ?? 0) > 0}
          confirmTitle={t('object_types.delete_confirm_title', {
            defaultValue: 'Usunąć ObjectType „{{name}}"?',
            name: labelText,
          })}
          confirmDescription={t('object_types.delete_confirm_body', {
            defaultValue:
              'Operacja jest nieodwracalna. Wszystkie powiązania z attribute groups zostaną zerwane.',
          })}
          onConfirm={handleDelete}
        />
      ) : null}

      <AuditTrailCompact resource="object_types" id={id} limit={5} />

      <footer className="flex items-center justify-end border-t border-zinc-100 pt-6 text-[11.5px] text-zinc-500">
        <span className="num">
          {t('object_types.footer_version', {
            defaultValue: 'v{{version}} · model schema rev {{rev}}',
            version: '1.0.0-rc.4',
            rev: objectType.schemaVersion ?? 1,
          })}
        </span>
      </footer>

      <DeclareObjectTypeAttributeGroupDialog
        open={declareGroupOpen}
        onOpenChange={setDeclareGroupOpen}
        objectTypeId={id}
        objectTypeName={labelText}
        attachedIds={new Set((groups.data ?? []).map((g) => g.id))}
        onAttached={() => void refreshGroups()}
        locale={i18n.language}
      />

      <CreateGroupInlineDialog
        open={createGroupOpen}
        onOpenChange={setCreateGroupOpen}
        onCreated={(group) => void handleAttachGroupAfterCreate(group)}
      />

      <AddAttributesToObjectTypeDialog
        open={addAttrOpen}
        onOpenChange={setAddAttrOpen}
        objectTypeId={id}
        objectTypeName={labelText}
        existingIds={new Set((directAttributes.data ?? []).map((a) => a.id))}
        onAttached={() => void refreshAttributes()}
        locale={i18n.language}
      />

      <CreateAttributeForObjectTypeDialog
        open={createAttrOpen}
        onOpenChange={setCreateAttrOpen}
        objectTypeId={id}
        objectTypeName={labelText}
        onCreated={() => void refreshAttributes()}
      />
    </div>
  );
}

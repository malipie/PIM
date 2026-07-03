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
import { useOne } from '@refinedev/core';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { Eye, GripVertical, Lock, Plus, Save, Trash2, X } from 'lucide-react';
import { useCallback, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router';

import { AddAttributesFromLibraryDialog } from '@/components/modeling/add-attributes-from-library-dialog';
import { AuditLogIndicator } from '@/components/modeling/audit-log-indicator';
import { BuiltInLockBadge } from '@/components/modeling/built-in-lock-badge';
import { CreateAttributeInGroupDialog } from '@/components/modeling/create-attribute-in-group-dialog';
import { DangerZoneCard } from '@/components/modeling/danger-zone-card';
import { LocaleTabsField } from '@/components/modeling/locale-tabs-field';
import { VisibleWhenRuleDialog } from '@/components/modeling/visible-when-rule-dialog';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { resolveLabel } from '@/features/catalog/attributes/list';
import { HttpError, jsonFetch } from '@/lib/http';
import { isLegacyOptionalSystemGroupCode } from '@/lib/legacy-attribute-groups';
import { useCurrentWorkspace } from '@/lib/use-current-workspace';
import { cn } from '@/lib/utils';

interface AttributeGroupDetail {
  id: string;
  code: string;
  label?: Record<string, string> | string | null;
  description?: Record<string, string> | string | null;
  icon?: string | null;
  color?: string | null;
  systemGroup?: boolean;
}

interface MemberRow {
  attribute: {
    id: string;
    code: string;
    type: string;
    label?: Record<string, string> | string | null;
    is_system: boolean;
  };
  position: number;
  is_required_in_group: boolean;
  visible_when: { field: string; operator: string; value: unknown } | null;
}

interface MembersResponse {
  attributeGroupId: string;
  members: MemberRow[];
}

interface UsageResponse {
  totalObjects?: number;
  attributeGroups?: Array<{ id: string }>;
  objectTypes?: Array<{ id: string }>;
  categories?: Array<{ id: string }>;
}

/**
 * VIEW-03 — pixel-perfect AttributeGroupDetail with edit-in-place + 2 popups
 * (mockup `groups-categories.jsx:82–253`).
 *
 * Layout:
 *   - Sticky header (X close, color icon, title + system badge, mono URL,
 *     "Zapisz zmiany" save+exit on the right).
 *   - Card "Identyfikacja": PL/EN locale tabs for Nazwa, locked Code, Color
 *     swatch, Description textarea.
 *   - Card "Attributes in this group": list with required checkbox + visible_when
 *     chip + trash; "+ Z biblioteki" / "+ Stwórz nowy" buttons open popups.
 *   - Card "Where used": 3 stat boxes (typesUsed / categoriesUsed / objectsAffected).
 *
 * Sticky bottom bar appears when the form is dirty (label/description edits).
 */
export function AttributeGroupShowPage() {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const params = useParams<{ id: string }>();
  const id = params.id ?? '';

  const { result, query } = useOne<AttributeGroupDetail>({
    resource: 'attribute_groups',
    id,
    queryOptions: { enabled: id.length > 0 },
  });

  if (query.isLoading || !result) {
    return <p className="text-sm text-muted-foreground">{t('app.loading')}</p>;
  }

  return (
    <Editor
      key={result.id}
      group={result}
      locale={i18n.language}
      onSaved={() => {
        queryClient.invalidateQueries({ queryKey: ['attribute_groups', result.id] });
        queryClient.invalidateQueries({ queryKey: ['attribute_groups'] });
      }}
      onClose={() => navigate('/modeling/attribute-groups')}
    />
  );
}

function Editor({
  group,
  locale,
  onSaved,
  onClose,
}: {
  group: AttributeGroupDetail;
  locale: string;
  onSaved: () => void;
  onClose: () => void;
}) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const workspace = useCurrentWorkspace();
  const enabledLocales = workspace.data?.enabledLocales ?? ['pl', 'en'];
  const primaryLocale = workspace.data?.primaryLocale ?? 'pl';

  const initialLabel = toLocaleMap(group.label);
  const initialDescription = toLocaleMap(group.description);
  const initialColor = group.color ?? '#71717a';

  const [label, setLabel] = useState<Record<string, string>>(initialLabel);
  const [description, setDescription] = useState<Record<string, string>>(initialDescription);
  const [color, setColor] = useState(initialColor);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [pickerOpen, setPickerOpen] = useState(false);
  const [createOpen, setCreateOpen] = useState(false);
  // DP-08 (#2039) — which member's visible_when rule is being edited.
  const [ruleTarget, setRuleTarget] = useState<MemberRow | null>(null);

  const isSystem = group.systemGroup === true;
  const isLegacyOptionalGroup = isLegacyOptionalSystemGroupCode(group.code);
  const isLockedSystemGroup = isSystem && !isLegacyOptionalGroup;

  const { data: members = [], refetch: refetchMembers } = useQuery<MemberRow[]>({
    queryKey: ['attribute_groups', group.id, 'attributes'],
    queryFn: async () => {
      const data = await jsonFetch<MembersResponse>(
        `/api/attribute_groups/${group.id}/attributes`,
        { accept: 'application/json' },
      );
      return data.members ?? [];
    },
    staleTime: 30_000,
  });

  const { data: usage } = useQuery<UsageResponse>({
    queryKey: ['attribute_groups', group.id, 'usage'],
    queryFn: () => jsonFetch<UsageResponse>(`/api/attribute_groups/${group.id}/usage`),
    staleTime: 60_000,
  });

  const sortedMembers = [...members].sort((a, b) => a.position - b.position);
  const existingCodes = new Set(sortedMembers.map((m) => m.attribute.code));

  const labelDirtyCount = countLocaleDiffs(label, initialLabel);
  const descriptionDirtyCount = countLocaleDiffs(description, initialDescription);
  const dirtyFields = labelDirtyCount + descriptionDirtyCount + (color !== initialColor ? 1 : 0);
  const dirty = dirtyFields > 0;

  const cancel = () => {
    setLabel(initialLabel);
    setDescription(initialDescription);
    setColor(initialColor);
    setError(null);
  };

  const save = useCallback(async (): Promise<boolean> => {
    setSaving(true);
    setError(null);
    try {
      const body: Record<string, unknown> = {};
      const nextLabel = stripEmpty(label);
      if (JSON.stringify(nextLabel) !== JSON.stringify(stripEmpty(initialLabel)))
        body.label = nextLabel;
      const nextDescription = stripEmpty(description);
      if (JSON.stringify(nextDescription) !== JSON.stringify(stripEmpty(initialDescription)))
        body.description = nextDescription;
      if (!isLockedSystemGroup) {
        if (color !== initialColor) body.color = color;
      }
      await jsonFetch(`/api/attribute_groups/${group.id}`, {
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
  }, [
    color,
    description,
    group.id,
    initialColor,
    initialDescription,
    initialLabel,
    isLockedSystemGroup,
    label,
    onSaved,
    t,
  ]);

  const saveAndExit = useCallback(async () => {
    if (dirty) {
      const ok = await save();
      if (!ok) return;
    }
    onClose();
  }, [dirty, onClose, save]);

  const reload = useCallback(async () => {
    await Promise.all([
      refetchMembers(),
      queryClient.invalidateQueries({ queryKey: ['attribute_groups', group.id, 'usage'] }),
    ]);
  }, [group.id, queryClient, refetchMembers]);

  const detach = async (attributeId: string) => {
    try {
      await jsonFetch(`/api/attribute_groups/${group.id}/attributes/${attributeId}`, {
        method: 'DELETE',
        accept: 'application/json',
      });
      await reload();
    } catch {
      // ignored — the next reload will resync
    }
  };

  const navigate = useNavigate();

  const deleteGroup = useCallback(async () => {
    try {
      await jsonFetch(`/api/attribute_groups/${group.id}`, {
        method: 'DELETE',
        accept: 'application/json',
      });
      queryClient.invalidateQueries({ queryKey: ['attribute_groups'] });
      navigate('/modeling/attribute-groups');
    } catch (err) {
      // Re-thrown so DangerZoneCard surfaces the message inline.
      if (err instanceof HttpError) {
        const detail =
          err.body && typeof err.body === 'object' && 'detail' in err.body
            ? String((err.body as Record<string, unknown>).detail)
            : null;
        throw new Error(
          detail ??
            t('modeling.attributeGroups.delete_error_generic', {
              defaultValue: 'Nie udało się usunąć grupy (HTTP {{status}}).',
              status: err.status,
            }),
        );
      }
      throw err instanceof Error
        ? err
        : new Error(
            t('modeling.attributeGroups.delete_error_generic', {
              defaultValue: 'Nie udało się usunąć grupy.',
              status: 0,
            }),
          );
    }
  }, [group.id, navigate, queryClient, t]);

  const toggleRequired = async (attributeId: string, next: boolean) => {
    try {
      await jsonFetch(`/api/attribute_groups/${group.id}/attributes/${attributeId}`, {
        method: 'PATCH',
        contentType: 'application/json',
        accept: 'application/json',
        body: { isRequiredInGroup: next },
      });
      await reload();
    } catch {
      // ignored
    }
  };

  // dnd-kit: sortable wiring for the members list. Drag handles only fire
  // on long-press / pointer-down, so checkboxes and trash icons keep their
  // own click events without competing with drag activation. Reorder posts
  // a strict permutation of current member codes; on error we fall back to
  // refetching (the BE state is authoritative).
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 6 } }),
    useSensor(KeyboardSensor, { coordinateGetter: sortableKeyboardCoordinates }),
  );

  const onDragEnd = useCallback(
    async (event: DragEndEvent) => {
      const { active, over } = event;
      if (over === null || active.id === over.id) return;
      const oldIndex = sortedMembers.findIndex((m) => m.attribute.id === active.id);
      const newIndex = sortedMembers.findIndex((m) => m.attribute.id === over.id);
      if (oldIndex < 0 || newIndex < 0) return;

      const reordered = arrayMove(sortedMembers, oldIndex, newIndex).map((m, i) => ({
        ...m,
        position: i,
      }));

      // Optimistic update: rewrite the cached members list so the UI
      // settles to the new order before the BE confirms.
      queryClient.setQueryData(['attribute_groups', group.id, 'attributes'], reordered);

      try {
        await jsonFetch(`/api/attribute_groups/${group.id}/attributes/reorder`, {
          method: 'POST',
          contentType: 'application/json',
          accept: 'application/json',
          body: { order: reordered.map((m) => m.attribute.code) },
        });
        await reload();
      } catch {
        // BE rejected the permutation — pull authoritative state.
        await reload();
      }
    },
    [group.id, queryClient, reload, sortedMembers],
  );

  const groupName = resolveLabel(group.label, locale);

  return (
    <div className={cn('-m-6 space-y-0', dirty ? 'pb-24' : '')}>
      {/* Sticky header */}
      <div className="sticky top-0 z-10 flex items-start gap-4 border-b border-zinc-200 bg-zinc-50/95 px-7 py-5 backdrop-blur">
        <button
          type="button"
          onClick={onClose}
          aria-label={t('app.close', { defaultValue: 'Zamknij' })}
          className="grid size-9 shrink-0 place-items-center rounded-xl text-muted-foreground hover:bg-zinc-200/60"
        >
          <X className="size-4" />
        </button>
        <div
          className="grid size-12 shrink-0 place-items-center rounded-2xl text-[20px]"
          style={{ background: `${color}18`, color }}
        >
          {group.icon ?? '📦'}
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <h1 className="font-display text-[22px] font-semibold tracking-tight">{groupName}</h1>
            {isLockedSystemGroup ? <BuiltInLockBadge /> : null}
          </div>
          <div className="mt-0.5 font-mono text-[12px] text-muted-foreground">
            /modeling/attribute-groups/{group.code}
          </div>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          <AuditLogIndicator />
          {!isLockedSystemGroup ? (
            <Button
              size="sm"
              disabled={saving}
              onClick={() => {
                void saveAndExit();
              }}
              className="h-9 rounded-xl bg-zinc-900 hover:bg-zinc-800"
            >
              <Save className="size-4" />
              {t('attribute_groups.save_changes', { defaultValue: 'Zapisz zmiany' })}
            </Button>
          ) : null}
        </div>
      </div>

      <div className="space-y-6 p-7">
        {/* Identyfikacja */}
        <Card className="p-6">
          <SectionTitle>
            {t('modeling.attributeGroups.definition_title', { defaultValue: 'Identyfikacja' })}
          </SectionTitle>

          <div className="mb-5">
            <Label className="text-[11.5px] font-medium text-muted-foreground">
              {t('modeling.attributeGroups.fields.name', { defaultValue: 'Nazwa' })}
            </Label>
            <div className="mt-1.5">
              <LocaleTabsField
                values={label}
                enabledLocales={enabledLocales}
                primaryLocale={primaryLocale}
                onChange={setLabel}
                readOnly={isLockedSystemGroup}
                placeholder={t('modeling.attributeGroups.fields.name_placeholder', {
                  defaultValue: 'Nazwa grupy',
                })}
              />
            </div>
          </div>

          <div className="grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2">
            <FieldRow
              label={t('modeling.attributeGroups.fields.code', { defaultValue: 'Code' })}
              lock
            >
              <span className="font-mono text-[13.5px]">{group.code}</span>
            </FieldRow>
            <FieldRow
              label={t('modeling.attributeGroups.fields.color', { defaultValue: 'Color' })}
              lock={isLockedSystemGroup}
            >
              <span className="inline-flex items-center gap-2">
                <span className="size-4 rounded" style={{ background: color }} aria-hidden />
                {isLockedSystemGroup ? (
                  <span className="font-mono text-[12px]">{color}</span>
                ) : (
                  <Input
                    type="text"
                    value={color}
                    onChange={(e) => setColor(e.target.value)}
                    className="h-7 w-[110px] font-mono text-[12px]"
                  />
                )}
              </span>
            </FieldRow>
          </div>

          <div className="mt-4">
            <Label className="text-[11.5px] font-medium text-muted-foreground">
              {t('modeling.attributeGroups.fields.description', { defaultValue: 'Description' })}
            </Label>
            <div className="mt-1.5 grid gap-2 sm:grid-cols-2">
              {enabledLocales.map((lc) => (
                <Textarea
                  key={lc}
                  rows={2}
                  value={description[lc] ?? ''}
                  onChange={(e) => setDescription({ ...description, [lc]: e.target.value })}
                  disabled={isLockedSystemGroup}
                  aria-label={t('modeling.attributeGroups.fields.description_locale_aria', {
                    defaultValue: 'Opis ({{locale}})',
                    locale: lc.toUpperCase(),
                  })}
                  placeholder={t('modeling.attributeGroups.fields.description_locale', {
                    defaultValue: '{{locale}} · krótki opis grupy',
                    locale: lc.toUpperCase(),
                  })}
                />
              ))}
            </div>
          </div>
        </Card>

        {/* Attributes in this group */}
        <Card className="p-6">
          <div className="mb-4 flex items-center justify-between gap-3">
            <div className="flex items-center gap-2">
              <SectionTitle as="span">
                {t('modeling.attributeGroups.members_title', {
                  defaultValue: 'Attributes in this group',
                })}
              </SectionTitle>
              <span className="text-[11px] text-muted-foreground">
                {t('modeling.attributeGroups.members_drag_hint', {
                  defaultValue: '— drag to reorder',
                })}
              </span>
            </div>
            <div className="flex items-center gap-2">
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={() => setPickerOpen(true)}
                className="h-8 rounded-lg px-2.5 text-[12px]"
              >
                <Plus className="size-3.5" />
                {t('modeling.attributeGroups.members_from_library_action', {
                  defaultValue: 'Z biblioteki',
                })}
              </Button>
              <Button
                type="button"
                size="sm"
                onClick={() => setCreateOpen(true)}
                className="h-8 rounded-lg bg-orange-50 px-2.5 text-[12px] text-orange-700 hover:bg-orange-100"
              >
                <Plus className="size-3.5" />
                {t('modeling.attributeGroups.members_create_new_action', {
                  defaultValue: 'Stwórz nowy',
                })}
              </Button>
            </div>
          </div>

          {sortedMembers.length === 0 ? (
            <button
              type="button"
              onClick={() => setPickerOpen(true)}
              className="flex w-full items-center justify-center gap-2 rounded-xl border border-dashed border-zinc-200 py-2.5 text-[12.5px] font-medium text-muted-foreground transition hover:border-orange-300 hover:bg-orange-50/40 hover:text-orange-700"
            >
              <Plus className="size-4" />
              {t('modeling.attributeGroups.members_empty_action', {
                defaultValue: 'Add attribute from library',
              })}
            </button>
          ) : (
            <DndContext
              sensors={sensors}
              collisionDetection={closestCenter}
              onDragEnd={(e) => {
                void onDragEnd(e);
              }}
            >
              <SortableContext
                items={sortedMembers.map((m) => m.attribute.id)}
                strategy={verticalListSortingStrategy}
              >
                <div className="space-y-1.5">
                  {sortedMembers.map((row) => (
                    <SortableMemberRow
                      key={row.attribute.id}
                      row={row}
                      locale={locale}
                      onToggleRequired={(next) => {
                        void toggleRequired(row.attribute.id, next);
                      }}
                      onDetach={() => {
                        void detach(row.attribute.id);
                      }}
                      onEditRule={() => setRuleTarget(row)}
                    />
                  ))}
                </div>
              </SortableContext>
            </DndContext>
          )}
        </Card>

        {sortedMembers.some((m) => m.visible_when !== null) ? (
          <Card className="p-6">
            <div className="mb-4 flex items-center gap-2">
              <SectionTitle as="span">
                {t('modeling.attributeGroups.rules_title', { defaultValue: 'Visibility rules' })}
              </SectionTitle>
              <span className="rounded bg-orange-100 px-1.5 py-0.5 text-[10.5px] font-semibold uppercase tracking-wider text-orange-700">
                {t('modeling.attributeGroups.rules_visible_when_badge', {
                  defaultValue: 'visible_when',
                })}
              </span>
            </div>
            <div className="space-y-1 rounded-2xl border border-orange-200 bg-orange-50/40 p-4">
              {sortedMembers
                .filter((m) => m.visible_when !== null)
                .map((m) => (
                  <div key={m.attribute.id} className="flex items-center gap-3 py-1">
                    <span className="font-mono text-[12.5px] font-medium">{m.attribute.code}</span>
                    <span className="text-[11.5px] text-muted-foreground">visible_when</span>
                    <span className="rounded border border-orange-200 bg-white px-2 py-0.5 font-mono text-[12.5px] text-orange-700">
                      {m.visible_when?.field}={String(m.visible_when?.value)}
                    </span>
                    <button
                      type="button"
                      onClick={() => setRuleTarget(m)}
                      className="ml-auto inline-flex items-center gap-1 text-[11.5px] text-muted-foreground hover:text-foreground"
                    >
                      <Eye className="size-3.5" />
                      {t('modeling.attributeGroups.rules_edit_action', {
                        defaultValue: 'Edit rule',
                      })}
                    </button>
                  </div>
                ))}
            </div>
            <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div className="rounded-xl border border-emerald-200 bg-emerald-50/40 px-4 py-3">
                <div className="text-[11px] font-medium uppercase tracking-wider text-emerald-700">
                  {t('modeling.attributeGroups.rules_test_pass_status', {
                    defaultValue: 'VISIBLE',
                  })}
                </div>
                <div className="mt-0.5 font-mono text-[12px] text-emerald-900">
                  test: rule met → field visible in form
                </div>
              </div>
              <div className="rounded-xl border border-zinc-200 bg-zinc-50 px-4 py-3">
                <div className="text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
                  {t('modeling.attributeGroups.rules_test_fail_status', {
                    defaultValue: 'HIDDEN',
                  })}
                </div>
                <div className="mt-0.5 font-mono text-[12px] text-zinc-700">
                  test: rule fails → field hidden in form
                </div>
              </div>
            </div>
          </Card>
        ) : null}

        {/* Where used */}
        <Card className="p-6">
          <SectionTitle>
            {t('modeling.attributeGroups.where_used_title', { defaultValue: 'Where used' })}
          </SectionTitle>
          <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
            <StatBox
              value={usage?.objectTypes?.length ?? 0}
              label={t('modeling.attributeGroups.where_used_object_types_label', {
                defaultValue: 'ObjectTypes (globalnie)',
              })}
            />
            <StatBox
              value={usage?.categories?.length ?? 0}
              label={t('modeling.attributeGroups.where_used_categories_label', {
                defaultValue: 'Categories (deklarują)',
              })}
            />
            <StatBox
              value={(usage?.totalObjects ?? 0).toLocaleString('pl-PL')}
              label={t('modeling.attributeGroups.where_used_instances_label', {
                defaultValue: 'instancji dotkniętych',
              })}
            />
          </div>
        </Card>

        {!isLockedSystemGroup ? (
          <DangerZoneCard
            title={t('modeling.attributeGroups.danger_zone_title', {
              defaultValue: 'Usuń grupę atrybutów',
            })}
            description={t('modeling.attributeGroups.danger_zone_description', {
              defaultValue:
                'Trwałe usunięcie grupy. Atrybuty przypisane do grupy pozostają w bibliotece. Backend zablokuje usunięcie, jeśli grupa jest jeszcze przypięta do ObjectType lub kategorii.',
            })}
            destructiveLabel={t('modeling.attributeGroups.danger_zone_action', {
              defaultValue: 'Usuń grupę',
            })}
            blockedLabel={t('modeling.attributeGroups.danger_zone_action_blocked', {
              defaultValue: 'Usuwanie zablokowane',
            })}
            blocked={false}
            confirmTitle={t('modeling.attributeGroups.danger_zone_confirm_title', {
              defaultValue: 'Usunąć grupę „{{name}}"?',
              name: groupName,
            })}
            confirmDescription={t('modeling.attributeGroups.danger_zone_confirm_description', {
              defaultValue:
                'Operacja jest nieodwracalna. Atrybuty zostaną odpięte od grupy, ale pozostaną dostępne w bibliotece.',
            })}
            onConfirm={deleteGroup}
          />
        ) : null}
      </div>

      {dirty ? (
        <div className="fixed inset-x-0 bottom-0 z-30 border-t border-zinc-200 bg-white shadow-lg">
          <div className="mx-auto flex max-w-7xl items-center justify-between gap-3 px-6 py-4">
            <span className="text-[13px] text-muted-foreground">
              {t('attribute_groups.dirty_count', {
                defaultValue: '{{count}} pól zmienionych',
                count: dirtyFields,
              })}
            </span>
            {error !== null ? <span className="text-[12px] text-destructive">{error}</span> : null}
            <div className="flex items-center gap-2">
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={cancel}
                disabled={saving}
                className="h-9 rounded-xl"
              >
                {t('app.cancel', { defaultValue: 'Anuluj' })}
              </Button>
              <Button
                type="button"
                size="sm"
                disabled={saving}
                onClick={() => {
                  void save();
                }}
                className="h-9 rounded-xl bg-zinc-900 hover:bg-zinc-800"
              >
                {t('attribute_groups.save_changes', { defaultValue: 'Zapisz zmiany' })}
              </Button>
            </div>
          </div>
        </div>
      ) : null}

      {/* DP-08 (#2039) — mounted only while open (fresh state per opening). */}
      {ruleTarget !== null ? (
        <VisibleWhenRuleDialog
          groupId={group.id}
          attributeId={ruleTarget.attribute.id}
          attributeCode={ruleTarget.attribute.code}
          siblingCodes={sortedMembers
            .filter((m) => m.attribute.id !== ruleTarget.attribute.id)
            .map((m) => m.attribute.code)}
          initialRule={ruleTarget.visible_when}
          onOpenChange={(open) => {
            if (!open) setRuleTarget(null);
          }}
          onSaved={() => reload()}
        />
      ) : null}

      <AddAttributesFromLibraryDialog
        open={pickerOpen}
        onOpenChange={setPickerOpen}
        groupId={group.id}
        groupName={groupName}
        existingCodes={existingCodes}
        onAttached={() => {
          void reload();
        }}
        locale={locale}
      />
      <CreateAttributeInGroupDialog
        open={createOpen}
        onOpenChange={setCreateOpen}
        groupId={group.id}
        groupName={groupName}
        onCreated={() => {
          void reload();
        }}
      />
    </div>
  );
}

function FieldRow({
  label,
  lock,
  children,
}: {
  label: string;
  lock?: boolean;
  children: React.ReactNode;
}) {
  return (
    <div className="space-y-1.5">
      <div className="flex items-center gap-1.5">
        <span className="text-[11.5px] font-medium text-muted-foreground">{label}</span>
        {lock ? <Lock className="size-3 text-muted-foreground" /> : null}
      </div>
      <div>{children}</div>
    </div>
  );
}

function SectionTitle({
  as: Tag = 'div',
  children,
}: {
  as?: 'div' | 'span';
  children: React.ReactNode;
}) {
  return (
    <Tag className="mb-4 text-[11px] font-medium uppercase tracking-wider text-muted-foreground">
      {children}
    </Tag>
  );
}

function SortableMemberRow({
  row,
  locale,
  onToggleRequired,
  onDetach,
  onEditRule,
}: {
  row: MemberRow;
  locale: string;
  onToggleRequired: (next: boolean) => void;
  onDetach: () => void;
  /** DP-08 (#2039) — opens the visible_when editor for this member. */
  onEditRule: () => void;
}) {
  const { t } = useTranslation();
  const { attributes, listeners, setNodeRef, transform, transition, isDragging } = useSortable({
    id: row.attribute.id,
  });
  const labelStr = resolveLabel(row.attribute.label, locale);
  const style = {
    transform: CSS.Transform.toString(transform),
    transition,
    opacity: isDragging ? 0.6 : 1,
  };

  return (
    <div
      ref={setNodeRef}
      style={style}
      className="grid grid-cols-[24px_1.5fr_120px_180px_100px_28px] items-center gap-3 rounded-xl border border-zinc-100 bg-white px-3 py-2.5 transition hover:border-zinc-200 hover:bg-zinc-50/60"
    >
      <button
        type="button"
        {...attributes}
        {...listeners}
        aria-label={t('modeling.attributeGroups.drag_handle', { defaultValue: 'Przeciągnij' })}
        className="grid size-6 cursor-grab place-items-center text-zinc-300 hover:text-zinc-500 active:cursor-grabbing"
      >
        <GripVertical className="size-4" />
      </button>
      <div className="min-w-0">
        <div className="flex items-center gap-2">
          <span className="truncate font-mono text-[13px] font-medium">{row.attribute.code}</span>
          {row.attribute.is_system ? <BuiltInLockBadge /> : null}
        </div>
        <div className="truncate text-[11.5px] text-muted-foreground">{labelStr}</div>
      </div>
      <span className="rounded-md bg-muted px-2 py-0.5 text-[11px] font-medium uppercase text-muted-foreground">
        {row.attribute.type}
      </span>
      {row.visible_when ? (
        <button
          type="button"
          onClick={onEditRule}
          className="inline-flex items-center gap-1.5 rounded-lg bg-orange-50 px-2 py-1 text-left font-mono text-[11px] text-orange-700 transition hover:bg-orange-100"
        >
          when {row.visible_when.field}={String(row.visible_when.value)}
        </button>
      ) : (
        <button
          type="button"
          onClick={onEditRule}
          className="text-left text-[11px] text-zinc-300 transition hover:text-zinc-600"
        >
          {t('modeling.attributeGroups.members_add_visibility_rule', {
            defaultValue: '+ reguła widoczności',
          })}
        </button>
      )}
      <label className="flex items-center gap-1.5 text-[11.5px] text-muted-foreground">
        <input
          type="checkbox"
          className="size-3.5 rounded"
          checked={row.is_required_in_group}
          onChange={(e) => onToggleRequired(e.target.checked)}
        />
        required
      </label>
      <button
        type="button"
        onClick={onDetach}
        className="grid size-7 place-items-center justify-self-end rounded text-zinc-300 hover:text-rose-600"
        aria-label={t('app.remove', { defaultValue: 'Usuń' })}
      >
        <Trash2 className="size-4" />
      </button>
    </div>
  );
}

function StatBox({ value, label }: { value: number | string; label: string }) {
  return (
    <div className="rounded-2xl border border-zinc-100 bg-zinc-50/40 px-4 py-4">
      <div className="font-display text-[26px] font-semibold tracking-tight tabular-nums">
        {value}
      </div>
      <div className="mt-0.5 text-[11.5px] text-muted-foreground">{label}</div>
    </div>
  );
}

function toLocaleMap(
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

function countLocaleDiffs(next: Record<string, string>, initial: Record<string, string>): number {
  const keys = new Set([...Object.keys(next), ...Object.keys(initial)]);
  let count = 0;
  for (const key of keys) {
    if ((next[key] ?? '') !== (initial[key] ?? '')) count += 1;
  }
  return count;
}

function stripEmpty(record: Record<string, string>): Record<string, string> {
  const out: Record<string, string> = {};
  for (const [k, v] of Object.entries(record)) {
    if (v.trim() !== '') out[k] = v;
  }
  return out;
}

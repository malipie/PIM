import { useInvalidate, useList } from '@refinedev/core';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { FolderTree, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useSearchParams } from 'react-router';
import { CategoryDangerZone } from '@/components/modeling/category-danger-zone';
import {
  buildCategoryTree,
  CategoryTree,
  type CategoryTreeNode,
} from '@/components/modeling/category-tree';
import { DeclareAttributeGroupDialog } from '@/components/modeling/declare-attribute-group-dialog';
import { ModelingPageHeader } from '@/components/modeling/modeling-page-header';
import { MoveCategoryDialog } from '@/components/modeling/move-category-dialog';
import { ObjectTypeFilterDropdown } from '@/components/modeling/object-type-filter-dropdown';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { MockBadge } from '@/components/ui/mock-badge';
import { TopbarCta } from '@/components/ui-v2/topbar-cta';
import { usePageActions } from '@/layout/page-actions-context';
import { jsonFetch } from '@/lib/http';
import { cn } from '@/lib/utils';

import { CategoryProductsCard } from './category-products-card';

interface CategoryEntry {
  id: string;
  code: string;
  path?: string | null;
  enabled?: boolean;
  attributesIndexed?: Record<string, unknown>;
}

interface DeclaredGroup {
  groupId: string;
  position: number;
  group: {
    id: string;
    code: string;
    label: Record<string, string> | string | null;
    icon?: string | null;
    color?: string | null;
    is_system_group?: boolean;
  };
}

interface DeclaredGroupsResponse {
  categoryId: string;
  targetObjectType: { id: string; code: string; kind: string; label: Record<string, string> };
  declaredGroups: DeclaredGroup[];
}

interface EffectiveGroup {
  id: string;
  code: string;
  label: Record<string, string> | string | null;
  icon?: string | null;
  color?: string | null;
  is_system_group?: boolean;
  position: number;
  source: 'object_type' | 'declared_here' | 'inherited_from';
  source_category: { id: string; code: string; path?: string | null } | null;
  attributes: Array<{ id: string; code: string; type: string; is_system: boolean }>;
}

interface EffectiveResponse {
  categoryId: string;
  objectType: { id: string; code: string; kind: string; label: Record<string, string> };
  effectiveGroups: EffectiveGroup[];
}

interface UsageResponse {
  categoryId: string;
  instanceCount: number;
  descendantCount: number;
  declaredFor: Array<{ targetObjectTypeKind: string; groupCount: number }>;
}

/**
 * VIEW-04 (#408) — modeling/categories pixel-perfect rebuild.
 *
 * Split layout (mockup `groups-categories.jsx:255–423`):
 *   - Left 320px Card: tree + target ObjectType filter.
 *   - Right Card stack: Detail panel (declared + inherited) + Effective
 *     preview ("killer feature" — what an object of `targetType` placed
 *     under this category will see in its form).
 *
 * State:
 *   - `selectedId` + `targetType` persist via URL search params (deep-link
 *     friendly — copy/paste a tree position into Slack and the receiver
 *     opens the same node + filter).
 *   - Declare-group popup is local; on success it invalidates the
 *     `attribute_groups` and `effective-groups` queries via the dialog
 *     itself — no manual refetch here.
 *
 * Out of scope (deferred to follow-ups):
 *   - DnD reorder of the tree (Move via separate dialog → next session).
 *   - Create + Edit pages (`/new` / `/:id`) — existing `show.tsx` still
 *     used as edit surface until the wizard pattern lands.
 */
export function CategoriesTreePage() {
  const { t, i18n } = useTranslation();
  const [searchParams, setSearchParams] = useSearchParams();

  // ADR-014 / MOD-11 (#903): default falls back to whatever the dropdown
  // resolves as the first eligible OT (`is_built_in=true` AND
  // `is_categorizable=true`). The dropdown emits its choice via
  // `onChange` on mount when the URL param doesn't match anything, so an
  // empty default is correct here — the URL gets stamped on first render.
  // ADR-015 — the tree is keyed by ObjectType id (`targetObjectTypeId`); the
  // kind (`targetType`) is kept alongside for the legacy kind-based attribute-
  // group declaration calls in the detail panel. Both are stamped by the
  // dropdown on mount, so a reload restores the exact tree + kind.
  const targetObjectTypeId = searchParams.get('targetObjectTypeId') ?? '';
  const targetType: string = searchParams.get('targetType') ?? 'product';

  usePageActions(
    useMemo(
      () => (
        <TopbarCta
          to={
            targetObjectTypeId
              ? `/modeling/categories/new?targetObjectTypeId=${targetObjectTypeId}`
              : '/modeling/categories/new'
          }
        >
          {t('categories.create_action', { defaultValue: 'Nowa kategoria' })}
        </TopbarCta>
      ),
      [t, targetObjectTypeId],
    ),
  );
  const selectedId = searchParams.get('selected') ?? null;
  // DP-01 (#2031) — `?move=1` (deep-link from show.tsx) auto-opens the Move
  // dialog for the selected node once the tree resolves.
  const moveRequested = searchParams.get('move') === '1';
  const [moveOpen, setMoveOpen] = useState(false);
  const invalidate = useInvalidate();
  const queryClient = useQueryClient();

  const { result } = useList<CategoryEntry>({
    resource: 'categories',
    pagination: { mode: 'off' },
    filters: targetObjectTypeId
      ? [{ field: 'categoryTargetObjectType', operator: 'eq', value: targetObjectTypeId }]
      : [],
    queryOptions: { enabled: targetObjectTypeId !== '' },
  });

  const tree = useMemo(
    () =>
      buildCategoryTree(
        (result.data ?? []).map((row) => ({
          id: row.id,
          code: row.code,
          path: row.path,
          attributesIndexed: row.attributesIndexed,
        })),
      ),
    [result.data],
  );

  const initialExpanded = useMemo(
    () => collectAllAncestorIds(tree, selectedId),
    [tree, selectedId],
  );

  const selectedNode = useMemo(
    () => (selectedId ? findNode(tree, selectedId) : null),
    [tree, selectedId],
  );

  useEffect(() => {
    if (moveRequested && selectedNode) {
      setMoveOpen(true);
      const next = new URLSearchParams(searchParams);
      next.delete('move');
      setSearchParams(next, { replace: true });
    }
  }, [moveRequested, selectedNode, searchParams, setSearchParams]);

  const handleMoved = () => {
    // Fresh paths for the whole tree + any per-category panels (usage,
    // declared/effective groups key off ['categories', id, ...]).
    invalidate({ resource: 'categories', invalidates: ['list', 'detail'] });
    void queryClient.invalidateQueries({ queryKey: ['categories'] });
  };

  const handleDeleted = () => {
    invalidate({ resource: 'categories', invalidates: ['list', 'detail'] });
    void queryClient.invalidateQueries({ queryKey: ['categories'] });
    const next = new URLSearchParams(searchParams);
    next.delete('selected');
    setSearchParams(next, { replace: true });
  };

  const handleSelect = (id: string) => {
    const next = new URLSearchParams(searchParams);
    next.set('selected', id);
    setSearchParams(next, { replace: true });
  };

  const handleTargetChange = (objectTypeId: string, kind: string) => {
    const next = new URLSearchParams(searchParams);
    next.set('targetObjectTypeId', objectTypeId);
    next.set('targetType', kind);
    // Switching trees invalidates the selected node (it lived in the old tree).
    next.delete('selected');
    setSearchParams(next, { replace: true });
  };

  return (
    <div className="space-y-6">
      <ModelingPageHeader
        title={t('categories.list_title', { defaultValue: 'Categories · modeling' })}
        trailing={
          <ObjectTypeFilterDropdown
            value={targetObjectTypeId || null}
            onChange={handleTargetChange}
          />
        }
      />

      <div className="grid gap-6 lg:grid-cols-[320px_1fr]">
        <Card className="p-3">
          <div className="mb-2 flex items-center gap-2 border-b border-zinc-100 px-3 py-2 text-[11px] font-medium uppercase tracking-wider text-zinc-500">
            <span>{t('categories.tree_label', { defaultValue: 'Drzewo kategorii' })}</span>
            <span className="ml-auto font-mono text-[10.5px] text-zinc-500">
              target: {targetType}
            </span>
          </div>
          {tree.length === 0 ? (
            <p className="px-3 py-6 text-center text-[13px] text-muted-foreground">
              {t('categories.empty', { defaultValue: 'Brak kategorii. Utwórz pierwszą.' })}
            </p>
          ) : (
            <CategoryTree
              nodes={tree}
              selectedId={selectedId ?? undefined}
              onSelect={handleSelect}
              initialExpanded={initialExpanded}
            />
          )}
        </Card>

        <div className="space-y-6">
          {selectedId ? (
            <CategoryDetailPanel
              categoryId={selectedId}
              targetObjectTypeId={targetObjectTypeId}
              targetType={targetType}
              locale={i18n.language}
              tree={tree}
              onMoveRequest={() => setMoveOpen(true)}
              onDeleted={handleDeleted}
            />
          ) : (
            <Card className="p-12">
              <p className="text-center text-[13px] italic text-zinc-500">
                {t('categories.empty_select_node', {
                  defaultValue: '← Wybierz kategorię z drzewa',
                })}
              </p>
            </Card>
          )}
        </div>
      </div>

      {/* Mounted only while open so each opening starts from fresh state
        (the dialog has no reset effect by design — ADR-0021 guard). */}
      {moveOpen && selectedNode ? (
        <MoveCategoryDialog
          open={moveOpen}
          onOpenChange={setMoveOpen}
          category={selectedNode}
          tree={tree}
          onMoved={handleMoved}
        />
      ) : null}
    </div>
  );
}

function CategoryDetailPanel({
  categoryId,
  targetObjectTypeId,
  targetType,
  locale,
  tree,
  onMoveRequest,
  onDeleted,
}: {
  categoryId: string;
  targetObjectTypeId: string;
  targetType: string;
  locale: string;
  tree: CategoryTreeNode[];
  onMoveRequest: () => void;
  onDeleted: () => void;
}) {
  const { t } = useTranslation();
  const [declareOpen, setDeclareOpen] = useState(false);

  const node = useMemo(() => findNode(tree, categoryId), [tree, categoryId]);

  // ADR-015 — resolve declared/effective groups by ObjectType id so custom-OT
  // category trees work (kind='custom' has no single built-in OT).
  const { data: declared, refetch: refetchDeclared } = useQuery<DeclaredGroupsResponse>({
    queryKey: ['categories', categoryId, 'attribute_groups', targetObjectTypeId],
    queryFn: () =>
      jsonFetch<DeclaredGroupsResponse>(
        `/api/categories/${categoryId}/attribute_groups?targetObjectTypeId=${targetObjectTypeId}`,
        { accept: 'application/json' },
      ),
    enabled: targetObjectTypeId !== '',
    staleTime: 10_000,
  });

  const { data: effective, refetch: refetchEffective } = useQuery<EffectiveResponse>({
    queryKey: ['categories', categoryId, 'effective-groups', targetObjectTypeId],
    queryFn: () =>
      jsonFetch<EffectiveResponse>(
        `/api/categories/${categoryId}/effective-groups?objectTypeId=${targetObjectTypeId}`,
        { accept: 'application/json' },
      ),
    enabled: targetObjectTypeId !== '',
    staleTime: 10_000,
  });

  const { data: usage } = useQuery<UsageResponse>({
    queryKey: ['categories', categoryId, 'usage'],
    queryFn: () =>
      jsonFetch<UsageResponse>(`/api/categories/${categoryId}/usage`, {
        accept: 'application/json',
      }),
    staleTime: 30_000,
  });

  const declaredGroupIds = useMemo(
    () => new Set((declared?.declaredGroups ?? []).map((d) => d.groupId)),
    [declared],
  );

  // Build inherited map (groupId → ancestor name) from effective minus declared minus object_type.
  const inheritedFromMap = useMemo(() => {
    const map = new Map<string, string>();
    for (const g of effective?.effectiveGroups ?? []) {
      if (g.source === 'inherited_from' && g.source_category) {
        map.set(g.id, labelString(g.source_category.path ?? g.source_category.code, locale));
      }
    }
    return map;
  }, [effective, locale]);

  const handleDetach = async (groupId: string, targetTypeId: string) => {
    if (!confirm(t('categories.detach_confirm', { defaultValue: 'Usunąć deklarację grupy?' }))) {
      return;
    }
    try {
      await jsonFetch(`/api/categories/${categoryId}/attribute_groups/${groupId}/${targetTypeId}`, {
        method: 'DELETE',
      });
      await refetchDeclared();
      await refetchEffective();
    } catch (err) {
      console.error('detach failed', err);
    }
  };

  return (
    <>
      <Card className="p-6">
        <div className="flex items-start justify-between">
          <div>
            <div className="text-[11px] font-medium uppercase tracking-wider text-zinc-500">
              {t('categories.detail.section_label', { defaultValue: 'Category' })}
            </div>
            <div className="display mt-1 flex items-center gap-2 text-[22px] font-semibold tracking-tight">
              <span>{node?.icon ?? '📂'}</span>
              <span>{node?.label ?? '—'}</span>
            </div>
            <div className="mt-1 flex items-center gap-2">
              <span className="font-mono text-[12px] text-zinc-500">{node?.path ?? '—'}</span>
              <Button
                size="sm"
                variant="ghost"
                className="h-6 gap-1 rounded-lg px-2 text-[11.5px] text-zinc-500 hover:text-zinc-900"
                onClick={onMoveRequest}
              >
                <FolderTree className="size-3" />
                {t('categories.move_action', { defaultValue: 'Przenieś' })}
              </Button>
            </div>
          </div>
          {usage ? (
            <div className="text-right">
              <div className="display text-[22px] font-semibold tabular-nums">
                {usage.instanceCount}
              </div>
              <div className="text-[11.5px] text-zinc-500">
                {t('categories.instance_count_label', { defaultValue: 'instancji' })}
              </div>
            </div>
          ) : null}
        </div>

        <div className="mt-5 space-y-4 border-t border-zinc-100 pt-5">
          <section>
            <div className="mb-2 text-[11.5px] font-medium text-zinc-500">
              {t('categories.detail.declared_directly', { defaultValue: 'Declared directly' })}
            </div>
            {(declared?.declaredGroups.length ?? 0) === 0 ? (
              <div className="text-[12px] italic text-zinc-500">
                {t('categories.detail.empty_declared', {
                  defaultValue: '— brak własnych grup, dziedziczy wszystko',
                })}
              </div>
            ) : (
              <div className="space-y-1.5">
                {(declared?.declaredGroups ?? []).map((d) => (
                  <div
                    key={d.groupId}
                    className="flex items-center gap-2 rounded-xl border border-zinc-200 bg-white px-3 py-2"
                  >
                    <span
                      className="grid size-6 place-items-center rounded-md"
                      style={{
                        background: d.group.color ? `${d.group.color}1f` : '#f4f4f5',
                        color: d.group.color ?? '#71717a',
                      }}
                    >
                      {d.group.icon ?? '📦'}
                    </span>
                    <span className="text-[13px] font-medium">
                      {labelString(d.group.label, locale) || d.group.code}
                    </span>
                    <button
                      type="button"
                      className="ml-auto text-zinc-300 hover:text-rose-600"
                      onClick={() => handleDetach(d.groupId, declared?.targetObjectType.id ?? '')}
                      aria-label={t('categories.actions.detach', {
                        defaultValue: 'Usuń deklarację',
                      })}
                    >
                      <Trash2 className="size-4" />
                    </button>
                  </div>
                ))}
              </div>
            )}
            <button
              type="button"
              onClick={() => setDeclareOpen(true)}
              className="mt-2 flex w-full items-center justify-center gap-2 rounded-xl border border-dashed border-zinc-200 py-2 text-[12.5px] font-medium text-zinc-500 transition hover:border-orange-300 hover:bg-orange-50/40 hover:text-orange-700"
            >
              + {t('categories.detail.declare_group', { defaultValue: 'Declare group' })}
            </button>
          </section>

          {inheritedFromMap.size > 0 ? (
            <section>
              <div className="mb-2 flex items-center gap-1.5 text-[11.5px] font-medium text-zinc-500">
                {t('categories.detail.inherited_from_parents', {
                  defaultValue: 'Inherited from parents',
                })}
                <span className="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[10px] text-zinc-500">
                  {t('categories.detail.read_only_badge', { defaultValue: 'read-only' })}
                </span>
              </div>
              <div className="space-y-1.5">
                {(effective?.effectiveGroups ?? [])
                  .filter((g) => g.source === 'inherited_from')
                  .map((g) => (
                    <div
                      key={g.id}
                      className="flex items-center gap-2 rounded-xl border border-zinc-100 bg-zinc-50 px-3 py-2"
                    >
                      <span
                        className="grid size-6 place-items-center rounded-md opacity-80"
                        style={{
                          background: g.color ? `${g.color}1f` : '#f4f4f5',
                          color: g.color ?? '#71717a',
                        }}
                      >
                        {g.icon ?? '📦'}
                      </span>
                      <span className="text-[13px] font-medium text-zinc-700">
                        {labelString(g.label, locale) || g.code}
                      </span>
                      <span className="ml-auto rounded border border-zinc-200 bg-white px-2 py-0.5 font-mono text-[10.5px] text-zinc-500">
                        ↪ {g.source_category?.code ?? '?'}
                      </span>
                    </div>
                  ))}
              </div>
            </section>
          ) : null}
        </div>
      </Card>

      <Card className="border border-orange-200 bg-orange-50/30 p-6">
        <div className="mb-3 flex items-center justify-between">
          <div className="flex items-center gap-2">
            <span className="text-[11px] font-semibold uppercase tracking-wider text-orange-700">
              {t('categories.preview.title', { defaultValue: 'Effective preview' })}
            </span>
            <span className="rounded bg-orange-100 px-1.5 py-0.5 text-[10.5px] font-medium text-orange-700">
              {t('categories.preview.killer_feature_badge', { defaultValue: 'killer feature' })}
            </span>
          </div>
          <span className="inline-flex items-center gap-1.5">
            {targetType === 'product' ? (
              <Link
                to={`/products/new?categories=${categoryId}&primary=${categoryId}`}
                className="inline-flex items-center gap-1.5 text-[11.5px] font-medium text-orange-700 hover:text-orange-800 hover:underline"
                title={t('categories.preview.create_test_object_active_tooltip', {
                  defaultValue: 'Utwórz produkt pre-przypisany do tej kategorii',
                })}
              >
                +{' '}
                {t('categories.preview.create_test_object', {
                  defaultValue: 'Create test object',
                })}
              </Link>
            ) : (
              <>
                <button
                  type="button"
                  disabled
                  aria-disabled
                  className="inline-flex cursor-not-allowed items-center gap-1.5 text-[11.5px] font-medium text-orange-500"
                  title={t('categories.preview.create_test_object_mock_tooltip', {
                    defaultValue: 'Wymaga wizard tworzenia obiektu (Faza 1)',
                  })}
                >
                  +{' '}
                  {t('categories.preview.create_test_object', {
                    defaultValue: 'Create test object',
                  })}
                </button>
                <MockBadge
                  tooltip={t('categories.preview.create_test_object_mock_tooltip', {
                    defaultValue: 'Wymaga wizard tworzenia obiektu (Faza 1)',
                  })}
                />
              </>
            )}
          </span>
        </div>
        <p className="mb-3 text-[12.5px] text-zinc-700">
          {t('categories.preview.intro', {
            defaultValue: 'Obiekt typu {{kind}} w kategorii „{{name}}" zobaczy w formularzu:',
            kind: targetType,
            name: node?.label ?? '—',
          })}
        </p>
        <div className="overflow-hidden rounded-2xl border border-orange-200 bg-white">
          {(effective?.effectiveGroups ?? []).length === 0 ? (
            <p className="px-4 py-6 text-center text-[12.5px] text-muted-foreground">
              {t('categories.preview.empty', { defaultValue: 'Brak grup do wyświetlenia.' })}
            </p>
          ) : (
            (effective?.effectiveGroups ?? []).map((g) => (
              <FormPreviewRow key={g.id} group={g} locale={locale} />
            ))
          )}
        </div>
        <p className="mt-3 text-[11.5px] text-zinc-500">
          {t('categories.preview.competitor_note', {
            defaultValue:
              'Tego nie ma w Pimcore ani Akeneo — operator zobaczy dokładnie to co użytkownik w formularzu „Stwórz {{kind}} → {{name}}".',
            kind: targetType,
            name: node?.label ?? '—',
          })}
        </p>
      </Card>

      <CategoryProductsCard categoryId={categoryId} />

      <CategoryDangerZone
        categoryId={categoryId}
        categoryLabel={node?.label ?? node?.code ?? ''}
        onDeleted={onDeleted}
      />

      <p className="text-xs text-muted-foreground">
        <Link to={`/modeling/categories/${categoryId}`} className="underline">
          {t('categories.detail.open_show', { defaultValue: 'Otwórz pełny widok kategorii →' })}
        </Link>
      </p>

      <DeclareAttributeGroupDialog
        open={declareOpen}
        onOpenChange={setDeclareOpen}
        categoryId={categoryId}
        targetObjectTypeId={targetObjectTypeId}
        targetObjectTypeKind={targetType}
        declaredGroupIds={declaredGroupIds}
        inheritedFromMap={inheritedFromMap}
        onDeclared={() => {
          void refetchDeclared();
          void refetchEffective();
        }}
      />
    </>
  );
}

function FormPreviewRow({ group, locale }: { group: EffectiveGroup; locale: string }) {
  const { t } = useTranslation();
  const sourceLabel =
    group.source === 'object_type'
      ? t('categories.preview.system_group_object_type', { defaultValue: 'z ObjectType' })
      : group.source === 'declared_here'
        ? t('categories.preview.here_badge', { defaultValue: 'tutaj' })
        : `↪ ${group.source_category?.code ?? '?'}`;

  const sourceTone =
    group.source === 'declared_here'
      ? 'bg-orange-100 text-orange-700'
      : group.source === 'inherited_from'
        ? 'bg-blue-100 text-blue-700'
        : 'bg-zinc-100 text-zinc-700';

  const attrs = group.attributes
    .slice(0, 3)
    .map((a) => a.code)
    .join(', ');
  const overflow = group.attributes.length > 3 ? '…' : '';

  return (
    <div className="flex items-center gap-3 border-b border-orange-50 px-4 py-2.5 last:border-b-0">
      <span
        className="grid size-7 place-items-center rounded-md text-[14px]"
        style={{
          background: group.color ? `${group.color}1f` : '#f4f4f5',
          color: group.color ?? '#71717a',
        }}
      >
        {group.icon ?? '📦'}
      </span>
      <span className="min-w-0 flex-1 text-[13px] font-medium">
        {labelString(group.label, locale) || group.code}
      </span>
      <span className="font-mono text-[11px] text-zinc-500 truncate">
        {attrs}
        {overflow}
      </span>
      <span className={cn('rounded px-2 py-0.5 text-[10.5px] font-medium', sourceTone)}>
        {sourceLabel}
      </span>
    </div>
  );
}

function labelString(
  value: Record<string, string> | string | null | undefined,
  locale: string,
): string {
  if (value === null || value === undefined) return '';
  if (typeof value === 'string') return value;
  return value[locale] ?? value.pl ?? value.en ?? Object.values(value)[0] ?? '';
}

function findNode(nodes: CategoryTreeNode[], id: string): CategoryTreeNode | null {
  for (const n of nodes) {
    if (n.id === id) return n;
    const inChildren = findNode(n.children, id);
    if (inChildren) return inChildren;
  }
  return null;
}

function collectAllAncestorIds(nodes: CategoryTreeNode[], selectedId: string | null): Set<string> {
  if (!selectedId) {
    return new Set(nodes.map((n) => n.id));
  }
  // Always expand the path to the selected node so deep-linking works.
  const path: string[] = [];
  const walk = (list: CategoryTreeNode[]): boolean => {
    for (const node of list) {
      if (node.id === selectedId) return true;
      path.push(node.id);
      if (walk(node.children)) return true;
      path.pop();
    }
    return false;
  };
  walk(nodes);
  return new Set(path);
}

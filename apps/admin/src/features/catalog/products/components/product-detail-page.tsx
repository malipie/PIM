import { Suspense, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useSearchParams } from 'react-router';

import { DetailLoadingState } from '@/components/catalog/detail-loading-state';
import { DetailNotFoundState } from '@/components/catalog/detail-not-found-state';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { useContentRecipes } from '@/features/agent/hooks/use-content-recipes';
import { HttpError } from '@/lib/http';
import { useIdentity } from '@/lib/identity';
import { fetchWorkflowState } from '@/lib/workflow/api';
import { computeEditLock } from '@/lib/workflow/edit-lock';
import { AskAiProposal } from './ask-ai-proposal';
import { ProductDetailContent } from './product-detail-content';
import { ProductDetailHeader } from './product-detail-header';
import type { TabKey } from './product-detail-helpers';
import { TabLoadingFallback } from './product-detail-other-tabs';
import { ProductDetailSidebar } from './product-detail-sidebar';
import { scopedCompleteness } from './scope';
import type { ProductChannel, ProductDetailMode, ProductLocale } from './types';
import { useAskAi } from './use-ask-ai';
import { useProductDetailData } from './use-product-detail-data';
import { useProductDetailForm } from './use-product-detail-form';
import { WorkflowLockBanner } from './workflow-lock-banner';

export interface ProductDetailPageProps {
  mode: ProductDetailMode;
  productId?: string;
  /**
   * #1348/#1351 unification — routing/labeling context for non-product
   * kinds. The component derives every capability flag (variants,
   * multimedia, categories) from the object's own ObjectType, so the
   * wrappers only tell it where "back" is and how to label the root
   * breadcrumb. Defaults preserve the legacy /products behaviour.
   */
  objectTypeLabel?: string;
  backHref?: string;
  detailPathFor?: (id: string) => string;
  /**
   * #1415 — create-mode ObjectType. When omitted the page creates a
   * product (legacy /products/new); /objects/:slug/new passes the
   * resolved custom ObjectType id so ONE create form serves every kind.
   */
  createObjectTypeId?: string;
  /**
   * Kind guard for sugar routes: `/api/objects/{id}` is poly-kind, so
   * /products/:id must reject a category/asset/custom id with the same
   * 404 state the legacy sugar endpoint produced.
   */
  requireKind?: string;
}

/**
 * VIEW-07 (#420) — single-component object detail / create page.
 *
 * Renders the full screen documented in
 * `Project Plan/UI/Wdrozenie_grafiki/ticket-VIEW-07-...`. In edit mode
 * loads the object + its effective attribute groups; in create mode
 * starts with an empty draft and POSTs on save.
 *
 * #1348/#1351 — this is THE detail page for every ObjectType kind:
 * /products/:id and /objects/:slug/:id both render it (the former
 * UniversalDetailPage is retired). Product-only affordances (preview,
 * duplicate, sync, history) gate on `kind === 'product'`; everything
 * else is capability-driven via list-schema flags.
 */
export function ProductDetailPage({
  mode,
  productId,
  objectTypeLabel,
  backHref = '/products',
  detailPathFor = (objectId: string) => `/products/${objectId}`,
  requireKind,
  createObjectTypeId,
}: ProductDetailPageProps) {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const lang = i18n.language === 'pl' ? 'pl' : 'en';
  const isEditMode = mode === 'edit';
  const id = productId ?? '';

  // PCAT-06b (#480): when entering create mode from a category panel
  // ("+ Create test object"), the URL carries `?categories=<id>&primary=<id>`.
  // After the product is POSTed we run a single PUT against
  // /api/products/{newId}/categories so the fresh row lands with the
  // assignment already in place.
  const prePopulatedCategoryIds = useMemo<string[]>(() => {
    if (mode !== 'create') return [];
    const raw = searchParams.get('categories');
    if (raw === null || raw === '') return [];
    return raw
      .split(',')
      .map((s) => s.trim())
      .filter((s) => s !== '');
  }, [mode, searchParams]);
  const prePopulatedPrimaryId = useMemo<string | null>(() => {
    if (mode !== 'create') return null;
    return searchParams.get('primary');
  }, [mode, searchParams]);

  const [locale, setLocale] = useState<ProductLocale>('pl');
  const [channel, setChannel] = useState<ProductChannel | null>(null);

  const [activeTab, setActiveTab] = useState<TabKey>('attributes');
  // #1351 — the detail page opens directly in edit mode; there is no
  // read-only state anymore. "Zapisz zmiany" is always visible and a
  // "Zapisz i wróć do listy" action saves + returns to the list.
  const isEditing = true;

  // WFL-P3-03 (#2425) — PRD §3.8 edit lock: banner + save gating derive
  // from the workflow place and the caller's permissions. `null` place
  // (state still loading / no workflow.view) means no lock UI.
  const { identity } = useIdentity();
  const [workflowPlace, setWorkflowPlace] = useState<string | null>(null);
  useEffect(() => {
    if (mode !== 'edit') return;
    let cancelled = false;
    fetchWorkflowState(id)
      .then((state) => {
        if (!cancelled) setWorkflowPlace(state.current_place);
      })
      .catch(() => {
        if (!cancelled) setWorkflowPlace(null);
      });
    return () => {
      cancelled = true;
    };
  }, [mode, id]);
  const editLock =
    workflowPlace !== null && identity !== null
      ? computeEditLock(workflowPlace, identity.permissions)
      : null;
  const [showDeleteConfirm, setShowDeleteConfirm] = useState<boolean>(false);
  // #2318 — "Anuluj" returns to the list without saving; if there are unsaved
  // edits it asks for confirmation first.
  const [showCancelConfirm, setShowCancelConfirm] = useState<boolean>(false);

  // #891 create-mode category selection. POST `/api/products` carries
  // this so the product + assignments land atomically — the previous
  // PCAT-06b follow-up PUT is removed.
  const [createCategoryIds, setCreateCategoryIds] = useState<string[]>(
    () => prePopulatedCategoryIds,
  );
  const [createPrimaryId, setCreatePrimaryId] = useState<string | null>(
    () => prePopulatedPrimaryId,
  );

  // AUD-057 (#1608) — every server read + the values derived purely from
  // it live in this hook (see use-product-detail-data.ts). The page keeps
  // the UI state (locale/channel/tabs/dirty fields) and the write path.
  const {
    productQuery,
    product,
    objectTypeId,
    kind,
    hasMultimediaCapability,
    hasVariantsCapability,
    isCategorizable,
    attrs,
    groupsQuery,
    groups,
    locales,
    channels,
    scopeStatus,
    tabModeGroups,
    stackedGroups,
    hasReverseRelations,
    createCategoriesSummaries,
  } = useProductDetailData({
    mode,
    id,
    isEditMode,
    createObjectTypeId,
    locale,
    channel,
    createCategoryIds,
    createPrimaryId,
  });

  // AUD-057 (#1608) — dirty buffer + required-field validation + the
  // create/edit save, cancel and delete mutations live in this hook.
  const {
    dirtyFields,
    requiredErrors,
    validationErrors,
    expandedGroups,
    isSaving,
    isDeleting,
    setFieldValue,
    fieldValue,
    toggleGroup,
    setExpandedAll,
    resetDirty,
    handleSave,
    cancelEdit,
    handleDelete,
  } = useProductDetailForm({
    mode,
    id,
    isEditMode,
    kind,
    objectTypeId,
    isCategorizable,
    locale,
    channel,
    groups,
    attrs,
    product,
    productQuery,
    createCategoryIds,
    createPrimaryId,
    backHref,
    detailPathFor,
  });

  // AICG-P5-01/02 (#2339/#2340) — the "Ask AI" lifecycle: a content run
  // per asked text field, its inline proposal, and the recipe names for
  // the badge tooltip. Edit mode only (a fresh create has no facts yet).
  const askAi = useAskAi({
    productId: isEditMode ? id : undefined,
    locale,
    channel,
    onAccepted: () => productQuery.refetch(),
  });
  const { nameById: recipeNameById } = useContentRecipes(isEditMode);
  const askAiProposalFor = (attributeCode: string) =>
    askAi.state !== null && askAi.state.attributeCode === attributeCode ? (
      <AskAiProposal
        state={askAi.state}
        onAccept={() => void askAi.accept()}
        onReject={() => void askAi.reject()}
        onDismiss={askAi.dismiss}
      />
    ) : null;

  // #1149 — default the locale selection to the tenant default once the
  // enabled-locale list arrives (shipped by effective-attribute-groups),
  // then respect manual switches.
  const [didInitLocale, setDidInitLocale] = useState(false);
  useEffect(() => {
    if (didInitLocale || locales.length === 0) return;
    const def = locales.find((l) => l.is_default) ?? locales[0];
    if (def === undefined) return;
    setLocale(def.code);
    setDidInitLocale(true);
  }, [locales, didInitLocale]);

  // #1150 / #1155 — switching locale or channel discards unsaved edits so an
  // edit is never written to the wrong scope; the operator saves first.
  // biome-ignore lint/correctness/useExhaustiveDependencies: intentional — reset on scope change
  useEffect(() => {
    resetDirty();
  }, [locale, channel]);

  // Issue #1092 — forward relation attributes render inline via their
  // natural group placement; the synthetic `relations` tab survives only
  // for the reverse-only case (object pointed at from elsewhere but with
  // no forward relation attribute of its own), which still needs the
  // bespoke `RelationsTab` (effective-attribute-groups has no concept of
  // "incoming links").
  const shouldSurfaceRelationsTab = hasReverseRelations;

  const visibleTabs: readonly TabKey[] = useMemo(() => {
    if (mode === 'create') {
      // #1415 — create renders the same group-driven tab set custom
      // kinds had on UniversalCreatePage (#1096); special tabs
      // (categories sidebar covers assignment, multimedia/variants
      // need a persisted id) stay detail-only.
      const createAttributesTab: TabKey[] =
        stackedGroups.length > 0 || tabModeGroups.length === 0 ? ['attributes' as const] : [];
      return [...createAttributesTab, ...tabModeGroups.map((g) => g.code)];
    }
    const fromGroups = tabModeGroups.map((g) => g.code);
    // MODR-06 (#928) — inject a synthetic `relations` tab when the
    // object has reverse links but no forward `relations` AttributeGroup.
    const ensureRelations =
      shouldSurfaceRelationsTab && !fromGroups.includes('relations') ? ['relations'] : [];
    const multimedia: TabKey[] = hasMultimediaCapability ? ['multimedia' as const] : [];
    // #1348 — the "Atrybuty" tab is the bucket for stacked/ungrouped
    // attributes (synthetic default group + display_mode='stacked'
    // groups). When the ObjectType has no such attributes the tab is
    // empty (only the ad-hoc adder), so drop it entirely — tab-mode
    // groups keep their own dedicated tabs.
    const attributesTab: TabKey[] = stackedGroups.length > 0 ? ['attributes' as const] : [];
    // #1348/#1351 unification — categories/variants follow the
    // ObjectType capability flags.
    const categories: TabKey[] = isCategorizable ? ['categories' as const] : [];
    const variants: TabKey[] = hasVariantsCapability ? ['variants' as const] : [];
    return [
      ...attributesTab,
      ...fromGroups,
      ...ensureRelations,
      ...multimedia,
      ...categories,
      ...variants,
    ];
  }, [
    mode,
    tabModeGroups,
    stackedGroups,
    shouldSurfaceRelationsTab,
    hasMultimediaCapability,
    isCategorizable,
    hasVariantsCapability,
  ]);

  // Keep activeTab valid as group set changes (e.g. groups data arrives
  // after first render or a tab→stacked switch in the wizard). Falls back
  // to the first visible tab — `attributes` may now be absent (#1348).
  // Skipped while the group set is still pending: the interim tab list
  // (categories/history/variants only) would otherwise steal the default
  // `attributes` selection before the data arrives.
  useEffect(() => {
    if (groupsQuery.isPending) return;
    const fallback = visibleTabs[0];
    if (fallback !== undefined && !visibleTabs.includes(activeTab)) {
      setActiveTab(fallback);
    }
  }, [activeTab, visibleTabs, groupsQuery.isPending]);

  // Default expand all groups once they first arrive (mockup shows every
  // section open). Refetches after the first success keep the operator's
  // chosen expand/collapse state intact.
  const [didExpandInitial, setDidExpandInitial] = useState(false);
  useEffect(() => {
    if (didExpandInitial) return;
    if (groups.length === 0) return;
    setExpandedAll(groups.map((g) => g.id));
    setDidExpandInitial(true);
  }, [didExpandInitial, groups, setExpandedAll]);

  // Issue #1043 — split the original mixed guard into loading + not-found.
  // The previous condition treated `product === undefined` (post-404) as
  // still-loading and pinned the page on an infinite "Ładowanie…". Refine's
  // `useOne` returns `isLoading=false`, `isError=true`, `data=undefined`
  // after a 404, so we now check `isError` explicitly.
  if (isEditMode && productQuery.isLoading) {
    return <DetailLoadingState />;
  }
  // Kind guard — /api/objects/{id} loads any kind, but a sugar route
  // like /products/:id must keep 404-ing for foreign kinds (the legacy
  // /api/products/{id} endpoint enforced this server-side).
  const kindMismatch =
    isEditMode && requireKind !== undefined && product != null && product.kind !== requireKind;
  // #2881 — a refused read is not a missing row. Every failure used to
  // render "nie istnieje lub został usunięty", so a role without the
  // permission for this ObjectType was told its data had been deleted and
  // went looking for the wrong problem. Reported from production on a
  // custom module opened by a viewer.
  const isForbidden = productQuery.error instanceof HttpError && productQuery.error.status === 403;
  if (
    isEditMode &&
    (productQuery.isError || product === null || product === undefined || kindMismatch)
  ) {
    return (
      <DetailNotFoundState
        id={id}
        backHref={backHref}
        title={
          isForbidden
            ? t('products.detail.errors.forbidden_title', {
                defaultValue: 'Brak uprawnień do tego obiektu',
              })
            : t('products.detail.errors.not_found_title', {
                defaultValue: 'Produkt nie znaleziony',
              })
        }
        description={
          isForbidden
            ? t('products.detail.errors.forbidden_description', {
                defaultValue:
                  'Twoja rola nie ma uprawnienia do odczytu tego typu obiektu. Poproś administratora o dostęp.',
              })
            : t('products.detail.errors.not_found_description', {
                defaultValue: 'Produkt o ID "{{id}}" nie istnieje lub został usunięty.',
                id,
              })
        }
        backLabel={t('products.detail.errors.back_to_list', {
          defaultValue: 'Wróć do listy produktów',
        })}
      />
    );
  }

  const skuValue =
    mode === 'create'
      ? typeof dirtyFields.sku === 'string'
        ? dirtyFields.sku
        : ''
      : (product?.code ?? '');
  // The header name <Input> is controlled by `nameValue`, so it must reflect
  // unsaved edits — read `dirtyFields.name` first (the user's keystrokes),
  // then fall back to the loaded value. Without the dirty branch in edit mode
  // the controlled input always re-rendered the original value and the field
  // appeared locked.
  const nameValue =
    typeof dirtyFields.name === 'string'
      ? dirtyFields.name
      : mode === 'create'
        ? ''
        : typeof attrs.name === 'string'
          ? attrs.name
          : (product?.code ?? '');
  const brandValue =
    mode === 'create'
      ? typeof dirtyFields.brand === 'string'
        ? dirtyFields.brand
        : ''
      : typeof attrs.brand === 'string'
        ? attrs.brand
        : '';
  const objectTypeName = product?.objectType?.name?.[lang] ?? null;
  const completenessPct = product?.completenessPct ?? 0;
  // #1152 — the ring reflects the active locale/channel scope (Akeneo
  // "readiness per target"); falls back to the global pct.
  const { pct: scopedCompletenessPct, scope: completenessScope } = scopedCompleteness(
    product?.completeness,
    locale,
    channel,
    completenessPct,
  );
  const breadcrumbCategory =
    typeof attrs.category === 'string' && attrs.category !== ''
      ? attrs.category
      : (objectTypeName ?? '—');

  return (
    <div className="bg-zinc-50 -mx-6 -mt-6 min-h-[calc(100vh-3rem)]">
      <ProductDetailHeader
        mode={mode}
        kind={kind}
        id={id}
        backHref={backHref}
        objectTypeLabel={objectTypeLabel}
        breadcrumbCategory={breadcrumbCategory}
        skuValue={skuValue}
        nameValue={nameValue}
        brandValue={brandValue}
        objectTypeName={objectTypeName}
        product={product}
        isEditing={isEditing}
        isSaving={isSaving}
        objectTypeId={objectTypeId}
        scopedCompletenessPct={scopedCompletenessPct}
        completenessScope={completenessScope}
        lang={lang}
        visibleTabs={visibleTabs}
        activeTab={activeTab}
        groups={groups}
        stackedGroups={stackedGroups}
        locale={locale}
        channel={channel}
        locales={locales}
        channels={channels}
        onSave={(returnToList) => {
          // WFL-P3-03 — locked states never reach the backend 403; the
          // banner explains, this guard absorbs a stray click.
          if (editLock?.locked === true) {
            return;
          }
          void handleSave(returnToList);
        }}
        onCancel={() => {
          // #2318 — guard unsaved edits before leaving to the list.
          if (Object.keys(dirtyFields).length > 0) {
            setShowCancelConfirm(true);
          } else {
            cancelEdit();
          }
        }}
        onRequestDelete={() => setShowDeleteConfirm(true)}
        onFieldChange={setFieldValue}
        onSelectTab={setActiveTab}
        onLocaleChange={setLocale}
        onChannelChange={setChannel}
      />

      {/* Body */}
      {editLock !== null && editLock.reason !== null ? (
        <div className="px-7 pt-4">
          <WorkflowLockBanner objectId={id} lock={editLock} />
        </div>
      ) : null}
      <div className="grid grid-cols-1 gap-5 px-7 py-6 lg:grid-cols-[minmax(0,1fr)_320px]">
        <div className="min-w-0 space-y-3">
          {/* AUD-071 (#1614) — lazy tab chunks (multimedia/categories/variants/
              relations) resolve behind this boundary; the attributes view and
              tab-mode groups render synchronously and never suspend. */}
          <Suspense fallback={<TabLoadingFallback />}>
            <ProductDetailContent
              mode={mode}
              isEditMode={isEditMode}
              id={id}
              kind={kind}
              objectTypeId={objectTypeId}
              activeTab={activeTab}
              lang={lang}
              locale={locale}
              channel={channel}
              isEditing={isEditing}
              product={product}
              stackedGroups={stackedGroups}
              tabModeGroups={tabModeGroups}
              scopeStatus={scopeStatus}
              expandedGroups={expandedGroups}
              requiredErrors={requiredErrors}
              validationErrors={validationErrors}
              fieldValue={fieldValue}
              onFieldChange={setFieldValue}
              onToggleGroup={toggleGroup}
              onAskAI={isEditMode ? askAi.start : undefined}
              askAiProposalFor={askAiProposalFor}
              recipeNameById={recipeNameById}
              fieldVersion={askAi.fieldVersion}
            />
          </Suspense>

          {mode === 'create' && isCategorizable && createCategoryIds.length === 0 ? (
            <p className="px-1 text-[12px] text-muted-foreground">
              {t('products.detail.create.hint', {
                defaultValue:
                  'Wybierz kategorię w panelu po prawej aby zobaczyć dziedziczone grupy atrybutów.',
              })}
            </p>
          ) : null}
        </div>

        <ProductDetailSidebar
          mode={mode}
          id={id}
          kind={kind}
          objectTypeId={objectTypeId}
          objectTypeName={objectTypeName}
          isCategorizable={isCategorizable}
          hasVariantsCapability={hasVariantsCapability}
          groups={groups}
          createCategoryIds={createCategoryIds}
          createPrimaryId={createPrimaryId}
          createCategoriesSummaries={createCategoriesSummaries}
          onCreateCategoriesChange={(ids, primary) => {
            setCreateCategoryIds(ids);
            setCreatePrimaryId(primary);
          }}
          onSelectVariant={(variantId) => navigate(detailPathFor(variantId))}
          onCreateVariant={() => setActiveTab('variants')}
        />
      </div>

      <Dialog
        open={showDeleteConfirm}
        onOpenChange={(next) => {
          if (!next && !isDeleting) setShowDeleteConfirm(false);
        }}
      >
        <DialogContent>
          <div className="space-y-2">
            <DialogTitle>
              {t('products.detail.delete.confirm_title', { defaultValue: 'Usunąć produkt?' })}
            </DialogTitle>
            <DialogDescription>
              {t('products.detail.delete.confirm_body', {
                defaultValue:
                  'Czy na pewno chcesz usunąć produkt {{code}}? Tej operacji nie da się cofnąć.',
                code: product?.code ?? '',
              })}
            </DialogDescription>
          </div>
          <div className="mt-4 flex justify-end gap-2">
            <Button
              variant="ghost"
              onClick={() => setShowDeleteConfirm(false)}
              disabled={isDeleting}
            >
              {t('app.cancel', { defaultValue: 'Anuluj' })}
            </Button>
            <Button
              variant="destructive"
              onClick={() => void handleDelete(() => setShowDeleteConfirm(false))}
              disabled={isDeleting}
            >
              {isDeleting
                ? t('products.detail.delete.deleting', { defaultValue: 'Usuwanie…' })
                : t('products.detail.delete.confirm_submit', { defaultValue: 'Usuń produkt' })}
            </Button>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={showCancelConfirm} onOpenChange={setShowCancelConfirm}>
        <DialogContent>
          <div className="space-y-2">
            <DialogTitle>
              {t('products.detail.cancel.confirm_title', { defaultValue: 'Anulować zmiany?' })}
            </DialogTitle>
            <DialogDescription>
              {t('products.detail.cancel.confirm_body', {
                defaultValue:
                  'Masz niezapisane zmiany. Czy na pewno chcesz anulować i wrócić do listy bez zapisu?',
              })}
            </DialogDescription>
          </div>
          <div className="mt-4 flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setShowCancelConfirm(false)}>
              {t('products.detail.cancel.keep_editing', { defaultValue: 'Wróć do edycji' })}
            </Button>
            <Button
              variant="destructive"
              onClick={() => {
                setShowCancelConfirm(false);
                cancelEdit();
              }}
            >
              {t('products.detail.cancel.discard', { defaultValue: 'Anuluj bez zapisu' })}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  );
}

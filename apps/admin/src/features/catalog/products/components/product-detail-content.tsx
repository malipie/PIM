import { lazy } from 'react';

import { isLegacyOptionalSystemGroupCode } from '@/lib/legacy-attribute-groups';
import { isVisibleWhen } from '@/lib/visible-when';
import { AttrGroupCard } from './attr-group-card';
import { AttrRow } from './attr-row';
import {
  countFilled,
  GROUP_ICONS,
  isSpecialTab,
  resolveProvenance,
  resolveProvenanceRunId,
  type TabKey,
} from './product-detail-helpers';
import { OtherTabs } from './product-detail-other-tabs';
import type {
  CatalogObjectDto,
  GroupMeta,
  ProductChannel,
  ProductDetailMode,
  ProductLocale,
  ScopeStatus,
} from './types';

// AUD-071 (#1614) — RelationsTab renders ONLY when the operator opens it
// (forward-relation tab-group or the reverse-only synthetic tab), so it is
// code-split behind React.lazy + the <Suspense> boundary the page wraps
// around <ProductDetailContent> (AUD-057).
const RelationsTab = lazy(() =>
  import('./relations-tab').then((m) => ({ default: m.RelationsTab })),
);

export interface ProductDetailContentProps {
  mode: ProductDetailMode;
  isEditMode: boolean;
  id: string;
  kind: string | null;
  objectTypeId: string | null;
  activeTab: TabKey;
  lang: 'pl' | 'en';
  locale: ProductLocale;
  channel: ProductChannel | null;
  isEditing: boolean;
  product: CatalogObjectDto | null | undefined;
  stackedGroups: GroupMeta[];
  tabModeGroups: GroupMeta[];
  scopeStatus: ScopeStatus;
  expandedGroups: Set<string>;
  requiredErrors: Set<string>;
  fieldValue: (code: string) => unknown;
  onFieldChange: (code: string, value: unknown) => void;
  onToggleGroup: (groupId: string) => void;
}

/**
 * AUD-057 (#1608) — the product-detail content-area tab dispatcher, lifted
 * out of product-detail-page.tsx (which inlined it as an IIFE inside the
 * Suspense boundary) to bring that monolith under the 500-line guard.
 *
 * MODR-03 (#925) — dispatch by active tab: `attributes` hosts every stacked
 * group as inline AttrGroupCard sections; a tab-mode group code renders that
 * single group; relations / multimedia / categories / history / variants
 * delegate to bespoke components.
 */
export function ProductDetailContent({
  mode,
  isEditMode,
  id,
  kind,
  objectTypeId,
  activeTab,
  lang,
  locale,
  channel,
  isEditing,
  product,
  stackedGroups,
  tabModeGroups,
  scopeStatus,
  expandedGroups,
  requiredErrors,
  fieldValue,
  onFieldChange,
  onToggleGroup,
}: ProductDetailContentProps) {
  // DP-08 (#2039) — conditional visibility: a member whose `visible_when`
  // rule fails against the CURRENT form values is not rendered. Reactive by
  // construction (fieldValue reads the live draft), and hidden values are
  // deliberately NOT cleared — flipping the driver back reveals them intact.
  const visibleAttributes = (group: GroupMeta) =>
    group.attributes.filter((attr) => isVisibleWhen(attr.visible_when, fieldValue));

  const renderStackedGroup = (group: GroupMeta) => (
    <AttrGroupCard
      key={group.id}
      id={group.id}
      title={group.label[lang] ?? group.code}
      icon={GROUP_ICONS[group.code]}
      filledCount={countFilled(group, fieldValue)}
      totalCount={group.attributes.length}
      expanded={expandedGroups.has(group.id)}
      onToggle={() => onToggleGroup(group.id)}
      isSystem={isLegacyOptionalSystemGroupCode(group.code)}
    >
      {visibleAttributes(group).map((attr) => (
        <AttrRow
          key={attr.id}
          attribute={attr}
          value={fieldValue(attr.code)}
          provenance={resolveProvenance(attr, product)}
          provenanceSource={resolveProvenanceRunId(attr, product)}
          locale={locale}
          channel={channel}
          isEditing={isEditing}
          isLocked={attr.is_system}
          onChange={(next) => onFieldChange(attr.code, next)}
          createMode={mode === 'create'}
          relationContextProductId={isEditMode ? id : undefined}
          isInherited={
            scopeStatus[attr.code]?.has_override === false &&
            scopeStatus[attr.code]?.inherited_from != null
          }
          inheritedFrom={scopeStatus[attr.code]?.inherited_from ?? null}
          requiredError={requiredErrors.has(attr.code)}
        />
      ))}
    </AttrGroupCard>
  );

  if (activeTab === 'attributes') {
    // #1357 — the non-functional "Dodaj grupę atrybutów ad-hoc" stub was
    // removed from this view per operator request.
    return <>{stackedGroups.map(renderStackedGroup)}</>;
  }

  // Tab-mode AttributeGroup → render only that group, with a bespoke
  // component for relations that retains its legacy data flow (relation
  // links endpoint). Multimedia is no longer dispatched here — UX-02 removes
  // it from the AttributeGroup model; the conditional Multimedia tab lives as
  // a hardcoded special tab driven by `ObjectType.hasMultimedia` (UX-06+).
  const tabGroup = tabModeGroups.find((g) => g.code === activeTab);
  if (tabGroup) {
    if (tabGroup.code === 'relations') {
      // Reverse-links UI needs a persisted object — edit only; forward
      // relation attrs render via RelationCreateField.
      return mode === 'edit' ? <RelationsTab productId={id} /> : renderStackedGroup(tabGroup);
    }
    return renderStackedGroup(tabGroup);
  }

  // MODR-06 (#928) — synthetic `relations` tab for the reverse-only case
  // (object has no forward AttributeGroup but is pointed at from elsewhere).
  // RelationsTab gracefully renders just the reverse panel when forward
  // groups are empty.
  if (activeTab === 'relations' && mode === 'edit') {
    return <RelationsTab productId={id} />;
  }

  if (mode === 'edit' && isSpecialTab(activeTab)) {
    return (
      <OtherTabs
        activeTab={activeTab}
        productId={id}
        objectTypeId={objectTypeId}
        kind={kind ?? 'product'}
        locale={locale}
        channel={channel}
      />
    );
  }

  return null;
}

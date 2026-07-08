import { useTranslation } from 'react-i18next';

import { CategorySelectorCard } from './category-selector-card';
import { EffectiveModelCard } from './effective-model-card';
import type { GroupMeta, ProductDetailMode } from './types';
import { VariantsListCard } from './variants-list-card';

export interface ProductDetailSidebarProps {
  mode: ProductDetailMode;
  id: string;
  kind: string | null;
  objectTypeId: string | null;
  objectTypeName: string | null;
  isCategorizable: boolean;
  hasVariantsCapability: boolean;
  groups: GroupMeta[];
  createCategoryIds: string[];
  createPrimaryId: string | null;
  createCategoriesSummaries: { categoryId: string; code: string; isPrimary: boolean }[];
  onCreateCategoriesChange: (ids: string[], primary: string | null) => void;
  onSelectVariant: (variantId: string) => void;
  onCreateVariant: () => void;
}

/**
 * AUD-057 (#1608) — the product-detail right rail (category selector + sync
 * status + variants list + effective-model card + agent suggestions),
 * lifted out of product-detail-page.tsx to bring that monolith under the
 * 500-line guard. Purely presentational.
 */
export function ProductDetailSidebar({
  mode,
  id,
  objectTypeId,
  objectTypeName,
  isCategorizable,
  hasVariantsCapability,
  groups,
  createCategoryIds,
  createPrimaryId,
  createCategoriesSummaries,
  onCreateCategoriesChange,
  onSelectVariant,
  onCreateVariant,
}: ProductDetailSidebarProps) {
  const { t } = useTranslation();

  return (
    <aside
      className="space-y-3"
      aria-label={t('products.detail.sidebar.aria', { defaultValue: 'Panel boczny produktu' })}
    >
      {isCategorizable ? (
        <CategorySelectorCard
          {...(mode === 'edit' && id !== ''
            ? { mode: 'edit', productId: id, objectTypeId }
            : {
                mode: 'create',
                selectedCategoryIds: createCategoryIds,
                primaryCategoryId: createPrimaryId,
                selectedCategories: createCategoriesSummaries,
                onChange: onCreateCategoriesChange,
                objectTypeId,
              })}
        />
      ) : null}
      {mode === 'edit' && id !== '' ? (
        <>
          {hasVariantsCapability ? (
            <VariantsListCard
              masterProductId={id}
              basePath="/api/objects"
              onSelectVariant={onSelectVariant}
              onCreateVariant={onCreateVariant}
            />
          ) : null}
          <EffectiveModelCard groups={groups} objectTypeName={objectTypeName ?? 'Product'} />
        </>
      ) : null}
    </aside>
  );
}

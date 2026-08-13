import { useTranslation } from 'react-i18next';
import { UniversalListPage } from '@/components/objects/universal-list-page';
import { useListSchema } from '@/hooks/use-list-schema';
import { HttpError } from '@/lib/http';
import { useDefaultObjectType } from './use-default-object-type';

/**
 * UP-10 (#1026) — default `/products` entry point. Renders
 * UniversalListPage parametrized for the built-in product
 * ObjectType so the product list view is now driven by the same
 * component as `/objects/:slug` (operator's "pixel-perfect"
 * requirement; ADR-009 first-class ObjectType contract).
 *
 * The legacy `ProductListPage` was retired in NUI-05 (#1424) after
 * the UP-10 dual-maintenance window; `/products/legacy` now only
 * redirects here.
 */
export function ProductsUniversalListPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language.split('-')[0] ?? 'en';
  const {
    objectTypeId,
    isLoading: isLookupLoading,
    error: lookupError,
  } = useDefaultObjectType('product');
  const schemaQuery = useListSchema(objectTypeId ?? undefined);

  if (isLookupLoading || (objectTypeId !== null && schemaQuery.isLoading)) {
    return (
      <div
        aria-busy="true"
        className="flex h-64 items-center justify-center text-sm text-muted-foreground"
      >
        {t('products.loading_schema', { defaultValue: 'Ładowanie…' })}
      </div>
    );
  }

  if (lookupError !== null || objectTypeId === null) {
    // #2839 — a 403 used to render as "run the catalog seeder", pointing
    // the diagnosis at missing data when the real cause was a missing
    // permission. That cost real time on #2838.
    const forbidden = lookupError instanceof HttpError && lookupError.status === 403;

    return (
      <div className="rounded border border-destructive bg-destructive/5 p-6 text-sm text-destructive">
        {forbidden
          ? t('products.errors.forbidden', {
              defaultValue: 'Nie masz uprawnień do tej sekcji. Skontaktuj się z administratorem.',
            })
          : t('products.errors.built_in_missing', {
              defaultValue:
                'Built-in product ObjectType not found in this tenant — run the catalog seeder.',
            })}
      </div>
    );
  }

  if (schemaQuery.isError || !schemaQuery.data) {
    return (
      <div className="rounded border border-destructive bg-destructive/5 p-6 text-sm text-destructive">
        {t('object_list.errors.schema_fetch', { defaultValue: 'Could not load list schema.' })}
      </div>
    );
  }

  const labels = schemaQuery.data.objectType.label ?? {};
  const typeLabel =
    labels[locale] ?? labels.en ?? t('products.list_title', { defaultValue: 'Produkty' });

  return (
    <UniversalListPage
      objectTypeId={objectTypeId}
      objectTypeCode="product"
      objectTypeLabel={typeLabel}
      searchKind="products"
      hasVariants={schemaQuery.data.objectType.has_variants}
      isCategorizable={schemaQuery.data.objectType.is_categorizable}
      hasMultimedia={schemaQuery.data.objectType.has_multimedia}
      createPath="/products/new"
      detailPathFor={(id) => `/products/${id}`}
    />
  );
}

export default ProductsUniversalListPage;

import { useQuery } from '@tanstack/react-query';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import { useParams } from 'react-router';

import { UniversalListPage } from '@/components/objects/universal-list-page';
import { useListSchema } from '@/hooks/use-list-schema';
import { jsonFetch } from '@/lib/http';

/**
 * ULV-08 (#990) + UP-06 (#1024) — `/objects/:slug` route now renders the
 * full-feature `UniversalListPage` (extracted from /products) instead of
 * the MVP `ObjectListView`. Pixel-perfect parity with /products is the
 * acceptance criterion per operator decision:
 *
 *   > "nie idziemy w żadne półśrodki. Lista ma być piksel perfect jak
 *      w produktach czyli smart filtry, zapisz widok, filtrowanie
 *      zaawansowane, płasko/drzewo, karty/excel, zapisanie widoku,
 *      własny preset."
 *
 * Slug resolution stays the same — `/api/object_types?code={slug}` →
 * single row → propagated as `objectTypeId` to UniversalListPage.
 *
 * Built-in `product` keeps `/products` as its canonical route; the
 * legacy ProductListPage was retired in NUI-05 (#1424). The
 * `/objects/product` deep-link still resolves to UniversalListPage
 * for evaluation parity.
 */
interface ObjectTypeLookupRow {
  id: string;
  code: string;
  label?: Record<string, string>;
  kind?: string;
}

interface ObjectTypeLookupResponse {
  member?: ObjectTypeLookupRow[];
  'hydra:member'?: ObjectTypeLookupRow[];
}

const BUILT_IN_KIND_BY_CODE: Record<string, 'products' | 'categories' | 'assets'> = {
  product: 'products',
  category: 'categories',
  asset: 'assets',
};

const BUILT_IN_CREATE_PATH: Record<string, string> = {
  product: '/products/new',
  category: '/modeling/categories?action=create',
  asset: '/multimedia?action=upload',
};

const BUILT_IN_DETAIL_PATH: Record<string, (id: string) => string> = {
  product: (id) => `/products/${id}`,
  category: (id) => `/modeling/categories/${id}`,
  asset: (id) => `/multimedia/${id}`,
};

export function ObjectListPage() {
  const { t, i18n } = useTranslation();
  const locale = i18n.language.split('-')[0] ?? 'en';
  const { slug } = useParams<{ slug: string }>();

  const lookup = useQuery({
    queryKey: ['object-type-by-code', slug],
    enabled: typeof slug === 'string' && slug.length > 0,
    staleTime: 5 * 60 * 1000,
    queryFn: async () => {
      const response = await jsonFetch<ObjectTypeLookupResponse>('/api/object_types', {
        accept: 'application/ld+json',
        query: { code: slug, itemsPerPage: 1 },
      });
      const members = response.member ?? response['hydra:member'] ?? [];
      const first = members[0];
      if (first === undefined || first.code !== slug) {
        return null;
      }
      return first;
    },
  });

  const schemaQuery = useListSchema(lookup.data?.id);

  const typeLabel = useMemo(() => {
    if (!lookup.data) return '';
    const labels = lookup.data.label ?? {};
    return labels[locale] ?? labels.en ?? lookup.data.code;
  }, [lookup.data, locale]);

  if (lookup.isLoading || (lookup.data && schemaQuery.isLoading)) {
    return (
      <div
        aria-busy="true"
        className="flex h-64 items-center justify-center text-sm text-muted-foreground"
      >
        {t('object_list.resolving_slug', { defaultValue: 'Resolving ObjectType…' })}
      </div>
    );
  }

  if (lookup.isError || lookup.data === null || lookup.data === undefined) {
    return (
      <div className="rounded border border-destructive bg-destructive/5 p-6 text-sm text-destructive">
        {t('object_list.errors.slug_not_found', {
          defaultValue: 'ObjectType "{{slug}}" was not found in this tenant.',
          slug,
        })}
      </div>
    );
  }

  if (schemaQuery.isError || !schemaQuery.data) {
    return (
      <div className="rounded border border-destructive bg-destructive/5 p-6 text-sm text-destructive">
        {t('object_list.errors.schema_fetch', {
          defaultValue: 'Could not load list schema.',
        })}
      </div>
    );
  }

  const objectType = lookup.data;
  const schema = schemaQuery.data;
  const code = objectType.code;
  const searchKind = BUILT_IN_KIND_BY_CODE[code];
  const createPath = BUILT_IN_CREATE_PATH[code] ?? `/objects/${code}/new`;
  const detailPathFor = BUILT_IN_DETAIL_PATH[code] ?? ((id: string) => `/objects/${code}/${id}`);

  return (
    <UniversalListPage
      objectTypeId={objectType.id}
      objectTypeCode={code}
      objectTypeLabel={typeLabel}
      searchKind={searchKind}
      hasVariants={schema.objectType.has_variants}
      isCategorizable={schema.objectType.is_categorizable}
      hasMultimedia={schema.objectType.has_multimedia}
      createPath={createPath}
      detailPathFor={detailPathFor}
    />
  );
}

export default ObjectListPage;

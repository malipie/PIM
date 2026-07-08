import { lazy } from 'react';
import { useTranslation } from 'react-i18next';

import type { ProductChannel, ProductLocale } from './types';

/**
 * AUD-057 (#1608) — the bespoke special-tab views (multimedia / categories
 * / variants) + the loading fallback, extracted from
 * product-detail-page.tsx to shrink that monolith under the 500-line guard.
 *
 * AUD-071 (#1614) — these tabs render ONLY after the operator clicks them,
 * so each is code-split behind React.lazy; the chunk loads on demand behind
 * the <Suspense> boundary the page wraps around <OtherTabs>. RelationsTab
 * stays in the page itself — it is also reached from the main tab dispatcher
 * (forward-relation + reverse-only paths), not just here.
 */
const CategoriesTab = lazy(() =>
  import('./categories-tab').then((m) => ({ default: m.CategoriesTab })),
);
const ProductMultimediaTab = lazy(() =>
  import('./product-multimedia-tab').then((m) => ({ default: m.ProductMultimediaTab })),
);
const VariantsTabHost = lazy(() =>
  import('./variants-tab-host').then((m) => ({ default: m.VariantsTabHost })),
);

export function OtherTabs({
  activeTab,
  productId,
  objectTypeId,
  kind,
  locale,
  channel,
}: {
  activeTab: 'multimedia' | 'categories' | 'variants' | 'attributes';
  productId: string;
  objectTypeId: string | null;
  kind: string;
  locale: ProductLocale;
  channel: ProductChannel | null;
}) {
  // UX bug fix #2 — Multimedia is back as a special tab gated by
  // `ObjectType.hasMultimedia` (UX-02 removed it from the AttributeGroup
  // dispatcher); the assets link table is poly-kind so the tab works
  // for every ObjectType.
  if (activeTab === 'multimedia') return <ProductMultimediaTab productId={productId} />;
  if (activeTab === 'categories')
    return <CategoriesTab productId={productId} objectTypeId={objectTypeId} kind={kind} />;
  if (activeTab === 'variants')
    return (
      <VariantsTabHost
        productId={productId}
        basePath="/api/objects"
        locale={locale}
        channel={channel}
      />
    );
  return null;
}

/**
 * AUD-071 (#1614) — discreet fallback while a lazy tab chunk loads. The
 * tab chunks are small (a few tens of KB gzip) so this flashes only on a
 * cold cache; it intentionally mirrors the muted card surface of the real
 * tabs to avoid a jarring layout shift.
 */
export function TabLoadingFallback() {
  const { t } = useTranslation();
  return (
    <div
      className="rounded-2xl border border-line bg-surface p-5 text-[12.5px] text-muted-foreground soft-shadow"
      role="status"
      aria-live="polite"
    >
      {t('products.detail.tabs.loading', { defaultValue: 'Ładowanie…' })}
    </div>
  );
}

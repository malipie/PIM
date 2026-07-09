import { Plus } from 'lucide-react';
import { useCallback, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';

import { PillTabs } from '@/components/ui-v2/pill-tabs';
import { usePageActions } from '@/layout/page-actions-context';

import { CatalogsTab } from './CatalogsTab';
import { TemplatesTab } from './TemplatesTab';

type CatalogsPdfTab = 'catalogs' | 'templates';

const VALID_TABS: readonly CatalogsPdfTab[] = ['catalogs', 'templates'];

function parseTab(value: string | null): CatalogsPdfTab {
  return VALID_TABS.includes(value as CatalogsPdfTab) ? (value as CatalogsPdfTab) : 'catalogs';
}

/**
 * CPDF-P5-01 — PDF catalogs area shell. A self-contained hub under the single
 * `/catalogs-pdf` route (no nested routes): two pill tabs (Katalogi / Szablony)
 * with internal `useState` active-tab state seeded from `?tab=`. Mirrors the
 * ExportsLayout v2 hub pattern (PillTabs + a topbar CTA via usePageActions).
 * The tabs' content is filled by CPDF-P5-02/03/04.
 */
export function CatalogsPdfPage() {
  const { t } = useTranslation();
  const [searchParams, setSearchParams] = useSearchParams();
  const [activeTab, setActiveTab] = useState<CatalogsPdfTab>(() =>
    parseTab(searchParams.get('tab')),
  );

  const changeTab = useCallback(
    (id: string) => {
      const next = parseTab(id);
      setActiveTab(next);
      setSearchParams(
        (prev) => {
          const params = new URLSearchParams(prev);
          params.set('tab', next);
          return params;
        },
        { replace: true },
      );
    },
    [setSearchParams],
  );

  // TODO(CPDF-P5-02): open the create-catalog wizard. Until it ships, the CTA
  // deep-links into the (still empty) catalogs tab so the flow is discoverable.
  const openNewCatalog = useCallback(() => {
    changeTab('catalogs');
    setSearchParams(
      (prev) => {
        const params = new URLSearchParams(prev);
        params.set('tab', 'new');
        return params;
      },
      { replace: true },
    );
  }, [changeTab, setSearchParams]);

  usePageActions(
    useMemo(
      () => (
        <button
          type="button"
          onClick={openNewCatalog}
          className="focus-ring inline-flex h-9 items-center gap-1.5 rounded-xl bg-cta px-3.5 text-[13px] font-semibold text-cta-foreground transition hover:bg-accent-hover"
        >
          <Plus className="size-4" aria-hidden />
          {t('catalogs_pdf.new_cta')}
        </button>
      ),
      [t, openNewCatalog],
    ),
  );

  return (
    <div className="space-y-6">
      <header className="space-y-1">
        <h1 className="display text-[28px] font-semibold tracking-tight">
          {t('catalogs_pdf.page_title')}
        </h1>
      </header>

      <PillTabs
        ariaLabel={t('catalogs_pdf.tabs_aria')}
        activeId={activeTab}
        onChange={changeTab}
        items={[
          { id: 'catalogs', label: t('catalogs_pdf.tab_catalogs') },
          { id: 'templates', label: t('catalogs_pdf.tab_templates') },
        ]}
      />

      {activeTab === 'catalogs' ? <CatalogsTab onNewCatalog={openNewCatalog} /> : <TemplatesTab />}
    </div>
  );
}

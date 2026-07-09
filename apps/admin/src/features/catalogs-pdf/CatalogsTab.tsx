import { useQuery } from '@tanstack/react-query';
import { FileText } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { EmptyState } from '@/components/ui-v2/empty-state';
import { jsonFetch } from '@/lib/http';

/** One row of `GET /api/catalogs` — only the fields the shell reads. */
interface CatalogProfileRow {
  id: string;
  name: string;
  template_kind: string;
  status: string;
}

/** `GET /api/catalogs` returns `{ items: [...] }` (CatalogProfileController::list). */
interface CatalogsResponse {
  items: CatalogProfileRow[];
}

interface CatalogsTabProps {
  /** CTA that opens the create wizard (owned by CPDF-P5-02). */
  onNewCatalog: () => void;
}

/**
 * CPDF-P5-01 — Catalogs hub tab. For the shell ticket this is a lightweight
 * hub: it fetches `GET /api/catalogs` and, until CPDF-P5-02 ships the wizard
 * and the card grid, renders an empty state with the "Nowy katalog" CTA. A
 * non-empty list still lands on the empty-state copy plus a count badge —
 * the real card grid is P5-02/P5-03 scope.
 */
export function CatalogsTab({ onNewCatalog }: CatalogsTabProps) {
  const { t } = useTranslation();

  const catalogsQuery = useQuery<CatalogsResponse>({
    queryKey: ['catalogs-pdf', 'profiles'],
    queryFn: () => jsonFetch<CatalogsResponse>('/api/catalogs', { accept: 'application/json' }),
    staleTime: 30_000,
  });

  const count = catalogsQuery.data?.items.length ?? 0;

  return (
    <div className="space-y-4">
      {count > 0 && (
        <p className="text-[12.5px] font-medium text-zinc-500">
          {t('catalogs_pdf.catalogs_count', { count })}
        </p>
      )}
      <EmptyState
        icon={<FileText className="size-5" aria-hidden />}
        title={t('catalogs_pdf.catalogs_empty_title')}
        description={t('catalogs_pdf.catalogs_empty_description')}
        action={
          <button
            type="button"
            onClick={onNewCatalog}
            className="focus-ring inline-flex h-9 items-center gap-1.5 rounded-xl bg-cta px-3.5 text-[13px] font-semibold text-cta-foreground transition hover:bg-accent-hover"
          >
            {t('catalogs_pdf.new_cta')}
          </button>
        }
      />
    </div>
  );
}

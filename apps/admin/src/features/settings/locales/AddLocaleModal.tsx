import { Search, Sparkles } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { toast } from '@/components/ui/toast';
import { httpErrorDetail, jsonFetch } from '@/lib/http';
import { cn } from '@/lib/utils';

import type { TenantLocaleListItem } from './types';

interface CatalogLocaleListItem {
  id: string;
  code: string;
  label: string;
  language: string;
  region: string | null;
  displayName: Record<string, string>;
  popular: boolean;
}

interface HydraCollection<T> {
  member?: T[];
}

interface AddLocaleModalProps {
  open: boolean;
  onOpenChange: (open: boolean) => void;
  activatedCodes: Set<string>;
  /** Highest existing sort_order so the new row lands at the bottom. */
  nextSortOrder: number;
  onSuccess: () => void;
}

/**
 * LOC-08 (#876) — "Add locale" modal.
 *
 * Loads the ISO catalog from `GET /api/locales`. A search box (debounced
 * 200ms) filters by code, native name, and translated name. The "Popular"
 * section pins the 14 CEE+DACH flagged rows on top; the rest of the catalog
 * follows. Already-activated locales render disabled with a hint.
 *
 * On select → `POST /api/tenant-locales` with `{code, sortOrder: nextSortOrder}`.
 * The optional "Set as mandatory" footer checkbox flips `isMandatory: true`
 * on the same POST and surfaces the impact preview from `LOC-05` (#873)
 * before committing.
 */
export function AddLocaleModal({
  open,
  onOpenChange,
  activatedCodes,
  nextSortOrder,
  onSuccess,
}: AddLocaleModalProps) {
  const { t, i18n } = useTranslation();
  const [catalog, setCatalog] = useState<CatalogLocaleListItem[]>([]);
  const [isLoading, setIsLoading] = useState(false);
  const [search, setSearch] = useState('');
  const [debounced, setDebounced] = useState('');
  const [submittingCode, setSubmittingCode] = useState<string | null>(null);
  const [makeMandatory, setMakeMandatory] = useState(false);
  const [impact, setImpact] = useState<{ missing: number; total: number } | null>(null);
  /** #2739 — a failed impact preview used to vanish, so the operator decided blind. */
  const [impactError, setImpactError] = useState<string | null>(null);
  const [selectedForPreview, setSelectedForPreview] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    setIsLoading(true);
    void (async () => {
      try {
        const response = await jsonFetch<HydraCollection<CatalogLocaleListItem>>('/api/locales', {
          accept: 'application/ld+json',
        });
        setCatalog(response.member ?? []);
      } catch (e) {
        toast.error(e instanceof Error ? e.message : 'Failed to load locale catalog.');
      } finally {
        setIsLoading(false);
      }
    })();
  }, [open]);

  useEffect(() => {
    const handle = window.setTimeout(() => setDebounced(search.trim().toLowerCase()), 200);
    return () => window.clearTimeout(handle);
  }, [search]);

  const filtered = useMemo(() => {
    if (debounced === '') return catalog;
    return catalog.filter((row) => {
      const haystack = [row.code, row.label, ...Object.values(row.displayName ?? {})]
        .join(' ')
        .toLowerCase();
      return haystack.includes(debounced);
    });
  }, [catalog, debounced]);

  const popular = useMemo(() => filtered.filter((r) => r.popular), [filtered]);
  const rest = useMemo(() => filtered.filter((r) => !r.popular), [filtered]);

  const previewImpactFor = async (code: string) => {
    setSelectedForPreview(code);
    setImpact(null);
    setImpactError(null);
    try {
      const response = await jsonFetch<{
        productsInTenant: number;
        objectsMissingValuesInLocale: number;
      }>('/api/tenant-locales/preview-impact', {
        method: 'POST',
        body: { code },
        contentType: 'application/json',
      });
      setImpact({
        missing: response.objectsMissingValuesInLocale,
        total: response.productsInTenant,
      });
    } catch (err) {
      setImpact(null);
      setImpactError(
        httpErrorDetail(err) ??
          t('settings.locales.add_modal.impact_failed', {
            defaultValue: 'Nie udało się policzyć wpływu dodania języka.',
          }),
      );
    }
  };

  const handleSelect = async (row: CatalogLocaleListItem) => {
    setSubmittingCode(row.code);
    try {
      await jsonFetch<TenantLocaleListItem>('/api/tenant-locales', {
        method: 'POST',
        body: {
          code: row.code,
          isMandatory: makeMandatory,
          sortOrder: nextSortOrder,
        },
        contentType: 'application/json',
      });
      toast.success(t('settings.locales.add_modal.toast_activated', { code: row.code }));
      onSuccess();
      onOpenChange(false);
      setSearch('');
      setMakeMandatory(false);
      setSelectedForPreview(null);
      setImpact(null);
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'Activation failed.');
    } finally {
      setSubmittingCode(null);
    }
  };

  return (
    <Dialog open={open} onOpenChange={onOpenChange}>
      <DialogContent className="flex max-h-[85vh] max-w-2xl flex-col overflow-hidden p-0">
        <DialogHeader className="border-b px-6 py-4">
          <DialogTitle>{t('settings.locales.add_modal.title')}</DialogTitle>
          <DialogDescription>{t('settings.locales.add_modal.intro')}</DialogDescription>
        </DialogHeader>

        <div
          data-testid="locale-catalog-scroll"
          className="min-h-0 flex-1 space-y-4 overflow-y-auto px-6 py-4"
        >
          <div className="relative">
            <Search
              className="absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
              aria-hidden="true"
            />
            <Input
              type="text"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={t('settings.locales.add_modal.search_placeholder')}
              className="pl-9"
              aria-label={t('settings.locales.add_modal.search_placeholder')}
            />
          </div>

          {isLoading && (
            <div className="space-y-2">
              {[0, 1, 2, 3, 4, 5].map((i) => (
                <div key={i} className="h-10 animate-pulse rounded-md bg-muted" />
              ))}
            </div>
          )}

          {!isLoading && popular.length > 0 && (
            <section>
              <div className="mb-2 flex items-center gap-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                <Sparkles className="size-3.5 text-amber-500" aria-hidden="true" />
                {t('settings.locales.add_modal.section_popular')}
              </div>
              <ul className="space-y-1">
                {popular.map((row) => (
                  <LocaleRow
                    key={row.code}
                    row={row}
                    locale={i18n.language}
                    activated={activatedCodes.has(row.code)}
                    submitting={submittingCode === row.code}
                    onPick={handleSelect}
                    onHover={previewImpactFor}
                  />
                ))}
              </ul>
            </section>
          )}

          {!isLoading && rest.length > 0 && (
            <section>
              <div className="mb-2 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
                {t('settings.locales.add_modal.section_all')}
              </div>
              <ul className="space-y-1">
                {rest.map((row) => (
                  <LocaleRow
                    key={row.code}
                    row={row}
                    locale={i18n.language}
                    activated={activatedCodes.has(row.code)}
                    submitting={submittingCode === row.code}
                    onPick={handleSelect}
                    onHover={previewImpactFor}
                  />
                ))}
              </ul>
            </section>
          )}

          {!isLoading && filtered.length === 0 && (
            <p className="py-8 text-center text-sm text-muted-foreground">
              {t('settings.locales.add_modal.no_results')}
            </p>
          )}
        </div>

        <DialogFooter className="border-t bg-muted/30 px-6 py-3">
          <label className="mr-auto inline-flex cursor-pointer items-center gap-2 text-xs">
            <input
              type="checkbox"
              checked={makeMandatory}
              onChange={(e) => setMakeMandatory(e.target.checked)}
              className="size-4 rounded border-input"
            />
            <span>{t('settings.locales.add_modal.mandatory_label')}</span>
          </label>
          {selectedForPreview && impact !== null && (
            <span className="mr-auto text-xs text-muted-foreground">
              {t('settings.locales.add_modal.impact_hint', {
                code: selectedForPreview,
                missing: impact.missing,
                total: impact.total,
              })}
            </span>
          )}
          {selectedForPreview && impactError !== null && (
            <span className="mr-auto text-xs text-destructive" role="alert">
              {impactError}
            </span>
          )}
          <Button variant="outline" onClick={() => onOpenChange(false)}>
            {t('settings.locales.add_modal.cancel')}
          </Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  );
}

function LocaleRow({
  row,
  locale,
  activated,
  submitting,
  onPick,
  onHover,
}: {
  row: CatalogLocaleListItem;
  locale: string;
  activated: boolean;
  submitting: boolean;
  onPick: (row: CatalogLocaleListItem) => void;
  onHover: (code: string) => void;
}) {
  const { t } = useTranslation();
  const localised =
    row.displayName?.[locale] ?? row.displayName?.en ?? row.displayName?.pl ?? row.label;

  return (
    <li>
      <button
        type="button"
        disabled={activated || submitting}
        onClick={() => onPick(row)}
        onMouseEnter={() => onHover(row.code)}
        className={cn(
          'flex w-full items-center justify-between rounded-md border px-3 py-2 text-left transition-colors',
          activated && 'cursor-not-allowed opacity-50',
          !activated && 'hover:bg-muted',
        )}
      >
        <span className="flex items-center gap-3">
          <span className="font-mono text-xs text-muted-foreground">{row.code}</span>
          <span className="text-sm">{localised}</span>
        </span>
        {activated ? (
          <span className="text-xs text-muted-foreground">
            {t('settings.locales.add_modal.already_active')}
          </span>
        ) : submitting ? (
          <span className="text-xs text-muted-foreground">
            {t('settings.locales.add_modal.activating')}
          </span>
        ) : null}
      </button>
    </li>
  );
}

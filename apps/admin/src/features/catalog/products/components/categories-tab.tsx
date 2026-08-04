import { useQuery, useQueryClient } from '@tanstack/react-query';
import { FolderTree, Star, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import {
  CategoryPickerDialog,
  type CurrentAssignment,
} from '@/components/catalog/category-picker-dialog';
import { Button } from '@/components/ui/button';
import { toast } from '@/components/ui/toast';
import { httpErrorDetail, jsonFetch } from '@/lib/http';
import { objectKeys } from '@/lib/object-query-keys';
import { cn } from '@/lib/utils';

import { ChannelPlacementsSection } from './channel-placements-section';
import { SchemaDriftBanner } from './schema-drift-banner';

interface AssignmentRow {
  categoryId: string;
  /** Legacy `/api/products/.../categories` shape. */
  code?: string;
  /** Poly-kind `/api/objects/.../categories` shape. */
  categoryCode?: string;
  isPrimary: boolean;
  position: number;
}

interface ListResponse {
  objectId?: string;
  productId?: string;
  primaryCategoryId?: string | null;
  assignments: AssignmentRow[];
}

interface Props {
  productId: string;
  /** ObjectType owning the object — scopes the picker to its category tree (#1413). */
  objectTypeId?: string | null;
  /**
   * Schema-drift banner + channel placements hit product-only endpoints —
   * hidden for custom kinds (#1348/#1351 unification).
   */
  kind?: string;
}

/**
 * PCAT-05 (#478) — "Kategorie" tab on the product detail page.
 *
 * Shows the current assignments as chips (label + ⭐ for primary + ×
 * to detach), plus an "Edytuj kategorie" button that opens
 * {@link CategoryPickerDialog} for full multi-select editing. Single-row
 * operations (toggle primary, detach) hit POST/DELETE directly so the
 * UI stays reactive without re-opening the dialog.
 *
 * Empty state explains the link to attribute inheritance — operator
 * needs to know that picking a category is what activates the
 * category-driven group set on the form (PCAT-03).
 */
export function CategoriesTab({ productId, objectTypeId, kind = 'product' }: Props) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [pickerOpen, setPickerOpen] = useState(false);
  const [busyCategoryId, setBusyCategoryId] = useState<string | null>(null);

  const { data, isLoading } = useQuery({
    queryKey: objectKeys.categories(productId),
    queryFn: async () =>
      jsonFetch<ListResponse>(`/api/objects/${productId}/categories`, {
        accept: 'application/json',
      }),
    enabled: productId !== '',
  });

  const assignments = (data?.assignments ?? []).map((a) => ({
    ...a,
    code: a.code ?? a.categoryCode ?? a.categoryId.slice(0, 8),
  }));
  const currentAssignments: CurrentAssignment[] = assignments.map((a) => ({
    categoryId: a.categoryId,
    isPrimary: a.isPrimary,
  }));

  const refresh = async () => {
    // Prefix invalidation covers categories + effective groups + detail.
    await queryClient.invalidateQueries({ queryKey: objectKeys.all(productId) });
  };

  const handleDetach = async (categoryId: string) => {
    if (busyCategoryId !== null) return;
    setBusyCategoryId(categoryId);
    try {
      await jsonFetch(`/api/objects/${productId}/categories/${categoryId}`, {
        method: 'DELETE',
        accept: 'application/json',
      });
      await refresh();
    } catch (err) {
      toast.error(
        httpErrorDetail(err) ??
          t('products.detail.categories.error_detach', {
            defaultValue: 'Nie udało się odpiąć kategorii.',
          }),
      );
    } finally {
      setBusyCategoryId(null);
    }
  };

  const handlePromote = async (categoryId: string) => {
    if (busyCategoryId !== null) return;
    setBusyCategoryId(categoryId);
    try {
      await jsonFetch(`/api/objects/${productId}/categories`, {
        method: 'POST',
        contentType: 'application/json',
        accept: 'application/json',
        body: { categoryId, isPrimary: true },
      });
      await refresh();
    } catch (err) {
      toast.error(
        httpErrorDetail(err) ??
          t('products.detail.categories.error_promote', {
            defaultValue: 'Nie udało się ustawić kategorii głównej.',
          }),
      );
    } finally {
      setBusyCategoryId(null);
    }
  };

  return (
    <section className="space-y-4">
      {kind === 'product' ? <SchemaDriftBanner productId={productId} /> : null}

      <header className="flex items-center justify-between">
        <div>
          <h3 className="text-[13.5px] font-semibold text-ink">
            {t('products.detail.categories.title', { defaultValue: 'Kategorie' })}
          </h3>
          <p className="mt-0.5 text-[11.5px] text-muted-foreground">
            {t('products.detail.categories.subtitle', {
              defaultValue: 'Przypisanie do kategorii decyduje o dziedziczonych polach formularza.',
            })}
          </p>
        </div>
        <Button onClick={() => setPickerOpen(true)} size="sm">
          {assignments.length === 0
            ? t('products.detail.categories.add_button', { defaultValue: '+ Przypisz kategorie' })
            : t('products.detail.categories.edit_button', {
                defaultValue: '+ Edytuj kategorie',
              })}
        </Button>
      </header>

      {isLoading ? (
        <p className="text-[12.5px] text-muted-foreground">
          {t('app.loading', { defaultValue: 'Ładowanie…' })}
        </p>
      ) : assignments.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-line bg-surface p-6 text-center">
          <FolderTree className="mx-auto size-6 text-zinc-500" />
          <p className="mt-2 text-[13px] font-medium text-ink">
            {t('products.detail.categories.empty', {
              defaultValue: 'Brak przypisanych kategorii',
            })}
          </p>
          <p className="mt-1 text-[11.5px] text-muted-foreground">
            {t('products.detail.categories.empty_hint', {
              defaultValue:
                'Dodaj kategorię aby aktywować dziedziczenie dodatkowych pól formularza.',
            })}
          </p>
        </div>
      ) : (
        <ul className="flex flex-wrap gap-2">
          {assignments.map((assignment) => {
            const isPrimary = assignment.isPrimary;
            const isBusy = busyCategoryId === assignment.categoryId;
            return (
              <li
                key={assignment.categoryId}
                className={cn(
                  'group inline-flex items-center gap-2 rounded-full border px-3 py-1.5 text-[12.5px] transition-colors',
                  isPrimary
                    ? 'border-amber-200 bg-amber-50 text-amber-900'
                    : 'border-zinc-200 bg-white text-ink hover:bg-zinc-50',
                  isBusy && 'opacity-50',
                )}
              >
                <button
                  type="button"
                  onClick={() => void handlePromote(assignment.categoryId)}
                  disabled={isPrimary || isBusy}
                  className={cn(
                    'grid size-5 place-items-center rounded-full',
                    isPrimary
                      ? 'cursor-default text-amber-600'
                      : 'text-zinc-500 hover:bg-amber-100 hover:text-amber-700',
                  )}
                  aria-label={
                    isPrimary
                      ? t('products.detail.categories.is_primary', {
                          defaultValue: 'Kategoria główna',
                        })
                      : t('products.detail.categories.set_primary', {
                          defaultValue: 'Ustaw jako główną',
                        })
                  }
                  aria-pressed={isPrimary}
                  title={
                    isPrimary
                      ? t('products.detail.categories.is_primary', {
                          defaultValue: 'Kategoria główna',
                        })
                      : t('products.detail.categories.set_primary', {
                          defaultValue: 'Ustaw jako główną',
                        })
                  }
                >
                  <Star className={cn('size-3.5', isPrimary && 'fill-amber-500')} />
                </button>
                <span className="font-medium">{assignment.code}</span>
                <button
                  type="button"
                  onClick={() => void handleDetach(assignment.categoryId)}
                  disabled={isBusy}
                  className="grid size-5 place-items-center rounded-full text-zinc-500 hover:bg-red-100 hover:text-red-600"
                  aria-label={t('products.detail.categories.detach', {
                    defaultValue: 'Odepnij kategorię',
                  })}
                  title={t('products.detail.categories.detach', {
                    defaultValue: 'Odepnij kategorię',
                  })}
                >
                  <X className="size-3.5" />
                </button>
              </li>
            );
          })}
        </ul>
      )}

      {kind === 'product' ? <ChannelPlacementsSection productId={productId} /> : null}

      <CategoryPickerDialog
        open={pickerOpen}
        onOpenChange={setPickerOpen}
        productId={productId}
        endpoint="objects"
        objectTypeId={objectTypeId ?? undefined}
        currentAssignments={currentAssignments}
        onSaved={() => undefined}
      />
    </section>
  );
}

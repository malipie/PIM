import { useQuery, useQueryClient } from '@tanstack/react-query';
import { FolderTree, Star, X } from 'lucide-react';
import { useMemo, useState } from 'react';
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

import { CategoryChangeWarningDialog } from './category-change-warning-dialog';

interface AssignmentRow {
  categoryId: string;
  code: string;
  isPrimary: boolean;
  position: number;
}

/**
 * Poly-kind `/api/objects/{id}/categories` serializes the code as
 * `categoryCode` and omits the top-level `primaryCategoryId` that the
 * legacy `/api/products/{id}/categories` shape carried — normalize both.
 */
interface RawAssignmentRow {
  categoryId: string;
  code?: string;
  categoryCode?: string;
  isPrimary: boolean;
  position: number;
}

interface ListResponse {
  objectId?: string;
  productId?: string;
  primaryCategoryId?: string | null;
  assignments: RawAssignmentRow[];
}

interface CreateModeCategorySummary {
  categoryId: string;
  code: string;
  isPrimary: boolean;
}

interface BaseProps {
  /** ObjectType under which the product lives — required for preview lookups. */
  objectTypeId: string | null;
}

interface EditModeProps extends BaseProps {
  mode: 'edit';
  productId: string;
}

interface CreateModeProps extends BaseProps {
  mode: 'create';
  /** Owner-managed state for the create flow — POST `/api/products` carries it. */
  selectedCategoryIds: string[];
  primaryCategoryId: string | null;
  /** Per-row summaries (id + code + isPrimary) so the chip list can render labels without a fetch. */
  selectedCategories: CreateModeCategorySummary[];
  onChange: (categoryIds: string[], primaryCategoryId: string | null) => void;
}

type Props = EditModeProps | CreateModeProps;

/**
 * #891 — sidebar widget for category assignment. Lives directly above
 * `SyncStatusCard` in the product detail right column. Operator sees a
 * compact chip list of currently assigned categories with star/× icons
 * and an "Edytuj kategorie" CTA opening the full picker dialog.
 *
 * Two modes:
 *   - `edit`: hits `/api/products/{id}/categories` for the chip data
 *     and dispatches detach/promote via the existing endpoints. Any
 *     destructive change (detach, full replace via picker) goes through
 *     {@link CategoryChangeWarningDialog} so the operator sees which
 *     inherited attributes will be hidden first.
 *   - `create`: state-driven from the parent (`ProductDetailPage` in
 *     create mode owns `createCategoryIds` / `createPrimaryId`).
 *     Picker opens in controlled mode (`productId=""`), the parent
 *     POSTs everything together so the product + assignments land in
 *     the same transaction.
 *
 * Empty state copy makes the link between category assignment and
 * effective attribute groups explicit — operators new to PIM otherwise
 * miss why the attributes tab looks bare.
 */
export function CategorySelectorCard(props: Props) {
  const { t } = useTranslation();
  const queryClient = useQueryClient();
  const [pickerOpen, setPickerOpen] = useState(false);
  const [busyCategoryId, setBusyCategoryId] = useState<string | null>(null);
  const [warningTarget, setWarningTarget] = useState<{
    action: 'detach' | 'replace';
    nextCategoryIds: string[];
    nextPrimaryId: string | null;
    payload: unknown;
  } | null>(null);

  const isEdit = props.mode === 'edit';
  const productId = isEdit ? props.productId : '';

  const { data, isLoading } = useQuery({
    queryKey: objectKeys.categories(productId),
    queryFn: async () =>
      jsonFetch<ListResponse>(`/api/objects/${productId}/categories`, {
        accept: 'application/json',
      }),
    enabled: isEdit && productId !== '',
  });

  const refresh = async () => {
    if (!isEdit) return;
    // Prefix invalidation covers categories + effective groups + the
    // scoped detail query — all live under objectKeys.all(id).
    await queryClient.invalidateQueries({ queryKey: objectKeys.all(productId) });
  };

  const assignments: AssignmentRow[] = useMemo(() => {
    if (isEdit) {
      return (data?.assignments ?? []).map((a) => ({
        categoryId: a.categoryId,
        code: a.code ?? a.categoryCode ?? a.categoryId.slice(0, 8),
        isPrimary: a.isPrimary,
        position: a.position,
      }));
    }
    return props.selectedCategories.map((c, idx) => ({
      categoryId: c.categoryId,
      code: c.code,
      isPrimary: c.isPrimary,
      position: idx,
    }));
  }, [isEdit, data, props]);

  const persistedPrimaryId = useMemo(
    () => data?.primaryCategoryId ?? assignments.find((a) => a.isPrimary)?.categoryId ?? null,
    [data, assignments],
  );

  const currentCategoryIds: string[] = useMemo(
    () => assignments.map((a) => a.categoryId),
    [assignments],
  );

  const currentPickerAssignments: CurrentAssignment[] = useMemo(
    () =>
      assignments.map((a) => ({
        categoryId: a.categoryId,
        isPrimary: a.isPrimary,
      })),
    [assignments],
  );

  const runDetach = async (categoryId: string) => {
    if (!isEdit) return;
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

  const handleDetach = (categoryId: string) => {
    if (busyCategoryId !== null) return;
    if (!isEdit) {
      const nextIds = props.selectedCategoryIds.filter((id) => id !== categoryId);
      const nextPrimary =
        props.primaryCategoryId === categoryId ? (nextIds[0] ?? null) : props.primaryCategoryId;
      props.onChange(nextIds, nextPrimary);
      return;
    }
    setWarningTarget({
      action: 'detach',
      nextCategoryIds: currentCategoryIds.filter((id) => id !== categoryId),
      nextPrimaryId: persistedPrimaryId === categoryId ? null : persistedPrimaryId,
      payload: { categoryId },
    });
  };

  const handlePromote = async (categoryId: string) => {
    if (busyCategoryId !== null) return;
    if (!isEdit) {
      props.onChange(props.selectedCategoryIds, categoryId);
      return;
    }
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

  const confirmWarning = async () => {
    if (warningTarget === null) return;
    if (warningTarget.action === 'detach') {
      const payload = warningTarget.payload as { categoryId: string };
      await runDetach(payload.categoryId);
    }
    setWarningTarget(null);
  };

  const isEmpty = assignments.length === 0;

  return (
    <section
      className="rounded-2xl border border-line bg-white p-4 soft-shadow"
      aria-label={t('products.detail.sidebar.categories.aria', {
        defaultValue: 'Kategorie produktu',
      })}
    >
      <header className="mb-3 flex items-start justify-between gap-2">
        <div>
          <h3 className="text-[13.5px] font-semibold text-ink">
            {t('products.detail.sidebar.categories.title', { defaultValue: 'Kategorie' })}
          </h3>
          <p className="mt-0.5 text-[11.5px] text-muted-foreground">
            {t('products.detail.sidebar.categories.subtitle', {
              defaultValue: 'Decyduje o zestawie dziedziczonych atrybutów.',
            })}
          </p>
        </div>
      </header>

      {isEdit && isLoading ? (
        <p className="text-[12.5px] text-muted-foreground">
          {t('app.loading', { defaultValue: 'Ładowanie…' })}
        </p>
      ) : isEmpty ? (
        <div className="rounded-xl border border-dashed border-line bg-surface px-3 py-4 text-center">
          <FolderTree className="mx-auto size-5 text-zinc-500" />
          <p className="mt-1 text-[12.5px] font-medium text-ink">
            {t('products.detail.sidebar.categories.empty', {
              defaultValue: 'Brak kategorii',
            })}
          </p>
          <p className="mt-0.5 text-[11px] text-muted-foreground">
            {t('products.detail.sidebar.categories.empty_hint', {
              defaultValue: 'Przypisz aby aktywować dziedziczone atrybuty.',
            })}
          </p>
        </div>
      ) : (
        <ul className="flex flex-wrap gap-1.5">
          {assignments.map((assignment) => {
            const isBusy = busyCategoryId === assignment.categoryId;
            return (
              <li
                key={assignment.categoryId}
                className={cn(
                  'group inline-flex items-center gap-1.5 rounded-full border px-2 py-1 text-[11.5px] transition-colors',
                  assignment.isPrimary
                    ? 'border-amber-200 bg-amber-50 text-amber-900'
                    : 'border-zinc-200 bg-white text-ink hover:bg-zinc-50',
                  isBusy && 'opacity-50',
                )}
              >
                <button
                  type="button"
                  onClick={() => void handlePromote(assignment.categoryId)}
                  disabled={assignment.isPrimary || isBusy}
                  className={cn(
                    'grid size-4 place-items-center rounded-full',
                    assignment.isPrimary
                      ? 'cursor-default text-amber-600'
                      : 'text-zinc-500 hover:bg-amber-100 hover:text-amber-700',
                  )}
                  aria-label={
                    assignment.isPrimary
                      ? t('products.detail.categories.is_primary', {
                          defaultValue: 'Kategoria główna',
                        })
                      : t('products.detail.categories.set_primary', {
                          defaultValue: 'Ustaw jako główną',
                        })
                  }
                  aria-pressed={assignment.isPrimary}
                >
                  <Star className={cn('size-3', assignment.isPrimary && 'fill-amber-500')} />
                </button>
                <span className="font-medium">{assignment.code}</span>
                <button
                  type="button"
                  onClick={() => handleDetach(assignment.categoryId)}
                  disabled={isBusy}
                  className="grid size-4 place-items-center rounded-full text-zinc-500 hover:bg-red-100 hover:text-red-600"
                  aria-label={t('products.detail.categories.detach', {
                    defaultValue: 'Odepnij kategorię',
                  })}
                >
                  <X className="size-3" />
                </button>
              </li>
            );
          })}
        </ul>
      )}

      <Button
        type="button"
        onClick={() => setPickerOpen(true)}
        size="sm"
        variant="ghost"
        className="mt-3 h-8 w-full justify-center rounded-lg text-[12px]"
      >
        {isEmpty
          ? t('products.detail.sidebar.categories.add_button', {
              defaultValue: '+ Przypisz kategorię',
            })
          : t('products.detail.sidebar.categories.edit_button', {
              defaultValue: 'Edytuj kategorie',
            })}
      </Button>

      <CategoryPickerDialog
        open={pickerOpen}
        onOpenChange={setPickerOpen}
        productId={productId}
        endpoint="objects"
        objectTypeId={props.objectTypeId ?? undefined}
        currentAssignments={currentPickerAssignments}
        onSaved={() => undefined}
        onSelect={
          isEdit
            ? undefined
            : (categoryIds, primaryCategoryId) => {
                // Create flow: parent owns the state, just propagate.
                props.onChange(categoryIds, primaryCategoryId);
              }
        }
      />

      {/*
        Picker dialog handles its own save in edit mode AND fires onSaved
        which bursts the cache. Detach + promote interactions on the chips
        are gated by the warning modal so the operator sees which inherited
        attributes will be hidden before confirming.
      */}
      {isEdit ? (
        <CategoryChangeWarningDialog
          open={warningTarget !== null}
          onOpenChange={(next) => {
            if (!next) setWarningTarget(null);
          }}
          productId={productId}
          objectTypeId={props.objectTypeId}
          currentCategoryIds={currentCategoryIds}
          nextCategoryIds={warningTarget?.nextCategoryIds ?? currentCategoryIds}
          onConfirm={() => void confirmWarning()}
        />
      ) : null}
    </section>
  );
}

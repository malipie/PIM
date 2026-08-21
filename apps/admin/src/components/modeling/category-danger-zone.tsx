import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';

import { PermissionGate } from '@/components/identity';
import { DangerZoneCard } from '@/components/modeling/danger-zone-card';
import { httpErrorDetail, jsonFetch } from '@/lib/http';

interface CategoryUsage {
  categoryId: string;
  instanceCount: number;
  descendantCount: number;
}

/**
 * #2942 — the delete affordance the categories UI never had. The backend
 * exposed `categories_delete` from the start, so an operator could only
 * remove a category by hand-crafting the request.
 *
 * Rendered on both category surfaces (the split-view panel and the full
 * detail page) from one component, because the two states it has to explain
 * are easy to get subtly different:
 *
 *   - **descendants** — `CategoryDeleteGuard` answers 409, so the action is
 *     disabled and says why rather than offering a click that cannot work;
 *   - **assigned objects** — PCAT-03 (#476) cascades those assignments away
 *     and promotes the next category to primary, so the delete succeeds. The
 *     operator still has to be told what it will detach.
 */
export function CategoryDangerZone({
  categoryId,
  categoryLabel,
  onDeleted,
}: {
  categoryId: string;
  categoryLabel: string;
  onDeleted: () => void;
}) {
  const { t } = useTranslation();

  const { data: usage } = useQuery<CategoryUsage>({
    queryKey: ['categories', categoryId, 'usage'],
    queryFn: () =>
      jsonFetch<CategoryUsage>(`/api/categories/${categoryId}/usage`, {
        accept: 'application/json',
      }),
    staleTime: 30_000,
  });

  const hasDescendants = (usage?.descendantCount ?? 0) > 0;
  const assignedCount = usage?.instanceCount ?? 0;

  const handleDelete = async () => {
    try {
      await jsonFetch(`/api/categories/${categoryId}`, { method: 'DELETE' });
      onDeleted();
    } catch (error) {
      throw new Error(
        httpErrorDetail(error) ??
          t('categories.delete_error', { defaultValue: 'Nie udało się usunąć kategorii.' }),
      );
    }
  };

  return (
    <PermissionGate code="categories.delete">
      <DangerZoneCard
        title={t('categories.delete_title', { defaultValue: 'Usuń kategorię' })}
        description={t('categories.delete_description', {
          defaultValue: 'Kategorię z podkategoriami trzeba najpierw opróżnić.',
        })}
        destructiveLabel={t('categories.delete_action', { defaultValue: 'Usuń kategorię' })}
        blockedLabel={t('categories.delete_blocked', { defaultValue: 'Ma podkategorie' })}
        blocked={hasDescendants}
        confirmTitle={t('categories.delete_confirm_title', {
          defaultValue: 'Usunąć kategorię „{{name}}”?',
          name: categoryLabel,
        })}
        confirmDescription={
          assignedCount > 0
            ? t('categories.delete_confirm_description_assigned', {
                defaultValue:
                  'Ta operacja jest nieodwracalna. Obiekty przypisane do tej kategorii ({{objectCount}}) zostaną od niej odpięte — same obiekty pozostaną w katalogu.',
                objectCount: assignedCount,
              })
            : t('categories.delete_confirm_description', {
                defaultValue: 'Ta operacja jest nieodwracalna.',
              })
        }
        onConfirm={handleDelete}
      />
    </PermissionGate>
  );
}

import type { UseQueryResult } from '@tanstack/react-query';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';

import { toast } from '@/components/ui/toast';
import { httpErrorDetail, jsonFetch } from '@/lib/http';
import { localizeAttributeMessage } from './attribute-validation-i18n';
import {
  collectRelationCodes,
  isAttributeRequired,
  isEmptyAttributeValue,
  splitDirtyAttributes,
  stripAttributes,
} from './product-detail-helpers';
import { scopeQuery } from './scope';
import type {
  CatalogObjectDto,
  GroupMeta,
  ProductChannel,
  ProductDetailMode,
  ProductLocale,
} from './types';

interface UseProductDetailFormArgs {
  mode: ProductDetailMode;
  id: string;
  isEditMode: boolean;
  kind: string | null;
  objectTypeId: string | null;
  isCategorizable: boolean;
  locale: ProductLocale;
  channel: ProductChannel | null;
  groups: GroupMeta[];
  attrs: Record<string, unknown>;
  product: CatalogObjectDto | null | undefined;
  productQuery: Pick<UseQueryResult<CatalogObjectDto>, 'refetch'>;
  createCategoryIds: string[];
  createPrimaryId: string | null;
  backHref: string;
  detailPathFor: (id: string) => string;
}

/**
 * AUD-057 (#1608) — the product-detail write path + form state (dirty
 * fields, required-field validation, expand/collapse, create/edit save,
 * cancel, delete), lifted out of product-detail-page.tsx to bring that
 * monolith under the 500-line guard. The page keeps locale/channel + tab
 * UI state and reads everything else from {@see useProductDetailData}; this
 * hook owns mutations + the dirty buffer they operate on.
 */
export function useProductDetailForm({
  mode,
  id,
  isEditMode,
  kind,
  objectTypeId,
  isCategorizable,
  locale,
  channel,
  groups,
  attrs,
  product,
  productQuery,
  createCategoryIds,
  createPrimaryId,
  backHref,
  detailPathFor,
}: UseProductDetailFormArgs) {
  const { t } = useTranslation();
  const navigate = useNavigate();

  const [dirtyFields, setDirtyFields] = useState<Record<string, unknown>>({});
  // #1350 — codes of required attributes that blocked the last save.
  const [requiredErrors, setRequiredErrors] = useState<Set<string>>(new Set());
  // Codes of attributes rejected by the backend value validation (min/max,
  // pattern, …) on the last save — same red highlight as required-empty.
  const [validationErrors, setValidationErrors] = useState<Set<string>>(new Set());
  const [expandedGroups, setExpandedGroups] = useState<Set<string>>(new Set());
  const [isSaving, setIsSaving] = useState(false);
  const [isDeleting, setIsDeleting] = useState(false);

  const setExpandedAll = (ids: string[]): void => setExpandedGroups(new Set(ids));

  const toggleGroup = (groupId: string): void => {
    setExpandedGroups((prev) => {
      const next = new Set(prev);
      if (next.has(groupId)) next.delete(groupId);
      else next.add(groupId);
      return next;
    });
  };

  const setFieldValue = (code: string, value: unknown): void => {
    setDirtyFields((prev) => ({ ...prev, [code]: value }));
    setRequiredErrors((prev) => {
      if (!prev.has(code)) return prev;
      const next = new Set(prev);
      next.delete(code);
      return next;
    });
    setValidationErrors((prev) => {
      if (!prev.has(code)) return prev;
      const next = new Set(prev);
      next.delete(code);
      return next;
    });
  };

  // Resolve an attribute's display label (for validation messages) against the
  // active value locale, degrading through EN/PL like AttrRow does.
  const attributeLabel = (code: string): string | null => {
    for (const group of groups) {
      for (const attr of group.attributes) {
        if (attr.code !== code) continue;
        const label = attr.label as Record<string, string>;
        return label[locale] ?? label.en ?? label.pl ?? Object.values(label)[0] ?? null;
      }
    }
    return null;
  };

  const fieldValue = (code: string): unknown => {
    if (Object.hasOwn(dirtyFields, code)) {
      return dirtyFields[code];
    }
    return attrs[code];
  };

  const resetDirty = (): void => setDirtyFields({});

  // #1350 / #1673 — full-state required check at save time. EDIT enforces every
  // required attribute (global `is_required` OR group-level
  // `is_required_in_group`), so a dirty imported entry cannot be saved while a
  // group-required field is empty — the reported bug. CREATE keeps the lighter
  // global-only gate: a brand-new draft must not be blocked on every
  // completeness field at once, so group-required fields are enforced on the
  // follow-up edit (where the asterisk + this guard apply). Booleans are never
  // "missing" — an unchecked box IS the value `false`.
  const collectRequiredViolations = (): string[] => {
    const violations: string[] = [];
    for (const group of groups) {
      for (const attr of group.attributes) {
        if (attr.type === 'boolean') continue;
        const required = mode === 'edit' ? isAttributeRequired(attr) : attr.is_required === true;
        if (!required) continue;
        const current = fieldValue(attr.code);
        if (isEmptyAttributeValue(current)) violations.push(attr.code);
      }
    }
    return violations;
  };

  const handleSave = async (returnToList = false): Promise<void> => {
    if (isSaving) return;
    const violations = collectRequiredViolations();
    if (violations.length > 0) {
      setRequiredErrors(new Set(violations));
      toast.error(
        t('products.detail.validation.required_fields', {
          defaultValue: 'Uzupełnij wymagane pola: {{fields}}',
          fields: violations.map((code) => attributeLabel(code) ?? code).join(', '),
        }),
      );
      return;
    }
    setRequiredErrors(new Set());
    setValidationErrors(new Set());
    setIsSaving(true);
    try {
      if (mode === 'create') {
        const skuRaw = dirtyFields.sku ?? dirtyFields.code ?? '';
        const sku = typeof skuRaw === 'string' ? skuRaw.trim() : '';
        if (sku === '') {
          // #1415 — the system identifier is labelled "ID" for every
          // ObjectType (operator decision); custom identifiers live as
          // ordinary attributes.
          toast.error(t('object_create.id_required', { defaultValue: 'ID jest wymagane' }));
          setIsSaving(false);
          return;
        }
        if (objectTypeId === null) {
          toast.error(
            t('products.detail.validation.object_type_missing', {
              defaultValue: 'Brak built-in ObjectType — uruchom seeder katalogu',
            }),
          );
          setIsSaving(false);
          return;
        }
        // #891 / #1359 — categorizable kinds require a category at create.
        if (isCategorizable && createCategoryIds.length === 0) {
          toast.error(
            t('products.detail.validation.categories_required', {
              defaultValue: 'Przypisz przynajmniej jedną kategorię',
            }),
          );
          setIsSaving(false);
          return;
        }
        // #1102 — relation values cannot ride the create POST (they live
        // in the relations link table, not object_values); split them out
        // and PUT after the object exists, like UniversalCreatePage did.
        const relationCodes = collectRelationCodes(groups);
        const { normal: attributes, relations } = splitDirtyAttributes(
          stripAttributes(dirtyFields),
          relationCodes,
        );
        const body: Record<string, unknown> = {
          code: sku,
          objectTypeId,
        };
        if (createCategoryIds.length > 0) {
          const primary =
            createPrimaryId !== null && createCategoryIds.includes(createPrimaryId)
              ? createPrimaryId
              : createCategoryIds[0];
          body.categoryIds = createCategoryIds;
          body.primaryCategoryId = primary;
        }
        if (Object.keys(attributes).length > 0) body.attributes = attributes;
        // #1415 — poly-kind create: same processor as the /api/products
        // sugar path, kind comes from objectTypeId.
        const created = await jsonFetch<{ id: string }>('/api/objects', {
          method: 'POST',
          contentType: 'application/ld+json',
          body,
        });

        const relationFailures: string[] = [];
        for (const [attrCode, targets] of Object.entries(relations)) {
          if (targets.length === 0) continue;
          try {
            await jsonFetch(`/api/objects/${created.id}/relations/${attrCode}`, {
              method: 'PUT',
              contentType: 'application/json',
              body: { targets: targets.map((targetId) => ({ id: targetId })) },
            });
          } catch {
            relationFailures.push(attrCode);
          }
        }
        if (relationFailures.length > 0) {
          toast.error(
            t('object_create.relations_partial_error', {
              defaultValue: 'Obiekt utworzony, ale relacje nie zapisane: {{codes}}',
              codes: relationFailures.join(', '),
            }),
          );
        } else {
          toast.success(
            kind === 'product'
              ? t('products.detail.create.success', {
                  defaultValue: 'Utworzono produkt {{code}}',
                  code: sku,
                })
              : t('object_create.success', { defaultValue: 'Utworzono {{code}}', code: sku }),
          );
        }
        navigate(detailPathFor(created.id));
      } else {
        if (Object.keys(dirtyFields).length === 0) {
          // Nothing to persist — "Zapisz i wróć do listy" still returns.
          if (returnToList) navigate(backHref);
          setIsSaving(false);
          return;
        }
        // #1350 (reopen #2) — in edit mode every dirty key IS an attribute
        // code; stripping 'sku'/'code' here silently dropped edits to a
        // real `sku` attribute. The strip only belongs to create mode.
        const attributes = { ...dirtyFields };
        // #1150 / #1155 — write in the active locale + channel: localizable
        // / scopable attributes land on that scope's row, others stay
        // global (BE decides per flag).
        await jsonFetch(`/api/objects/${id}${scopeQuery(locale, channel)}`, {
          method: 'PATCH',
          contentType: 'application/merge-patch+json',
          body: { attributes },
        });
        await productQuery.refetch();
        setDirtyFields({});
        toast.success(t('products.detail.save.success', { defaultValue: 'Zapisano zmiany' }));
        // #1351 — "Zapisz zmiany" keeps the row in edit mode; only
        // "Zapisz i wróć do listy" navigates back to the list.
        if (returnToList) navigate(backHref);
      }
    } catch (error) {
      // #1179 — surface the server's Problem Details `detail` (e.g. duplicate
      // identifier 409) instead of the generic copy.
      const detail = httpErrorDetail(error);
      // Value-validation 422 arrives as `Attribute "<code>": <message>`. Swap
      // the raw code for the attribute's display name and flag that field so it
      // gets the same red highlight as a required-empty one.
      const match = detail?.match(/^Attribute "([^"]+)": (.*)$/s);
      const code = match?.[1];
      const rawMessage = match?.[2];
      if (code !== undefined && rawMessage !== undefined) {
        setValidationErrors(new Set([code]));
        toast.error(
          t('products.detail.validation.attribute_error', {
            defaultValue: 'Atrybut „{{name}}”: {{message}}',
            name: attributeLabel(code) ?? code,
            message: localizeAttributeMessage(rawMessage, t),
          }),
        );
      } else {
        toast.error(
          detail ?? t('products.detail.save.failed', { defaultValue: 'Nie udało się zapisać' }),
        );
      }
    } finally {
      setIsSaving(false);
    }
  };

  const cancelEdit = (): void => {
    // #2318 — "Anuluj" discards any unsaved edits and returns to the list
    // without saving. The unsaved-changes confirmation is enforced by the
    // caller (product-detail-page) before this runs, so here we just drop the
    // dirty buffer and navigate back.
    setDirtyFields({});
    navigate(backHref);
  };

  const handleDelete = async (onDone: () => void): Promise<void> => {
    if (mode !== 'edit' || id === '' || isDeleting) return;
    setIsDeleting(true);
    try {
      await jsonFetch(`/api/objects/${id}`, { method: 'DELETE' });
      toast.success(
        t('products.detail.delete.success', {
          defaultValue: 'Usunięto produkt {{code}}',
          code: product?.code ?? id,
        }),
      );
      navigate(backHref);
    } catch {
      toast.error(
        t('products.detail.delete.failed', { defaultValue: 'Nie udało się usunąć produktu' }),
      );
      setIsDeleting(false);
      onDone();
    }
  };

  return {
    dirtyFields,
    requiredErrors,
    validationErrors,
    expandedGroups,
    isSaving,
    isDeleting,
    setFieldValue,
    fieldValue,
    toggleGroup,
    setExpandedAll,
    resetDirty,
    handleSave,
    cancelEdit,
    handleDelete,
    // Exposed so the page's "reset dirty on scope change" + isEditMode
    // guards stay in the component where the effects live.
    isEditMode,
  };
}

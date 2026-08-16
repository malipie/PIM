/*
 * #1102 — picker for `type='relation'` attributes during object create.
 *
 * The detail-page editor (`RelationInlineEditor`) needs an existing object
 * id to read `/api/objects/{id}/relations`. In create flow there is no id
 * yet, so we collect target ids into the form's dirtyFields and the
 * parent (`UniversalCreatePage`) writes them via PUT
 * `/api/objects/{newId}/relations/{attributeCode}` after the main POST
 * succeeds.
 *
 * Candidates come from the same poly-kind `GET /api/objects` endpoint the
 * detail-page ObjectPickerDialog uses. itemsPerPage=200 is generous for
 * MVP (~50k SKU max per the planning doc).
 *
 * #2881 — one request per allowed ObjectType, not one unscoped request
 * filtered client-side. The unscoped collection is the question "give me
 * every kind at once", which #2848 deliberately left on the broad legacy
 * `object.read`, so every PRD role got a 403 here and the picker showed
 * no candidates at all. Scoping by `?objectType=` is also what the
 * per-kind read gate authorises, and it stops pulling rows the filter was
 * going to throw away anyway.
 *
 * An attribute with no `relation_target_object_type_ids` really does mean
 * "anything" — the seeded `related_to` is one. Asking the unscoped
 * collection for those was still a 403 for PRD roles, so "anything"
 * resolves to "every ObjectType this tenant has" and scopes the same way.
 * The result is the same list the client-side filter used to produce,
 * minus the kinds the caller may not read — which is the correct list,
 * not a smaller one.
 */
import { useQuery } from '@tanstack/react-query';
import { useTranslation } from 'react-i18next';

import { Combobox, type ComboboxOption } from '@/components/ui/combobox';
import { MultiSelect, type MultiSelectOption } from '@/components/ui/multi-select';
import type { AttributeMeta } from '@/features/catalog/products/components/types';
import { jsonFetch } from '@/lib/http';

interface CandidateRow {
  id: string;
  code?: string;
  objectType?: { id?: string } | null;
}

interface ObjectsListResponse {
  member?: CandidateRow[];
  'hydra:member'?: CandidateRow[];
}

interface ObjectTypesResponse {
  member?: { id?: string }[];
  'hydra:member'?: { id?: string }[];
}

export interface RelationCreateFieldProps {
  attribute: AttributeMeta;
  value: unknown;
  onChange: (next: unknown) => void;
}

export function RelationCreateField({
  attribute,
  value,
  onChange,
}: RelationCreateFieldProps): React.ReactElement {
  const { t } = useTranslation();
  const allowedTypeIds = attribute.relation_target_object_type_ids ?? [];
  const cardinality = attribute.relation_cardinality ?? 'many';

  const candidatesQuery = useQuery<CandidateRow[]>({
    queryKey: ['relation-candidates', allowedTypeIds.join(',')],
    queryFn: async () => {
      let typeIds = allowedTypeIds;
      if (typeIds.length === 0) {
        const types = await jsonFetch<ObjectTypesResponse>('/api/object_types?itemsPerPage=200', {
          accept: 'application/ld+json',
        });
        typeIds = (types.member ?? types['hydra:member'] ?? [])
          .map((row) => row.id)
          .filter((id): id is string => typeof id === 'string');
      }

      // A caller may legitimately lack read access to some of the types —
      // a 403 on one of them must narrow the list, not blank the picker.
      const pages = await Promise.all(
        typeIds.map((typeId) =>
          jsonFetch<ObjectsListResponse>(
            `/api/objects?itemsPerPage=200&objectType=${encodeURIComponent(typeId)}`,
            { accept: 'application/ld+json' },
          ).catch((): ObjectsListResponse => ({ member: [] })),
        ),
      );

      return pages.flatMap((page) => page.member ?? page['hydra:member'] ?? []);
    },
    staleTime: 30_000,
  });

  const candidates = candidatesQuery.data ?? [];
  const filtered =
    allowedTypeIds.length === 0
      ? candidates
      : candidates.filter((row) => {
          const otId = row.objectType?.id;
          return typeof otId === 'string' && allowedTypeIds.includes(otId);
        });

  if (candidatesQuery.isLoading) {
    return (
      <p className="text-xs text-muted-foreground">
        {t('relation_create_field.loading', { defaultValue: 'Ładowanie kandydatów…' })}
      </p>
    );
  }

  if (cardinality === 'one') {
    const options: ComboboxOption[] = filtered.map((row) => ({
      value: row.id,
      label: row.code ?? row.id,
    }));
    const currentValue = typeof value === 'string' && value !== '' ? value : null;
    return (
      <Combobox
        options={options}
        value={currentValue}
        onChange={(next) => onChange(next)}
        placeholder={t('relation_create_field.placeholder', { defaultValue: 'Wybierz…' })}
        className="rounded-xl text-[13.5px]"
      />
    );
  }

  const options: MultiSelectOption[] = filtered.map((row) => ({
    value: row.id,
    label: row.code ?? row.id,
  }));
  const currentValues = readMultiValue(value);
  return (
    <MultiSelect
      options={options}
      value={currentValues}
      onChange={(next) => onChange(next)}
      placeholder={t('relation_create_field.placeholder', { defaultValue: 'Wybierz…' })}
      className="rounded-xl text-[13.5px]"
    />
  );
}

function readMultiValue(value: unknown): string[] {
  if (!Array.isArray(value)) return [];
  return value.filter((v): v is string => typeof v === 'string' && v !== '');
}

import { type UseQueryResult, useQuery } from '@tanstack/react-query';

import { jsonFetch } from '@/lib/http';

/**
 * ULV-03 (#984) — `GET /api/object_types/{id}/list-schema` response shape.
 *
 * The schema endpoint returns the columns (system + attribute-flagged
 * `show_in_list=true`), the per-attribute filterable / searchable code
 * lists, and the object type header with capability flags. Field-level
 * `restricted` attributes (ULV-04b #986) are already filtered server-side.
 */
export interface ListSchemaObjectType {
  id: string;
  code: string;
  kind: string;
  label: Record<string, string>;
  is_categorizable: boolean;
  has_variants: boolean;
  has_multimedia: boolean;
  expose_to_main_menu: boolean;
}

export interface ListSchemaColumn {
  key: string;
  type: string;
  label: Record<string, string>;
  position: number;
  sortable: boolean;
  /** GRID-P6-01 — inline-editable in the Excel view (type + edit permission). */
  editable?: boolean;
  system: boolean;
  /** GRID-P3-01 — full mode only: `show_in_list` default flag. */
  default?: boolean;
  /** GRID-P3-01 — full mode only: owning AttributeGroup projection. */
  group?: { id: string; code: string; label: Record<string, string>; position: number } | null;
}

export interface ListSchemaResponse {
  objectType: ListSchemaObjectType;
  columns: ListSchemaColumn[];
  filterableAttributes: string[];
  searchableAttributes: string[];
}

/**
 * Fetches the universal list schema for an ObjectType. Cached for 5 min
 * — the schema rarely changes (operator must edit the ObjectType via
 * the modeling wizard) and the universal `ObjectListView` reads it on
 * every page mount.
 */
export function useListSchema(
  objectTypeId: string | undefined,
  options: { full?: boolean } = {},
): UseQueryResult<ListSchemaResponse> {
  const full = options.full === true;
  return useQuery({
    queryKey: ['list-schema', objectTypeId, full ? 'full' : 'std'],
    enabled: Boolean(objectTypeId),
    staleTime: 5 * 60 * 1000,
    queryFn: async () => {
      const response = await jsonFetch<ListSchemaResponse>(
        `/api/object_types/${objectTypeId}/list-schema${full ? '?full=1' : ''}`,
        { accept: 'application/json' },
      );

      return response;
    },
  });
}

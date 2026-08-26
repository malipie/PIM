import { useQuery } from '@tanstack/react-query';

import { jsonFetch } from '@/lib/http';

/**
 * #3034 — one batched read of the `where-used` counters for a whole modeling
 * list, replacing one HTTP request per row.
 *
 * Why this module exists at all: the payload shapes below were previously
 * re-declared at each call site, and two of those copies had drifted from what
 * the API actually returns (`attributeGroups` / `totalObjects` instead of
 * `groups` / `instanceCount`). Because the readers used `?? 0`, the drift
 * showed up as counters permanently stuck at zero rather than as a type error.
 * These types are now declared once and consumed everywhere, including
 * `<WhereUsedList>`.
 *
 * Keep them in step with `UsageQueryService` (apps/api) — the API test
 * `ModelingUsageApiTest` pins the server side of the same contract.
 */

export interface AttributeUsage {
  groups: { id: string; code: string; label?: Record<string, string> }[];
  objectTypes: { id: string; code: string; kind: string }[];
  categories: { id: string; path: string | null }[];
  instanceCount: number;
  optionCount: number;
}

export interface AttributeGroupUsage {
  directlyAttachedTo: {
    objectTypes: { id: string; code: string; kind: string }[];
    categories: { id: string; path: string | null; target_kind: string | null }[];
  };
  attributeCount: number;
  affectedInstanceCount: number;
}

export interface ObjectTypeUsage {
  instanceCount: number;
  attributesAttachedCount: number;
  attributeGroupsAttachedCount: number;
  referencedByApiProfileCount: number;
  referencedByCategoryAttachmentCount: number;
}

export interface UsageByResource {
  attributes: AttributeUsage;
  'attribute-groups': AttributeGroupUsage;
  'object-types': ObjectTypeUsage;
}

export type UsageResource = keyof UsageByResource;

/** Mirrors `UsageQueryService::MAX_BATCH_IDS`; the API answers 400 above it. */
const MAX_IDS_PER_REQUEST = 500;

function chunk(ids: string[], size: number): string[][] {
  const out: string[][] = [];
  for (let i = 0; i < ids.length; i += size) {
    out.push(ids.slice(i, i + size));
  }
  return out;
}

/**
 * Fetch usage counters for `ids` in a single request (or one request per 500
 * ids on lists longer than the server's ceiling).
 *
 * The query key is derived from the *sorted* id list, so re-ordering a list
 * client-side (the attributes list sorts newest-first after fetching) reuses
 * the cached response instead of refetching.
 */
export function useModelingUsage<R extends UsageResource>(
  resource: R,
  ids: string[],
): { data: Record<string, UsageByResource[R]>; isLoading: boolean } {
  const sorted = [...ids].sort();
  const key = sorted.join(',');

  const query = useQuery<Record<string, UsageByResource[R]>>({
    queryKey: ['modeling-usage', resource, key],
    queryFn: async ({ signal }) => {
      const pages = await Promise.all(
        chunk(sorted, MAX_IDS_PER_REQUEST).map((page) =>
          jsonFetch<Record<string, UsageByResource[R]>>(
            `/api/modeling/usage/${resource}?ids=${page.join(',')}`,
            { accept: 'application/json', signal },
          ),
        ),
      );
      return Object.assign({}, ...pages) as Record<string, UsageByResource[R]>;
    },
    enabled: sorted.length > 0,
    staleTime: 60_000,
  });

  return { data: query.data ?? {}, isLoading: query.isLoading };
}

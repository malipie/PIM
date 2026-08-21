import type { DataProvider } from '@refinedev/core';

import { jsonFetch } from './http';

/**
 * Minimal Hydra-aware DataProvider for API Platform 4. Only the operations
 * Refine needs for the Sprint-0 admin slice (list + getOne + create + update)
 * are implemented; deleteOne, getMany etc. land alongside the tickets that
 * actually use them.
 *
 * The collection response is a Hydra Collection: `member` is the data array
 * and `totalItems` the total. Cursor pagination via `id[lt]` / `id[gt]` is
 * available on /api/products (ticket #3) but Refine's offset model isn't a
 * great fit yet — getList exposes only `?page=` and reads the totalItems
 * count for now. Full cursor wiring is a follow-up in epic 0.4.
 */
interface HydraCollection<T> {
  member: T[];
  totalItems: number;
}

interface HydraResource {
  '@id'?: string;
  id?: string;
}

const API_BASE = '/api';
const UNPAGINATED_PAGE_SIZE = 200;

async function fetchCollection(
  resource: string,
  query: Record<string, string | number | undefined>,
): Promise<HydraCollection<HydraResource>> {
  return jsonFetch<HydraCollection<HydraResource>>(`${API_BASE}/${resource}`, { query });
}

/**
 * #2942 — `pagination: { mode: 'off' }` means "give me the collection", but
 * Refine still fills `currentPage: 1, pageSize: 10` on such a query and this
 * provider forwarded them, so the caller got one short page and no way to
 * tell. Ask for a large page instead and walk the rest.
 *
 * The walk is bounded twice over: by the page count derived from
 * `totalItems`, and by a per-page identity check. The second bound matters
 * because several collections here are cursor-paginated (`id[lt]`), where a
 * future server-side change could start ignoring `page` — a walk that kept
 * re-reading page 1 would otherwise fill the list with duplicates rather
 * than fail visibly.
 */
async function fetchCompleteCollection(
  resource: string,
  query: Record<string, string | number | undefined>,
): Promise<HydraCollection<HydraResource>> {
  const first = await fetchCollection(resource, {
    ...query,
    page: 1,
    itemsPerPage: UNPAGINATED_PAGE_SIZE,
  });
  const members = [...(first.member ?? [])];
  const totalItems = first.totalItems ?? members.length;
  const actualPageSize = members.length;

  if (actualPageSize === 0 || members.length >= totalItems) {
    return { member: members, totalItems };
  }

  const seen = new Set(members.map(memberKey));
  const pageCount = Math.ceil(totalItems / actualPageSize);
  for (let page = 2; page <= pageCount; page += 1) {
    const next = await fetchCollection(resource, {
      ...query,
      page,
      itemsPerPage: UNPAGINATED_PAGE_SIZE,
    });
    const fresh = (next.member ?? []).filter((member) => !seen.has(memberKey(member)));
    if (fresh.length === 0) break;
    for (const member of fresh) seen.add(memberKey(member));
    members.push(...fresh);
  }

  return { member: members, totalItems };
}

function memberKey(member: HydraResource): string {
  return member['@id'] ?? member.id ?? JSON.stringify(member);
}

export const dataProvider: DataProvider = {
  getApiUrl: () => API_BASE,

  async getList({ resource, pagination, filters }) {
    const query: Record<string, string | number | undefined> = {};
    if (pagination?.mode !== 'off' && pagination?.currentPage) {
      query.page = pagination.currentPage;
    }
    if (pagination?.mode !== 'off' && pagination?.pageSize) {
      // API Platform reads `itemsPerPage` per the Hydra pagination
      // extension. Without this the BE always returned the default
      // page size (30) regardless of the operator's selection in the
      // pager dropdown.
      query.itemsPerPage = pagination.pageSize;
    }
    // Forward simple `eq` field filters as query params. The custom
    // collection extensions per resource read these directly (the
    // Asset DAM pipeline relies on `?search=` + `?mimeGroup=`).
    if (filters) {
      for (const filter of filters) {
        if (
          'field' in filter &&
          filter.operator === 'eq' &&
          filter.value !== undefined &&
          filter.value !== ''
        ) {
          query[filter.field] = String(filter.value);
        }
      }
    }
    const data =
      pagination?.mode === 'off'
        ? await fetchCompleteCollection(resource, query)
        : await fetchCollection(resource, query);
    return {
      data: (data.member ?? []) as never[],
      total: data.totalItems ?? 0,
    };
  },

  async getOne({ resource, id }) {
    const data = await jsonFetch<HydraResource>(`${API_BASE}/${resource}/${id}`);
    return { data: data as never };
  },

  async create({ resource, variables }) {
    const data = await jsonFetch<HydraResource>(`${API_BASE}/${resource}`, {
      method: 'POST',
      body: variables,
    });
    return { data: data as never };
  },

  async update({ resource, id, variables }) {
    const data = await jsonFetch<HydraResource>(`${API_BASE}/${resource}/${id}`, {
      method: 'PATCH',
      body: variables,
      contentType: 'application/merge-patch+json',
    });
    return { data: data as never };
  },

  async deleteOne({ resource, id }) {
    await jsonFetch(`${API_BASE}/${resource}/${id}`, { method: 'DELETE' });
    return { data: {} as never };
  },

  async getMany({ resource, ids }) {
    const fetched = await Promise.all(
      ids.map((id) => jsonFetch<HydraResource>(`${API_BASE}/${resource}/${id}`)),
    );
    return { data: fetched as never[] };
  },

  // Forwards Refine's `useCustom` / `useCustomMutation` to `jsonFetch` so
  // non-Hydra endpoints (auto-map, backup status, profile test-connection,
  // token rotation, …) flow through the same auth pipeline as the rest of
  // the admin. Without this, hooks fail silently and the UI looks blank —
  // exactly the symptom that surfaced on the Mapping step of the import
  // wizard before IMP-10 follow-up.
  async custom({ url, method, payload, query }) {
    const upper = method.toUpperCase();
    if (
      upper !== 'GET' &&
      upper !== 'POST' &&
      upper !== 'PATCH' &&
      upper !== 'PUT' &&
      upper !== 'DELETE'
    ) {
      throw new Error(`dataProvider.custom: unsupported method "${method}"`);
    }
    const data = await jsonFetch(url, {
      method: upper,
      body: payload,
      query: query as Record<string, string | number | undefined> | undefined,
      contentType: 'application/json',
      accept: 'application/json',
    });
    return { data: data as never };
  },
};

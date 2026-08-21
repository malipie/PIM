import { beforeEach, describe, expect, it, vi } from 'vitest';

import { dataProvider } from './data-provider';
import { jsonFetch } from './http';

vi.mock('./http', () => ({
  jsonFetch: vi.fn(),
}));

const mockedJsonFetch = vi.mocked(jsonFetch);

describe('dataProvider.getList', () => {
  beforeEach(() => {
    mockedJsonFetch.mockReset();
  });

  it('keeps server pagination in a single request', async () => {
    mockedJsonFetch.mockResolvedValue({ member: [{ id: 'one' }], totalItems: 12 });

    const result = await dataProvider.getList({
      resource: 'attributes',
      pagination: { mode: 'server', currentPage: 2, pageSize: 8 },
      filters: [],
      sorters: [],
      meta: {},
    });

    expect(result).toEqual({ data: [{ id: 'one' }], total: 12 });
    expect(mockedJsonFetch).toHaveBeenCalledOnce();
    expect(mockedJsonFetch).toHaveBeenCalledWith('/api/attributes', {
      query: { page: 2, itemsPerPage: 8 },
    });
  });

  it('walks every page when pagination is off and preserves filters', async () => {
    mockedJsonFetch
      .mockResolvedValueOnce({ member: [{ id: 'one' }, { id: 'two' }], totalItems: 5 })
      .mockResolvedValueOnce({ member: [{ id: 'three' }, { id: 'four' }], totalItems: 5 })
      .mockResolvedValueOnce({ member: [{ id: 'five' }], totalItems: 5 });

    const result = await dataProvider.getList({
      resource: 'categories',
      pagination: { mode: 'off' },
      filters: [{ field: 'categoryTargetObjectType', operator: 'eq', value: 'product-id' }],
      sorters: [],
      meta: {},
    });

    expect(result).toEqual({
      data: [{ id: 'one' }, { id: 'two' }, { id: 'three' }, { id: 'four' }, { id: 'five' }],
      total: 5,
    });
    expect(mockedJsonFetch).toHaveBeenNthCalledWith(1, '/api/categories', {
      query: { categoryTargetObjectType: 'product-id', page: 1, itemsPerPage: 200 },
    });
    expect(mockedJsonFetch).toHaveBeenNthCalledWith(2, '/api/categories', {
      query: { categoryTargetObjectType: 'product-id', page: 2, itemsPerPage: 200 },
    });
    expect(mockedJsonFetch).toHaveBeenNthCalledWith(3, '/api/categories', {
      query: { categoryTargetObjectType: 'product-id', page: 3, itemsPerPage: 200 },
    });
  });

  it('stops instead of duplicating when a collection ignores the page param', async () => {
    // Cursor-paginated collections key off `id[lt]`; if one ever stopped
    // honouring `page`, re-reading the same rows would fill the list with
    // duplicates. The walk must end at the first page that brings nothing new.
    mockedJsonFetch.mockResolvedValue({ member: [{ id: 'one' }, { id: 'two' }], totalItems: 6 });

    const result = await dataProvider.getList({
      resource: 'categories',
      pagination: { mode: 'off' },
      filters: [],
      sorters: [],
      meta: {},
    });

    expect(result).toEqual({ data: [{ id: 'one' }, { id: 'two' }], total: 6 });
    expect(mockedJsonFetch).toHaveBeenCalledTimes(2);
  });
});

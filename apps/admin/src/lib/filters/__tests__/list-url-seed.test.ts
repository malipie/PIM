import { describe, expect, it } from 'vitest';

import { readInitialFilterDsl } from '../list-url-seed';

describe('readInitialFilterDsl', () => {
  it('parses a flat single-condition deep-link', () => {
    const dsl = readInitialFilterDsl(
      '?filter[completeness_pct][op]=gte&filter[completeness_pct][value]=80',
    );
    expect(dsl).toEqual({ attr: 'completeness_pct', op: '>=', value: '80' });
  });

  it('parses a between range into an array value', () => {
    const dsl = readInitialFilterDsl(
      '?filter[completeness_pct][op]=between&filter[completeness_pct][value]=80,99',
    );
    expect(dsl).toEqual({ attr: 'completeness_pct', op: 'between', value: ['80', '99'] });
  });

  it('parses multiple conditions into an AND group', () => {
    const dsl = readInitialFilterDsl(
      '?filter[brand][op]=eq&filter[brand][value]=Festo&filter[completeness_pct][op]=lt&filter[completeness_pct][value]=80',
    );
    expect(dsl).toEqual({
      operator: 'AND',
      conditions: [
        { attr: 'brand', op: '=', value: 'Festo' },
        { attr: 'completeness_pct', op: '<', value: '80' },
      ],
    });
  });

  it('ignores the list pagination params (page/pageSize are not filters)', () => {
    expect(readInitialFilterDsl('?page=2&pageSize=50')).toBeNull();
    expect(
      readInitialFilterDsl('?page=2&pageSize=50&filter[brand][op]=eq&filter[brand][value]=Festo'),
    ).toEqual({ attr: 'brand', op: '=', value: 'Festo' });
  });

  it('returns null for an empty search string', () => {
    expect(readInitialFilterDsl('')).toBeNull();
    expect(readInitialFilterDsl('?')).toBeNull();
  });

  it('decodes the base64 blob flavour (?q=)', () => {
    const dsl = { attr: 'brand', op: '=', value: 'Festo' };
    const blob = btoa(unescape(encodeURIComponent(JSON.stringify(dsl))));
    expect(readInitialFilterDsl(`?q=${blob}`)).toEqual(dsl);
  });

  it('drops nested groups the flat panel cannot represent', () => {
    const nested = {
      operator: 'AND',
      conditions: [
        { attr: 'brand', op: '=', value: 'Festo' },
        { operator: 'OR', conditions: [{ attr: 'enabled', op: '= TRUE' }] },
      ],
    };
    const blob = btoa(unescape(encodeURIComponent(JSON.stringify(nested))));
    expect(readInitialFilterDsl(`?q=${blob}`)).toBeNull();
  });

  it('returns null for a malformed blob instead of throwing', () => {
    expect(readInitialFilterDsl('?q=%%%not-base64%%%')).toBeNull();
  });
});

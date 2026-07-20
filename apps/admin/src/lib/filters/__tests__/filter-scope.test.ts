import { describe, expect, it } from 'vitest';

import { conditionsToDsl, extractScope, type FilterCondition, normalizeScope } from '../filter-dsl';
import { dslToUrlParams, searchStringToDsl, urlParamsToDsl } from '../url-serializer';

/**
 * #2673 — value-context scope (channel/locale) on the shared filter DSL:
 * composition, normalisation and the `fscope[...]` URL round-trip.
 */
describe('filter scope (#2673)', () => {
  const brand: FilterCondition = { attr: 'brand', op: '=', value: 'Festo' };
  const weight: FilterCondition = { attr: 'weight', op: '>', value: 10 };

  it('conditionsToDsl appends a normalised scope to the root', () => {
    const single = conditionsToDsl([brand], 'AND', { channel: 'shopify' });
    expect(single).toEqual({ ...brand, scope: { channel: 'shopify' } });

    const group = conditionsToDsl([brand, weight], 'OR', { channel: 'shopify', locale: 'pl' });
    expect(group).toEqual({
      operator: 'OR',
      conditions: [brand, weight],
      scope: { channel: 'shopify', locale: 'pl' },
    });
  });

  it('empty or blank scope never serialises', () => {
    expect(normalizeScope(null)).toBeNull();
    expect(normalizeScope({})).toBeNull();
    expect(normalizeScope({ channel: '', locale: '  ' })).toBeNull();
    expect(conditionsToDsl([brand], 'AND', {})).toEqual(brand);
  });

  it('extractScope reads the root scope off condition and group roots', () => {
    expect(extractScope({ ...brand, scope: { locale: 'pl' } })).toEqual({ locale: 'pl' });
    expect(extractScope({ operator: 'AND', conditions: [brand], scope: { channel: 'x' } })).toEqual(
      { channel: 'x' },
    );
    expect(extractScope(brand)).toBeNull();
    expect(extractScope(null)).toBeNull();
  });

  it('flat URL round-trips fscope params without leaking a condition', () => {
    const dsl = conditionsToDsl([brand], 'AND', { channel: 'shopify', locale: 'pl' });
    const params = dslToUrlParams(dsl);

    expect(params.get('fscope[channel]')).toBe('shopify');
    expect(params.get('fscope[locale]')).toBe('pl');

    const parsed = urlParamsToDsl(params);
    expect(parsed).toEqual({ ...brand, scope: { channel: 'shopify', locale: 'pl' } });
  });

  it('fscope params never become a bare-shorthand condition', () => {
    const params = new URLSearchParams('fscope[channel]=shopify');
    expect(urlParamsToDsl(params)).toBeNull();
  });

  it('searchStringToDsl restores scope from a flat search string', () => {
    const dsl = conditionsToDsl([brand, weight], 'AND', { locale: 'en' });
    const search = dslToUrlParams(dsl).toString();
    const parsed = searchStringToDsl(search);

    expect(extractScope(parsed)).toEqual({ locale: 'en' });
  });
});

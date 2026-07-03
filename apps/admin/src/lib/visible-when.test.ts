import { describe, expect, it } from 'vitest';

import { isVisibleWhen, parseVisibleWhen } from './visible-when';

/**
 * DP-08 (#2039) — 1:1 mirror of the backend VisibleWhenRuleEvaluator
 * semantics (VisibleWhenRuleTest / UI-08.8 #263): missing field hides,
 * scalars strict-equal, arrays deep-equal regardless of order, envelope
 * shapes unwrap, malformed rules are lenient (always visible).
 */
describe('visible-when', () => {
  const values = (map: Record<string, unknown>) => (code: string) => map[code];

  it('no rule or malformed rule → always visible', () => {
    expect(isVisibleWhen(null, values({}))).toBe(true);
    expect(isVisibleWhen(undefined, values({}))).toBe(true);
    expect(isVisibleWhen({ field: '' }, values({}))).toBe(true);
    expect(isVisibleWhen('garbage', values({}))).toBe(true);
    expect(parseVisibleWhen({ field: 'a', operator: 'equals' })).toBeNull(); // no value key
  });

  it('missing or empty driver value hides the field', () => {
    const rule = { field: 'light_type', operator: 'equals', value: 'bulb' };
    expect(isVisibleWhen(rule, values({}))).toBe(false);
    expect(isVisibleWhen(rule, values({ light_type: null }))).toBe(false);
    expect(isVisibleWhen(rule, values({ light_type: '' }))).toBe(false);
  });

  it('scalar equals compares strictly', () => {
    const rule = { field: 'light_type', operator: 'equals', value: 'bulb' };
    expect(isVisibleWhen(rule, values({ light_type: 'bulb' }))).toBe(true);
    expect(isVisibleWhen(rule, values({ light_type: 'led' }))).toBe(false);

    const boolRule = { field: 'expandable', operator: 'equals', value: true };
    expect(isVisibleWhen(boolRule, values({ expandable: true }))).toBe(true);
    expect(isVisibleWhen(boolRule, values({ expandable: false }))).toBe(false);
  });

  it('unwraps envelope shapes before comparing', () => {
    const rule = { field: 'light_type', operator: 'equals', value: 'bulb' };
    expect(isVisibleWhen(rule, values({ light_type: { value: 'bulb' } }))).toBe(true);
    expect(isVisibleWhen(rule, values({ light_type: { option_code: 'bulb' } }))).toBe(true);

    const multiRule = { field: 'tags', operator: 'equals', value: ['new', 'sale'] };
    expect(isVisibleWhen(multiRule, values({ tags: { option_codes: ['sale', 'new'] } }))).toBe(
      true,
    );
  });

  it('arrays deep-equal regardless of order, length-sensitive', () => {
    const rule = { field: 'tags', operator: 'equals', value: ['a', 'b'] };
    expect(isVisibleWhen(rule, values({ tags: ['b', 'a'] }))).toBe(true);
    expect(isVisibleWhen(rule, values({ tags: ['a'] }))).toBe(false);
    expect(isVisibleWhen(rule, values({ tags: ['a', 'c'] }))).toBe(false);
  });

  it('unknown operators are lenient (forward-compat, mirrors BE)', () => {
    const rule = { field: 'light_type', operator: 'not_equals', value: 'bulb' };
    expect(isVisibleWhen(rule, values({ light_type: 'bulb' }))).toBe(true);
  });
});

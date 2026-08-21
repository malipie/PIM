import { describe, expect, it } from 'vitest';

import { objectNameFromAttributes } from './attributes-indexed';

describe('objectNameFromAttributes', () => {
  it('reads a plain string reading', () => {
    expect(objectNameFromAttributes({ name: { value: 'Stanisław Lem' } })).toBe('Stanisław Lem');
  });

  it('reads a per-locale map, preferring the active locale', () => {
    // #2943 — a localizable `name` arrives as {pl, en}; callers that tested
    // only for `string` fell through to the technical code.
    const reading = { name: { value: { pl: 'Twórcy', en: 'Creators' } } };
    expect(objectNameFromAttributes(reading, 'en')).toBe('Creators');
    expect(objectNameFromAttributes(reading, 'pl-PL')).toBe('Twórcy');
  });

  it('falls back across locales when the active one is missing', () => {
    expect(objectNameFromAttributes({ name: { value: { en: 'Creators' } } }, 'de')).toBe(
      'Creators',
    );
  });

  it('returns null when there is nothing usable, so the caller picks the fallback', () => {
    expect(objectNameFromAttributes(null)).toBeNull();
    expect(objectNameFromAttributes({})).toBeNull();
    expect(objectNameFromAttributes({ name: { value: '   ' } })).toBeNull();
    expect(objectNameFromAttributes({ name: { value: {} } })).toBeNull();
  });
});

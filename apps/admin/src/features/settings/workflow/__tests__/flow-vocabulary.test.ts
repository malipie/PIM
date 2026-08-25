import { describe, expect, it } from 'vitest';

import {
  isAdvancedPermission,
  isCanonicalTransition,
  permissionLabel,
  slugify,
  transitionNameFor,
  uniqueSlug,
} from '../flow-vocabulary';

describe('slugify', () => {
  it('folds Polish diacritics and joins words with underscores', () => {
    expect(slugify('W przeglądzie')).toBe('w_przegladzie');
    expect(slugify('Zatwierdzony')).toBe('zatwierdzony');
    expect(slugify('Wstrzymany — czeka na zdjęcia')).toBe('wstrzymany_czeka_na_zdjecia');
  });

  it('produces a name the backend validator accepts', () => {
    const validator = /^[a-z][a-z0-9_]{0,31}$/;
    for (const label of ['Szkic', 'Gotowe do 2. korekty', 'ŻÓŁW', 'W przeglądzie']) {
      expect(slugify(label)).toMatch(validator);
    }
  });

  it('prefixes a leading digit, because the validator demands a letter first', () => {
    expect(slugify('2 korekta')).toBe('x2_korekta');
  });

  it('truncates without leaving a trailing underscore', () => {
    const slug = slugify('Bardzo długa nazwa etapu która nie zmieści się w limicie');
    expect(slug.length).toBeLessThanOrEqual(32);
    expect(slug.endsWith('_')).toBe(false);
  });

  it('returns an empty string for input with nothing to slug', () => {
    expect(slugify('   ')).toBe('');
    expect(slugify('!!!')).toBe('');
  });
});

describe('uniqueSlug', () => {
  it('suffixes collisions instead of merging two states into one', () => {
    expect(uniqueSlug('Wstrzymany', [])).toBe('wstrzymany');
    expect(uniqueSlug('Wstrzymany', ['wstrzymany'])).toBe('wstrzymany_2');
    expect(uniqueSlug('Wstrzymany', ['wstrzymany', 'wstrzymany_2'])).toBe('wstrzymany_3');
  });

  it('keeps the suffixed name within the length limit', () => {
    const taken = ['abcdefghij_abcdefghij_abcdefghij'];
    const slug = uniqueSlug('abcdefghij abcdefghij abcdefghij', taken);
    expect(slug.length).toBeLessThanOrEqual(32);
    expect(taken).not.toContain(slug);
  });
});

describe('transitionNameFor', () => {
  // The regression this guards: task automation and the event recorder
  // match on canonical names only (`default => null`), so a slugified
  // "Zgłoś do przeglądu" would ship a flow whose review step creates no
  // task and sends no notification.
  it('keeps built-in steps on their canonical names', () => {
    expect(transitionNameFor('Zgłoś do przeglądu')).toBe('submit_for_review');
    expect(transitionNameFor('Zatwierdź')).toBe('approve');
    expect(transitionNameFor('Odrzuć')).toBe('reject');
    expect(transitionNameFor('Opublikuj')).toBe('publish');
    expect(transitionNameFor('Cofnij publikację')).toBe('unpublish');
    expect(transitionNameFor('Archiwizuj')).toBe('archive');
    expect(transitionNameFor('Przywróć')).toBe('restore');
  });

  it('matches the canonical label regardless of case and padding', () => {
    expect(transitionNameFor('  zatwierdź  ')).toBe('approve');
    expect(transitionNameFor('APPROVE')).toBe('approve');
  });

  it('slugifies anything else as a custom step', () => {
    expect(transitionNameFor('Przekaż do publikacji')).toBe('przekaz_do_publikacji');
    expect(isCanonicalTransition('przekaz_do_publikacji')).toBe(false);
    expect(isCanonicalTransition('approve')).toBe(true);
  });

  it('does not hand out a canonical name that is already used', () => {
    expect(transitionNameFor('Zatwierdź', ['approve'])).toBe('zatwierdz');
  });
});

describe('permissionLabel', () => {
  it('names the known codes the way people talk about them', () => {
    expect(permissionLabel('workflow.approve_reject', 'pl')).toBe('Akceptant');
    expect(permissionLabel('workflow.approve_reject', 'en')).toBe('Approver');
    expect(permissionLabel('exports.run', 'pl')).toBe('Eksporty i integracje');
  });

  it('falls back to the raw code, so custom permissions stay usable', () => {
    expect(permissionLabel('tenant.delete', 'pl')).toBe('tenant.delete');
    expect(isAdvancedPermission('tenant.delete')).toBe(true);
    expect(isAdvancedPermission('products.edit')).toBe(false);
    expect(isAdvancedPermission('')).toBe(false);
  });
});

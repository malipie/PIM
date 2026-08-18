import { describe, expect, it } from 'vitest';

import { instanceUrl, subdomainShapeError, suggestSubdomain } from './subdomain';

describe('kształt subdomeny', () => {
  it('przyjmuje poprawne etykiety', () => {
    for (const value of ['acme', 'acme-pl', 'acme2026', 'abc']) {
      expect(subdomainShapeError(value)).toBeNull();
    }
  });

  it('rozróżnia powody odrzucenia, żeby komunikat mówił co poprawić', () => {
    expect(subdomainShapeError('')).toBe('empty');
    expect(subdomainShapeError('ab')).toBe('too_short');
    expect(subdomainShapeError('a'.repeat(33))).toBe('too_long');
    expect(subdomainShapeError('acme_pl')).toBe('charset');
    expect(subdomainShapeError('acme.pl')).toBe('charset');
    expect(subdomainShapeError('-acme')).toBe('edges');
    expect(subdomainShapeError('acme-')).toBe('edges');
  });

  it('normalizuje wielkość liter i białe znaki', () => {
    expect(subdomainShapeError('  ACME  ')).toBeNull();
  });
});

describe('podpowiedź z kodu tenanta', () => {
  it('zamienia podkreślenia na myślniki, bo kod je dopuszcza a subdomena nie', () => {
    expect(suggestSubdomain('acme_corp')).toBe('acme-corp');
  });

  it('obcina znaki niedozwolone i myślniki na brzegach', () => {
    expect(suggestSubdomain('__Acme Corp!__')).toBe('acme-corp');
  });

  it('przycina do 32 znaków', () => {
    expect(suggestSubdomain('a'.repeat(40))).toHaveLength(32);
  });
});

describe('adres instancji', () => {
  it('składa pełny adres pokazywany pod polem', () => {
    expect(instanceUrl('acme', 'app.harmonpim.pl')).toBe('https://acme.app.harmonpim.pl');
  });
});

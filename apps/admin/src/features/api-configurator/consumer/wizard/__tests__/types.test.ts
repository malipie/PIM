import { describe, expect, it } from 'vitest';

import {
  connectionToForm,
  credentialsFor,
  hasCredentialInput,
  headersFor,
  INITIAL_FORM,
  slugify,
  toConnectionInput,
  toConnectionPatch,
  type WizardForm,
} from '../types';

describe('wizard helpers', () => {
  it('slugifies a name to the backend code shape (^[a-z0-9-]+$)', () => {
    expect(slugify('Nexar Components!')).toBe('nexar-components');
    expect(slugify('  Acme / EU  ')).toBe('acme-eu');
    expect(slugify('ÜPER—weird__name')).toBe('per-weird-name');
  });

  it('folds credentials per auth scheme', () => {
    const form: WizardForm = {
      ...INITIAL_FORM,
      authType: 'api_key',
      apiKeyHeader: 'X-Key',
      apiKeyValue: 'secret',
    };
    expect(credentialsFor(form)).toEqual({ header: 'X-Key', value: 'secret' });
    expect(credentialsFor({ ...form, authType: 'bearer', bearer: 'tok' })).toEqual({
      token: 'tok',
    });
    expect(credentialsFor({ ...form, authType: 'oauth2_token', oauthToken: 'o' })).toEqual({
      token: 'o',
    });
    expect(credentialsFor({ ...form, authType: 'basic', basicUser: 'u', basicPass: 'p' })).toEqual({
      user: 'u',
      pass: 'p',
    });
    expect(credentialsFor({ ...form, authType: 'none' })).toEqual({});
  });

  it('drops header rows with an empty key', () => {
    const form: WizardForm = {
      ...INITIAL_FORM,
      headers: [
        { k: 'Accept', v: 'application/json' },
        { k: '  ', v: 'ignored' },
        { k: 'X-Trace', v: '1' },
      ],
    };
    expect(headersFor(form)).toEqual({ Accept: 'application/json', 'X-Trace': '1' });
  });

  it('nulls rateLimitHint when blank or non-positive', () => {
    expect(toConnectionInput({ ...INITIAL_FORM, rateLimit: '' }).rateLimitHint).toBeNull();
    expect(toConnectionInput({ ...INITIAL_FORM, rateLimit: '0' }).rateLimitHint).toBeNull();
    expect(toConnectionInput({ ...INITIAL_FORM, rateLimit: '600' }).rateLimitHint).toBe(600);
  });

  it('builds a ConnectionInput body that matches the API contract', () => {
    const form: WizardForm = {
      ...INITIAL_FORM,
      name: '  Shopify EU ',
      code: 'shopify-eu',
      baseUrl: ' https://eu.shopify.example/api ',
      authType: 'bearer',
      bearer: 'tok',
    };
    expect(toConnectionInput(form)).toEqual({
      code: 'shopify-eu',
      name: 'Shopify EU',
      baseUrl: 'https://eu.shopify.example/api',
      authType: 'bearer',
      credentials: { token: 'tok' },
      defaultHeaders: { Accept: 'application/json' },
      rateLimitHint: 600,
    });
  });

  // ── #2630 — edit mode helpers ──────────────────────────────────────

  it('detects whether the user typed a secret for the selected scheme', () => {
    expect(hasCredentialInput({ ...INITIAL_FORM, authType: 'api_key', apiKeyValue: '' })).toBe(
      false,
    );
    expect(hasCredentialInput({ ...INITIAL_FORM, authType: 'api_key', apiKeyValue: 'k' })).toBe(
      true,
    );
    expect(hasCredentialInput({ ...INITIAL_FORM, authType: 'bearer', bearer: ' ' })).toBe(false);
    expect(hasCredentialInput({ ...INITIAL_FORM, authType: 'basic', basicPass: 'p' })).toBe(true);
    expect(hasCredentialInput({ ...INITIAL_FORM, authType: 'none' })).toBe(false);
  });

  it('PATCH body omits the immutable code and blank credentials', () => {
    const form: WizardForm = {
      ...INITIAL_FORM,
      name: 'Edited',
      code: 'immutable-code',
      baseUrl: 'https://api.example.com',
      authType: 'bearer',
      bearer: '',
    };
    const patch = toConnectionPatch(form);
    expect(patch).not.toHaveProperty('code');
    expect(patch).not.toHaveProperty('credentials');
    expect(patch).toMatchObject({ name: 'Edited', baseUrl: 'https://api.example.com' });
  });

  it('PATCH body carries credentials only when new ones were typed', () => {
    const form: WizardForm = {
      ...INITIAL_FORM,
      authType: 'bearer',
      bearer: 'fresh-token',
    };
    expect(toConnectionPatch(form)).toMatchObject({ credentials: { token: 'fresh-token' } });
  });

  it('prefills the form from a persisted connection, keeping secrets blank', () => {
    const form = connectionToForm({
      code: 'shopify-eu',
      name: 'Shopify EU',
      baseUrl: 'https://eu.shopify.example/api',
      authType: 'api_key',
      defaultHeaders: { Accept: 'application/json', 'X-Trace': '1' },
      rateLimitHint: 120,
    });
    expect(form.name).toBe('Shopify EU');
    expect(form.code).toBe('shopify-eu');
    expect(form.baseUrl).toBe('https://eu.shopify.example/api');
    expect(form.authType).toBe('api_key');
    expect(form.headers).toEqual([
      { k: 'Accept', v: 'application/json' },
      { k: 'X-Trace', v: '1' },
    ]);
    expect(form.rateLimit).toBe('120');
    expect(form.apiKeyValue).toBe('');
    expect(form.bearer).toBe('');
  });

  it('prefill falls back to an empty header row and blank rate limit', () => {
    const form = connectionToForm({
      code: 'c',
      name: 'C',
      baseUrl: 'https://x',
      authType: 'none',
      defaultHeaders: null,
      rateLimitHint: null,
    });
    expect(form.headers).toEqual([{ k: '', v: '' }]);
    expect(form.rateLimit).toBe('');
  });
});

import { describe, expect, it } from 'vitest';

import { HttpError, httpErrorDetail } from '@/lib/http';

/**
 * #2739 — three call sites (grid commit, variant expand, cross-page select)
 * toasted `err.message`, which for an HttpError is the bare "HTTP 422". The
 * fix routes them through httpErrorDetail so the operator sees the backend's
 * RFC 7807 reason; these cases pin the helper's contract the sites rely on.
 */
describe('httpErrorDetail', () => {
  it('returns the RFC 7807 detail instead of the bare status message', () => {
    const error = new HttpError(422, {
      type: 'about:blank',
      title: 'Unprocessable Entity',
      status: 422,
      detail: 'Wartość nie przechodzi walidacji atrybutu.',
    });

    expect(error.message).toBe('HTTP 422');
    expect(httpErrorDetail(error)).toBe('Wartość nie przechodzi walidacji atrybutu.');
  });

  it('returns null when the body carries no string detail, so callers fall back to i18n', () => {
    expect(httpErrorDetail(new HttpError(500, null))).toBeNull();
    expect(httpErrorDetail(new HttpError(500, { title: 'Server Error' }))).toBeNull();
    expect(httpErrorDetail(new HttpError(500, { detail: 42 }))).toBeNull();
    expect(httpErrorDetail(new Error('boom'))).toBeNull();
    expect(httpErrorDetail('not an error')).toBeNull();
  });
});

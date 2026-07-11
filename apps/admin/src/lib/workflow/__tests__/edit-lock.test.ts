import { describe, expect, it } from 'vitest';

import { computeEditLock } from '../edit-lock';

/**
 * WFL-P3-03 (#2425) — the PRD §3.8 edit-lock matrix as a pure function.
 */
const perms = (...codes: string[]) => new Set(codes) as ReadonlySet<string>;

describe('computeEditLock', () => {
  it('draft is editable without any lock', () => {
    expect(computeEditLock('draft', perms('products.edit'))).toMatchObject({
      locked: false,
      reason: null,
    });
  });

  it('review locks plain editors and admits reviewers', () => {
    expect(computeEditLock('review', perms('products.edit')).locked).toBe(true);
    expect(computeEditLock('review', perms('workflow.edit_in_review')).locked).toBe(false);
    expect(computeEditLock('review', perms('workflow.edit_any_state')).locked).toBe(false);
  });

  it('published: edit_any_state edits in place, unpublish holders auto-unpublish, others request', () => {
    expect(computeEditLock('published', perms('workflow.edit_any_state'))).toMatchObject({
      locked: false,
      autoUnpublishOnSave: false,
    });
    expect(
      computeEditLock('published', perms('products.edit', 'workflow.transition.unpublish')),
    ).toMatchObject({ locked: false, autoUnpublishOnSave: true });
    expect(computeEditLock('published', perms('products.edit'))).toMatchObject({
      locked: true,
      canRequestUnpublish: true,
    });
  });

  it('archived is read-only for everyone', () => {
    expect(
      computeEditLock('archived', perms('workflow.edit_any_state', 'products.edit')).locked,
    ).toBe(true);
  });
});

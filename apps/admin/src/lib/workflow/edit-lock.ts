import { jsonFetch } from '@/lib/http';

/**
 * WFL-P3-03 (#2425) — PRD §3.8 edit-lock semantics as a pure function
 * so the banner and the save gating share one source of truth (and the
 * matrix is unit-testable without a DOM):
 *
 *   - draft      → editable with products.edit,
 *   - review     → workflow.edit_in_review or workflow.edit_any_state,
 *   - published  → edit_any_state edits in place; transition.unpublish
 *                  edits WITH the auto-unpublish side effect (the
 *                  backend applies it atomically, WFL-P1-03); anyone
 *                  else is locked with "Request unpublish",
 *   - archived   → read-only for everyone (restore is a transition).
 */
export type EditLockReason = 'review' | 'published' | 'archived' | null;

export interface EditLock {
  locked: boolean;
  reason: EditLockReason;
  /** Published + transition.unpublish: saving will auto-unpublish. */
  autoUnpublishOnSave: boolean;
  /** Published + locked: offer the request-unpublish flow. */
  canRequestUnpublish: boolean;
}

export function computeEditLock(place: string, permissions: ReadonlySet<string>): EditLock {
  const editAnywhere = permissions.has('workflow.edit_any_state');

  switch (place) {
    case 'review':
      return {
        locked: !(editAnywhere || permissions.has('workflow.edit_in_review')),
        reason: 'review',
        autoUnpublishOnSave: false,
        canRequestUnpublish: false,
      };
    case 'published': {
      if (editAnywhere) {
        return {
          locked: false,
          reason: null,
          autoUnpublishOnSave: false,
          canRequestUnpublish: false,
        };
      }
      if (permissions.has('workflow.transition.unpublish') && permissions.has('products.edit')) {
        return {
          locked: false,
          reason: 'published',
          autoUnpublishOnSave: true,
          canRequestUnpublish: false,
        };
      }
      return {
        locked: true,
        reason: 'published',
        autoUnpublishOnSave: false,
        canRequestUnpublish: true,
      };
    }
    case 'archived':
      return {
        locked: true,
        reason: 'archived',
        autoUnpublishOnSave: false,
        canRequestUnpublish: false,
      };
    default:
      return {
        locked: false,
        reason: null,
        autoUnpublishOnSave: false,
        canRequestUnpublish: false,
      };
  }
}

export function requestUnpublish(
  objectId: string,
  comment?: string,
): Promise<{ object_id: string; requested: boolean }> {
  return jsonFetch(`/api/objects/${objectId}/workflow/request-unpublish`, {
    method: 'POST',
    contentType: 'application/json',
    body: comment !== undefined && comment !== '' ? { comment } : {},
  });
}

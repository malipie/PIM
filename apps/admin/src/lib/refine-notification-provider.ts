import type { NotificationProvider } from '@refinedev/core';

import { toast } from '@/components/ui/toast';

/**
 * #2724 — global Refine notification provider on the project toast.
 *
 * Without a provider, a Refine mutation without a local `onError` failed in
 * COMPLETE silence (the rotate-webhook-secret dead button). This maps Refine's
 * notification calls onto the shared toast so every data hook gets a visible
 * error baseline; views that render their own inline error state should pass
 * `errorNotification: false` to avoid double-reporting.
 *
 * `progress` (undoable mutations) is intentionally a no-op — the project does
 * not use undoable mutations, and a countdown toast would be noise.
 */
export const notificationProvider: NotificationProvider = {
  open: ({ type, message, description }) => {
    if (type === 'progress') return;
    const text =
      typeof description === 'string' && description !== '' && description !== message
        ? `${message}: ${description}`
        : message;
    if (type === 'error') {
      toast.error(text);
      return;
    }
    toast.success(text);
  },
  close: () => {
    // The project toast auto-dismisses; per-key dismissal is not supported.
  },
};

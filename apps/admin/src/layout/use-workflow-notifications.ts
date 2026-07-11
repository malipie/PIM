import { useCallback, useEffect, useState } from 'react';

import { useIdentity } from '@/lib/identity';
import { ensureMercureAuthorization, mercureSubscribeUrl, mercureTenantTopic } from '@/lib/mercure';
import {
  fetchNotifications,
  markAllNotificationsRead,
  type PersistentNotification,
} from '@/lib/notifications/api';

/**
 * WFL-P2-03 (#2422) — persistent workflow notifications for the bell:
 * the initial page comes from `/api/notifications` (survives reloads,
 * unlike the ephemeral activity feed next to it), live entries arrive
 * on the per-user Mercure topic
 * (`tenant/<tid>/users/<uid>/notifications`, WFL-P2-02) and read
 * receipts go back through `read-all` when the dropdown opens.
 *
 * Connection failure is silent — same contract as useNotifications:
 * Mercure may be down in dev, the REST page still renders.
 */
export interface UseWorkflowNotificationsState {
  entries: PersistentNotification[];
  unreadCount: number;
  markAllRead: () => void;
}

const MAX_ENTRIES = 20;

export function useWorkflowNotifications(): UseWorkflowNotificationsState {
  const [entries, setEntries] = useState<PersistentNotification[]>([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const { identity } = useIdentity();
  const tenantId = identity?.tenant?.id ?? null;
  const userId = identity?.id ?? null;

  useEffect(() => {
    let cancelled = false;
    if (userId === null) return;

    fetchNotifications(MAX_ENTRIES)
      .then((page) => {
        if (cancelled) return;
        setEntries(page.items);
        setUnreadCount(page.unread_count);
      })
      .catch(() => {
        // Silent: the bell is a convenience surface; auth/permission
        // errors surface on the primary screens.
      });

    return () => {
      cancelled = true;
    };
  }, [userId]);

  useEffect(() => {
    if (typeof window === 'undefined' || typeof EventSource === 'undefined') return;
    if (tenantId === null || userId === null) return;

    const topic = mercureTenantTopic(tenantId, 'users', userId, 'notifications');
    let source: EventSource | null = null;
    let disposed = false;

    void ensureMercureAuthorization().then(() => {
      if (disposed) return;
      source = new EventSource(mercureSubscribeUrl(topic), { withCredentials: true });
      source.onmessage = (event: MessageEvent<string>) => {
        try {
          const parsed = JSON.parse(event.data) as PersistentNotification;
          if (typeof parsed.id !== 'string' || typeof parsed.type !== 'string') return;
          setEntries((previous) => {
            if (previous.some((entry) => entry.id === parsed.id)) return previous;
            return [{ ...parsed, read_at: null }, ...previous].slice(0, MAX_ENTRIES);
          });
          setUnreadCount((previous) => previous + 1);
        } catch {
          // Malformed frame — ignore; the next REST load reconciles.
        }
      };
    });

    return () => {
      disposed = true;
      source?.close();
    };
  }, [tenantId, userId]);

  const markAllRead = useCallback(() => {
    if (unreadCount === 0) return;
    setUnreadCount(0);
    setEntries((previous) =>
      previous.map((entry) =>
        entry.read_at === null ? { ...entry, read_at: new Date().toISOString() } : entry,
      ),
    );
    void markAllNotificationsRead().catch(() => {
      // Optimistic zeroing stays; the next REST load reconciles.
    });
  }, [unreadCount]);

  return { entries, unreadCount, markAllRead };
}

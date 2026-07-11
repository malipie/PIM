import { jsonFetch } from '@/lib/http';

/**
 * WFL-P2-03 (#2422) — typed client for the persistent notification
 * surface (`/api/notifications`, WFL-P2-02). The admin uses exactly the
 * endpoints integrators use (ADR-0020).
 */

export interface PersistentNotification {
  id: string;
  type: string;
  payload: Record<string, unknown> | null;
  read_at: string | null;
  created_at: string;
}

export interface NotificationsPage {
  items: PersistentNotification[];
  unread_count: number;
  next_cursor: string | null;
}

export function fetchNotifications(limit = 20): Promise<NotificationsPage> {
  return jsonFetch<NotificationsPage>(`/api/notifications?limit=${limit}`);
}

export function markAllNotificationsRead(): Promise<{ marked_read: number }> {
  return jsonFetch<{ marked_read: number }>('/api/notifications/read-all', { method: 'POST' });
}

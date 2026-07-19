import { useDelete } from '@refinedev/core';
import { Pencil, Plug, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';

import {
  AuthBadge,
  type AuthType,
  type ConnectionStatus,
  ConnStatusPill,
} from '../components/primitives';

export interface ConnectionRow {
  id: string;
  code: string;
  name: string;
  baseUrl: string;
  authType: AuthType;
  rateLimitHint: number | null;
  status: ConnectionStatus;
  lastHealthCheckAt: string | null;
  createdAt: string;
  updatedAt: string;
}

const DETAIL_BASE = '/integrations/api-configurator/connections';

/**
 * APIC-P1-07 — a connection card on the consumer hub. Shows the Connection's own
 * fields (name, base URL, status, auth, last health check). The whole card is a
 * stretched link into the detail (overview/endpoints/mapping/sync/history tabs);
 * a delete action with a confirm dialog sits above the link via z-index.
 */
export function ConnectionCard({ connection }: { connection: ConnectionRow }) {
  const { t, i18n } = useTranslation();
  const { mutate: remove, mutation } = useDelete();
  const [confirmOpen, setConfirmOpen] = useState(false);

  const detailHref = `${DETAIL_BASE}/${connection.id}`;

  const checkedAt =
    connection.lastHealthCheckAt !== null
      ? new Date(connection.lastHealthCheckAt).toLocaleString(i18n.language)
      : t('api_configurator.hub.never_checked');

  const iconCls =
    connection.status === 'error'
      ? 'bg-rose-50 text-rose-600'
      : connection.status === 'paused'
        ? 'bg-zinc-100 text-zinc-400'
        : 'bg-zinc-900 text-white';

  function confirmDelete(): void {
    remove(
      { resource: 'connections', id: connection.id, successNotification: false },
      { onSuccess: () => setConfirmOpen(false) },
    );
  }

  return (
    <div className="soft-shadow relative rounded-2xl border border-zinc-200 bg-white p-5 transition hover:border-zinc-300">
      {/* Stretched link: clicking anywhere on the card opens the detail. The
          delete control sits above it (z-index) so it stays independently
          clickable without nesting a button inside the anchor (a11y). */}
      <Link
        to={detailHref}
        aria-label={t('api_configurator.hub.open_connection', { name: connection.name })}
        className="focus-ring absolute inset-0 z-[1] rounded-2xl"
      />

      <div className="flex items-start gap-3">
        <div className={cn('grid h-11 w-11 shrink-0 place-items-center rounded-xl', iconCls)}>
          <Plug className="size-5" aria-hidden />
        </div>
        <div className="min-w-0 flex-1">
          <div className="flex items-center gap-2">
            <span className="truncate text-[15px] font-semibold tracking-tight">
              {connection.name}
            </span>
            <span className="font-mono text-[11px] text-zinc-400">{connection.code}</span>
          </div>
          <div className="mt-1 truncate font-mono text-[11.5px] text-zinc-500">
            {connection.baseUrl}
          </div>
        </div>
        <div className="relative z-[2] flex items-center gap-1.5">
          <ConnStatusPill
            status={connection.status}
            label={t(`api_configurator.hub.status.${connection.status}`)}
          />
          <Link
            to={`${DETAIL_BASE}/${connection.id}/edit`}
            aria-label={t('api_configurator.hub.edit', { name: connection.name })}
            className="focus-ring grid size-8 place-items-center rounded-lg text-zinc-500 transition hover:bg-zinc-100 hover:text-zinc-900"
          >
            <Pencil className="size-4" aria-hidden />
          </Link>
          <button
            type="button"
            onClick={() => setConfirmOpen(true)}
            aria-label={t('api_configurator.hub.delete', { name: connection.name })}
            className="focus-ring grid size-8 place-items-center rounded-lg text-zinc-500 transition hover:bg-rose-50 hover:text-rose-600"
          >
            <Trash2 className="size-4" aria-hidden />
          </button>
        </div>
      </div>

      <div className="mt-4 grid grid-cols-2 gap-x-5 gap-y-3 text-[12px]">
        <div>
          <div className="text-[10px] uppercase tracking-wider text-zinc-500">
            {t('api_configurator.hub.auth')}
          </div>
          <div className="mt-1">
            <AuthBadge type={connection.authType} />
          </div>
        </div>
        <div>
          <div className="text-[10px] uppercase tracking-wider text-zinc-500">
            {t('api_configurator.hub.last_check')}
          </div>
          <div className="mt-1 text-zinc-700">{checkedAt}</div>
        </div>
      </div>

      <Dialog
        open={confirmOpen}
        onOpenChange={(next) => (!next ? setConfirmOpen(false) : undefined)}
      >
        <DialogContent>
          <div className="space-y-2">
            <DialogTitle>{t('api_configurator.hub.delete_confirm_title')}</DialogTitle>
            <DialogDescription>
              {t('api_configurator.hub.delete_confirm_body', { name: connection.name })}
            </DialogDescription>
          </div>
          <div className="mt-4 flex justify-end gap-2">
            <Button variant="ghost" onClick={() => setConfirmOpen(false)}>
              {t('app.cancel')}
            </Button>
            <Button variant="destructive" disabled={mutation.isPending} onClick={confirmDelete}>
              {t('api_configurator.hub.delete_submit')}
            </Button>
          </div>
        </DialogContent>
      </Dialog>
    </div>
  );
}

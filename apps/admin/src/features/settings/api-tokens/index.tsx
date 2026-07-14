import { useList } from '@refinedev/core';
import { KeyRound, MoreHorizontal, Plus } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableHeader,
  TableRow,
} from '@/components/ui/table';
import { cn } from '@/lib/utils';

import { CreateTokenWizard } from './CreateTokenWizard';
import { RevokeTokenModal } from './RevokeTokenModal';
import type { ApiTokenListItem } from './types';

type Scope = 'own' | 'tenant';

/**
 * RBAC-P5-009 (#699) — Settings → API tokens list.
 *
 * Two scope toggles share one endpoint:
 *   - "own" (default) — `GET /api/api-tokens` returns the caller's own
 *     tokens.
 *   - "tenant" — caller with `api_tokens.all.view_revoke` can flip to
 *     the tenant-wide view via `?scope=tenant`. Non-privileged callers
 *     get the same response as "own" — the backend silently downgrades
 *     so the UI never shows a permission-denied flash mid-render.
 *
 * Action affordances (create wizard, revoke modal) ship disabled with
 * pending hints — wiring lands in #700 / #701. The page surface
 * (table, scope toggle, status badges, last-used cell) is the static
 * substrate they layer onto.
 */
export function ApiTokensSettingsPage() {
  const { t, i18n } = useTranslation();
  const [scope, setScope] = useState<Scope>('own');

  const { result, query } = useList<ApiTokenListItem>({
    resource: 'api-tokens',
    pagination: { mode: 'off' },
    filters: scope === 'tenant' ? [{ field: 'scope', operator: 'eq', value: 'tenant' }] : [],
  });
  const tokens: ApiTokenListItem[] = result?.data ?? [];
  const isLoading = query.isLoading;
  const isError = query.isError;
  const refetch = query.refetch;

  const [revokeTarget, setRevokeTarget] = useState<ApiTokenListItem | null>(null);
  const [revokeOpen, setRevokeOpen] = useState(false);
  const openRevoke = (token: ApiTokenListItem) => {
    setRevokeTarget(token);
    setRevokeOpen(true);
  };
  const [createOpen, setCreateOpen] = useState(false);

  return (
    <div className="space-y-4">
      <header className="flex items-start justify-between gap-4">
        <div className="space-y-1">
          <h2 className="display text-xl font-semibold tracking-tight">
            {t('settings.api_tokens.title')}
          </h2>
          <p className="max-w-2xl text-sm text-muted-foreground">
            {t('settings.api_tokens.intro')}
          </p>
        </div>
        <Button size="sm" className="gap-1.5" onClick={() => setCreateOpen(true)}>
          <Plus className="size-4" aria-hidden="true" />
          {t('settings.api_tokens.create_cta')}
        </Button>
      </header>

      {/* #2573 — spell out what API tokens are for; the page title alone left
          operators unsure whether the feature does anything. */}
      <div className="flex gap-3 rounded-lg border border-zinc-200 bg-zinc-50/70 px-4 py-3 text-[13px] text-zinc-600">
        <KeyRound className="mt-0.5 size-4 shrink-0 text-zinc-500" aria-hidden="true" />
        <div className="space-y-1.5">
          <p>
            {t('settings.api_tokens.help_purpose', {
              defaultValue:
                'Tokeny API pozwalają integracjom, skryptom i systemom zewnętrznym wywoływać to samo API co panel — bez logowania e-mailem. Każdy token ma własne uprawnienia (scopes) i można go w każdej chwili unieważnić.',
            })}
          </p>
          <p className="text-zinc-500">
            {t('settings.api_tokens.help_usage', { defaultValue: 'Użycie w nagłówku żądania:' })}{' '}
            <code className="rounded bg-white px-1.5 py-0.5 font-mono text-[12px] text-zinc-700 ring-1 ring-zinc-200">
              Authorization: Token cortex_…
            </code>
          </p>
        </div>
      </div>

      <div className="inline-flex rounded-md border bg-background p-0.5">
        <ScopeButton
          active={scope === 'own'}
          label={t('settings.api_tokens.scope_own')}
          onClick={() => setScope('own')}
        />
        <ScopeButton
          active={scope === 'tenant'}
          label={t('settings.api_tokens.scope_tenant')}
          onClick={() => setScope('tenant')}
        />
      </div>

      <div className="overflow-hidden rounded-lg border bg-background shadow-sm">
        <Table>
          <TableHeader>
            <TableRow>
              <TableHead className="pl-5">{t('settings.api_tokens.col_name')}</TableHead>
              {scope === 'tenant' ? (
                <TableHead>{t('settings.api_tokens.col_owner')}</TableHead>
              ) : null}
              <TableHead>{t('settings.api_tokens.col_scopes')}</TableHead>
              <TableHead>{t('settings.api_tokens.col_status')}</TableHead>
              <TableHead>{t('settings.api_tokens.col_last_used')}</TableHead>
              <TableHead>{t('settings.api_tokens.col_expires')}</TableHead>
              <TableHead
                className="pr-5 text-right"
                aria-label={t('settings.api_tokens.col_actions')}
              />
            </TableRow>
          </TableHeader>
          <TableBody>
            {isError && (
              <TableRow>
                <TableCell colSpan={7} className="py-8 text-center text-sm text-rose-600">
                  {t('settings.api_tokens.error_loading')}
                </TableCell>
              </TableRow>
            )}
            {!isError && isLoading && tokens.length === 0 && <SkeletonRows scope={scope} />}
            {!isError && !isLoading && tokens.length === 0 && (
              <TableRow>
                <TableCell colSpan={7} className="py-12 text-center text-sm text-muted-foreground">
                  {t('settings.api_tokens.empty')}
                </TableCell>
              </TableRow>
            )}
            {tokens.map((token) => (
              <TokenRow
                key={token.id}
                token={token}
                scope={scope}
                locale={i18n.language}
                onRevoke={openRevoke}
              />
            ))}
          </TableBody>
        </Table>
      </div>

      <RevokeTokenModal
        token={revokeTarget}
        open={revokeOpen}
        onOpenChange={setRevokeOpen}
        onSuccess={() => {
          void refetch();
        }}
      />

      <CreateTokenWizard
        open={createOpen}
        onOpenChange={setCreateOpen}
        onSuccess={() => {
          void refetch();
        }}
      />
    </div>
  );
}

function ScopeButton({
  active,
  label,
  onClick,
}: {
  active: boolean;
  label: string;
  onClick: () => void;
}) {
  return (
    <button
      type="button"
      onClick={onClick}
      className={cn(
        'rounded-md px-3 py-1.5 text-xs font-medium transition-colors',
        active ? 'bg-foreground text-background' : 'text-muted-foreground hover:bg-muted',
      )}
    >
      {label}
    </button>
  );
}

function TokenRow({
  token,
  scope,
  locale,
  onRevoke,
}: {
  token: ApiTokenListItem;
  scope: Scope;
  locale: string;
  onRevoke: (token: ApiTokenListItem) => void;
}) {
  const { t } = useTranslation();
  const lastUsed = token.last_used_at
    ? new Date(token.last_used_at).toLocaleString(locale)
    : t('settings.api_tokens.last_used_never');
  const expires = token.expires_at
    ? new Date(token.expires_at).toLocaleDateString(locale)
    : t('settings.api_tokens.expires_never');

  return (
    <TableRow className={cn(token.status !== 'active' && 'opacity-60')}>
      <TableCell className="pl-5">
        <div className="flex items-center gap-3">
          <span
            className="inline-grid size-8 place-items-center rounded-md bg-orange-500/10 text-orange-700"
            aria-hidden="true"
          >
            <KeyRound className="size-4" />
          </span>
          <div className="min-w-0">
            <div className="text-sm font-medium">{token.name}</div>
            <div className="font-mono text-[11px] text-muted-foreground">
              ···{token.token_last4}
            </div>
          </div>
        </div>
      </TableCell>
      {scope === 'tenant' ? (
        <TableCell className="text-xs text-muted-foreground">{token.owner_email ?? '—'}</TableCell>
      ) : null}
      <TableCell>
        <div className="flex flex-wrap gap-1">
          {token.scopes.length === 0 ? (
            <span className="text-xs text-muted-foreground">
              {t('settings.api_tokens.no_scopes')}
            </span>
          ) : (
            token.scopes.map((s) => (
              <span
                key={s}
                className="inline-flex items-center rounded-md bg-orange-50 px-2 py-0.5 text-[11px] font-medium text-orange-700 ring-1 ring-orange-200"
              >
                {s}
              </span>
            ))
          )}
        </div>
      </TableCell>
      <TableCell>
        <StatusBadge status={token.status} />
      </TableCell>
      <TableCell className="text-xs text-muted-foreground">{lastUsed}</TableCell>
      <TableCell className="text-xs text-muted-foreground">{expires}</TableCell>
      <TableCell className="pr-5 text-right">
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button
              variant="ghost"
              size="icon"
              aria-label={t('settings.api_tokens.row_actions')}
              disabled={token.status !== 'active'}
            >
              <MoreHorizontal className="size-4" />
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end">
            <DropdownMenuItem
              onSelect={() => onRevoke(token)}
              className="text-rose-600 focus:text-rose-700"
            >
              {t('settings.api_tokens.action_revoke')}
            </DropdownMenuItem>
          </DropdownMenuContent>
        </DropdownMenu>
      </TableCell>
    </TableRow>
  );
}

function StatusBadge({ status }: { status: ApiTokenListItem['status'] }) {
  const { t } = useTranslation();
  const variant: Record<ApiTokenListItem['status'], { cls: string; dot: string; key: string }> = {
    active: {
      cls: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
      dot: 'bg-emerald-500',
      key: 'settings.api_tokens.status.active',
    },
    expired: {
      cls: 'bg-amber-50 text-amber-700 ring-amber-200',
      dot: 'bg-amber-500',
      key: 'settings.api_tokens.status.expired',
    },
    revoked: {
      cls: 'bg-rose-50 text-rose-700 ring-rose-200',
      dot: 'bg-rose-500',
      key: 'settings.api_tokens.status.revoked',
    },
  };
  const { cls, dot, key } = variant[status];
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-md px-2 py-1 text-[11px] font-medium ring-1',
        cls,
      )}
    >
      <span className={cn('h-1.5 w-1.5 rounded-full', dot)} aria-hidden="true" />
      {t(key)}
    </span>
  );
}

function SkeletonRows({ scope }: { scope: Scope }) {
  return (
    <>
      {[0, 1, 2].map((row) => (
        <TableRow key={row}>
          <TableCell className="pl-5">
            <div className="flex items-center gap-3">
              <div className="size-8 animate-pulse rounded-md bg-muted" />
              <div className="space-y-1.5">
                <div className="h-3 w-32 animate-pulse rounded bg-muted" />
                <div className="h-3 w-16 animate-pulse rounded bg-muted/60" />
              </div>
            </div>
          </TableCell>
          {scope === 'tenant' ? (
            <TableCell>
              <div className="h-3 w-24 animate-pulse rounded bg-muted" />
            </TableCell>
          ) : null}
          <TableCell>
            <div className="h-4 w-20 animate-pulse rounded bg-muted" />
          </TableCell>
          <TableCell>
            <div className="h-5 w-16 animate-pulse rounded bg-muted" />
          </TableCell>
          <TableCell>
            <div className="h-3 w-24 animate-pulse rounded bg-muted" />
          </TableCell>
          <TableCell>
            <div className="h-3 w-16 animate-pulse rounded bg-muted" />
          </TableCell>
          <TableCell className="pr-5" />
        </TableRow>
      ))}
    </>
  );
}

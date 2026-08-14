import { useList } from '@refinedev/core';
import {
  ChevronLeft,
  ChevronRight,
  Mail,
  MoreHorizontal,
  Search,
  UserCheck,
  UserMinus,
  UserPlus,
  X,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { GatedButton } from '@/components/identity';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { toast } from '@/components/ui/toast';
import type { RoleListItem } from '@/features/settings/roles/types';
import { jsonFetch } from '@/lib/http';
import { useDebouncedCallback } from '@/lib/use-debounced-callback';
import { cn } from '@/lib/utils';

import { AddUserManuallyModal } from './AddUserManuallyModal';
import { DeactivateUserModal } from './DeactivateUserModal';
import { InviteUserModal } from './InviteUserModal';
import { RoleChip } from './RoleChip';
import { invitationValidity, relativeTime } from './relativeTime';
import { ScopePill } from './ScopePill';
import { SecurityBadgeStack } from './SecurityBadgeStack';
import { StatusBadge } from './StatusBadge';
import type { UserListItem, UserStatus } from './types';
import { UserAvatar } from './UserAvatar';

const PAGE_SIZE = 50;
type StatusFilter = 'all' | UserStatus;

/**
 * RBAC-P5-001 (#691) + UI re-align (#865) — Settings → Users list re-styled
 * to match `Zrodla/Front_Claude_Design/PIM-nowoczesny/settings/users.jsx`
 * §UsersTab.
 *
 * Behaviour kept from #691/#848: Refine `useList` against `/api/users`,
 * debounced search, status + role filters, pager, GatedButton invite CTA,
 * unified invitation+user rows via `kind`.
 *
 * Visual delta vs #848:
 *   - Header h2 + subtitle paragraph per §5.4
 *   - Toolbar in rounded-3xl card with `+ Zaproś użytkownika` CTA inline
 *   - 7 columns (was 5): User · Roles · Scope · Security · Status · Last login · Actions
 *   - Per-role colored chips (RoleChip resolves color by `role.code`)
 *   - Multi-role union sub-label (`union · N ról`)
 *   - Locale + channel scope pills stacked per row
 *   - MFA + SSO badges stacked per row
 *   - Status invited shows `link wygasa za Nd Xh`, disabled shows deactivation meta
 *   - Last login + IP + country sub-line
 *   - `[Edytuj]` button + 3-dot menu (instead of 3-dot only)
 *   - Row click navigates to /settings/users/:id full-page detail (delta D)
 *
 * Role filter is wired against /api/roles via useList<RoleListItem> — was
 * disabled in #848 until the role catalogue ticket landed.
 */
export function UsersListView() {
  const { t } = useTranslation();
  const [page, setPage] = useState(1);
  const [searchInput, setSearchInput] = useState('');
  const [searchValue, setSearchValue] = useState('');
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');
  const [roleFilter, setRoleFilter] = useState<string>('all');

  // Roles catalogue for the role filter dropdown. Pulled via Refine so the
  // data-provider invariants (tenant filter, auth headers) stay consistent.
  // Page size 100 is enough — 10 system + custom roles are well under 100.
  const { result: rolesResult } = useList<RoleListItem>({
    resource: 'roles',
    pagination: { currentPage: 1, pageSize: 100 },
  });
  const rolesCatalogue: RoleListItem[] = rolesResult?.data ?? [];

  const filters = useMemo(() => {
    const out: Array<{ field: string; operator: 'eq'; value: string }> = [];
    if (statusFilter !== 'all') {
      out.push({ field: 'status', operator: 'eq', value: statusFilter });
    }
    if (searchValue.trim().length > 0) {
      out.push({ field: 'search', operator: 'eq', value: searchValue.trim() });
    }
    if (roleFilter !== 'all') {
      // Backend accepts `role[]=<uuid>` to keep the door open for multi-role
      // filtering later without a wire-format break.
      out.push({ field: 'role[]', operator: 'eq', value: roleFilter });
    }
    return out;
  }, [statusFilter, searchValue, roleFilter]);

  const { result, query: listQuery } = useList<UserListItem>({
    resource: 'users',
    pagination: { currentPage: page, pageSize: PAGE_SIZE },
    filters,
  });
  const isLoading = listQuery.isLoading;
  const isError = listQuery.isError;
  const refetch = listQuery.refetch;

  const [deactivateTarget, setDeactivateTarget] = useState<UserListItem | null>(null);
  const [deactivateOpen, setDeactivateOpen] = useState(false);
  const openDeactivate = (user: UserListItem) => {
    setDeactivateTarget(user);
    setDeactivateOpen(true);
  };

  const handleReactivate = async (user: UserListItem) => {
    try {
      await jsonFetch(`/api/users/${user.id}/reactivate`, {
        method: 'POST',
        body: {},
        accept: 'application/json',
        contentType: 'application/json',
      });
      toast.success(t('settings.users.reactivate.toast_success', { name: user.display_name }));
      void refetch();
    } catch {
      toast.error(t('settings.users.reactivate.error_generic'));
    }
  };

  const handleResendInvitation = async (user: UserListItem) => {
    if (!user.invitation_id) return;
    try {
      await jsonFetch(`/api/invitations/${user.invitation_id}/resend`, {
        method: 'POST',
        accept: 'application/json',
      });
      toast.success(t('settings.users.invitation_actions.toast_resent', { email: user.email }));
      void refetch();
    } catch {
      toast.error(t('settings.users.invitation_actions.error_resend'));
    }
  };

  const handleRevokeInvitation = async (user: UserListItem) => {
    if (!user.invitation_id) return;
    if (
      !window.confirm(t('settings.users.invitation_actions.confirm_revoke', { email: user.email }))
    ) {
      return;
    }
    try {
      await jsonFetch(`/api/invitations/${user.invitation_id}/revoke`, {
        method: 'POST',
        accept: 'application/json',
      });
      toast.success(t('settings.users.invitation_actions.toast_revoked', { email: user.email }));
      void refetch();
    } catch {
      toast.error(t('settings.users.invitation_actions.error_revoke'));
    }
  };

  useEffect(() => {
    setPage(1);
  }, [statusFilter, searchValue, roleFilter]);

  const debouncedSetSearch = useDebouncedCallback((value: string) => {
    setSearchValue(value);
  }, 300);

  const onSearchChange = (value: string) => {
    setSearchInput(value);
    debouncedSetSearch(value);
  };

  const total = result?.total ?? 0;
  const totalPages = Math.max(1, Math.ceil(total / PAGE_SIZE));
  const users: UserListItem[] = result?.data ?? [];
  const [inviteOpen, setInviteOpen] = useState(false);
  // Manual user creation (#867) — admin alternative to magic-link invite.
  // State sits next to inviteOpen so both modals share the same list-level
  // lifetime + refetch path; UsersListView is mounted once and never
  // remounts between flows.
  const [addManualOpen, setAddManualOpen] = useState(false);

  return (
    <div className="space-y-4">
      <header className="flex items-start justify-between gap-4">
        <div className="space-y-1">
          <h2 className="text-[22px] font-semibold tracking-tight text-zinc-900">
            {t('settings.users.title')}
          </h2>
          <p className="max-w-2xl text-[13px] text-zinc-500">{t('settings.users.intro')}</p>
        </div>
      </header>

      <div className="flex flex-wrap items-center gap-3 rounded-3xl bg-white p-4 shadow-[0_1px_2px_rgba(0,0,0,0.04),0_4px_12px_rgba(0,0,0,0.04)]">
        <div className="relative max-w-md flex-1">
          <Search
            className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-zinc-500"
            aria-hidden
          />
          <Input
            type="search"
            placeholder={t('settings.users.search_placeholder')}
            value={searchInput}
            onChange={(e) => onSearchChange(e.target.value)}
            className="h-10 rounded-xl border-zinc-100 bg-zinc-50 pl-10 text-[13px]"
            aria-label={t('settings.users.search_aria')}
          />
        </div>
        <FilterSelect
          label={t('settings.users.filter_role')}
          value={roleFilter}
          onChange={setRoleFilter}
          options={[
            { value: 'all', label: t('settings.users.filter_role_all') },
            ...rolesCatalogue.map((role) => ({ value: role.id, label: role.name })),
          ]}
        />
        <FilterSelect
          label={t('settings.users.filter_status')}
          value={statusFilter}
          onChange={(v) => setStatusFilter(v as StatusFilter)}
          options={[
            { value: 'all', label: t('settings.users.filter_status_all') },
            { value: 'active', label: t('settings.users.status.active') },
            { value: 'invited', label: t('settings.users.status.invited') },
            { value: 'disabled', label: t('settings.users.status.disabled') },
          ]}
        />
        <div className="font-mono text-[11.5px] text-zinc-500">
          {t('settings.users.showing_count', { shown: users.length, total })}
        </div>
        <GatedButton
          anyOf={['user.write', 'settings.users.manage']}
          variant="outline"
          size="sm"
          className="h-9 gap-1.5 rounded-xl border-zinc-200 px-3.5 text-[12.5px] font-medium text-zinc-700 hover:bg-zinc-100"
          onClick={() => setAddManualOpen(true)}
        >
          <UserPlus className="size-4" aria-hidden />
          {t('settings.users.add_manually.cta')}
        </GatedButton>
        <GatedButton
          anyOf={['user.write', 'settings.users.manage']}
          size="sm"
          className="h-9 gap-1.5 rounded-xl bg-zinc-900 px-3.5 text-[12.5px] font-medium text-white hover:bg-zinc-800"
          onClick={() => setInviteOpen(true)}
        >
          <UserPlus className="size-4" aria-hidden />
          {t('settings.users.invite_cta')}
        </GatedButton>
      </div>

      <div className="overflow-hidden rounded-3xl bg-white shadow-[0_1px_2px_rgba(0,0,0,0.04),0_4px_12px_rgba(0,0,0,0.04)]">
        <table className="w-full text-[13px]">
          <thead className="border-b border-zinc-100 bg-zinc-50/70">
            <tr className="text-[10.5px] font-medium uppercase tracking-wider text-zinc-500">
              <th className="py-3 pl-5 pr-3 text-left">{t('settings.users.col_user')}</th>
              <th className="px-3 py-3 text-left">{t('settings.users.col_roles')}</th>
              <th className="px-3 py-3 text-left">
                {t('settings.users.col_scope', { defaultValue: 'Scope (locale · channel)' })}
              </th>
              <th className="px-3 py-3 text-left">
                {t('settings.users.col_security', { defaultValue: 'Bezpieczeństwo' })}
              </th>
              <th className="px-3 py-3 text-left">{t('settings.users.col_status')}</th>
              <th className="px-3 py-3 text-left">{t('settings.users.col_last_login')}</th>
              <th className="py-3 pr-5" aria-label={t('settings.users.col_actions')} />
            </tr>
          </thead>
          <tbody className="divide-y divide-zinc-100">
            {isError && (
              <tr>
                <td colSpan={7} className="py-8 text-center text-sm text-rose-600">
                  {t('settings.users.error_loading')}
                </td>
              </tr>
            )}
            {!isError && isLoading && users.length === 0 && <SkeletonRows />}
            {!isError && !isLoading && users.length === 0 && (
              <tr>
                <td colSpan={7} className="py-12 text-center">
                  <EmptyState />
                </td>
              </tr>
            )}
            {users.map((user) => (
              <UserRow
                key={user.id}
                user={user}
                onDeactivate={openDeactivate}
                onReactivate={handleReactivate}
                onResendInvitation={handleResendInvitation}
                onRevokeInvitation={handleRevokeInvitation}
              />
            ))}
          </tbody>
        </table>
      </div>

      <DeactivateUserModal
        user={deactivateTarget}
        open={deactivateOpen}
        onOpenChange={setDeactivateOpen}
        onSuccess={() => {
          void refetch();
        }}
      />

      <AddUserManuallyModal
        open={addManualOpen}
        onOpenChange={setAddManualOpen}
        onSuccess={() => {
          void refetch();
        }}
      />

      <InviteUserModal
        open={inviteOpen}
        onOpenChange={setInviteOpen}
        onSuccess={() => {
          void refetch();
        }}
      />

      {totalPages > 1 && (
        <div className="flex items-center justify-between rounded-2xl bg-white px-3 py-2 text-sm shadow-[0_1px_2px_rgba(0,0,0,0.04),0_4px_12px_rgba(0,0,0,0.04)]">
          <span className="text-zinc-500">
            {t('settings.users.pagination_status', { page, total_pages: totalPages })}
          </span>
          <div className="flex items-center gap-2">
            <Button
              variant="outline"
              size="sm"
              onClick={() => setPage((p) => Math.max(1, p - 1))}
              disabled={page <= 1}
              aria-label={t('settings.users.pagination_prev')}
            >
              <ChevronLeft className="size-4" />
            </Button>
            <Button
              variant="outline"
              size="sm"
              onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
              disabled={page >= totalPages}
              aria-label={t('settings.users.pagination_next')}
            >
              <ChevronRight className="size-4" />
            </Button>
          </div>
        </div>
      )}
    </div>
  );
}

interface UserRowProps {
  user: UserListItem;
  onDeactivate: (user: UserListItem) => void;
  onReactivate: (user: UserListItem) => void;
  onResendInvitation: (user: UserListItem) => void;
  onRevokeInvitation: (user: UserListItem) => void;
}

function UserRow({
  user,
  onDeactivate,
  onReactivate,
  onResendInvitation,
  onRevokeInvitation,
}: UserRowProps) {
  const { t, i18n } = useTranslation();
  const isInvitation = user.kind === 'invitation';
  const isDisabled = user.status === 'disabled';

  const lastLogin = isInvitation
    ? t('settings.users.last_login_n_a')
    : relativeTime(t, i18n.language, user.last_login_at);

  const invitationValidLabel = isInvitation
    ? invitationValidity(t, user.invitation_expires_at)
    : null;

  const mfaRequiredByRole = user.roles.some((r) => r.mfa_required === true);

  const deactivationMeta =
    isDisabled && user.deactivated_at
      ? user.deactivated_by
        ? t('settings.users.deactivated_meta_by', {
            date: new Date(user.deactivated_at).toLocaleDateString(i18n.language),
            actor: user.deactivated_by,
            defaultValue: '{{date}} · przez {{actor}}',
          })
        : new Date(user.deactivated_at).toLocaleDateString(i18n.language)
      : null;

  return (
    <tr className={cn('transition hover:bg-zinc-50/60', isDisabled && 'opacity-60')}>
      <td className="py-3 pl-5 pr-3">
        <div className="flex items-center gap-3">
          <UserAvatar initial={user.avatar_initial} seed={user.email} />
          <div className="min-w-0">
            <div className="flex items-center gap-1.5 text-[13.5px] font-medium text-zinc-900">
              <span className="truncate">{user.display_name}</span>
              {user.is_you ? (
                <span className="rounded bg-zinc-900 px-1 py-0.5 font-mono text-[10px] text-white">
                  {t('settings.users.is_you', { defaultValue: 'to ty' })}
                </span>
              ) : null}
              {user.platform_access ? (
                <span className="rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-medium text-rose-700">
                  {t('settings.users.platform_badge', { defaultValue: 'Platforma' })}
                </span>
              ) : null}
            </div>
            <div className="truncate text-[11.5px] text-zinc-500">{user.email}</div>
          </div>
        </div>
      </td>

      <td className="px-3 py-3">
        {user.roles.length === 0 ? (
          <span className="text-xs text-zinc-500">{t('settings.users.no_roles')}</span>
        ) : (
          <div className="space-y-1">
            <div className="flex flex-wrap gap-1">
              {user.roles.map((role) => (
                <RoleChip key={role.id} code={role.code} name={role.name} size="sm" />
              ))}
            </div>
            {user.roles.length >= 2 ? (
              <div className="font-mono text-[10px] text-zinc-500">
                {t('settings.users.role_union', {
                  count: user.roles.length,
                  defaultValue: 'union · {{count}} ról',
                })}
              </div>
            ) : null}
          </div>
        )}
      </td>

      <td className="px-3 py-3">
        <div className="flex flex-col gap-1">
          <ScopePill values={user.scope_locale} kind="locale" />
          <ScopePill values={user.scope_channel} kind="channel" />
        </div>
      </td>

      <td className="px-3 py-3">
        <SecurityBadgeStack
          mfaEnabled={user.mfa_enabled}
          mfaMethod={user.mfa_method ?? null}
          mfaRequiredByRole={mfaRequiredByRole}
          sso={user.sso ?? null}
        />
      </td>

      <td className="px-3 py-3">
        <div className="space-y-1">
          <StatusBadge status={user.status} />
          {invitationValidLabel ? (
            <div className="font-mono text-[10.5px] text-amber-700">{invitationValidLabel}</div>
          ) : null}
          {deactivationMeta ? (
            <div className="text-[10.5px] text-zinc-500">{deactivationMeta}</div>
          ) : null}
        </div>
      </td>

      <td className="px-3 py-3">
        <div className="text-[12px] text-zinc-700">{lastLogin}</div>
        {user.last_login_ip ? (
          <div className="font-mono text-[10.5px] text-zinc-500">
            {user.last_login_ip}
            {user.last_login_country ? <> · {user.last_login_country.toUpperCase()}</> : null}
          </div>
        ) : null}
      </td>

      <td className="py-3 pr-5">
        <div className="flex items-center justify-end gap-1">
          {!isInvitation ? (
            <Link
              to={`/settings/users/${user.id}`}
              className="inline-flex h-8 items-center rounded-lg border border-zinc-200 px-2.5 text-[12px] font-medium text-zinc-700 transition hover:bg-zinc-100"
            >
              {t('settings.users.action_edit')}
            </Link>
          ) : null}
          <DropdownMenu>
            <DropdownMenuTrigger asChild>
              <Button variant="ghost" size="icon" aria-label={t('settings.users.row_actions')}>
                <MoreHorizontal className="size-4" />
              </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="end">
              {isInvitation ? (
                <>
                  <DropdownMenuItem onSelect={() => onResendInvitation(user)}>
                    <Mail className="mr-2 size-4" aria-hidden />
                    {t('settings.users.invitation_actions.resend')}
                  </DropdownMenuItem>
                  <DropdownMenuItem
                    onSelect={() => onRevokeInvitation(user)}
                    className="text-rose-600 focus:text-rose-700"
                  >
                    <X className="mr-2 size-4" aria-hidden />
                    {t('settings.users.invitation_actions.revoke')}
                  </DropdownMenuItem>
                </>
              ) : user.status === 'active' ? (
                <DropdownMenuItem
                  onSelect={() => onDeactivate(user)}
                  className="text-rose-600 focus:text-rose-700"
                >
                  <UserMinus className="mr-2 size-4" aria-hidden />
                  {t('settings.users.action_deactivate')}
                </DropdownMenuItem>
              ) : (
                <DropdownMenuItem onSelect={() => onReactivate(user)}>
                  <UserCheck className="mr-2 size-4" aria-hidden />
                  {t('settings.users.action_reactivate')}
                </DropdownMenuItem>
              )}
            </DropdownMenuContent>
          </DropdownMenu>
        </div>
      </td>
    </tr>
  );
}

function SkeletonRows() {
  return (
    <>
      {[0, 1, 2].map((row) => (
        <tr key={row}>
          <td className="py-3 pl-5 pr-3">
            <div className="flex items-center gap-3">
              <div className="size-9 animate-pulse rounded-full bg-zinc-100" />
              <div className="space-y-1.5">
                <div className="h-3 w-32 animate-pulse rounded bg-zinc-100" />
                <div className="h-3 w-24 animate-pulse rounded bg-zinc-100/60" />
              </div>
            </div>
          </td>
          <td className="px-3 py-3">
            <div className="h-4 w-20 animate-pulse rounded bg-zinc-100" />
          </td>
          <td className="px-3 py-3">
            <div className="h-4 w-16 animate-pulse rounded bg-zinc-100" />
          </td>
          <td className="px-3 py-3">
            <div className="h-4 w-20 animate-pulse rounded bg-zinc-100" />
          </td>
          <td className="px-3 py-3">
            <div className="h-5 w-16 animate-pulse rounded bg-zinc-100" />
          </td>
          <td className="px-3 py-3">
            <div className="h-3 w-24 animate-pulse rounded bg-zinc-100" />
          </td>
          <td className="pr-5" />
        </tr>
      ))}
    </>
  );
}

function EmptyState() {
  const { t } = useTranslation();
  return (
    <div className="flex flex-col items-center justify-center gap-2 text-center">
      <UserPlus className="size-8 text-zinc-500" aria-hidden />
      <div className="text-sm font-medium text-zinc-900">{t('settings.users.empty_title')}</div>
      <div className="max-w-md text-xs text-zinc-500">{t('settings.users.empty_description')}</div>
    </div>
  );
}

interface FilterSelectProps {
  label: string;
  value: string;
  onChange: (value: string) => void;
  options: ReadonlyArray<{ value: string; label: string }>;
  disabled?: boolean;
  disabledHint?: string;
}

function FilterSelect({
  label,
  value,
  onChange,
  options,
  disabled,
  disabledHint,
}: FilterSelectProps) {
  return (
    <label
      className={cn(
        'inline-flex items-center gap-2 text-[12px] text-zinc-500',
        disabled && 'opacity-60',
      )}
      title={disabled ? disabledHint : undefined}
    >
      <span>{label}:</span>
      <select
        value={value}
        disabled={disabled}
        onChange={(e) => onChange(e.target.value)}
        className="h-9 rounded-xl border border-zinc-100 bg-zinc-50 px-2.5 text-[12.5px] text-zinc-800 outline-none focus-visible:ring-2 focus-visible:ring-ring"
      >
        {options.map((opt) => (
          <option key={opt.value} value={opt.value}>
            {opt.label}
          </option>
        ))}
      </select>
    </label>
  );
}

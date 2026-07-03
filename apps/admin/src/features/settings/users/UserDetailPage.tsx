import { useGetIdentity, useList } from '@refinedev/core';
import { ArrowLeft, ArrowRightLeft, Check, KeyRound, ShieldAlert } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useParams } from 'react-router';

import { Button } from '@/components/ui/button';
import { toast } from '@/components/ui/toast';
import { jsonFetch } from '@/lib/http';
import { cn } from '@/lib/utils';

import type { RoleListItem } from '../roles/types';
import { EffectivePermissionsPanel } from './EffectivePermissionsPanel';
import { SecurityBadgeStack } from './SecurityBadgeStack';
import { SetPasswordModal } from './SetPasswordModal';
import { StatusBadge } from './StatusBadge';
import type { UserListItem } from './types';
import { UserAvatar } from './UserAvatar';

interface RefineIdentity {
  id: string;
  name: string;
  email: string;
  roles: string[];
  tenant: { id: string; code: string; name: string } | null;
  lastLoginAt: string | null;
}

const SUPPORTED_LOCALES = ['pl', 'en', 'de', 'cs', 'sk'] as const;
const SUPPORTED_CHANNELS = ['shopify', 'allegro', 'baselinker', 'magento', 'idosell'] as const;

/**
 * Total atomic permissions across all RBAC modules — hardcoded mirror of the
 * design `RBAC_MODULES` enumeration (Zrodla/.../settings/data.jsx). When the
 * backend exposes `GET /api/users/{id}/effective-permissions` (delta Backend
 * follow-up) this value can shift to the response payload.
 */
const TOTAL_ATOMIC_PERMISSIONS = 48;

function toggleScope(values: string[], value: string): string[] {
  if (value === '*') return ['*'];
  const withoutAll = values.filter((v) => v !== '*');
  const next = withoutAll.includes(value)
    ? withoutAll.filter((v) => v !== value)
    : [...withoutAll, value];
  return next.length === 0 ? ['*'] : next;
}

/**
 * UI re-align (#865) — Settings → Użytkownicy detail page (full-page,
 * replaces EditUserModal). Mirrors §UserEditorPage from
 * `Zrodla/Front_Claude_Design/PIM-nowoczesny/settings/users.jsx`.
 *
 * Layout: 3-column (main spans 2, sticky right rail = EffectivePermissionsPanel)
 * + sticky bottom action bar with audit notice + Anuluj + Zapisz.
 *
 * Sections:
 *   1. Roles — multi-select grid, 2-col, role chips with color dots
 *   2. Locale & Channel scope — toggle pills `wszystkie / PL / EN / ...`
 *   3. Bezpieczeństwo — MFA card + sessions card
 *   4. Strefa niebezpieczna — deactivate/reactivate + (when owner) transfer
 *
 * Save = PATCH /api/users/{id} with { role_ids, locale_scope, channel_scope }.
 * Self-edit of role assignments is blocked client-side as well as on the
 * backend (409 self_edit).
 */
export function UserDetailPage() {
  const { t } = useTranslation();
  const params = useParams<{ id?: string }>();
  const navigate = useNavigate();
  const { data: identity } = useGetIdentity<RefineIdentity>();

  const [user, setUser] = useState<UserListItem | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState(false);

  const [selectedRoleIds, setSelectedRoleIds] = useState<Set<string>>(new Set());
  const [localeScope, setLocaleScope] = useState<string[]>(['*']);
  const [channelScope, setChannelScope] = useState<string[]>(['*']);
  const [initialSnapshot, setInitialSnapshot] = useState<{
    roleIds: Set<string>;
    locale: string[];
    channel: string[];
  } | null>(null);
  const [submitting, setSubmitting] = useState(false);
  // DP-02 (#2032) — admin set-password modal.
  const [setPasswordOpen, setSetPasswordOpen] = useState(false);

  const { result: rolesResult } = useList<RoleListItem>({
    resource: 'roles',
    pagination: { mode: 'off' },
  });
  const rolesCatalogue: RoleListItem[] = useMemo(
    () => rolesResult?.data ?? [],
    [rolesResult?.data],
  );

  // `/api/users` returns the same `UserListItem` projection as the list view,
  // so we fetch the catalogue once and pick the target by id. Backend has not
  // shipped GET /api/users/{id} yet (delta Backend follow-up) — this keeps
  // the detail page working without that endpoint.
  const { result: usersResult, query: usersQuery } = useList<UserListItem>({
    resource: 'users',
    pagination: { mode: 'off' },
  });

  useEffect(() => {
    if (!params.id) return;
    if (usersQuery.isLoading) return;
    if (usersQuery.isError) {
      setLoadError(true);
      setLoading(false);
      return;
    }
    const fetched = (usersResult?.data ?? []).find((u) => u.id === params.id) ?? null;
    if (!fetched) {
      setLoadError(true);
      setLoading(false);
      return;
    }
    setUser(fetched);
    const roleIds = new Set(fetched.roles.map((r) => r.id));
    const locale =
      fetched.scope_locale && fetched.scope_locale.length > 0 ? [...fetched.scope_locale] : ['*'];
    const channel =
      fetched.scope_channel && fetched.scope_channel.length > 0
        ? [...fetched.scope_channel]
        : ['*'];
    setSelectedRoleIds(roleIds);
    setLocaleScope(locale);
    setChannelScope(channel);
    setInitialSnapshot({ roleIds, locale: [...locale], channel: [...channel] });
    setLoading(false);
  }, [params.id, usersResult?.data, usersQuery.isLoading, usersQuery.isError]);

  const isSelf = Boolean(user && identity && user.id === identity.id);
  const isOwner = user?.roles.some((r) => r.code === 'tenant_owner') ?? false;

  const selectedRolesFull = useMemo(() => {
    return Array.from(selectedRoleIds)
      .map((id) => {
        const fromCatalogue = rolesCatalogue.find((r) => r.id === id);
        if (fromCatalogue) {
          return { id: fromCatalogue.id, code: fromCatalogue.code, name: fromCatalogue.name };
        }
        const fromUser = user?.roles.find((r) => r.id === id);
        return fromUser ?? null;
      })
      .filter((r): r is { id: string; code: string; name: string } => r !== null);
  }, [selectedRoleIds, rolesCatalogue, user]);

  const effectivePermissions = useMemo(() => {
    const total = selectedRolesFull.reduce((acc, role) => {
      const catalogue = rolesCatalogue.find((r) => r.id === role.id);
      return acc + (catalogue?.permissions_count ?? 0);
    }, 0);
    return Math.min(total, TOTAL_ATOMIC_PERMISSIONS);
  }, [selectedRolesFull, rolesCatalogue]);

  const isDirty = useMemo(() => {
    if (!initialSnapshot) return false;
    if (initialSnapshot.roleIds.size !== selectedRoleIds.size) return true;
    for (const id of selectedRoleIds) {
      if (!initialSnapshot.roleIds.has(id)) return true;
    }
    if (JSON.stringify(initialSnapshot.locale) !== JSON.stringify(localeScope)) return true;
    if (JSON.stringify(initialSnapshot.channel) !== JSON.stringify(channelScope)) return true;
    return false;
  }, [initialSnapshot, selectedRoleIds, localeScope, channelScope]);

  const handleToggleRole = (id: string) => {
    if (isSelf) return;
    setSelectedRoleIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) {
        next.delete(id);
      } else {
        next.add(id);
      }
      return next;
    });
  };

  const handleCancel = () => {
    if (initialSnapshot) {
      setSelectedRoleIds(new Set(initialSnapshot.roleIds));
      setLocaleScope([...initialSnapshot.locale]);
      setChannelScope([...initialSnapshot.channel]);
    }
    navigate('/settings/users');
  };

  const handleSave = async () => {
    if (!user || submitting) return;
    setSubmitting(true);
    try {
      await jsonFetch(`/api/users/${user.id}`, {
        method: 'PATCH',
        body: {
          role_ids: Array.from(selectedRoleIds),
          locale_scope: localeScope,
          channel_scope: channelScope,
        },
        accept: 'application/json',
        contentType: 'application/json',
      });
      toast.success(t('settings.users.edit.toast_success', { name: user.display_name }));
      navigate('/settings/users');
    } catch (error: unknown) {
      const status = (error as { status?: number; body?: { code?: string; detail?: string } })
        ?.status;
      const body = (error as { body?: { code?: string; detail?: string } })?.body;
      if (status === 409 && body?.code === 'last_admin') {
        toast.error(body?.detail ?? t('settings.users.edit.error_last_admin'));
      } else if (status === 409 && body?.code === 'self_edit') {
        toast.error(t('settings.users.edit.error_self_edit'));
      } else if (status === 403) {
        toast.error(t('settings.users.edit.error_forbidden'));
      } else if (status === 400) {
        toast.error(body?.detail ?? t('settings.users.edit.error_validation'));
      } else {
        toast.error(t('settings.users.edit.error_generic'));
      }
    } finally {
      setSubmitting(false);
    }
  };

  const handleDeactivate = async () => {
    if (!user) return;
    const confirmed = window.confirm(
      t('settings.users.deactivate.confirm_inline', {
        name: user.display_name,
        defaultValue: 'Dezaktywować {{name}}? User nie będzie mógł się zalogować.',
      }),
    );
    if (!confirmed) return;
    try {
      await jsonFetch(`/api/users/${user.id}/deactivate`, {
        method: 'POST',
        body: {},
        accept: 'application/json',
        contentType: 'application/json',
      });
      toast.success(t('settings.users.deactivate.toast_success', { name: user.display_name }));
      navigate('/settings/users');
    } catch {
      toast.error(t('settings.users.deactivate.error_generic'));
    }
  };

  const handleReactivate = async () => {
    if (!user) return;
    try {
      await jsonFetch(`/api/users/${user.id}/reactivate`, {
        method: 'POST',
        body: {},
        accept: 'application/json',
        contentType: 'application/json',
      });
      toast.success(t('settings.users.reactivate.toast_success', { name: user.display_name }));
      navigate('/settings/users');
    } catch {
      toast.error(t('settings.users.reactivate.error_generic'));
    }
  };

  const handleResetMfa = async () => {
    if (!user) return;
    const confirmed = window.confirm(
      t('settings.users.detail.confirm_reset_mfa', {
        defaultValue: 'Zresetować MFA dla tego użytkownika? Wymusi nowy setup przy logowaniu.',
      }),
    );
    if (!confirmed) return;
    try {
      await jsonFetch(`/api/users/${user.id}/reset-mfa`, {
        method: 'POST',
        body: {},
        accept: 'application/json',
        contentType: 'application/json',
      });
      toast.success(
        t('settings.users.detail.reset_mfa_success', { defaultValue: 'MFA zresetowane.' }),
      );
    } catch {
      toast.error(
        t('settings.users.detail.reset_mfa_error', {
          defaultValue: 'Nie udało się zresetować MFA.',
        }),
      );
    }
  };

  if (loading) {
    return <div className="py-16 text-center text-sm text-zinc-500">{t('app.loading')}</div>;
  }
  if (loadError || !user) {
    return (
      <div className="rounded-3xl bg-white p-12 text-center shadow-sm">
        <p className="text-sm text-rose-600">{t('settings.users.error_loading')}</p>
        <Link to="/settings/users" className="mt-3 inline-block text-sm text-zinc-700 underline">
          {t('settings.users.detail.back_to_list', { defaultValue: 'Wróć do listy' })}
        </Link>
      </div>
    );
  }

  return (
    <div className="pb-24">
      <header className="mb-6 flex items-start gap-4">
        <button
          type="button"
          onClick={() => navigate('/settings/users')}
          className="mt-1 grid size-9 shrink-0 place-items-center rounded-xl bg-white text-zinc-600 shadow-[0_1px_2px_rgba(0,0,0,0.04),0_4px_12px_rgba(0,0,0,0.04)] transition hover:bg-zinc-50 hover:text-zinc-900"
          aria-label={t('settings.users.detail.back_to_list', { defaultValue: 'Wróć do listy' })}
        >
          <ArrowLeft className="size-4" />
        </button>
        <div className="min-w-0 flex-1">
          <div className="mb-1 text-[11.5px] text-zinc-500">
            <Link to="/settings/users" className="hover:text-zinc-900">
              {t('settings.users.title')}
            </Link>
            <span className="mx-1.5 text-zinc-300">/</span>
            <span className="text-zinc-700">{user.display_name}</span>
          </div>
          <div className="flex flex-wrap items-center gap-3">
            <UserAvatar initial={user.avatar_initial} seed={user.email} />
            <div>
              <h2 className="flex items-center gap-2 text-[24px] font-semibold tracking-tight text-zinc-900">
                {user.display_name}
                {user.is_you ? (
                  <span className="rounded bg-zinc-900 px-1.5 py-0.5 font-mono text-[10px] text-white">
                    {t('settings.users.is_you', { defaultValue: 'to ty' })}
                  </span>
                ) : null}
                {user.platform_access ? (
                  <span className="rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-medium text-rose-700">
                    {t('settings.users.platform_badge', { defaultValue: 'Platforma' })}
                  </span>
                ) : null}
              </h2>
              <div className="mt-0.5 text-[12.5px] text-zinc-500">{user.email}</div>
            </div>
          </div>
        </div>
      </header>

      {isSelf ? (
        <div className="mb-5 flex items-start gap-2 rounded-2xl bg-amber-50 px-4 py-3 text-[12.5px] text-amber-800 ring-1 ring-amber-200">
          <ShieldAlert className="mt-0.5 size-4 shrink-0" aria-hidden />
          <span>
            <span className="font-medium">
              {t('settings.users.detail.self_lock_title', {
                defaultValue: 'Self-modification block:',
              })}{' '}
            </span>
            {t('settings.users.edit.self_edit_notice')}
          </span>
        </div>
      ) : null}

      <div className="grid grid-cols-1 gap-5 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          <SectionCard
            title={t('settings.users.detail.roles_card_title', { defaultValue: 'Role' })}
            subtitle={t('settings.users.detail.roles_card_subtitle', {
              selected: effectivePermissions,
              total: TOTAL_ATOMIC_PERMISSIONS,
              defaultValue:
                'Multi-select · uprawnienia = union · {{selected}}/{{total}} atomic permissions',
            })}
          >
            {rolesCatalogue.length === 0 ? (
              <p className="text-xs text-zinc-500">{t('settings.users.edit.roles_loading')}</p>
            ) : (
              <div className="grid grid-cols-1 gap-2 sm:grid-cols-2">
                {rolesCatalogue.map((role) => {
                  const checked = selectedRoleIds.has(role.id);
                  return (
                    <label
                      key={role.id}
                      className={cn(
                        'flex cursor-pointer items-start gap-2.5 rounded-2xl border p-3 transition',
                        checked
                          ? 'border-zinc-900 bg-zinc-50/80'
                          : 'border-zinc-200 hover:bg-zinc-50/60',
                        isSelf && 'cursor-not-allowed opacity-60',
                      )}
                    >
                      <input
                        type="checkbox"
                        checked={checked}
                        disabled={isSelf}
                        onChange={() => handleToggleRole(role.id)}
                        className="mt-0.5 size-4"
                      />
                      <div className="min-w-0 flex-1">
                        <div className="flex items-center gap-1.5">
                          <span className="text-[12.5px] font-medium text-zinc-900">
                            {role.name}
                          </span>
                          {role.type === 'custom' ? (
                            <span className="rounded bg-pink-50 px-1 py-0.5 text-[9.5px] font-medium text-pink-700">
                              {t('settings.users.edit.role_custom_tag', { defaultValue: 'custom' })}
                            </span>
                          ) : null}
                        </div>
                        <div className="mt-0.5 line-clamp-2 text-[11px] text-zinc-500">
                          {role.code}
                        </div>
                      </div>
                    </label>
                  );
                })}
              </div>
            )}
          </SectionCard>

          <SectionCard
            title={t('settings.users.detail.scope_card_title', {
              defaultValue: 'Locale & Channel scope',
            })}
            subtitle={t('settings.users.detail.scope_card_subtitle', {
              defaultValue:
                'Default "wszystkie" = brak ograniczenia. Ograniczenie aplikuje się per request (per-locale guard, per-channel guard).',
            })}
          >
            <div className="grid grid-cols-1 gap-5 sm:grid-cols-2">
              <Field
                label={t('settings.users.detail.locale_scope_label', {
                  defaultValue: 'Locale scope',
                })}
              >
                <ScopeMultiSelect
                  options={['*', ...SUPPORTED_LOCALES]}
                  value={localeScope}
                  onChange={setLocaleScope}
                  kind="locale"
                />
              </Field>
              <Field
                label={t('settings.users.detail.channel_scope_label', {
                  defaultValue: 'Channel scope',
                })}
              >
                <ScopeMultiSelect
                  options={['*', ...SUPPORTED_CHANNELS]}
                  value={channelScope}
                  onChange={setChannelScope}
                  kind="channel"
                />
              </Field>
            </div>
          </SectionCard>

          <SectionCard
            title={t('settings.users.detail.security_card_title', {
              defaultValue: 'Bezpieczeństwo',
            })}
          >
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <div className="rounded-2xl border border-zinc-200 p-3">
                <SecurityBadgeStack
                  mfaEnabled={user.mfa_enabled}
                  mfaMethod={user.mfa_method ?? null}
                  sso={user.sso ?? null}
                />
                <div className="mt-2 text-[11px] text-zinc-500">
                  {user.mfa_enabled
                    ? t('settings.users.detail.mfa_method_info', {
                        method: user.mfa_method ?? '—',
                        defaultValue: 'Metoda: {{method}}',
                      })
                    : t('settings.users.detail.mfa_disabled_info', {
                        defaultValue: 'MFA wyłączone.',
                      })}
                </div>
                <button
                  type="button"
                  onClick={handleResetMfa}
                  className="mt-3 h-8 rounded-lg border border-zinc-200 px-2.5 text-[12px] text-zinc-700 transition hover:bg-zinc-100"
                >
                  {user.mfa_enabled
                    ? t('settings.users.detail.reset_mfa', { defaultValue: 'Reset MFA' })
                    : t('settings.users.detail.enforce_mfa', { defaultValue: 'Wymuś setup MFA' })}
                </button>
              </div>
              <div className="rounded-2xl border border-zinc-200 p-3">
                <div className="text-[12px] font-medium text-zinc-900">
                  {t('settings.users.detail.sessions_title', { defaultValue: 'Sesje aktywne' })}
                </div>
                <div className="mt-1 text-[11px] text-zinc-500">
                  {t('settings.users.detail.sessions_summary', {
                    last_ip: user.last_login_ip ?? '—',
                    defaultValue: 'Ostatnia z {{last_ip}}',
                  })}
                </div>
                <button
                  type="button"
                  disabled
                  title={t('settings.users.detail.logout_all_pending', {
                    defaultValue: 'Bulk session revoke dochodzi w #672',
                  })}
                  className="mt-3 h-8 cursor-not-allowed rounded-lg border border-rose-200 px-2.5 text-[12px] text-rose-700 opacity-60"
                >
                  {t('settings.users.detail.logout_all', {
                    defaultValue: 'Wyloguj wszystkie sesje',
                  })}
                </button>
              </div>
            </div>
          </SectionCard>

          <SectionCard
            title={t('settings.users.detail.danger_card_title', {
              defaultValue: 'Strefa niebezpieczna',
            })}
            tone="danger"
          >
            <div className="space-y-2">
              {/* DP-02 (#2032) — admin sets a new password from the panel.
                Self-target is blocked (backend answers 409) — the operator
                changes their own password in Settings → Security. */}
              <div className="flex items-center gap-3 rounded-2xl border border-zinc-200 p-3">
                <div className="flex-1">
                  <div className="text-[12.5px] font-medium text-zinc-900">
                    {t('settings.users.set_password.row_title', {
                      defaultValue: 'Ustaw nowe hasło',
                    })}
                  </div>
                  <div className="text-[11px] text-zinc-500">
                    {t('settings.users.set_password.row_description', {
                      defaultValue:
                        'Dla kont bez działającej skrzynki (email pełni rolę loginu) — reset mailowy ich nie dosięgnie. Sesje użytkownika zostaną unieważnione.',
                    })}
                  </div>
                </div>
                <button
                  type="button"
                  disabled={identity?.id === user.id}
                  title={
                    identity?.id === user.id
                      ? t('settings.users.set_password.self_hint', {
                          defaultValue: 'Własne hasło zmienisz w Ustawienia → Bezpieczeństwo.',
                        })
                      : undefined
                  }
                  onClick={() => setSetPasswordOpen(true)}
                  className={cn(
                    'inline-flex h-9 items-center gap-1.5 rounded-xl border border-zinc-200 px-3 text-[12.5px] font-medium transition',
                    identity?.id === user.id
                      ? 'cursor-not-allowed opacity-50'
                      : 'text-zinc-700 hover:bg-zinc-50',
                  )}
                >
                  <KeyRound className="size-3.5" aria-hidden />
                  {t('settings.users.set_password.cta', { defaultValue: 'Ustaw hasło' })}
                </button>
              </div>
              <div className="flex items-center gap-3 rounded-2xl border border-zinc-200 p-3">
                <div className="flex-1">
                  <div className="text-[12.5px] font-medium text-zinc-900">
                    {user.status === 'disabled'
                      ? t('settings.users.detail.reactivate_title', {
                          defaultValue: 'Reaktywuj konto',
                        })
                      : t('settings.users.detail.deactivate_title', {
                          defaultValue: 'Dezaktywuj konto',
                        })}
                  </div>
                  <div className="text-[11px] text-zinc-500">
                    {t('settings.users.detail.deactivate_description', {
                      defaultValue:
                        'User nie będzie mógł się zalogować. Dane historyczne (audit, autorstwo) pozostają.',
                    })}
                  </div>
                </div>
                <button
                  type="button"
                  onClick={user.status === 'disabled' ? handleReactivate : handleDeactivate}
                  className={cn(
                    'h-9 rounded-xl border px-3 text-[12.5px] font-medium transition',
                    user.status === 'disabled'
                      ? 'border-emerald-200 text-emerald-700 hover:bg-emerald-50'
                      : 'border-rose-200 text-rose-700 hover:bg-rose-50',
                  )}
                >
                  {user.status === 'disabled'
                    ? t('settings.users.action_reactivate')
                    : t('settings.users.action_deactivate')}
                </button>
              </div>
              {isOwner ? (
                <div className="flex items-center gap-3 rounded-2xl border border-rose-200 bg-rose-50/40 p-3">
                  <div className="flex-1">
                    <div className="text-[12.5px] font-medium text-rose-900">
                      {t('settings.users.detail.transfer_ownership_title', {
                        defaultValue: 'Transfer Ownership',
                      })}
                    </div>
                    <div className="text-[11px] text-rose-800">
                      {t('settings.users.detail.transfer_ownership_description', {
                        defaultValue:
                          'Tenant Owner jest unique (max 1). Aby przekazać własność, najpierw promuj innego usera; obecny zostanie Administrator.',
                      })}
                    </div>
                  </div>
                  <button
                    type="button"
                    disabled
                    title={t('settings.users.detail.transfer_ownership_pending', {
                      defaultValue: 'Transfer ownership flow lands in Phase 5 follow-up.',
                    })}
                    className="inline-flex h-9 cursor-not-allowed items-center gap-1 rounded-xl border border-rose-300 px-3 text-[12.5px] font-medium text-rose-700 opacity-60"
                  >
                    <ArrowRightLeft className="size-3.5" aria-hidden />
                    {t('settings.users.detail.transfer_ownership_cta', {
                      defaultValue: 'Transfer ownership',
                    })}
                  </button>
                </div>
              ) : null}
            </div>
          </SectionCard>
        </div>

        <div>
          <EffectivePermissionsPanel
            totalPermissions={TOTAL_ATOMIC_PERMISSIONS}
            effectivePermissions={effectivePermissions}
            selectedRoles={selectedRolesFull}
            localeScope={localeScope}
            channelScope={channelScope}
            meta={[
              {
                label: t('settings.users.detail.meta_status', { defaultValue: 'Status' }),
                value: <StatusBadge status={user.status} />,
              },
              {
                label: t('settings.users.detail.meta_created', { defaultValue: 'Konto od' }),
                value: (
                  <span className="font-mono">
                    {new Date(user.created_at).toLocaleDateString()}
                  </span>
                ),
              },
              ...(user.last_login_at
                ? [
                    {
                      label: t('settings.users.detail.meta_last_login', {
                        defaultValue: 'Ostatnie logowanie',
                      }),
                      value: new Date(user.last_login_at).toLocaleDateString(),
                    },
                  ]
                : []),
            ]}
          />
        </div>
      </div>

      <div className="fixed bottom-0 left-0 right-0 z-20 border-t border-zinc-200 bg-white/95 px-4 py-3 backdrop-blur md:left-[260px] md:px-8">
        <div className="mx-auto flex max-w-7xl items-center gap-2">
          <div className="text-[11.5px] text-zinc-500">
            {t('settings.users.detail.audit_note', {
              defaultValue:
                'Zmiany zostaną zapisane w audit log z user_id, IP, timestamp, old/new value.',
            })}
          </div>
          <div className="ml-auto flex items-center gap-2">
            <Button
              type="button"
              variant="ghost"
              onClick={handleCancel}
              disabled={submitting}
              className="h-10 rounded-xl px-4 text-[13px] text-zinc-700 hover:bg-zinc-100"
            >
              {t('settings.users.edit.cancel')}
            </Button>
            <Button
              type="button"
              onClick={handleSave}
              disabled={submitting || !isDirty || isSelf}
              className={cn(
                'h-10 rounded-xl px-4 text-[13px] font-medium',
                isDirty && !isSelf && !submitting
                  ? 'bg-zinc-900 text-white hover:bg-zinc-800'
                  : 'bg-zinc-200 text-zinc-500',
              )}
            >
              <Check className="mr-1.5 size-4" aria-hidden />
              {submitting ? t('settings.users.edit.submitting') : t('settings.users.edit.submit')}
            </Button>
          </div>
        </div>
      </div>

      <SetPasswordModal user={user} open={setPasswordOpen} onOpenChange={setSetPasswordOpen} />
    </div>
  );
}

interface SectionCardProps {
  title: string;
  subtitle?: string;
  tone?: 'default' | 'danger';
  children: React.ReactNode;
}

function SectionCard({ title, subtitle, tone = 'default', children }: SectionCardProps) {
  return (
    <div
      className={cn(
        'rounded-3xl bg-white p-5 shadow-[0_1px_2px_rgba(0,0,0,0.04),0_4px_12px_rgba(0,0,0,0.04)]',
        tone === 'danger' && 'ring-1 ring-rose-200',
      )}
    >
      <div className="mb-4">
        <div
          className={cn(
            'text-[14px] font-semibold tracking-tight',
            tone === 'danger' ? 'text-rose-900' : 'text-zinc-900',
          )}
        >
          {title}
        </div>
        {subtitle ? <div className="mt-0.5 text-[11.5px] text-zinc-500">{subtitle}</div> : null}
      </div>
      {children}
    </div>
  );
}

interface FieldProps {
  label: string;
  children: React.ReactNode;
}

function Field({ label, children }: FieldProps) {
  return (
    <div>
      <div className="mb-1.5 text-[11.5px] font-medium uppercase tracking-wider text-zinc-500">
        {label}
      </div>
      {children}
    </div>
  );
}

interface ScopeMultiSelectProps {
  options: readonly string[];
  value: string[];
  onChange: (next: string[]) => void;
  kind: 'locale' | 'channel';
}

function ScopeMultiSelect({ options, value, onChange, kind }: ScopeMultiSelectProps) {
  const { t } = useTranslation();
  return (
    <div className="flex flex-wrap gap-1.5">
      {options.map((v) => {
        const selected = value.includes(v);
        const isAll = v === '*';
        const cls = selected
          ? isAll
            ? 'bg-zinc-900 text-white border-zinc-900'
            : kind === 'locale'
              ? 'bg-orange-100 text-orange-800 border-orange-300'
              : 'bg-cyan-100 text-cyan-800 border-cyan-300'
          : 'bg-white text-zinc-600 border-zinc-200 hover:bg-zinc-50';
        return (
          <button
            key={v}
            type="button"
            onClick={() => onChange(toggleScope(value, v))}
            className={cn(
              'h-8 rounded-lg border px-2.5 font-mono text-[11.5px] font-medium transition',
              cls,
            )}
          >
            {isAll ? t('settings.users.scope_all', { defaultValue: 'wszystkie' }) : v.toUpperCase()}
          </button>
        );
      })}
    </div>
  );
}

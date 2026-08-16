import { useList } from '@refinedev/core';
import { MoreHorizontal, Pencil, Plus, Search, ShieldCheck } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';

import { GatedButton } from '@/components/identity';
import { Button } from '@/components/ui/button';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { CoverageStrip } from './CoverageStrip';
import { resolveRoleColor } from './colors';
import { CUSTOM_PERSONA, ROLE_PERSONAS } from './personas';
import { resolveRoleScope } from './scope';
import type { RoleListItem } from './types';

const TOTAL_PERMS_DENOMINATOR = 48;
type RoleFilter = 'all' | 'platform' | 'tenant' | 'system' | 'custom';

const FILTER_TABS: ReadonlyArray<{ id: RoleFilter; labelKey: string }> = [
  { id: 'all', labelKey: 'settings.roles.filter_all' },
  { id: 'platform', labelKey: 'settings.roles.filter_platform' },
  { id: 'tenant', labelKey: 'settings.roles.filter_tenant' },
  { id: 'system', labelKey: 'settings.roles.filter_system' },
  { id: 'custom', labelKey: 'settings.roles.filter_custom' },
];

/**
 * UI re-align (#865) — Settings → Role i uprawnienia per
 * `Zrodla/Front_Claude_Design/PIM-nowoczesny/settings/roles.jsx` §RolesTab.
 *
 * Visual delta vs #847:
 *   - 5-tab filter pills (`Wszystkie / Platform / Tenant / System / Custom`)
 *     replacing the 3-state select. Platform/Tenant resolves via
 *     {@link resolveRoleScope} until backend exposes Role.scope.
 *   - Each role rendered as a `rounded-3xl` card (was: table row).
 *   - Identity column: color dot + name + system/custom + platform +
 *     unique · max 1 + MFA wymagane + auto-grant badges (when applicable).
 *   - Persona sub-label (`Tomasz · właściciel firmy`) sourced from
 *     {@link ROLE_PERSONAS} fallback map until Role.persona ships.
 *   - Description text from RoleListItem.description (when present).
 *   - 13-module coverage strip via {@link CoverageStrip} (degraded to
 *     overall % until backend exposes permission_coverage).
 *   - Users count card on the right + Edytuj button + 3-dot menu.
 */
export function RolesListView() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [search, setSearch] = useState('');
  const [filter, setFilter] = useState<RoleFilter>('all');

  const { result, query: listQuery } = useList<RoleListItem>({
    resource: 'roles',
    pagination: { mode: 'off' },
  });
  const isLoading = listQuery.isLoading;
  const isError = listQuery.isError;
  const roles: RoleListItem[] = result?.data ?? [];

  const filtered = useMemo(() => {
    const needle = search.trim().toLowerCase();
    return roles.filter((role) => {
      const scope = resolveRoleScope(role);
      if (filter === 'platform' && scope !== 'platform') return false;
      if (filter === 'tenant' && scope !== 'tenant') return false;
      if (filter === 'system' && role.type !== 'system') return false;
      if (filter === 'custom' && role.type !== 'custom') return false;
      if (needle.length > 0) {
        if (!`${role.code} ${role.name} ${role.description ?? ''}`.toLowerCase().includes(needle)) {
          return false;
        }
      }
      return true;
    });
  }, [roles, search, filter]);

  return (
    <div className="space-y-4">
      <header className="flex items-start gap-4">
        <div className="flex-1 space-y-1">
          <h2 className="text-[22px] font-semibold tracking-tight text-zinc-900">
            {t('settings.roles.title')}
          </h2>
          <p className="max-w-2xl text-[13px] text-zinc-500">
            {t('settings.roles.intro_865', {
              defaultValue:
                'Templates systemowych + custom roles. Per-attribute restrictions, locale & channel scope, auto-grant flag.',
            })}
          </p>
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
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            placeholder={t('settings.roles.search_placeholder')}
            className="h-10 rounded-xl border-zinc-100 bg-zinc-50 pl-10 text-[13px]"
          />
        </div>
        <div className="flex items-center gap-1 rounded-xl bg-zinc-50 p-1">
          {FILTER_TABS.map((tab) => (
            <button
              key={tab.id}
              type="button"
              onClick={() => setFilter(tab.id)}
              className={cn(
                'h-8 rounded-lg px-3 text-[12px] font-medium transition',
                filter === tab.id
                  ? 'bg-white text-zinc-900 shadow-[0_1px_2px_rgba(0,0,0,0.04),0_4px_12px_rgba(0,0,0,0.04)]'
                  : 'text-zinc-500 hover:text-zinc-900',
              )}
            >
              {t(tab.labelKey)}
            </button>
          ))}
        </div>
        <div className="font-mono text-[11.5px] text-zinc-500 sm:ml-auto">
          {t('settings.roles.showing_count', { shown: filtered.length, total: roles.length })}
        </div>
        {/* #2881 — the last legacy code left on the frontend. Role
            templates carry settings.roles.manage; `role.write` belongs to
            the legacy grid and no panel-created role holds it, so this
            button was invisible to every tenant Owner. */}
        <GatedButton
          anyOf={['role.write', 'settings.roles.manage']}
          size="sm"
          className="h-9 gap-1.5 rounded-xl bg-zinc-900 px-3.5 text-[12.5px] font-medium text-white hover:bg-zinc-800"
          onClick={() => navigate('/settings/roles/new')}
        >
          <Plus className="size-4" aria-hidden />
          {t('settings.roles.create_cta')}
        </GatedButton>
      </div>

      {isError ? (
        <div className="rounded-3xl bg-white p-12 text-center shadow-sm">
          <p className="text-sm text-rose-600">{t('settings.roles.error_loading')}</p>
        </div>
      ) : null}

      {isLoading && filtered.length === 0 ? (
        <SkeletonCards />
      ) : !isError && filtered.length === 0 ? (
        <div className="rounded-3xl bg-white py-16 text-center text-[13px] text-zinc-500 shadow-[0_1px_2px_rgba(0,0,0,0.04),0_4px_12px_rgba(0,0,0,0.04)]">
          <ShieldCheck className="mx-auto mb-3 size-8 text-zinc-500" aria-hidden />
          <div className="text-sm font-medium text-zinc-900">{t('settings.roles.empty_title')}</div>
          <div className="mt-1 text-xs text-zinc-500">{t('settings.roles.empty_description')}</div>
        </div>
      ) : (
        <div className="space-y-2.5">
          {filtered.map((role) => (
            <RoleCard
              key={role.id}
              role={role}
              onEdit={() => navigate(`/settings/roles/${role.id}/edit`)}
            />
          ))}
        </div>
      )}
    </div>
  );
}

interface RoleCardProps {
  role: RoleListItem;
  onEdit: () => void;
}

function RoleCard({ role, onEdit }: RoleCardProps) {
  const { t } = useTranslation();
  const color = resolveRoleColor(role.code);
  const scope = resolveRoleScope(role);
  const persona = role.persona ?? ROLE_PERSONAS[role.code] ?? CUSTOM_PERSONA;
  const overallPct =
    TOTAL_PERMS_DENOMINATOR > 0
      ? Math.round((role.permissions_count / TOTAL_PERMS_DENOMINATOR) * 100)
      : 0;

  return (
    <div className="rounded-3xl bg-white px-5 py-4 shadow-[0_1px_2px_rgba(0,0,0,0.04),0_4px_12px_rgba(0,0,0,0.04)] transition hover:shadow-md">
      <div className="flex flex-col items-stretch gap-5 lg:flex-row">
        <div className="w-full shrink-0 lg:w-[280px]">
          <div className="flex flex-wrap items-center gap-2">
            <span className={cn('size-2 rounded-full', color.dot)} aria-hidden />
            <button
              type="button"
              onClick={onEdit}
              className="text-left text-[15px] font-semibold tracking-tight text-zinc-900 hover:underline"
            >
              {role.name}
            </button>
            {role.type === 'system' ? (
              <span className="rounded bg-zinc-100 px-1.5 py-0.5 text-[10px] font-medium text-zinc-600">
                {t('settings.roles.badge_system', { defaultValue: 'system' })}
              </span>
            ) : (
              <span className="rounded bg-pink-50 px-1.5 py-0.5 text-[10px] font-medium text-pink-700">
                {t('settings.roles.badge_custom', { defaultValue: 'custom' })}
              </span>
            )}
            {scope === 'platform' ? (
              <span className="rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-medium text-rose-700">
                {t('settings.roles.badge_platform', { defaultValue: 'platform' })}
              </span>
            ) : null}
            {role.is_unique ? (
              <span className="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700">
                {t('settings.roles.badge_unique', { defaultValue: 'unique · max 1' })}
              </span>
            ) : null}
            {role.mfa_required ? (
              <span className="rounded bg-emerald-50 px-1.5 py-0.5 text-[10px] font-medium text-emerald-700">
                {t('settings.roles.badge_mfa_required', { defaultValue: 'MFA wymagane' })}
              </span>
            ) : null}
          </div>
          <div className="mt-1 text-[11.5px] text-zinc-500">{persona}</div>
          {role.description ? (
            <div className="mt-2 line-clamp-2 text-[12px] text-zinc-700">{role.description}</div>
          ) : null}
        </div>

        <div className="min-w-0 flex-1">
          <div className="mb-2 flex items-center justify-between">
            <div className="text-[10.5px] font-medium uppercase tracking-wider text-zinc-500">
              {t('settings.roles.coverage_label', { defaultValue: 'Pokrycie uprawnień' })}
            </div>
            <div className="font-mono text-[11.5px] text-zinc-500">
              {role.permissions_count} / {TOTAL_PERMS_DENOMINATOR} · {overallPct}%
            </div>
          </div>
          <CoverageStrip perModule={role.permission_coverage} overallPct={overallPct} />
        </div>

        <div className="flex w-full shrink-0 flex-col items-end gap-2 lg:w-[140px]">
          <div className="text-right">
            <div className="text-[10.5px] font-medium uppercase tracking-wider text-zinc-500">
              {t('settings.roles.users_label', { defaultValue: 'Użytkownicy' })}
            </div>
            <div className="font-mono text-[24px] font-semibold tracking-tight text-zinc-900">
              {role.user_count}
            </div>
          </div>
          <div className="mt-auto flex items-center gap-1.5">
            {role.auto_grant_new_object_types ? (
              <span
                className="rounded bg-blue-50 px-1.5 py-0.5 text-[10px] font-medium text-blue-700"
                title={t('settings.roles.auto_grant_tooltip', {
                  defaultValue: 'Auto-grant view+edit dla nowych ObjectTypes',
                })}
              >
                {t('settings.roles.badge_auto_grant', { defaultValue: 'auto-grant' })}
              </span>
            ) : null}
            <button
              type="button"
              onClick={onEdit}
              className="h-8 rounded-lg border border-zinc-200 px-2.5 text-[12px] font-medium text-zinc-700 transition hover:bg-zinc-100"
            >
              {role.type === 'system'
                ? t('settings.roles.action_view_permissions')
                : t('settings.roles.action_edit')}
            </button>
            <DropdownMenu>
              <DropdownMenuTrigger asChild>
                <Button
                  variant="ghost"
                  size="icon"
                  aria-label={t('settings.roles.row_actions')}
                  className="h-8 w-8 text-zinc-500 hover:text-zinc-900"
                >
                  <MoreHorizontal className="size-4" />
                </Button>
              </DropdownMenuTrigger>
              <DropdownMenuContent align="end">
                <DropdownMenuItem onSelect={onEdit}>
                  <Pencil className="mr-2 size-4" aria-hidden />
                  {role.type === 'system'
                    ? t('settings.roles.action_view_permissions')
                    : t('settings.roles.action_edit')}
                </DropdownMenuItem>
              </DropdownMenuContent>
            </DropdownMenu>
          </div>
        </div>
      </div>
    </div>
  );
}

function SkeletonCards() {
  return (
    <div className="space-y-2.5">
      {[0, 1, 2, 3].map((row) => (
        <div
          key={row}
          className="flex items-stretch gap-5 rounded-3xl bg-white px-5 py-4 shadow-[0_1px_2px_rgba(0,0,0,0.04),0_4px_12px_rgba(0,0,0,0.04)]"
        >
          <div className="w-[280px] space-y-2">
            <div className="h-4 w-40 animate-pulse rounded bg-zinc-100" />
            <div className="h-3 w-32 animate-pulse rounded bg-zinc-100/60" />
            <div className="h-3 w-48 animate-pulse rounded bg-zinc-100/60" />
          </div>
          <div className="flex-1">
            <div className="mb-2 h-3 w-24 animate-pulse rounded bg-zinc-100" />
            <div
              className="grid grid-cols-13 gap-1"
              style={{ gridTemplateColumns: 'repeat(13, minmax(0, 1fr))' }}
            >
              {[
                'pla',
                'pro',
                'kat',
                'mul',
                'mod',
                'pub',
                'imp',
                'exp',
                'wor',
                'cmdk',
                'set',
                'tok',
                'aud',
              ].map((slot) => (
                <div key={slot} className="h-8 animate-pulse rounded-md bg-zinc-100" />
              ))}
            </div>
          </div>
          <div className="w-[140px] space-y-2">
            <div className="ml-auto h-3 w-16 animate-pulse rounded bg-zinc-100" />
            <div className="ml-auto h-6 w-12 animate-pulse rounded bg-zinc-100" />
          </div>
        </div>
      ))}
    </div>
  );
}

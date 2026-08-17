import { useList } from '@refinedev/core';
import {
  AlertTriangle,
  ArrowLeft,
  Check,
  FileText,
  Info,
  Layers,
  Lock,
  Settings,
  ShieldCheck,
  Trash2,
} from 'lucide-react';
import { type FormEvent, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toast } from '@/components/ui/toast';
import { httpErrorDetail, jsonFetch } from '@/lib/http';
import { cn } from '@/lib/utils';
import {
  type AttributePermissionLevel,
  AttributePermissionsSection,
  type AttributePermsApiGroup,
} from './AttributePermissionsSection';
import { resolveRoleColor } from './colors';
import type { PermissionGroup } from './PermissionMatrix';
import { PermissionMatrixAccordion } from './PermissionMatrixAccordion';
import { visibleGroups } from './permission-catalogue';
import { resolveRoleScope } from './scope';
import type { RoleDetail, RoleListItem } from './types';

interface PermissionsResponse {
  member: PermissionGroup[];
  totalItems: number;
}

interface AttrPermsResponse {
  role_id: string;
  groups: AttributePermsApiGroup[];
}

interface ApiProblem {
  detail?: string;
  code?: string;
  user_count?: number;
}

type TabId = 'matrix' | 'attrs' | 'scope' | 'meta';

const TABS: ReadonlyArray<{
  id: TabId;
  labelKey: string;
  icon: typeof ShieldCheck;
}> = [
  { id: 'matrix', labelKey: 'settings.roles.editor.tab_matrix', icon: ShieldCheck },
  { id: 'attrs', labelKey: 'settings.roles.editor.tab_attrs', icon: Lock },
  { id: 'scope', labelKey: 'settings.roles.editor.tab_scope', icon: Layers },
  { id: 'meta', labelKey: 'settings.roles.editor.tab_meta', icon: Settings },
];

/**
 * UI re-align (#865) — Settings → Role i uprawnienia → /:id editor per
 * `Zrodla/Front_Claude_Design/PIM-nowoczesny/settings/roles.jsx`
 * §RoleEditorPage.
 *
 * Structure shift vs #847:
 *   - 4 tabs (`Macierz uprawnień / Uprawnienia per atrybut / Locale &
 *     Channel scope / Metadane`) in a single `rounded-3xl` card body
 *     INSTEAD of 5 flat stacked card sections.
 *   - Header surfaces breadcrumb + title + system/platform/unique/user-count
 *     badges + role color dot.
 *   - System-template notice and platform-level warning render above the
 *     tab card when applicable.
 *   - Matrix tab body wraps the existing PermissionMatrix with quick-start
 *     preset buttons (`Wyzeruj wszystko / Tylko read-only / Skopiuj z
 *     Catalog Manager`) — presets call into local state, do not touch backend.
 *   - Sticky bottom action bar adds the "System template — nie można usunąć"
 *     hint per design + delete (custom) + cancel + save.
 *
 * Save pipeline preserved verbatim from #847:
 *   1. PATCH /api/roles/{id} (identity + permission_codes + auto-grant)
 *   2. PUT /api/roles/{id}/attribute-permissions (replacement set)
 * Both run in sequence; failure leaves draft intact for retry.
 */
export function RoleEditorPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const params = useParams<{ id?: string }>();
  const isEdit = Boolean(params.id);

  const [role, setRole] = useState<RoleDetail | null>(null);
  const [activeTab, setActiveTab] = useState<TabId>('matrix');

  // Identity section state
  const [name, setName] = useState('');
  const [code, setCode] = useState('');
  const [codeTouched, setCodeTouched] = useState(false);
  const [description, setDescription] = useState('');

  // Advanced state (auto-grant flag)
  const [autoGrant, setAutoGrant] = useState(false);

  // Matrix state
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [groups, setGroups] = useState<PermissionGroup[]>([]);

  // Attribute permissions state
  const [attrGroups, setAttrGroups] = useState<AttributePermsApiGroup[]>([]);
  const [attrDraft, setAttrDraft] = useState<Record<string, AttributePermissionLevel>>({});

  // Original snapshots for dirty tracking
  const [originalIdentity, setOriginalIdentity] = useState({ name: '', description: '' });
  const [originalAutoGrant, setOriginalAutoGrant] = useState(false);
  const [originalPermissions, setOriginalPermissions] = useState<Set<string>>(new Set());
  const [originalAttrDraft, setOriginalAttrDraft] = useState<
    Record<string, AttributePermissionLevel>
  >({});

  const [loading, setLoading] = useState(true);
  const [submitting, setSubmitting] = useState(false);
  const [deleting, setDeleting] = useState(false);

  // Catalogue of all roles — used by Matrix tab "Skopiuj z Catalog Manager"
  // preset and for surfacing the role's user count badge in the header.
  const { result: rolesResult } = useList<RoleListItem>({
    resource: 'roles',
    pagination: { mode: 'off' },
  });
  const rolesCatalogue: RoleListItem[] = rolesResult?.data ?? [];

  // Load catalogue + role detail + attribute permissions in parallel.
  useEffect(() => {
    let cancelled = false;
    setLoading(true);
    const tasks: Array<Promise<unknown>> = [
      jsonFetch<PermissionsResponse>('/api/permissions', { method: 'GET' })
        .then((data) => {
          if (!cancelled) setGroups(data.member);
        })
        .catch(() => {
          if (!cancelled) toast.error(t('settings.roles.editor.error_load_permissions'));
        }),
    ];

    if (isEdit && params.id) {
      tasks.push(
        jsonFetch<RoleDetail>(`/api/roles/${params.id}`, { method: 'GET' })
          .then((data) => {
            if (cancelled) return;
            setRole(data);
            setName(data.name);
            setCode(data.code);
            setCodeTouched(true);
            setDescription(data.description ?? '');
            setSelected(new Set(data.permission_codes));
            setAutoGrant(data.auto_grant_new_object_types);
            setOriginalIdentity({ name: data.name, description: data.description ?? '' });
            setOriginalAutoGrant(data.auto_grant_new_object_types);
            setOriginalPermissions(new Set(data.permission_codes));
          })
          .catch(() => {
            if (!cancelled) toast.error(t('settings.roles.editor.error_load_role'));
          }),
        jsonFetch<AttrPermsResponse>(`/api/roles/${params.id}/attribute-permissions`, {
          method: 'GET',
        })
          .then((data) => {
            if (cancelled) return;
            setAttrGroups(data.groups);
            const seed: Record<string, AttributePermissionLevel> = {};
            for (const group of data.groups) {
              for (const attr of group.attributes) {
                seed[attr.id] = attr.permission_level;
              }
            }
            setAttrDraft(seed);
            setOriginalAttrDraft(seed);
          })
          .catch(() => {
            if (!cancelled) toast.error(t('settings.roles.attr_perms.error_load'));
          }),
      );
    }

    Promise.all(tasks).finally(() => {
      if (!cancelled) setLoading(false);
    });
    return () => {
      cancelled = true;
    };
  }, [isEdit, params.id, t]);

  // Auto-derive code from name on create only.
  useEffect(() => {
    if (codeTouched || isEdit) return;
    setCode(slugify(name));
  }, [name, codeTouched, isEdit]);

  const isSystem = role?.type === 'system';
  const isCustom = role?.type === 'custom';
  const scope = role ? resolveRoleScope(role) : 'tenant';
  const color = resolveRoleColor(role?.code ?? '');

  const togglePermission = (permissionCode: string) => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(permissionCode)) next.delete(permissionCode);
      else next.add(permissionCode);
      return next;
    });
  };

  const applyPreset = (preset: 'deny_all' | 'read_only' | 'from_catalog_manager') => {
    if (preset === 'deny_all') {
      setSelected(new Set());
      return;
    }
    if (preset === 'read_only') {
      const next = new Set<string>();
      for (const group of groups) {
        for (const perm of group.permissions) {
          if (
            perm.code.endsWith('.view') ||
            perm.code.endsWith('.view_own') ||
            perm.code === 'audit.own' ||
            perm.code === 'tokens.own'
          ) {
            next.add(perm.code);
          }
        }
      }
      setSelected(next);
      return;
    }
    // from_catalog_manager — copy permission_codes via /api/roles/{id} fetch
    const cm = rolesCatalogue.find((r) => r.code === 'catalog_manager');
    if (!cm) {
      toast.error(
        t('settings.roles.editor.preset_no_catalog_manager', {
          defaultValue: 'Brak roli Catalog Manager w katalogu.',
        }),
      );
      return;
    }
    jsonFetch<RoleDetail>(`/api/roles/${cm.id}`, { method: 'GET' })
      .then((cmDetail) => {
        setSelected(new Set(cmDetail.permission_codes));
        toast.success(
          t('settings.roles.editor.preset_applied_catalog_manager', {
            count: cmDetail.permission_codes.length,
            defaultValue: 'Skopiowano {{count}} uprawnień z Catalog Manager.',
          }),
        );
      })
      .catch(() => {
        toast.error(t('settings.roles.editor.error_load_role'));
      });
  };

  const counts = useMemo(() => {
    // #2881 — count what the matrix actually renders. The denominator used
    // to include the legacy codes the builder no longer offers, so the badge
    // read "11 / 127" over a screen showing 61 rows.
    let totalCells = 0;
    for (const group of visibleGroups(groups, selected)) totalCells += group.permissions.length;
    return { selected: selected.size, total: totalCells };
  }, [groups, selected]);

  const attrOverrideCount = useMemo(
    () => Object.values(attrDraft).filter((l) => l !== null).length,
    [attrDraft],
  );

  const isDirty = useMemo(() => {
    if (!isEdit) return name.trim().length > 0;
    if (name !== originalIdentity.name) return true;
    if ((description ?? '') !== originalIdentity.description) return true;
    if (autoGrant !== originalAutoGrant) return true;
    if (selected.size !== originalPermissions.size) return true;
    for (const c of selected) {
      if (!originalPermissions.has(c)) return true;
    }
    const draftKeys = Object.keys(attrDraft);
    const origKeys = Object.keys(originalAttrDraft);
    if (draftKeys.length !== origKeys.length) return true;
    for (const id of draftKeys) {
      if (attrDraft[id] !== (originalAttrDraft[id] ?? null)) return true;
    }
    return false;
  }, [
    isEdit,
    name,
    description,
    autoGrant,
    selected,
    attrDraft,
    originalIdentity,
    originalAutoGrant,
    originalPermissions,
    originalAttrDraft,
  ]);

  const handleReset = () => {
    if (!role) return;
    setName(role.name);
    setDescription(role.description ?? '');
    setSelected(new Set(role.permission_codes));
    setAutoGrant(role.auto_grant_new_object_types);
    setAttrDraft(originalAttrDraft);
  };

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    if (submitting || name.trim().length === 0) return;
    setSubmitting(true);
    // Track whether the base-permissions PATCH already persisted, so a later
    // failure in the attribute-permissions PUT can report the partial success
    // instead of a blanket "everything failed" (the two calls are independent).
    let baseSaved = false;
    try {
      const permissionCodes = Array.from(selected);

      if (isEdit && role) {
        const body: Record<string, unknown> = {
          permission_codes: permissionCodes,
          auto_grant_new_object_types: autoGrant,
          description: description.trim() || null,
        };
        if (isCustom) body.name = name.trim();
        await jsonFetch(`/api/roles/${role.id}`, {
          method: 'PATCH',
          body,
          accept: 'application/json',
          contentType: 'application/json',
        });
        baseSaved = true;

        const attrPayload = Object.entries(attrDraft)
          .filter(([, level]) => level !== null)
          .map(([attribute_id, permission_level]) => ({ attribute_id, permission_level }));
        await jsonFetch(`/api/roles/${role.id}/attribute-permissions`, {
          method: 'PUT',
          body: { attribute_permissions: attrPayload },
          accept: 'application/json',
          contentType: 'application/json',
        });

        setOriginalIdentity({ name: name.trim(), description: description.trim() });
        setOriginalAutoGrant(autoGrant);
        setOriginalPermissions(new Set(permissionCodes));
        setOriginalAttrDraft({ ...attrDraft });
        toast.success(t('settings.roles.editor.toast_updated', { name: name.trim() }));
      } else {
        const created = await jsonFetch<RoleDetail>('/api/roles', {
          method: 'POST',
          body: {
            name: name.trim(),
            code: code.trim() || undefined,
            description: description.trim() || null,
            permission_codes: permissionCodes,
            auto_grant_new_object_types: autoGrant,
          },
          accept: 'application/json',
          contentType: 'application/json',
        });
        toast.success(t('settings.roles.editor.toast_created', { name: created.name }));
        navigate(`/settings/roles/${created.id}/edit`, { replace: true });
        return;
      }
    } catch (error: unknown) {
      const status = (error as { status?: number; body?: ApiProblem })?.status;
      const body = (error as { body?: ApiProblem })?.body;
      // The server's RFC 7807 `detail` (or the synthesized non-JSON message
      // http.ts builds for HTML fatal pages) is far more useful than a blanket
      // "operation failed" — surface it wherever we don't have a tailored copy.
      const detail = httpErrorDetail(error);
      if (status === 409 && body?.code === 'duplicate_code') {
        toast.error(body?.detail ?? t('settings.roles.editor.error_duplicate'));
      } else if (status === 400) {
        toast.error(detail ?? body?.detail ?? t('settings.roles.editor.error_validation'));
      } else if (status === 403) {
        toast.error(t('settings.roles.editor.error_forbidden'));
      } else if (baseSaved) {
        // PATCH persisted the base permissions; only the attribute-permissions
        // PUT failed — tell the operator their core change was NOT lost.
        toast.error(detail ?? t('settings.roles.editor.error_attribute_permissions'));
      } else {
        toast.error(detail ?? t('settings.roles.editor.error_generic'));
      }
    } finally {
      setSubmitting(false);
    }
  };

  const handleDelete = async () => {
    if (!role || !isCustom || deleting) return;
    if (!window.confirm(t('settings.roles.editor.confirm_delete', { name: role.name }))) return;
    setDeleting(true);
    try {
      await jsonFetch(`/api/roles/${role.id}`, { method: 'DELETE', accept: 'application/json' });
      toast.success(t('settings.roles.editor.toast_deleted', { name: role.name }));
      navigate('/settings/roles', { replace: true });
    } catch (error: unknown) {
      const status = (error as { status?: number; body?: ApiProblem })?.status;
      const body = (error as { body?: ApiProblem })?.body;
      if (status === 409 && body?.code === 'role_in_use') {
        toast.error(t('settings.roles.editor.error_in_use', { count: body?.user_count ?? 0 }));
      } else if (status === 403) {
        toast.error(t('settings.roles.editor.error_delete_forbidden'));
      } else {
        toast.error(t('settings.roles.editor.error_generic'));
      }
    } finally {
      setDeleting(false);
    }
  };

  const userCount = useMemo(() => {
    if (!role) return null;
    const fromCatalogue = rolesCatalogue.find((r) => r.id === role.id);
    return fromCatalogue?.user_count ?? null;
  }, [role, rolesCatalogue]);

  return (
    <form onSubmit={handleSubmit} className="pb-24">
      <header className="mb-6 flex items-start gap-4">
        <button
          type="button"
          onClick={() => navigate('/settings/roles')}
          className="mt-1 grid size-9 shrink-0 place-items-center rounded-xl bg-white text-zinc-600 shadow-[0_1px_2px_rgba(0,0,0,0.04),0_4px_12px_rgba(0,0,0,0.04)] transition hover:bg-zinc-50 hover:text-zinc-900"
          aria-label={t('settings.roles.editor.back')}
        >
          <ArrowLeft className="size-4" />
        </button>
        <div className="min-w-0 flex-1">
          <div className="mb-1 text-[11.5px] text-zinc-500">
            <button
              type="button"
              onClick={() => navigate('/settings/roles')}
              className="hover:text-zinc-900"
            >
              {t('settings.roles.title')}
            </button>
            <span className="mx-1.5 text-zinc-300">/</span>
            <span className="text-zinc-700">
              {isEdit
                ? (role?.name ?? '...')
                : t('settings.roles.editor.title_create_short', {
                    defaultValue: 'Nowa custom role',
                  })}
            </span>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            {role ? <span className={cn('size-2.5 rounded-full', color.dot)} aria-hidden /> : null}
            {isEdit && isSystem ? (
              <h2 className="text-[24px] font-semibold tracking-tight text-zinc-900">
                {role?.name ?? '...'}
              </h2>
            ) : (
              <Input
                value={name}
                disabled={isSystem || loading}
                onChange={(e) => setName(e.target.value)}
                placeholder={t('settings.roles.editor.field_name_placeholder')}
                className="h-auto min-w-[320px] border-0 border-b border-zinc-200 bg-transparent p-0 px-1 text-[24px] font-semibold tracking-tight focus-visible:border-zinc-900 focus-visible:ring-0"
              />
            )}
            {isSystem ? (
              <span className="inline-flex items-center gap-1 rounded bg-zinc-100 px-1.5 py-0.5 text-[10.5px] font-medium text-zinc-600">
                <Lock className="size-2.5" aria-hidden />
                {t('settings.roles.badge_system', { defaultValue: 'system' })}
              </span>
            ) : null}
            {scope === 'platform' ? (
              <span className="rounded bg-rose-100 px-1.5 py-0.5 text-[10px] font-medium text-rose-700">
                {t('settings.roles.editor.badge_platform_cross_tenant', {
                  defaultValue: 'platform · cross-tenant',
                })}
              </span>
            ) : null}
            {role?.is_unique ? (
              <span className="rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-700">
                {t('settings.roles.badge_unique', { defaultValue: 'unique · max 1' })}
              </span>
            ) : null}
            {userCount !== null ? (
              <span className="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[10.5px] text-zinc-500">
                {t('settings.roles.editor.user_count', {
                  count: userCount,
                  defaultValue: '{{count}} użytkowników',
                })}
              </span>
            ) : null}
          </div>
          {description ? (
            <p className="mt-2 max-w-2xl text-[12.5px] text-zinc-500">{description}</p>
          ) : null}
        </div>
      </header>

      {isSystem ? (
        <div className="mb-4 flex items-start gap-2 rounded-2xl border border-zinc-200 bg-zinc-50 px-4 py-3 text-[12px] text-zinc-700">
          <Info className="mt-0.5 size-4 shrink-0 text-zinc-500" aria-hidden />
          <span>
            <span className="font-medium text-zinc-900">
              {t('settings.roles.editor.system_notice_title', {
                defaultValue: 'System template:',
              })}{' '}
            </span>
            {t('settings.roles.editor.system_notice_body', {
              defaultValue:
                'nazwa i kod są read-only, ale możesz dostosowywać permissions, restrykcje atrybutów, locale & channel scope. Roli nie można usunąć.',
            })}
          </span>
        </div>
      ) : null}

      {scope === 'platform' ? (
        <div className="mb-4 flex items-start gap-2 rounded-2xl border border-rose-200 bg-rose-50 px-4 py-3 text-[12px] text-rose-800">
          <AlertTriangle className="mt-0.5 size-4 shrink-0" aria-hidden />
          <span>
            <span className="font-medium">
              {t('settings.roles.editor.platform_notice_title', {
                defaultValue: 'Platform-level role.',
              })}{' '}
            </span>
            {t('settings.roles.editor.platform_notice_body', {
              defaultValue:
                'Edycja wymaga uprawnień operatora platformy + MFA re-auth. Każda zmiana logowana jako SUPER_ADMIN_RECOVERY.',
            })}
          </span>
        </div>
      ) : null}

      <div className="overflow-hidden rounded-3xl bg-white shadow-[0_1px_2px_rgba(0,0,0,0.04),0_4px_12px_rgba(0,0,0,0.04)]">
        <div className="flex items-center gap-1 overflow-x-auto border-b border-zinc-100 px-5 pt-3">
          {TABS.map((tab) => {
            const Icon = tab.icon;
            const active = activeTab === tab.id;
            const meta =
              tab.id === 'matrix'
                ? `${counts.selected}/${counts.total}`
                : tab.id === 'attrs' && attrOverrideCount > 0
                  ? String(attrOverrideCount)
                  : null;
            return (
              <button
                key={tab.id}
                type="button"
                onClick={() => setActiveTab(tab.id)}
                className={cn(
                  'relative flex h-11 shrink-0 items-center gap-1.5 px-3.5 text-[12.5px] font-medium transition',
                  active ? 'text-zinc-900' : 'text-zinc-500 hover:text-zinc-900',
                )}
              >
                <Icon className={cn('size-3.5', active ? 'text-zinc-900' : 'text-zinc-500')} />
                {t(tab.labelKey)}
                {meta ? (
                  <span
                    className={cn(
                      'rounded-md px-1.5 py-0.5 font-mono text-[10.5px]',
                      active
                        ? 'bg-zinc-900 text-white'
                        : tab.id === 'attrs'
                          ? 'bg-amber-100 text-amber-800 ring-1 ring-amber-200'
                          : 'bg-zinc-100 text-zinc-500',
                    )}
                  >
                    {meta}
                  </span>
                ) : null}
                {active ? (
                  <span className="absolute inset-x-0 -bottom-px h-[2px] rounded-t bg-zinc-900" />
                ) : null}
              </button>
            );
          })}
        </div>

        <div className="px-5 py-6">
          {activeTab === 'matrix' ? (
            <div className="space-y-4">
              {!isSystem || scope !== 'platform' ? (
                <div className="flex flex-wrap items-center gap-2">
                  <div className="text-[11.5px] text-zinc-500">
                    {t('settings.roles.editor.quick_start_label', {
                      defaultValue: 'Szybki start:',
                    })}
                  </div>
                  <button
                    type="button"
                    onClick={() => applyPreset('deny_all')}
                    disabled={loading}
                    className="h-7 rounded-lg border border-zinc-200 bg-white px-2.5 text-[11.5px] text-zinc-700 transition hover:bg-zinc-50 disabled:opacity-60"
                  >
                    {t('settings.roles.editor.preset_deny_all', {
                      defaultValue: 'Wyzeruj wszystko',
                    })}
                  </button>
                  <button
                    type="button"
                    onClick={() => applyPreset('read_only')}
                    disabled={loading}
                    className="h-7 rounded-lg border border-zinc-200 bg-white px-2.5 text-[11.5px] text-zinc-700 transition hover:bg-zinc-50 disabled:opacity-60"
                  >
                    {t('settings.roles.editor.preset_read_only', {
                      defaultValue: 'Tylko read-only',
                    })}
                  </button>
                  <button
                    type="button"
                    onClick={() => applyPreset('from_catalog_manager')}
                    disabled={loading || rolesCatalogue.length === 0}
                    className="h-7 rounded-lg border border-zinc-200 bg-white px-2.5 text-[11.5px] text-zinc-700 transition hover:bg-zinc-50 disabled:opacity-60"
                  >
                    {t('settings.roles.editor.preset_catalog_manager', {
                      defaultValue: 'Skopiuj z Catalog Manager',
                    })}
                  </button>
                </div>
              ) : null}

              {loading ? (
                <div className="h-64 animate-pulse rounded-md border bg-muted/30" />
              ) : (
                <PermissionMatrixAccordion
                  groups={groups}
                  selectedCodes={selected}
                  onToggle={togglePermission}
                  onToggleGroup={(group, allOn) => {
                    setSelected((prev) => {
                      const next = new Set(prev);
                      if (allOn) {
                        for (const p of group.permissions) next.delete(p.code);
                      } else {
                        for (const p of group.permissions) next.add(p.code);
                      }
                      return next;
                    });
                  }}
                  disabled={submitting || deleting}
                />
              )}
            </div>
          ) : null}

          {activeTab === 'attrs' ? (
            isEdit ? (
              <AttributePermissionsSection
                groups={attrGroups}
                draft={attrDraft}
                onChange={setAttrDraft}
                loading={loading}
                disabled={submitting || deleting}
              />
            ) : (
              <div className="rounded-md border border-dashed bg-muted/30 px-3 py-4 text-center text-xs text-muted-foreground">
                {t('settings.roles.attr_perms.create_first')}
              </div>
            )
          ) : null}

          {activeTab === 'scope' ? (
            <div className="rounded-2xl border border-dashed border-zinc-200 bg-zinc-50/50 p-6 text-center">
              <Layers className="mx-auto mb-2 size-6 text-zinc-500" aria-hidden />
              <div className="text-[13px] font-medium text-zinc-900">
                {t('settings.roles.editor.scope_tab_title', {
                  defaultValue: 'Locale & Channel scope',
                })}
              </div>
              <p className="mt-1 text-[12px] text-zinc-500">
                {t('settings.roles.editor.scope_deferred_notice')}
              </p>
            </div>
          ) : null}

          {activeTab === 'meta' ? (
            <div className="space-y-4">
              <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5">
                  <Label htmlFor="role-name">{t('settings.roles.editor.field_name')}</Label>
                  <Input
                    id="role-name"
                    required
                    value={name}
                    disabled={isSystem || loading}
                    onChange={(e) => setName(e.target.value)}
                    placeholder={t('settings.roles.editor.field_name_placeholder')}
                    maxLength={80}
                    autoComplete="off"
                  />
                </div>
                <div className="space-y-1.5">
                  <Label htmlFor="role-code">{t('settings.roles.editor.field_code')}</Label>
                  <Input
                    id="role-code"
                    value={code}
                    disabled={isSystem || isEdit || loading}
                    onChange={(e) => {
                      setCodeTouched(true);
                      setCode(e.target.value);
                    }}
                    placeholder={t('settings.roles.editor.field_code_placeholder')}
                    maxLength={64}
                    autoComplete="off"
                    className="font-mono"
                  />
                  <p className="text-[11px] text-muted-foreground">
                    {t('settings.roles.editor.field_code_hint')}
                  </p>
                </div>
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="role-description">
                  {t('settings.roles.editor.field_description')}
                </Label>
                <textarea
                  id="role-description"
                  value={description}
                  disabled={isSystem || loading}
                  onChange={(e) => setDescription(e.target.value)}
                  rows={3}
                  maxLength={500}
                  placeholder={t('settings.roles.editor.field_description_placeholder')}
                  className="w-full rounded-md border border-input bg-background p-2 text-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                />
                <p className="text-[11px] text-muted-foreground">
                  {t('settings.roles.editor.field_description_hint')}
                </p>
              </div>
              <label className="flex items-start gap-3 rounded-md border bg-background px-3 py-2 text-sm">
                <input
                  type="checkbox"
                  className="mt-0.5 size-4"
                  checked={autoGrant}
                  disabled={loading || submitting}
                  onChange={(e) => setAutoGrant(e.target.checked)}
                />
                <div className="flex-1 space-y-0.5">
                  <div className="font-medium">{t('settings.roles.editor.auto_grant_label')}</div>
                  <p className="text-[11px] text-muted-foreground">
                    {t('settings.roles.editor.auto_grant_hint')}
                  </p>
                </div>
              </label>
            </div>
          ) : null}
        </div>
      </div>

      <div className="fixed bottom-0 left-0 right-0 z-20 border-t border-zinc-200 bg-white/95 px-4 py-3 backdrop-blur md:left-[260px] md:px-8">
        <div className="mx-auto flex max-w-7xl flex-wrap items-center gap-2">
          {isCustom ? (
            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={handleDelete}
              disabled={submitting || deleting}
              className="h-10 gap-1.5 rounded-xl px-3 text-[13px] text-rose-700 hover:bg-rose-50"
            >
              <Trash2 className="size-4" aria-hidden />
              {t('settings.roles.editor.delete')}
            </Button>
          ) : null}
          {isSystem ? (
            <div className="flex items-center gap-1.5 text-[11.5px] text-zinc-500">
              <Lock className="size-3.5" aria-hidden />
              {t('settings.roles.editor.system_template_cannot_delete', {
                defaultValue: 'System template — nie można usunąć',
              })}
            </div>
          ) : null}
          <div className="ml-2 hidden text-[11.5px] text-zinc-500 sm:inline-flex sm:items-center sm:gap-1">
            <FileText className="size-3.5" aria-hidden />
            {t('settings.roles.editor.cache_invalidation_note', {
              defaultValue:
                'Zmiana macierzy permissions invalidates cache wszystkich userów z tą rolą (Mercure SSE event).',
            })}
          </div>
          <div className="ml-auto flex items-center gap-2">
            {isEdit ? (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                onClick={handleReset}
                disabled={!isDirty || submitting || deleting}
                className="h-10 rounded-xl px-4 text-[13px] text-zinc-700 hover:bg-zinc-100"
              >
                {t('settings.roles.editor.reset')}
              </Button>
            ) : null}
            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={() => navigate('/settings/roles')}
              disabled={submitting}
              className="h-10 rounded-xl px-4 text-[13px] text-zinc-700 hover:bg-zinc-100"
            >
              {t('settings.roles.editor.cancel')}
            </Button>
            <Button
              type="submit"
              size="sm"
              disabled={submitting || deleting || name.trim().length === 0 || (isEdit && !isDirty)}
              className={cn(
                'h-10 rounded-xl px-4 text-[13px] font-medium',
                isDirty && name.trim().length > 0 && !submitting
                  ? 'bg-zinc-900 text-white hover:bg-zinc-800'
                  : 'bg-zinc-200 text-zinc-500',
              )}
            >
              <Check className="mr-1.5 size-4" aria-hidden />
              {submitting
                ? t('settings.roles.editor.saving')
                : isEdit
                  ? t('settings.roles.editor.save_changes')
                  : t('settings.roles.editor.save_create')}
            </Button>
          </div>
        </div>
      </div>
    </form>
  );
}

function slugify(input: string): string {
  return input
    .normalize('NFD')
    .replace(/[̀-ͯ]/g, '')
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '');
}

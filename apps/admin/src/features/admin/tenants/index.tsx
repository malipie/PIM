import {
  Ban,
  Building2,
  Loader2,
  MoreHorizontal,
  Play,
  Plus,
  ShieldAlert,
  Trash2,
  Users,
} from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { GatedButton } from '@/components/identity';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { toast } from '@/components/ui/toast';
import { jsonFetch } from '@/lib/http';
import { cn } from '@/lib/utils';

import { AdminTenantShowPage } from './AdminTenantShowPage';
import { CreateTenantModal } from './CreateTenantModal';
import { ProvisioningProgress } from './ProvisioningProgress';
import type { AdminTenantSummary, TenantStatus } from './types';

export { AdminTenantShowPage };

interface ListResponse {
  member: AdminTenantSummary[];
  totalItems: number;
}

type PlanFilter = 'all' | string;
type StatusFilter = 'all' | TenantStatus;

/**
 * RBAC-P5-019 (#709) + RBAC-P5-021 (#711) — Super Admin operator
 * panel: tenant list with full lifecycle CRUD (create + suspend /
 * reactivate / soft-delete).
 *
 * Lives at `/admin/tenants` inside the existing admin app. The
 * long-term home per the ticket spec is the dedicated
 * `admin.cortex.pl` subdomain; that's an operator infra task
 * (Caddyfile + cookie domain config) and not a blocker for the
 * functional substrate — the page works today on the main domain
 * gated by the `super_admin` role check on the backend.
 *
 * **Privacy boundary:** the wire shape carries metadata only (tenant
 * identity, plan, status, locale config, active-user counter). Tenant
 * domain data (products, attributes, values) is NEVER exposed here —
 * the audit row stamps `cross_tenant_access=true` on every read so the
 * forensic trail is mechanical.
 */
export function AdminTenantsListPage() {
  const { t } = useTranslation();
  const [tenants, setTenants] = useState<AdminTenantSummary[]>([]);
  const [loading, setLoading] = useState(true);
  const [forbidden, setForbidden] = useState(false);
  const [error, setError] = useState(false);
  const [createOpen, setCreateOpen] = useState(false);

  const [search, setSearch] = useState('');
  const [planFilter, setPlanFilter] = useState<PlanFilter>('all');
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all');

  const reload = useCallback(() => {
    setLoading(true);
    setError(false);
    jsonFetch<ListResponse>('/api/admin/tenants', { method: 'GET' })
      .then((data) => setTenants(data.member))
      .catch((err: unknown) => {
        if ((err as { status?: number })?.status === 403) {
          setForbidden(true);
        } else {
          setError(true);
        }
      })
      .finally(() => setLoading(false));
  }, []);

  useEffect(reload, [reload]);

  const plans = useMemo(() => {
    const seen = new Set<string>();
    for (const tenant of tenants) seen.add(tenant.plan);
    return Array.from(seen).sort();
  }, [tenants]);

  const filtered = useMemo(() => {
    const needle = search.trim().toLowerCase();
    return tenants.filter((tenant) => {
      if (planFilter !== 'all' && tenant.plan !== planFilter) return false;
      if (statusFilter !== 'all' && tenant.status !== statusFilter) return false;
      if (needle.length > 0) {
        const haystack = `${tenant.code} ${tenant.name} ${tenant.domain ?? ''}`.toLowerCase();
        if (!haystack.includes(needle)) return false;
      }
      return true;
    });
  }, [tenants, search, planFilter, statusFilter]);

  if (forbidden) {
    return (
      <div className="mx-auto max-w-md rounded-lg border border-amber-200 bg-amber-50 p-6 text-center text-sm text-amber-800">
        <ShieldAlert className="mx-auto mb-2 size-8" aria-hidden="true" />
        <h2 className="display mb-1 text-lg font-semibold">{t('admin.tenants.forbidden_title')}</h2>
        <p>{t('admin.tenants.forbidden_description')}</p>
      </div>
    );
  }

  return (
    <div className="space-y-4">
      <header className="flex items-start justify-between gap-4">
        <div>
          <div className="flex items-baseline gap-2">
            <Building2 className="size-5 text-accent-violet" aria-hidden="true" />
            <h2 className="display text-xl font-semibold tracking-tight">
              {t('admin.tenants.title')}
            </h2>
          </div>
          <p className="max-w-2xl text-sm text-muted-foreground">{t('admin.tenants.intro')}</p>
        </div>
        <GatedButton
          permission="platform.tenants.manage"
          size="sm"
          className="gap-1.5"
          onClick={() => setCreateOpen(true)}
        >
          <Plus className="size-4" aria-hidden="true" />
          {t('admin.tenants.create_cta')}
        </GatedButton>
      </header>

      <div className="rounded-md border border-dashed bg-amber-50 px-3 py-2 text-xs text-amber-900">
        {t('admin.tenants.privacy_boundary_notice')}
      </div>

      <div className="rounded-lg border bg-background p-3 shadow-sm">
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
          <Input
            type="search"
            placeholder={t('admin.tenants.search_placeholder')}
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            className="flex-1"
          />
          <label className="inline-flex items-center gap-2 text-xs text-muted-foreground">
            <span>{t('admin.tenants.filter_plan')}:</span>
            <select
              value={planFilter}
              onChange={(e) => setPlanFilter(e.target.value)}
              className="h-9 rounded-md border border-input bg-background px-2 text-xs"
            >
              <option value="all">{t('admin.tenants.filter_plan_all')}</option>
              {plans.map((plan) => (
                <option key={plan} value={plan}>
                  {plan}
                </option>
              ))}
            </select>
          </label>
          <label className="inline-flex items-center gap-2 text-xs text-muted-foreground">
            <span>{t('admin.tenants.filter_status')}:</span>
            <select
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value as StatusFilter)}
              className="h-9 rounded-md border border-input bg-background px-2 text-xs"
            >
              <option value="all">{t('admin.tenants.filter_status_all')}</option>
              <option value="active">{t('admin.tenants.status.active')}</option>
              <option value="suspended">{t('admin.tenants.status.suspended')}</option>
              <option value="deleted">{t('admin.tenants.status.deleted')}</option>
              {/* TNT-P4-06 (#2907) — stany instancji, która dopiero powstaje
                  albo nie powstała. Bez nich operator nie odfiltruje tego, co
                  wymaga jego uwagi. */}
              <option value="provisioning">{t('admin.tenants.status.provisioning')}</option>
              <option value="failed">{t('admin.tenants.status.failed')}</option>
            </select>
          </label>
          <div className="text-xs text-muted-foreground sm:ml-auto">
            {t('admin.tenants.count', { count: filtered.length })}
          </div>
        </div>
      </div>

      {loading ? (
        <div className="flex items-center justify-center py-8 text-muted-foreground">
          <Loader2 className="size-5 animate-spin" aria-hidden="true" />
        </div>
      ) : error ? (
        <div className="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
          {t('admin.tenants.error_loading')}
        </div>
      ) : filtered.length === 0 ? (
        <div className="rounded-md border bg-background px-3 py-8 text-center text-sm text-muted-foreground">
          {t('admin.tenants.empty')}
        </div>
      ) : (
        <div className="overflow-hidden rounded-lg border bg-background shadow-sm">
          <table className="w-full text-sm">
            <thead className="bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
              <tr>
                <th className="px-4 py-2 text-left">{t('admin.tenants.col_tenant')}</th>
                <th className="px-4 py-2 text-left">{t('admin.tenants.col_status')}</th>
                <th className="px-4 py-2 text-left">{t('admin.tenants.col_plan')}</th>
                <th className="px-4 py-2 text-left">{t('admin.tenants.col_users')}</th>
                <th className="px-4 py-2 text-left">{t('admin.tenants.col_locales')}</th>
                <th className="px-4 py-2 text-left">{t('admin.tenants.col_created')}</th>
                <th className="px-4 py-2 text-right" aria-label={t('admin.tenants.col_actions')} />
              </tr>
            </thead>
            <tbody>
              {filtered.map((tenant) => (
                <TenantRow key={tenant.id} tenant={tenant} onChanged={reload} />
              ))}
            </tbody>
          </table>
        </div>
      )}

      <CreateTenantModal open={createOpen} onOpenChange={setCreateOpen} onSuccess={reload} />
    </div>
  );
}

function TenantRow({ tenant, onChanged }: { tenant: AdminTenantSummary; onChanged: () => void }) {
  const { i18n } = useTranslation();
  const createdAt = new Date(tenant.created_at).toLocaleDateString(i18n.language);
  const isDeleted = tenant.status === 'deleted';

  return (
    <tr className={cn('border-t hover:bg-muted/20', isDeleted && 'opacity-60')}>
      <td className="px-4 py-2.5">
        <Link to={`/admin/tenants/${tenant.id}`} className="block">
          <div className="text-sm font-medium hover:underline">{tenant.name}</div>
          <div className="flex items-center gap-2 text-[11px] text-muted-foreground">
            <span className="font-mono">{tenant.code}</span>
            {tenant.domain ? (
              <>
                <span aria-hidden="true">·</span>
                <span>{tenant.domain}</span>
              </>
            ) : null}
          </div>
        </Link>
      </td>
      <td className="px-4 py-2.5">
        <StatusBadge status={tenant.status} />
      </td>
      <td className="px-4 py-2.5">
        <PlanBadge plan={tenant.plan} />
      </td>
      <td className="px-4 py-2.5 text-xs">
        <span className="inline-flex items-center gap-1 text-muted-foreground">
          <Users className="size-3" aria-hidden="true" />
          {tenant.active_users}
        </span>
      </td>
      <td className="px-4 py-2.5">
        <div className="flex flex-wrap gap-1">
          {tenant.enabled_locales.map((locale) => (
            <span
              key={locale}
              className={cn(
                'rounded px-1.5 py-0.5 text-[10px] font-mono ring-1',
                locale === tenant.primary_locale
                  ? 'bg-accent-violet/10 text-accent-violet ring-accent-violet/30'
                  : 'bg-muted text-muted-foreground ring-input',
              )}
            >
              {locale}
            </span>
          ))}
        </div>
      </td>
      <td className="px-4 py-2.5 text-xs text-muted-foreground">{createdAt}</td>
      <td className="px-4 py-2.5 text-right">
        <TenantActions tenant={tenant} onChanged={onChanged} />
      </td>
    </tr>
  );
}

function TenantActions({
  tenant,
  onChanged,
}: {
  tenant: AdminTenantSummary;
  onChanged: () => void;
}) {
  const { t } = useTranslation();
  const [busy, setBusy] = useState(false);
  // TNT-P4-08 (#2909) — przy instancjach per tenant operacja cyklu życia nie
  // kończy się z odpowiedzią HTTP: zatrzymanie albo wznowienie stacku trwa
  // dalej. Gdy API odda identyfikator zlecenia, pokazujemy jego postęp — bez
  // tego „Przywróć" wyglądałoby na akcję bez skutku, bo status wraca na
  // `active` dopiero po potwierdzeniu, że instancja odpowiada.
  const [job, setJob] = useState<{ id: string; action: string } | null>(null);

  const callAction = async (
    path: string,
    method: 'POST' | 'DELETE',
    action: string,
    confirmKey?: string,
  ) => {
    if (confirmKey) {
      const confirmText = t(confirmKey, { name: tenant.name, code: tenant.code });
      if (!window.confirm(confirmText)) return;
    }
    setBusy(true);
    try {
      const response = await jsonFetch<AdminTenantSummary>(
        `/api/admin/tenants/${tenant.id}${path}`,
        { method, accept: 'application/json' },
      );
      if (response.provisioning_job_id) {
        setJob({ id: response.provisioning_job_id, action });
      } else {
        toast.success(t('admin.tenants.actions.toast_done'));
      }
      onChanged();
    } catch (error: unknown) {
      const body = (error as { body?: { detail?: string } })?.body;
      toast.error(body?.detail ?? t('admin.tenants.actions.error'));
    } finally {
      setBusy(false);
    }
  };

  const progress = job ? (
    <Dialog open onOpenChange={() => setJob(null)}>
      <DialogContent className="max-w-md">
        <DialogHeader>
          <DialogTitle>{t(`admin.tenants.lifecycle.title_${job.action}`)}</DialogTitle>
        </DialogHeader>
        <ProvisioningProgress jobId={job.id} variant="lifecycle" onFinished={() => onChanged()} />
        <DialogFooter>
          <Button onClick={() => setJob(null)}>{t('admin.tenants.create.close')}</Button>
        </DialogFooter>
      </DialogContent>
    </Dialog>
  ) : null;

  if (tenant.status === 'deleted') {
    return (
      <>
        <span className="text-[11px] text-muted-foreground">
          {t('admin.tenants.actions.deleted_label')}
        </span>
        {progress}
      </>
    );
  }

  return (
    <>
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button
            variant="ghost"
            size="icon"
            disabled={busy}
            aria-label={t('admin.tenants.col_actions')}
          >
            <MoreHorizontal className="size-4" />
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end">
          {tenant.status === 'active' ? (
            <DropdownMenuItem
              onSelect={() =>
                void callAction(
                  '/suspend',
                  'POST',
                  'suspend',
                  'admin.tenants.actions.confirm_suspend',
                )
              }
            >
              <Ban className="mr-2 size-4 text-amber-700" aria-hidden="true" />
              {t('admin.tenants.actions.suspend')}
            </DropdownMenuItem>
          ) : null}
          {tenant.status === 'suspended' ? (
            <DropdownMenuItem onSelect={() => void callAction('/reactivate', 'POST', 'reactivate')}>
              <Play className="mr-2 size-4 text-emerald-700" aria-hidden="true" />
              {t('admin.tenants.actions.reactivate')}
            </DropdownMenuItem>
          ) : null}
          <DropdownMenuItem
            onSelect={() =>
              void callAction('', 'DELETE', 'delete', 'admin.tenants.actions.confirm_delete')
            }
            className="text-rose-600 focus:text-rose-700"
          >
            <Trash2 className="mr-2 size-4" aria-hidden="true" />
            {t('admin.tenants.actions.delete')}
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>
      {progress}
    </>
  );
}

function StatusBadge({ status }: { status: TenantStatus }) {
  const { t } = useTranslation();
  const classes: Record<TenantStatus, string> = {
    active: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    suspended: 'bg-amber-50 text-amber-800 ring-amber-200',
    deleted: 'bg-rose-50 text-rose-700 ring-rose-200',
    // TNT-P4-06 (#2907) — instancja w trakcie powstawania jest neutralna
    // (nic nie wymaga reakcji), a nieudana czerwona jak usunięta: obie
    // oznaczają, że pod tym adresem nikt się nie zaloguje.
    pending: 'bg-slate-50 text-slate-600 ring-slate-200',
    provisioning: 'bg-sky-50 text-sky-700 ring-sky-200',
    failed: 'bg-rose-50 text-rose-700 ring-rose-200',
  };
  return (
    <span
      className={cn(
        'inline-flex rounded px-2 py-0.5 text-[11px] font-medium uppercase tracking-wide ring-1',
        classes[status],
      )}
    >
      {t(`admin.tenants.status.${status}`)}
    </span>
  );
}

function PlanBadge({ plan }: { plan: string }) {
  const classes: Record<string, string> = {
    starter: 'bg-blue-50 text-blue-700 ring-blue-200',
    growth: 'bg-violet-50 text-violet-700 ring-violet-200',
    scale: 'bg-emerald-50 text-emerald-700 ring-emerald-200',
    pro: 'bg-violet-50 text-violet-700 ring-violet-200',
    enterprise: 'bg-amber-50 text-amber-700 ring-amber-200',
  };
  return (
    <span
      className={cn(
        'inline-flex rounded px-2 py-0.5 text-[11px] font-medium uppercase tracking-wide ring-1',
        classes[plan] ?? 'bg-muted text-muted-foreground ring-input',
      )}
    >
      {plan}
    </span>
  );
}

import { Building2, Globe, Loader2, ShieldAlert } from 'lucide-react';
import { type FormEvent, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { PermissionGate } from '@/components/identity';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toast } from '@/components/ui/toast';
import { HttpError, jsonFetch } from '@/lib/http';
import { cn } from '@/lib/utils';

interface TenantConfig {
  id: string;
  code: string;
  name: string;
  plan: string;
  domain: string | null;
  enabled_locales: string[];
  primary_locale: string;
  created_at: string;
}

/**
 * RBAC-P5-015 (#705) — Settings → Tenant config.
 *
 * Owner-only via `PermissionGate(user.admin)` — non-owners get the
 * shared 403 fallback that the gate provides.
 *
 * MVP scope: rename + primary-locale switch. Channels CRUD lives on
 * its own page (`/settings/channels`, already shipped); deleting the
 * tenant is destructive enough to deserve its own flow (deferred —
 * `cortex:tenant:delete` CLI exists for the operator who really
 * needs it before the danger-zone UI lands).
 */
export function TenantSettingsPage() {
  return (
    <PermissionGate
      anyOf={['user.admin', 'settings.tenant.manage']}
      fallback={<ForbiddenFallback />}
    >
      <TenantConfigForm />
    </PermissionGate>
  );
}

function TenantConfigForm() {
  const { t, i18n } = useTranslation();
  const [tenant, setTenant] = useState<TenantConfig | null>(null);
  const [name, setName] = useState('');
  const [primaryLocale, setPrimaryLocale] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    let cancelled = false;
    (async () => {
      try {
        const data = await jsonFetch<TenantConfig>('/api/tenant', { accept: 'application/json' });
        if (cancelled) return;
        setTenant(data);
        setName(data.name);
        setPrimaryLocale(data.primary_locale);
      } catch {
        if (cancelled) return;
        toast.error(t('settings.tenant.error_loading'));
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [t]);

  const dirty =
    tenant !== null && (name !== tenant.name || primaryLocale !== tenant.primary_locale);

  const cancel = () => {
    if (tenant) {
      setName(tenant.name);
      setPrimaryLocale(tenant.primary_locale);
    }
  };

  const submit = async (event?: FormEvent) => {
    event?.preventDefault();
    if (!tenant || !dirty || saving) return;
    setSaving(true);
    const payload: Record<string, string> = {};
    if (name !== tenant.name) payload.name = name.trim();
    if (primaryLocale !== tenant.primary_locale) payload.primary_locale = primaryLocale;
    try {
      const updated = await jsonFetch<TenantConfig>('/api/tenant', {
        method: 'PATCH',
        body: payload,
        accept: 'application/json',
        contentType: 'application/json',
      });
      setTenant(updated);
      setName(updated.name);
      setPrimaryLocale(updated.primary_locale);
      toast.success(t('settings.tenant.toast_saved'));
    } catch (error) {
      const status = (error as { status?: number })?.status;
      if (status === 409) {
        toast.error(t('settings.tenant.error_primary_not_enabled'));
      } else if (error instanceof HttpError && error.status === 400) {
        toast.error(t('settings.tenant.error_validation'));
      } else {
        toast.error(t('settings.tenant.error_generic'));
      }
    } finally {
      setSaving(false);
    }
  };

  if (loading) {
    return (
      <div className="flex min-h-[320px] items-center justify-center rounded-lg border bg-background p-8">
        <Loader2 className="size-8 animate-spin text-muted-foreground" aria-hidden="true" />
      </div>
    );
  }

  if (!tenant) {
    return null;
  }

  return (
    <div className="space-y-6">
      {/* Action bar (ObjectType-style): ghost Anuluj + navy Save greyed until
          there are unsaved changes. */}
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0 space-y-1">
          <h2 className="display text-xl font-semibold tracking-tight">
            {t('settings.tenant.title')}
          </h2>
          <p className="max-w-2xl text-sm text-muted-foreground">{t('settings.tenant.intro')}</p>
        </div>
        <div className="flex shrink-0 items-center gap-2">
          <Button
            type="button"
            variant="ghost"
            size="sm"
            onClick={cancel}
            disabled={!dirty || saving}
            className="h-9 rounded-xl px-3 text-[12.5px] text-zinc-600"
          >
            {t('app.cancel', { defaultValue: 'Anuluj' })}
          </Button>
          <Button
            type="button"
            onClick={() => void submit()}
            disabled={!dirty || saving}
            className={cn(
              'h-9 rounded-xl px-4 text-[12.5px] font-medium',
              dirty && !saving
                ? 'bg-zinc-900 text-white hover:bg-zinc-800'
                : 'bg-zinc-200 text-zinc-500',
            )}
          >
            {saving ? t('settings.tenant.saving') : t('settings.tenant.save')}
          </Button>
        </div>
      </header>

      <form
        className="rounded-lg border bg-background p-6 shadow-sm"
        onSubmit={submit}
        aria-labelledby="tenant-config-heading"
      >
        <div className="mb-4 flex items-center gap-3">
          <span
            className="inline-grid size-10 place-items-center rounded-md bg-orange-500/10 text-orange-700"
            aria-hidden="true"
          >
            <Building2 className="size-5" />
          </span>
          <div>
            <h3 id="tenant-config-heading" className="text-sm font-semibold">
              {t('settings.tenant.identity_title')}
            </h3>
            <p className="text-xs text-muted-foreground">{t('settings.tenant.identity_intro')}</p>
          </div>
        </div>

        <div className="grid gap-4 sm:grid-cols-2">
          <div className="space-y-1.5">
            <Label htmlFor="tenant-code">{t('settings.tenant.field_code')}</Label>
            <Input id="tenant-code" value={tenant.code} disabled readOnly />
            <p className="text-[11px] text-muted-foreground">{t('settings.tenant.code_hint')}</p>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="tenant-plan">{t('settings.tenant.field_plan')}</Label>
            <Input id="tenant-plan" value={tenant.plan} disabled readOnly />
          </div>
          <div className="space-y-1.5 sm:col-span-2">
            <Label htmlFor="tenant-name">{t('settings.tenant.field_name')}</Label>
            <Input
              id="tenant-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              required
            />
          </div>
        </div>

        <div className="mt-6 space-y-3 border-t pt-6">
          <div className="flex items-center gap-3">
            <span
              className="inline-grid size-10 place-items-center rounded-md bg-cyan-100 text-cyan-700"
              aria-hidden="true"
            >
              <Globe className="size-5" />
            </span>
            <div>
              <h3 className="text-sm font-semibold">{t('settings.tenant.locales_title')}</h3>
              <p className="text-xs text-muted-foreground">{t('settings.tenant.locales_intro')}</p>
            </div>
          </div>
          <div className="space-y-1.5">
            <Label htmlFor="tenant-primary-locale">
              {t('settings.tenant.field_primary_locale')}
            </Label>
            <select
              id="tenant-primary-locale"
              value={primaryLocale}
              onChange={(e) => setPrimaryLocale(e.target.value)}
              className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm text-foreground outline-none focus-visible:ring-2 focus-visible:ring-ring"
            >
              {tenant.enabled_locales.map((locale) => (
                <option key={locale} value={locale}>
                  {locale.toUpperCase()}
                </option>
              ))}
            </select>
            <p className="text-[11px] text-muted-foreground">
              {t('settings.tenant.locales_pending_hint')}
            </p>
          </div>
        </div>
      </form>

      <p className="text-[11px] text-muted-foreground">
        {t('settings.tenant.danger_zone_pending', {
          date: new Date(tenant.created_at).toLocaleDateString(i18n.language),
        })}
      </p>
    </div>
  );
}

function ForbiddenFallback() {
  const { t } = useTranslation();
  return (
    <div className="flex min-h-[320px] flex-col items-center justify-center gap-2 rounded-lg border border-dashed bg-background p-8 text-center">
      <ShieldAlert className="size-8 text-muted-foreground" aria-hidden="true" />
      <h2 className="text-sm font-semibold">{t('settings.tenant.forbidden_title')}</h2>
      <p className="max-w-md text-xs text-muted-foreground">
        {t('settings.tenant.forbidden_description')}
      </p>
    </div>
  );
}

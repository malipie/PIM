import { CreditCard, Mail, ShieldAlert } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { PermissionGate } from '@/components/identity';
import { Button } from '@/components/ui/button';
import { useIdentity } from '@/lib/identity';

/**
 * RBAC-P5-016 (#706) — placeholder for Settings → Billing.
 *
 * Owner-only via {@link PermissionGate}. Accepts either catalogue:
 * legacy `user.admin` (bootstrap principals) or the PRD §3.2
 * `settings.billing.manage` (#2874) — a tenant owner created through the
 * admin UI holds only the latter, and used to be told "only the tenant
 * Owner can see Subscription" while being exactly that. The actual billing integration ships in Faza 1
 * — this page only surfaces the current plan tier read from
 * `/api/auth/me` (extended in this PR) and a mailto link so the
 * Owner has a route to contact support today.
 */
export function BillingSettingsPage() {
  return (
    <PermissionGate
      anyOf={['user.admin', 'settings.billing.manage']}
      fallback={<ForbiddenFallback />}
    >
      <BillingPlaceholder />
    </PermissionGate>
  );
}

function BillingPlaceholder() {
  const { t } = useTranslation();
  const { identity } = useIdentity();
  const plan = identity?.tenant?.plan ?? null;

  return (
    <div className="space-y-6">
      <header className="space-y-1">
        <h2 className="display text-xl font-semibold tracking-tight">
          {t('settings.billing.title')}
        </h2>
        <p className="max-w-2xl text-sm text-muted-foreground">{t('settings.billing.intro')}</p>
      </header>

      <section
        aria-labelledby="settings-billing-current-plan"
        className="rounded-lg border bg-background p-6 shadow-sm"
      >
        <div className="flex items-start gap-4">
          <span
            className="inline-grid size-10 place-items-center rounded-md bg-orange-500/10 text-orange-700"
            aria-hidden="true"
          >
            <CreditCard className="size-5" />
          </span>
          <div className="flex-1 space-y-1">
            <h3
              id="settings-billing-current-plan"
              className="text-sm font-semibold uppercase tracking-wide text-muted-foreground"
            >
              {t('settings.billing.current_plan_label')}
            </h3>
            <p className="text-2xl font-semibold tracking-tight">
              {plan ? t(`settings.billing.plans.${plan}`, { defaultValue: plan }) : '—'}
            </p>
            <p className="text-xs text-muted-foreground">{t('settings.billing.upgrade_hint')}</p>
          </div>
        </div>
      </section>

      <section
        aria-labelledby="settings-billing-coming-soon"
        className="rounded-lg border border-dashed bg-muted/30 p-6"
      >
        <div className="flex items-start gap-4">
          <span
            className="inline-grid size-10 place-items-center rounded-md bg-amber-100 text-amber-700"
            aria-hidden="true"
          >
            <ShieldAlert className="size-5" />
          </span>
          <div className="space-y-2">
            <h3 id="settings-billing-coming-soon" className="text-sm font-semibold tracking-tight">
              {t('settings.billing.coming_soon_title')}
            </h3>
            <p className="max-w-xl text-sm text-muted-foreground">
              {t('settings.billing.coming_soon_description')}
            </p>
            <Button asChild size="sm" variant="outline" className="gap-1.5">
              <a href="mailto:support@cortex.pl?subject=Billing%20enquiry">
                <Mail className="size-4" aria-hidden="true" />
                {t('settings.billing.contact_support')}
              </a>
            </Button>
          </div>
        </div>
      </section>
    </div>
  );
}

function ForbiddenFallback() {
  const { t } = useTranslation();
  return (
    <div className="flex min-h-[320px] flex-col items-center justify-center gap-2 rounded-lg border border-dashed bg-background p-8 text-center">
      <ShieldAlert className="size-8 text-muted-foreground" aria-hidden="true" />
      <h2 className="text-sm font-semibold">{t('settings.billing.forbidden_title')}</h2>
      <p className="max-w-md text-xs text-muted-foreground">
        {t('settings.billing.forbidden_description')}
      </p>
    </div>
  );
}

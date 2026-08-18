import { Copy, Mail, Plus } from 'lucide-react';
import { type FormEvent, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toast } from '@/components/ui/toast';
import { jsonFetch } from '@/lib/http';

import { ProvisioningProgress } from './ProvisioningProgress';
import { instanceUrl, subdomainShapeError, suggestSubdomain } from './subdomain';
import type { AdminTenantSummary } from './types';

/**
 * Domena bazowa instancji. Panel pokazuje pełny adres pod polem, żeby operator
 * widział, co dokładnie powstanie — sama subdomena bez kontekstu bywa myląca.
 */
const BASE_DOMAIN = 'app.harmonpim.pl';

interface ApiProblem {
  detail?: string;
  code?: string;
}

interface Props {
  open: boolean;
  onOpenChange: (next: boolean) => void;
  onSuccess: () => void;
}

/**
 * RBAC-P5-021 (#711) — Super Admin: create new tenant.
 *
 * Calls `POST /api/admin/tenants` which auto-seeds PRD roles and
 * provisions the default Owner via the existing InvitationService.
 * The owner receives an invitation email (Mailpit in dev) so they can
 * set their password without operator handoff.
 *
 * Form scope per operator spec:
 *   - code (slug, validated server-side against /[a-z0-9_-]{2,64}/)
 *   - name
 *   - owner_email (manual entry — no autocomplete from existing users
 *     because new tenants don't share user space)
 *   - plan dropdown (default starter)
 */
export function CreateTenantModal({ open, onOpenChange, onSuccess }: Props) {
  const { t } = useTranslation();
  const [code, setCode] = useState('');
  const [subdomain, setSubdomain] = useState('');
  // Operator mógł nadpisać podpowiedź — wtedy przestajemy ją przeliczać z kodu.
  const [subdomainTouched, setSubdomainTouched] = useState(false);
  const [jobId, setJobId] = useState<string | null>(null);
  const [name, setName] = useState('');
  const [ownerEmail, setOwnerEmail] = useState('');
  const [plan, setPlan] = useState<'starter' | 'pro' | 'enterprise'>('starter');
  const [submitting, setSubmitting] = useState(false);
  const [success, setSuccess] = useState<AdminTenantSummary | null>(null);

  const reset = () => {
    setCode('');
    setSubdomain('');
    setSubdomainTouched(false);
    setJobId(null);
    setName('');
    setOwnerEmail('');
    setPlan('starter');
    setSubmitting(false);
    setSuccess(null);
  };

  const close = (next: boolean) => {
    if (!next) reset();
    onOpenChange(next);
  };

  // Subdomena domyślnie idzie za kodem, dopóki operator jej nie tknie.
  const effectiveSubdomain = subdomainTouched
    ? subdomain.trim().toLowerCase()
    : suggestSubdomain(code);
  const shapeError = effectiveSubdomain === '' ? null : subdomainShapeError(effectiveSubdomain);
  const subdomainMessage = shapeError
    ? t(`admin.tenants.create.subdomain_error_${shapeError}`)
    : null;

  const handleSubmit = async (event: FormEvent) => {
    event.preventDefault();
    if (submitting) return;
    if (shapeError !== null || effectiveSubdomain === '') {
      toast.error(subdomainMessage ?? t('admin.tenants.create.subdomain_error_empty'));

      return;
    }
    setSubmitting(true);
    try {
      const result = await jsonFetch<AdminTenantSummary>('/api/admin/tenants', {
        method: 'POST',
        body: {
          code: code.trim(),
          name: name.trim(),
          owner_email: ownerEmail.trim(),
          plan,
          subdomain: effectiveSubdomain,
        },
        accept: 'application/json',
        contentType: 'application/json',
      });
      setSuccess(result);
      // Ścieżka panelowa zwraca identyfikator zlecenia — wtedy zamiast
      // komunikatu „utworzono" pokazujemy postęp, bo instancja dopiero
      // powstaje (#2906).
      setJobId(result.provisioning_job_id ?? null);
      toast.success(t('admin.tenants.create.toast_created', { name: result.name }));
      onSuccess();
    } catch (error: unknown) {
      const status = (error as { status?: number })?.status;
      const body = (error as { body?: ApiProblem })?.body;
      if (status === 409 && body?.code === 'duplicate_code') {
        toast.error(body?.detail ?? t('admin.tenants.create.error_duplicate'));
      } else if (status === 422 && body?.code === 'invalid_subdomain') {
        toast.error(body?.detail ?? t('admin.tenants.create.subdomain_error_charset'));
      } else if (status === 409 && body?.code === 'duplicate_subdomain') {
        toast.error(body?.detail ?? t('admin.tenants.create.error_subdomain_taken'));
      } else if (status === 400) {
        toast.error(body?.detail ?? t('admin.tenants.create.error_validation'));
      } else if (status === 403) {
        toast.error(t('admin.tenants.forbidden_description'));
      } else {
        toast.error(t('admin.tenants.create.error_generic'));
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <Dialog open={open} onOpenChange={close}>
      <DialogContent className="max-w-md">
        {success && jobId ? (
          <>
            <DialogHeader>
              <DialogTitle>{t('admin.tenants.provisioning.title')}</DialogTitle>
            </DialogHeader>
            <p className="text-sm text-muted-foreground">
              {t('admin.tenants.create.field_subdomain_preview')}{' '}
              <a
                href={instanceUrl(effectiveSubdomain, BASE_DOMAIN)}
                className="font-mono underline"
                target="_blank"
                rel="noreferrer"
              >
                {instanceUrl(effectiveSubdomain, BASE_DOMAIN)}
              </a>
            </p>
            <ProvisioningProgress jobId={jobId} onFinished={() => onSuccess()} />
            <DialogFooter>
              <Button onClick={() => close(false)}>{t('admin.tenants.create.close')}</Button>
            </DialogFooter>
          </>
        ) : success ? (
          <>
            <DialogHeader>
              <div className="mb-2 inline-grid size-10 place-items-center rounded-full bg-emerald-100 text-emerald-700">
                <Mail className="size-5" aria-hidden="true" />
              </div>
              <DialogTitle>{t('admin.tenants.create.success_title')}</DialogTitle>
            </DialogHeader>
            <p className="text-sm text-muted-foreground">
              {t('admin.tenants.create.success_body', { tenant: success.name, email: ownerEmail })}
            </p>
            <div className="space-y-1 rounded-md border border-dashed bg-muted/30 p-3 text-xs">
              <div className="flex items-center justify-between gap-2">
                <span className="font-medium uppercase tracking-wide text-muted-foreground">
                  {t('admin.tenants.create.success_code_label')}
                </span>
                <span className="flex items-center gap-1.5">
                  <code className="font-mono">{success.code}</code>
                  <button
                    type="button"
                    onClick={() => {
                      void navigator.clipboard.writeText(success.code);
                      toast.success(t('admin.tenants.create.code_copied'));
                    }}
                    className="rounded p-1 hover:bg-muted"
                    aria-label={t('admin.tenants.create.copy_code')}
                  >
                    <Copy className="size-3" aria-hidden="true" />
                  </button>
                </span>
              </div>
              <p className="text-[11px] text-muted-foreground">
                {t('admin.tenants.create.success_hint')}
              </p>
            </div>
            <DialogFooter>
              <Button onClick={() => close(false)}>{t('admin.tenants.create.close')}</Button>
            </DialogFooter>
          </>
        ) : (
          <form onSubmit={handleSubmit}>
            <DialogHeader>
              <div className="mb-2 inline-grid size-10 place-items-center rounded-full bg-accent-violet/10 text-accent-violet">
                <Plus className="size-5" aria-hidden="true" />
              </div>
              <DialogTitle>{t('admin.tenants.create.title')}</DialogTitle>
            </DialogHeader>
            <p className="text-sm text-muted-foreground">{t('admin.tenants.create.intro')}</p>
            <div className="space-y-3 py-3">
              <div className="space-y-1.5">
                <Label htmlFor="tenant-code">{t('admin.tenants.create.field_code')}</Label>
                <Input
                  id="tenant-code"
                  required
                  value={code}
                  onChange={(e) => setCode(e.target.value)}
                  placeholder="acme_corp"
                  // Myślnik MUSI być zaescape'owany. Przeglądarki kompilują
                  // `pattern` z flagą `v`, a w niej `[a-z0-9_-]` jest błędem
                  // składni — atrybut był po cichu martwy i walidacja HTML
                  // nie działała wcale (znalezione E2E w #2909).
                  pattern="[a-z0-9_\-]{2,64}"
                  maxLength={64}
                  className="font-mono"
                  autoComplete="off"
                />
                <p className="text-[11px] text-muted-foreground">
                  {t('admin.tenants.create.field_code_hint')}
                </p>
              </div>

              <div className="space-y-1.5">
                <Label htmlFor="tenant-subdomain">
                  {t('admin.tenants.create.field_subdomain')}
                </Label>
                <Input
                  id="tenant-subdomain"
                  required
                  value={effectiveSubdomain}
                  onChange={(e) => {
                    setSubdomainTouched(true);
                    setSubdomain(e.target.value);
                  }}
                  placeholder="acme"
                  maxLength={32}
                  className="font-mono"
                  autoComplete="off"
                  aria-invalid={subdomainMessage !== null}
                  aria-describedby="tenant-subdomain-hint"
                />
                <p id="tenant-subdomain-hint" className="text-[11px] text-muted-foreground">
                  {t('admin.tenants.create.field_subdomain_hint')}
                </p>
                {/* Pełny adres pod polem: sama subdomena bez kontekstu bywa
                    myląca, a operator ma zobaczyć dokładnie to, co powstanie. */}
                {effectiveSubdomain !== '' && subdomainMessage === null && (
                  <p className="text-[11px] text-muted-foreground">
                    {t('admin.tenants.create.field_subdomain_preview')}{' '}
                    <code className="font-mono">
                      {instanceUrl(effectiveSubdomain, BASE_DOMAIN)}
                    </code>
                  </p>
                )}
                {subdomainMessage !== null && (
                  <p className="text-[11px] text-destructive" role="alert">
                    {subdomainMessage}
                  </p>
                )}
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="tenant-name">{t('admin.tenants.create.field_name')}</Label>
                <Input
                  id="tenant-name"
                  required
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder="Acme Corporation"
                  maxLength={255}
                  autoComplete="off"
                />
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="tenant-owner-email">
                  {t('admin.tenants.create.field_owner_email')}
                </Label>
                <Input
                  id="tenant-owner-email"
                  type="email"
                  required
                  value={ownerEmail}
                  onChange={(e) => setOwnerEmail(e.target.value)}
                  placeholder="owner@example.com"
                  autoComplete="off"
                />
                <p className="text-[11px] text-muted-foreground">
                  {t('admin.tenants.create.field_owner_email_hint')}
                </p>
              </div>
              <div className="space-y-1.5">
                <Label htmlFor="tenant-plan">{t('admin.tenants.create.field_plan')}</Label>
                <select
                  id="tenant-plan"
                  value={plan}
                  onChange={(e) => setPlan(e.target.value as 'starter' | 'pro' | 'enterprise')}
                  className="h-10 w-full rounded-md border border-input bg-background px-3 text-sm"
                >
                  <option value="starter">starter</option>
                  <option value="pro">pro</option>
                  <option value="enterprise">enterprise</option>
                </select>
              </div>
            </div>
            <DialogFooter>
              <Button
                type="button"
                variant="outline"
                onClick={() => close(false)}
                disabled={submitting}
              >
                {t('admin.tenants.create.cancel')}
              </Button>
              <Button
                type="submit"
                disabled={
                  submitting ||
                  code.trim().length < 2 ||
                  name.trim().length === 0 ||
                  ownerEmail.trim().length === 0
                }
              >
                {submitting
                  ? t('admin.tenants.create.submitting')
                  : t('admin.tenants.create.submit')}
              </Button>
            </DialogFooter>
          </form>
        )}
      </DialogContent>
    </Dialog>
  );
}

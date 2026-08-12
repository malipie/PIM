import { CheckCircle2, KeyRound, Loader2, ShieldAlert } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useSearchParams } from 'react-router';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toast } from '@/components/ui/toast';
import { authProvider } from '@/lib/auth-provider';
import { HttpError, jsonFetch } from '@/lib/http';

const TOKEN_RE = /^[a-f0-9]{64}$/;

interface VerifyResponse {
  status: 'valid' | 'expired' | 'accepted' | 'revoked' | 'not_found';
  email?: string;
  tenant_name?: string;
  expires_at?: string;
}

type VerifyState =
  | { kind: 'pending' }
  | { kind: 'invalid'; reason: 'malformed' | 'not_found' }
  | { kind: 'expired' | 'revoked' | 'accepted'; email?: string; tenant_name?: string }
  | { kind: 'valid'; email: string; tenant_name?: string; expires_at?: string };

/**
 * RBAC-P5-017 (#707) — magic-link accept invitation page.
 *
 * Public route at `/accept-invitation?token=<64 hex>`. Three branches:
 *
 *   1. Pre-flight verify — GET /api/invitations/{token}/verify
 *      Renders a spinner; if the API replies with `status !== valid` we
 *      short-circuit to a dedicated error card without ever showing
 *      the password form, so the operator never types into a doomed
 *      submission.
 *   2. Password setup — single-step form (new + confirm). The PRD §5.3
 *      mockup mentions an optional MFA enrol step; that ships with the
 *      MFA wizard (#689 / #703) so the route can drop it in without
 *      schema changes. Min 12 characters matches the change-password
 *      flow (#702).
 *   3. Success — POSTs accept + immediately logs the new user in via
 *      the standard auth-provider, redirecting to /dashboard. The
 *      `User` row + `UserRole` assignment already exist server-side
 *      so the JWT issuance is a regular json_login call.
 */
export function AcceptInvitationPage() {
  const { t } = useTranslation();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const token = searchParams.get('token') ?? '';

  const [state, setState] = useState<VerifyState>({ kind: 'pending' });
  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [submitting, setSubmitting] = useState(false);

  useEffect(() => {
    if (!TOKEN_RE.test(token)) {
      setState({ kind: 'invalid', reason: 'malformed' });
      return;
    }

    let cancelled = false;
    (async () => {
      try {
        const response = await jsonFetch<VerifyResponse>(`/api/invitations/${token}/verify`, {
          accept: 'application/json',
        });
        if (cancelled) return;
        if ('valid' === response.status && response.email) {
          setState({
            kind: 'valid',
            email: response.email,
            tenant_name: response.tenant_name,
            expires_at: response.expires_at,
          });
          return;
        }
        if ('not_found' === response.status) {
          setState({ kind: 'invalid', reason: 'not_found' });
          return;
        }
        // 'expired' | 'accepted' | 'revoked' — narrow explicitly so the
        // discriminated union accepts the assignment.
        if (
          'expired' === response.status ||
          'accepted' === response.status ||
          'revoked' === response.status
        ) {
          setState({
            kind: response.status,
            email: response.email,
            tenant_name: response.tenant_name,
          });
        }
      } catch (error) {
        if (cancelled) return;
        // 410 (expired/revoked/accepted) lands here as HttpError because
        // jsonFetch throws on non-2xx. Re-parse the body so the UX still
        // distinguishes the three states.
        if (error instanceof HttpError && error.status === 410) {
          const body = error.body as VerifyResponse;
          const kind: 'expired' | 'accepted' | 'revoked' =
            'accepted' === body?.status || 'revoked' === body?.status ? body.status : 'expired';
          setState({
            kind,
            email: body?.email,
            tenant_name: body?.tenant_name,
          });
          return;
        }
        setState({ kind: 'invalid', reason: 'not_found' });
      }
    })();

    return () => {
      cancelled = true;
    };
  }, [token]);

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (state.kind !== 'valid') return;
    if (submitting || password.length < 12 || password !== confirm) return;
    setSubmitting(true);
    try {
      await jsonFetch(`/api/invitations/${token}/accept`, {
        method: 'POST',
        body: { password },
        accept: 'application/json',
        contentType: 'application/json',
      });
      // Backend created the User + UserRole; log in via the standard
      // flow so the auth-provider populates the token store + redirects
      // to /dashboard.
      const result = await authProvider.login?.({ email: state.email, password });
      if (result?.success) {
        toast.success(t('accept_invitation.toast_success'));
        navigate('/dashboard', { replace: true });
      } else {
        // Acceptance succeeded but login failed — send the user to
        // /login so they can re-authenticate manually instead of
        // being stuck on this page.
        toast.success(t('accept_invitation.toast_accepted_login_required'));
        navigate('/login', { replace: true });
      }
    } catch (error) {
      const message =
        error instanceof HttpError && error.status === 400
          ? t('accept_invitation.error_validation')
          : t('accept_invitation.error_generic');
      toast.error(message);
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="flex min-h-screen items-center justify-center bg-muted/30 p-6">
      {/* #2827 — landmark added along with the first automated a11y pass over
          this page: until the mail linked here correctly, no spec ever
          rendered it, and axe flags content outside any landmark. */}
      <main className="w-full max-w-md">
        {state.kind === 'pending' && <PendingCard />}
        {state.kind === 'invalid' && <InvalidCard reason={state.reason} />}
        {(state.kind === 'expired' || state.kind === 'revoked' || state.kind === 'accepted') && (
          <ErrorCard kind={state.kind} email={state.email} tenant_name={state.tenant_name} />
        )}
        {state.kind === 'valid' && (
          <ValidCard
            email={state.email}
            tenant_name={state.tenant_name}
            expires_at={state.expires_at}
            password={password}
            confirm={confirm}
            onPasswordChange={setPassword}
            onConfirmChange={setConfirm}
            onSubmit={submit}
            submitting={submitting}
          />
        )}
      </main>
    </div>
  );
}

function PendingCard() {
  const { t } = useTranslation();
  return (
    <section className="rounded-2xl border bg-background p-8 text-center shadow-sm">
      <Loader2 className="mx-auto size-10 animate-spin text-muted-foreground" />
      <p className="mt-4 text-sm text-muted-foreground">{t('accept_invitation.verifying')}</p>
    </section>
  );
}

function InvalidCard({ reason }: { reason: 'malformed' | 'not_found' }) {
  const { t } = useTranslation();
  return (
    <section className="rounded-2xl border bg-background p-8 text-center shadow-sm">
      <div
        className="mx-auto mb-3 grid size-12 place-items-center rounded-full bg-rose-100 text-rose-700"
        aria-hidden="true"
      >
        <ShieldAlert className="size-6" />
      </div>
      <h1 className="display text-xl font-semibold tracking-tight">
        {t('accept_invitation.invalid_title')}
      </h1>
      <p className="mt-2 text-sm text-muted-foreground">
        {reason === 'malformed'
          ? t('accept_invitation.invalid_body_malformed')
          : t('accept_invitation.invalid_body_not_found')}
      </p>
      <Button asChild variant="outline" size="sm" className="mt-5">
        <a href="/login">{t('accept_invitation.go_login')}</a>
      </Button>
    </section>
  );
}

function ErrorCard({
  kind,
  email,
  tenant_name,
}: {
  kind: 'expired' | 'revoked' | 'accepted';
  email?: string;
  tenant_name?: string;
}) {
  const { t } = useTranslation();
  const titleKey = `accept_invitation.${kind}_title` as const;
  const bodyKey = `accept_invitation.${kind}_body` as const;
  return (
    <section className="rounded-2xl border bg-background p-8 text-center shadow-sm">
      <div
        className="mx-auto mb-3 grid size-12 place-items-center rounded-full bg-amber-100 text-amber-700"
        aria-hidden="true"
      >
        <ShieldAlert className="size-6" />
      </div>
      <h1 className="display text-xl font-semibold tracking-tight">{t(titleKey)}</h1>
      <p className="mt-2 text-sm text-muted-foreground">
        {t(bodyKey, { email: email ?? '', tenant_name: tenant_name ?? '' })}
      </p>
      <Button asChild variant="outline" size="sm" className="mt-5">
        <a href="/login">{t('accept_invitation.go_login')}</a>
      </Button>
    </section>
  );
}

interface ValidCardProps {
  email: string;
  tenant_name?: string;
  expires_at?: string;
  password: string;
  confirm: string;
  onPasswordChange: (value: string) => void;
  onConfirmChange: (value: string) => void;
  onSubmit: (event: React.FormEvent) => void;
  submitting: boolean;
}

function ValidCard({
  email,
  tenant_name,
  password,
  confirm,
  onPasswordChange,
  onConfirmChange,
  onSubmit,
  submitting,
}: ValidCardProps) {
  const { t } = useTranslation();
  const tooShort = password.length > 0 && password.length < 12;
  const mismatch = confirm.length > 0 && password !== confirm;
  const canSubmit = password.length >= 12 && password === confirm && !submitting;

  return (
    <section className="rounded-2xl border bg-background p-8 shadow-sm">
      <div
        className="mx-auto mb-3 grid size-12 place-items-center rounded-full bg-emerald-100 text-emerald-700"
        aria-hidden="true"
      >
        <CheckCircle2 className="size-6" />
      </div>
      <h1 className="display text-center text-xl font-semibold tracking-tight">
        {t('accept_invitation.valid_title', { tenant_name: tenant_name ?? '' })}
      </h1>
      <p className="mt-2 text-center text-sm text-muted-foreground">
        {t('accept_invitation.valid_body', { email })}
      </p>

      <form className="mt-6 space-y-4" onSubmit={onSubmit}>
        <div className="space-y-1.5">
          <Label htmlFor="accept-password">{t('accept_invitation.field_new')}</Label>
          <div className="relative">
            <KeyRound
              className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-muted-foreground"
              aria-hidden="true"
            />
            <Input
              id="accept-password"
              type="password"
              value={password}
              onChange={(e) => onPasswordChange(e.target.value)}
              required
              autoComplete="new-password"
              className="pl-9"
            />
          </div>
          {tooShort ? (
            <p className="text-xs text-rose-600">
              {t('accept_invitation.error_too_short', { min: 12 })}
            </p>
          ) : null}
        </div>
        <div className="space-y-1.5">
          <Label htmlFor="accept-confirm">{t('accept_invitation.field_confirm')}</Label>
          <Input
            id="accept-confirm"
            type="password"
            value={confirm}
            onChange={(e) => onConfirmChange(e.target.value)}
            required
            autoComplete="new-password"
            aria-invalid={mismatch}
          />
          {mismatch ? (
            <p className="text-xs text-rose-600">{t('accept_invitation.error_mismatch')}</p>
          ) : null}
        </div>

        <Button type="submit" disabled={!canSubmit} className="w-full">
          {submitting ? t('accept_invitation.submitting') : t('accept_invitation.submit')}
        </Button>
      </form>
    </section>
  );
}

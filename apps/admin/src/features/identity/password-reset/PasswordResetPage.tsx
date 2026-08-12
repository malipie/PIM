import { KeyRound, ShieldAlert } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate, useSearchParams } from 'react-router';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toast } from '@/components/ui/toast';
import { HttpError, jsonFetch } from '@/lib/http';

const TOKEN_RE = /^[a-f0-9]{64}$/;
const MIN_PASSWORD_LENGTH = 12;

/**
 * #2827 — the page the password-reset e-mail links to.
 *
 * Public route at `/password-reset?token=<64 hex>`, mirroring
 * `/accept-invitation?token=…` so both recovery mails carry the same link
 * shape and both pages read the token the same way. Before this ticket the
 * mail pointed at `/password-reset/<token>`, which no router served — the
 * SPA catch-all bounced the recipient to /login.
 *
 * Unlike the invitation flow there is deliberately NO pre-flight verify
 * call: an endpoint that reports whether a reset token is live would be a
 * public oracle for probing tokens. The form renders straight away and the
 * token is judged once, on submit, by the endpoint that consumes it:
 *   - 404 — no such token,
 *   - 400 — already used / expired,
 * both of which land on the same "ask for a fresh link" card, because
 * distinguishing them tells an attacker more than it tells a user.
 *
 * On success we send the user to /login rather than logging them in: the
 * confirm endpoint issues no JWT, and an account with MFA enabled would
 * have to pass the challenge anyway.
 */
export function PasswordResetPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [searchParams] = useSearchParams();
  const token = searchParams.get('token') ?? '';

  const [password, setPassword] = useState('');
  const [confirm, setConfirm] = useState('');
  const [submitting, setSubmitting] = useState(false);
  const [rejected, setRejected] = useState(false);

  const tooShort = password.length > 0 && password.length < MIN_PASSWORD_LENGTH;
  const mismatch = confirm.length > 0 && password !== confirm;
  const canSubmit = password.length >= MIN_PASSWORD_LENGTH && password === confirm && !submitting;

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (!canSubmit) return;
    setSubmitting(true);
    try {
      await jsonFetch('/api/auth/password-reset/confirm', {
        method: 'POST',
        body: { token, password },
        accept: 'application/json',
        contentType: 'application/json',
      });
      toast.success(t('password_reset.toast_success'));
      navigate('/login', { replace: true });
    } catch (error) {
      if (error instanceof HttpError && (error.status === 400 || error.status === 404)) {
        setRejected(true);
        return;
      }
      toast.error(t('password_reset.error_generic'));
    } finally {
      setSubmitting(false);
    }
  };

  if (!TOKEN_RE.test(token) || rejected) {
    return (
      <Shell>
        <div
          className="mx-auto mb-3 grid size-12 place-items-center rounded-full bg-amber-100 text-amber-700"
          aria-hidden="true"
        >
          <ShieldAlert className="size-6" />
        </div>
        <h1 className="display text-center text-xl font-semibold tracking-tight">
          {t('password_reset.invalid_title')}
        </h1>
        <p className="mt-2 text-center text-sm text-muted-foreground">
          {t('password_reset.invalid_body')}
        </p>
        <Button asChild size="sm" className="mt-6 w-full">
          <Link to="/forgot-password">{t('password_reset.request_new')}</Link>
        </Button>
        <p className="mt-4 text-center text-sm">
          <Link to="/login" className="text-muted-foreground underline underline-offset-4">
            {t('password_reset.go_login')}
          </Link>
        </p>
      </Shell>
    );
  }

  return (
    <Shell>
      <div
        className="mx-auto mb-3 grid size-12 place-items-center rounded-full bg-emerald-100 text-emerald-700"
        aria-hidden="true"
      >
        <KeyRound className="size-6" />
      </div>
      <h1 className="display text-center text-xl font-semibold tracking-tight">
        {t('password_reset.title')}
      </h1>
      <p className="mt-2 text-center text-sm text-muted-foreground">{t('password_reset.body')}</p>

      <form className="mt-6 space-y-4" onSubmit={submit}>
        <div className="space-y-1.5">
          <Label htmlFor="reset-password">{t('password_reset.field_new')}</Label>
          <Input
            id="reset-password"
            type="password"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
            required
            autoFocus
            autoComplete="new-password"
            aria-invalid={tooShort}
            aria-describedby={tooShort ? 'reset-password-hint' : undefined}
          />
          {tooShort ? (
            <p id="reset-password-hint" className="text-xs text-rose-600">
              {t('password_reset.error_too_short', { min: MIN_PASSWORD_LENGTH })}
            </p>
          ) : null}
        </div>

        <div className="space-y-1.5">
          <Label htmlFor="reset-confirm">{t('password_reset.field_confirm')}</Label>
          <Input
            id="reset-confirm"
            type="password"
            value={confirm}
            onChange={(e) => setConfirm(e.target.value)}
            required
            autoComplete="new-password"
            aria-invalid={mismatch}
            aria-describedby={mismatch ? 'reset-confirm-hint' : undefined}
          />
          {mismatch ? (
            <p id="reset-confirm-hint" className="text-xs text-rose-600">
              {t('password_reset.error_mismatch')}
            </p>
          ) : null}
        </div>

        <Button type="submit" className="w-full" disabled={!canSubmit}>
          {submitting ? t('password_reset.submitting') : t('password_reset.submit')}
        </Button>
      </form>
    </Shell>
  );
}

function Shell({ children }: { children: React.ReactNode }) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-muted/30 p-6">
      {/* <main> rather than a bare <div>: axe flags a page whose content sits
          outside any landmark (landmark-one-main / region). */}
      <main className="w-full max-w-md">
        <section className="rounded-2xl border bg-background p-8 shadow-sm">{children}</section>
      </main>
    </div>
  );
}

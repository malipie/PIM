import { MailCheck, Send } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { HttpError, jsonFetch } from '@/lib/http';

/**
 * #2827 — "nie pamiętam hasła" entry point.
 *
 * The backend has had `POST /api/auth/password-reset/request` since #658,
 * but no screen ever called it: the only way to start a reset was to hand-
 * craft the request. This page is that missing entry point, reachable from
 * /login and from the expired-link card on {@link PasswordResetPage}.
 *
 * Account-enumeration rule (mirrors the controller, which always answers
 * 200 regardless of whether the address exists): the UI must NOT branch on
 * the outcome either. Whatever the API says, a submitted form renders the
 * same "check your inbox" card — otherwise the screen would leak exactly
 * what the always-200 response is designed to hide. The one exception is a
 * 429, which is a rate-limit signal about the caller, not about the address.
 *
 * `token_dev_only` (present in dev/test responses only, see DevTokenExposure)
 * is deliberately never read here — a reset token on screen would defeat the
 * point of mailing it.
 */
export function ForgotPasswordPage() {
  const { t } = useTranslation();
  const [email, setEmail] = useState('');
  const [state, setState] = useState<'idle' | 'submitting' | 'sent' | 'throttled'>('idle');

  const submit = async (event: React.FormEvent) => {
    event.preventDefault();
    if (state === 'submitting' || email.trim() === '') return;
    setState('submitting');
    try {
      await jsonFetch('/api/auth/password-reset/request', {
        method: 'POST',
        body: { email: email.trim() },
        accept: 'application/json',
        contentType: 'application/json',
      });
      setState('sent');
    } catch (error) {
      if (error instanceof HttpError && error.status === 429) {
        setState('throttled');
        return;
      }
      // Any other failure (network, 5xx) still renders the neutral card:
      // telling the user "that address is unknown" is precisely the leak
      // the always-200 contract prevents, and a delivery problem is not
      // something they can act on from here.
      setState('sent');
    }
  };

  if (state === 'sent') {
    return (
      <Shell>
        <div
          className="mx-auto mb-3 grid size-12 place-items-center rounded-full bg-emerald-100 text-emerald-700"
          aria-hidden="true"
        >
          <MailCheck className="size-6" />
        </div>
        <h1 className="display text-center text-xl font-semibold tracking-tight">
          {t('forgot_password.sent_title')}
        </h1>
        <p className="mt-2 text-center text-sm text-muted-foreground">
          {t('forgot_password.sent_body')}
        </p>
        <Button asChild variant="outline" size="sm" className="mt-6 w-full">
          <Link to="/login">{t('forgot_password.go_login')}</Link>
        </Button>
      </Shell>
    );
  }

  return (
    <Shell>
      <h1 className="display text-center text-xl font-semibold tracking-tight">
        {t('forgot_password.title')}
      </h1>
      <p className="mt-2 text-center text-sm text-muted-foreground">{t('forgot_password.body')}</p>

      <form className="mt-6 space-y-4" onSubmit={submit}>
        <div className="space-y-1.5">
          <Label htmlFor="forgot-email">{t('forgot_password.field_email')}</Label>
          <Input
            id="forgot-email"
            type="email"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
            required
            autoFocus
            autoComplete="email"
            aria-describedby={state === 'throttled' ? 'forgot-throttled' : undefined}
          />
        </div>

        {state === 'throttled' ? (
          <p id="forgot-throttled" role="alert" className="text-xs text-rose-600">
            {t('forgot_password.error_throttled')}
          </p>
        ) : null}

        <Button type="submit" className="w-full" disabled={state === 'submitting'}>
          <Send className="size-4" aria-hidden="true" />
          {state === 'submitting' ? t('forgot_password.submitting') : t('forgot_password.submit')}
        </Button>
      </form>

      <p className="mt-6 text-center text-sm">
        <Link to="/login" className="text-muted-foreground underline underline-offset-4">
          {t('forgot_password.go_login')}
        </Link>
      </p>
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

import { zodResolver } from '@hookform/resolvers/zod';
import { type AuthActionResponse, useLogin } from '@refinedev/core';
import { useQueryClient } from '@tanstack/react-query';
import { useState } from 'react';
import { useForm } from 'react-hook-form';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';
import { z } from 'zod';

import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { IDENTITY_QUERY_KEY } from '@/lib/identity';

const loginSchema = z.object({
  email: z.string().email(),
  password: z.string().min(1),
});

const mfaSchema = z.object({
  code: z.string().min(1),
});

type LoginValues = z.infer<typeof loginSchema>;
type MfaValues = z.infer<typeof mfaSchema>;

/** Variables accepted by the auth provider's `login` — password step OR 2FA step. */
type LoginVariables = Partial<LoginValues> & { mfaToken?: string; code?: string };

interface MfaChallengeResult {
  mfaRequired?: boolean;
  mfaToken?: string;
}

export function LoginPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const queryClient = useQueryClient();
  const { mutate: login, isPending } = useLogin<LoginVariables>();

  // #1141 — once the password step reports `mfa_required`, we hold the
  // short-lived challenge token and switch the form to the code step.
  const [mfaToken, setMfaToken] = useState<string | null>(null);

  const passwordForm = useForm<LoginValues>({ resolver: zodResolver(loginSchema) });
  const mfaForm = useForm<MfaValues>({ resolver: zodResolver(mfaSchema) });

  const goTo = (response: AuthActionResponse | undefined): void => {
    // #2618 — the identity cache has a 5-minute staleTime; without this a
    // re-login (possibly as a different user) would keep serving the previous
    // session's permissions until the cache expired.
    queryClient.invalidateQueries({ queryKey: IDENTITY_QUERY_KEY });
    const target = response?.redirectTo ?? '/dashboard';
    navigate(typeof target === 'string' ? target : '/dashboard', { replace: true });
  };

  const submitPassword = passwordForm.handleSubmit((values) => {
    login(values, {
      onSuccess: (response) => {
        const result = response as (AuthActionResponse & MfaChallengeResult) | undefined;
        if (result?.mfaRequired === true && typeof result.mfaToken === 'string') {
          setMfaToken(result.mfaToken);
          mfaForm.reset();
          return;
        }
        if (result?.success === false) {
          passwordForm.setError('root', {
            message: result.error?.message ?? 'auth.login_failed',
          });
          return;
        }
        goTo(result);
      },
      onError: () => {
        passwordForm.setError('root', { message: 'auth.login_failed' });
      },
    });
  });

  const submitMfa = mfaForm.handleSubmit((values) => {
    if (mfaToken === null) {
      return;
    }
    login(
      { mfaToken, code: values.code },
      {
        onSuccess: (response) => {
          if (response?.success === false) {
            mfaForm.setError('root', {
              message: response.error?.message ?? 'auth.mfa_invalid_code',
            });
            return;
          }
          goTo(response);
        },
        onError: () => {
          mfaForm.setError('root', { message: 'auth.mfa_invalid_code' });
        },
      },
    );
  });

  const backToPassword = (): void => {
    setMfaToken(null);
    mfaForm.reset();
  };

  const passwordRootError = passwordForm.formState.errors.root?.message ?? null;
  const mfaRootError = mfaForm.formState.errors.root?.message ?? null;
  const inMfaStep = mfaToken !== null;

  return (
    <div className="flex min-h-screen items-center justify-center bg-muted/40 px-4">
      <Card className="w-full max-w-sm">
        <CardHeader>
          <CardTitle>{inMfaStep ? t('auth.mfa_title') : t('auth.login_title')}</CardTitle>
          <CardDescription>
            {inMfaStep ? t('auth.mfa_subtitle') : t('auth.login_subtitle')}
          </CardDescription>
        </CardHeader>
        <CardContent>
          {inMfaStep ? (
            <form onSubmit={submitMfa} className="space-y-4" noValidate>
              <div className="space-y-2">
                <Label htmlFor="mfa-code">{t('auth.mfa_code')}</Label>
                <Input
                  id="mfa-code"
                  type="text"
                  inputMode="numeric"
                  autoComplete="one-time-code"
                  autoFocus
                  aria-invalid={mfaForm.formState.errors.code ? 'true' : 'false'}
                  {...mfaForm.register('code')}
                />
              </div>
              {mfaRootError ? (
                <p className="text-sm text-destructive" role="alert">
                  {t(mfaRootError)}
                </p>
              ) : null}
              <Button type="submit" className="w-full" disabled={isPending}>
                {isPending ? t('auth.submitting') : t('auth.mfa_submit')}
              </Button>
              <Button
                type="button"
                variant="ghost"
                className="w-full"
                onClick={backToPassword}
                disabled={isPending}
              >
                {t('auth.mfa_back')}
              </Button>
            </form>
          ) : (
            <form onSubmit={submitPassword} className="space-y-4" noValidate>
              <div className="space-y-2">
                <Label htmlFor="email">{t('auth.email')}</Label>
                <Input
                  id="email"
                  type="email"
                  autoComplete="username"
                  aria-invalid={passwordForm.formState.errors.email ? 'true' : 'false'}
                  {...passwordForm.register('email')}
                />
              </div>
              <div className="space-y-2">
                <Label htmlFor="password">{t('auth.password')}</Label>
                <Input
                  id="password"
                  type="password"
                  autoComplete="current-password"
                  aria-invalid={passwordForm.formState.errors.password ? 'true' : 'false'}
                  {...passwordForm.register('password')}
                />
              </div>
              {passwordRootError ? (
                <p className="text-sm text-destructive" role="alert">
                  {t(passwordRootError)}
                </p>
              ) : null}
              <Button type="submit" className="w-full" disabled={isPending}>
                {isPending ? t('auth.submitting') : t('auth.submit')}
              </Button>
            </form>
          )}
        </CardContent>
      </Card>
    </div>
  );
}

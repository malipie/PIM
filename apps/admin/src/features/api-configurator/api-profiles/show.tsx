import { useApiUrl, useCustomMutation, useList, useOne } from '@refinedev/core';
import { ChevronLeft, KeyRound, Pencil, Send, Webhook } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';

import { Button } from '@/components/ui/button';

import type { ApiProfileRow } from './list';

interface ApiKeyRow {
  id: string;
  keyPrefix: string;
  name: string;
  scopes: string[];
  expiresAt: string | null;
  revokedAt: string | null;
  lastUsedAt: string | null;
  rateLimitPerHour: number;
  createdAt: string;
}

interface TestWebhookResult {
  url: string;
  statusCode: number;
  durationMs: number;
  success: boolean;
}

interface RotateSecretResult {
  webhookSecret: string;
  note: string;
}

export function ApiProfileShowPage() {
  const { t, i18n } = useTranslation();
  const { id } = useParams<{ id: string }>();

  const { result, query } = useOne<ApiProfileRow>({
    resource: 'api_profiles',
    id: id ?? '',
    queryOptions: { enabled: id !== undefined && id !== '' },
  });
  const profile = result;

  const keysQuery = useList<ApiKeyRow>({
    resource: 'api_keys',
    pagination: { mode: 'off' },
    queryOptions: { enabled: profile !== undefined },
  });
  const allKeys = keysQuery.result.data ?? [];
  const linkedKeys =
    profile !== undefined ? allKeys.filter((k) => k.scopes.includes(profile.code)) : [];

  if (query.isLoading || profile === undefined) {
    return <p className="text-sm text-muted-foreground">{t('app.loading')}</p>;
  }

  return (
    <div className="space-y-6">
      <div>
        <Button asChild variant="ghost" size="sm" className="-ml-2 mb-2">
          <Link to="/integrations/api-configurator">
            <ChevronLeft className="size-4" />
            {t('api_profiles.actions.back_to_list')}
          </Link>
        </Button>
        <div className="flex items-start justify-between gap-4">
          <div>
            <h1 className="text-2xl font-semibold tracking-tight">{profile.name}</h1>
            <p className="font-mono text-xs text-muted-foreground">{profile.code}</p>
          </div>
          <Button asChild variant="outline">
            <Link to={`/integrations/api-configurator/${profile.id}/edit`}>
              <Pencil className="mr-1 size-4" />
              {t('api_profiles.actions.edit')}
            </Link>
          </Button>
        </div>
      </div>

      <section className="space-y-3 rounded-xl border bg-card p-6">
        <h2 className="text-lg font-medium">{t('api_profiles.show.basic_info')}</h2>
        <dl className="grid grid-cols-1 gap-3 text-sm md:grid-cols-2">
          <div>
            <dt className="text-xs uppercase tracking-wide text-muted-foreground">
              {t('api_profiles.fields.output_format')}
            </dt>
            <dd className="font-mono">{profile.outputFormat}</dd>
          </div>
          <div>
            <dt className="text-xs uppercase tracking-wide text-muted-foreground">
              {t('api_profiles.fields.rate_limit')}
            </dt>
            <dd>{profile.rateLimitPerHour}/h</dd>
          </div>
          <div>
            <dt className="text-xs uppercase tracking-wide text-muted-foreground">
              {t('api_profiles.fields.object_types_count')}
            </dt>
            <dd>{(profile.objectTypeIds ?? []).length}</dd>
          </div>
          <div>
            <dt className="text-xs uppercase tracking-wide text-muted-foreground">
              {t('api_profiles.fields.included_attributes_count')}
            </dt>
            <dd>{(profile.includedAttributes ?? []).length}</dd>
          </div>
          {profile.description !== null && profile.description !== '' && (
            <div className="md:col-span-2">
              <dt className="text-xs uppercase tracking-wide text-muted-foreground">
                {t('api_profiles.fields.description')}
              </dt>
              <dd className="whitespace-pre-line">{profile.description}</dd>
            </div>
          )}
        </dl>
      </section>

      <WebhookSection profile={profile} />

      <section className="space-y-3 rounded-xl border bg-card p-6">
        <div className="flex items-center justify-between">
          <h2 className="flex items-center gap-2 text-lg font-medium">
            <KeyRound className="size-4" />
            {t('api_profiles.show.linked_keys', { count: linkedKeys.length })}
          </h2>
        </div>
        {linkedKeys.length === 0 ? (
          <p className="text-sm text-muted-foreground">{t('api_profiles.show.no_keys')}</p>
        ) : (
          <ul className="space-y-2 text-sm">
            {linkedKeys.map((key) => (
              <li
                key={key.id}
                className="flex items-center justify-between gap-3 rounded border bg-muted/50 px-3 py-2"
              >
                <div className="space-y-0.5">
                  <p className="font-mono text-xs">{key.keyPrefix}…</p>
                  <p className="font-medium">{key.name}</p>
                </div>
                <div className="text-right text-xs text-muted-foreground">
                  {key.lastUsedAt !== null ? (
                    <span>
                      {t('api_profiles.show.last_used')}{' '}
                      {new Date(key.lastUsedAt).toLocaleDateString(i18n.language)}
                    </span>
                  ) : (
                    <span>{t('api_profiles.show.never_used')}</span>
                  )}
                </div>
              </li>
            ))}
          </ul>
        )}
        <p className="text-xs text-muted-foreground">
          {t('api_profiles.show.keys_management_note')}
        </p>
      </section>
    </div>
  );
}

function WebhookSection({ profile }: { profile: ApiProfileRow }) {
  const { t } = useTranslation();
  const apiUrl = useApiUrl();
  const [testResult, setTestResult] = useState<TestWebhookResult | null>(null);
  const [testError, setTestError] = useState<string | null>(null);
  const [rotatedSecret, setRotatedSecret] = useState<string | null>(null);
  const [rotateError, setRotateError] = useState<string | null>(null);
  const { mutate: testMutate, mutation: testMutation } = useCustomMutation();
  const { mutate: rotateMutate, mutation: rotateMutation } = useCustomMutation();

  const hasUrl = profile.webhookUrl !== null && profile.webhookUrl !== '';
  const events = profile.webhookEvents ?? [];

  function handleTest(): void {
    setTestResult(null);
    setTestError(null);
    testMutate(
      {
        url: `${apiUrl}/api_profiles/${profile.id}/test_webhook`,
        method: 'post',
        values: {},
        // Inline testError below is the error surface — skip the global toast.
        errorNotification: false,
      },
      {
        onSuccess: ({ data }) => {
          setTestResult(data as unknown as TestWebhookResult);
        },
        onError: (err) => {
          setTestError(err?.message ?? t('api_profiles.show.webhook_test_failed'));
        },
      },
    );
  }

  function handleRotate(): void {
    setRotatedSecret(null);
    setRotateError(null);
    rotateMutate(
      {
        url: `${apiUrl}/api_profiles/${profile.id}/rotate_webhook_secret`,
        method: 'post',
        values: {},
        // Inline rotateError below is the error surface — skip the global toast.
        errorNotification: false,
      },
      {
        onSuccess: ({ data }) => {
          const result = data as unknown as RotateSecretResult;
          setRotatedSecret(result.webhookSecret);
        },
        onError: (err) => {
          // #2724 — a silent failure here reads as "secret rotated" while the
          // old secret is still live; surface the failure explicitly.
          setRotateError(err?.message ?? t('api_profiles.show.webhook_rotate_failed'));
        },
      },
    );
  }

  return (
    <section className="space-y-3 rounded-xl border bg-card p-6">
      <h2 className="flex items-center gap-2 text-lg font-medium">
        <Webhook className="size-4" />
        {t('api_profiles.show.webhook_title')}
      </h2>

      {!hasUrl ? (
        <p className="text-sm text-muted-foreground">{t('api_profiles.show.no_webhook_url')}</p>
      ) : (
        <>
          <dl className="grid grid-cols-1 gap-3 text-sm md:grid-cols-2">
            <div>
              <dt className="text-xs uppercase tracking-wide text-muted-foreground">
                {t('api_profiles.fields.webhook_url')}
              </dt>
              <dd className="break-all font-mono text-xs">{profile.webhookUrl}</dd>
            </div>
            <div>
              <dt className="text-xs uppercase tracking-wide text-muted-foreground">
                {t('api_profiles.show.webhook_events_count')}
              </dt>
              <dd>{events.length}</dd>
            </div>
          </dl>

          {events.length > 0 && (
            <div className="flex flex-wrap gap-1">
              {events.map((ev) => (
                <span
                  key={ev}
                  className="rounded bg-muted px-1.5 py-0.5 font-mono text-[10px] uppercase text-muted-foreground"
                >
                  {ev}
                </span>
              ))}
            </div>
          )}

          <div className="flex flex-wrap gap-2 pt-2">
            <Button
              type="button"
              variant="outline"
              size="sm"
              onClick={handleTest}
              disabled={testMutation.isPending}
            >
              <Send className="mr-1 size-3.5" />
              {testMutation.isPending
                ? t('api_profiles.show.webhook_testing')
                : t('api_profiles.show.webhook_test')}
            </Button>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              onClick={handleRotate}
              disabled={rotateMutation.isPending}
            >
              {t('api_profiles.show.webhook_rotate_secret')}
            </Button>
          </div>

          {testResult !== null && (
            <p
              className={
                testResult.success ? 'text-sm text-emerald-600' : 'text-sm text-destructive'
              }
              role="status"
            >
              {testResult.success
                ? t('api_profiles.show.webhook_test_ok', {
                    status: testResult.statusCode,
                    duration: testResult.durationMs,
                  })
                : t('api_profiles.show.webhook_test_fail', {
                    status: testResult.statusCode,
                  })}
            </p>
          )}
          {testError !== null && (
            <p className="text-sm text-destructive" role="alert">
              {testError}
            </p>
          )}
          {rotateError !== null && (
            <p className="text-sm text-destructive" role="alert">
              {rotateError}
            </p>
          )}

          {rotatedSecret !== null && (
            <div className="rounded-md border border-amber-500/40 bg-amber-500/10 p-3 text-sm">
              <p className="mb-1 font-medium">{t('api_profiles.show.webhook_secret_new')}</p>
              <p className="break-all font-mono text-xs">{rotatedSecret}</p>
              <p className="mt-1 text-xs text-muted-foreground">
                {t('api_profiles.show.webhook_secret_warning')}
              </p>
            </div>
          )}
        </>
      )}
      <p className="text-xs text-muted-foreground">{t('api_profiles.show.webhook_history_note')}</p>
    </section>
  );
}

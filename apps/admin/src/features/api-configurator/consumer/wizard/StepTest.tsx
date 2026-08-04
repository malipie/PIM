import { useApiUrl, useCustomMutation } from '@refinedev/core';
import { AlertTriangle, CheckCircle2, Zap } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { objectWithKeys } from '@/lib/runtime-guards';
import { AuthBadge, JsonView, SecurityNote } from '../../components/primitives';

import type { ConnectionTestResult, WizardForm } from './types';

interface StepTestProps {
  form: WizardForm;
  connectionId: string | null;
}

/** Parses the truncated sample body as JSON, falling back to the raw string. */
function parseSample(sample: string | undefined): unknown {
  if (sample === undefined || sample === '') {
    return null;
  }
  try {
    return JSON.parse(sample);
  } catch {
    return sample;
  }
}

/**
 * APIC-P1-08 — wizard step 2: probes the just-saved connection through the
 * SSRF-safe client (`POST /api/connections/{id}/test`) and renders the live
 * status / latency / size / content-type plus a sample of the raw response.
 */
export function StepTest({ form, connectionId }: StepTestProps) {
  const { t } = useTranslation();
  const apiUrl = useApiUrl();
  const { mutate, mutation } = useCustomMutation<ConnectionTestResult>();
  const [result, setResult] = useState<ConnectionTestResult | null>(null);
  const [error, setError] = useState<string | null>(null);

  function handleTest(): void {
    if (connectionId === null) {
      return;
    }
    setResult(null);
    setError(null);
    mutate(
      { url: `${apiUrl}/connections/${connectionId}/test`, method: 'post', values: {} },
      {
        onSuccess: ({ data }) => {
          const parsed = objectWithKeys<ConnectionTestResult>(data, ['status']);
          if (parsed === null) {
            setError(
              t('api_configurator.wizard.test_failed', { defaultValue: 'Test nie powiódł się.' }),
            );
            return;
          }
          setResult(parsed);
        },
        onError: (err) => setError(err?.message ?? t('api_configurator.wizard.test_failed')),
      },
    );
  }

  const testing = mutation.isPending;
  const metrics =
    result?.ok === true
      ? [
          [t('api_configurator.wizard.metric_status'), `${result.http_status ?? ''} OK`],
          [t('api_configurator.wizard.metric_latency'), `${result.latency_ms ?? '—'} ms`],
          [t('api_configurator.wizard.metric_size'), `${result.size_bytes ?? '—'} B`],
          [t('api_configurator.wizard.metric_content_type'), result.content_type ?? '—'],
        ]
      : [];

  return (
    <div className="soft-shadow rounded-2xl border border-zinc-200 bg-white p-6">
      <div className="flex flex-wrap items-center gap-4">
        <Button onClick={handleTest} disabled={testing || connectionId === null}>
          <Zap className="mr-1.5 size-4" aria-hidden="true" />
          {testing
            ? t('api_configurator.wizard.testing')
            : t('api_configurator.wizard.test_connection')}
        </Button>
        <div className="text-[12.5px] text-zinc-500">
          <span className="font-mono text-zinc-700">
            GET {form.baseUrl !== '' ? form.baseUrl : 'https://api.example.com/v2'}
          </span>{' '}
          · <AuthBadge type={form.authType} />
        </div>
      </div>

      {result?.ok === true ? (
        <div className="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-[1fr_1.2fr]">
          <div className="space-y-3">
            <div className="rounded-xl border border-emerald-200 bg-emerald-50/60 p-4">
              <div className="flex items-center gap-2">
                <CheckCircle2 className="size-6 text-emerald-600" aria-hidden="true" />
                <div>
                  <div className="text-[13.5px] font-semibold text-emerald-900">
                    {t('api_configurator.wizard.result_ok', { status: result.http_status ?? 200 })}
                  </div>
                  <div className="text-[11.5px] text-emerald-700">
                    {t('api_configurator.wizard.result_ok_sub', { ms: result.latency_ms ?? 0 })}
                  </div>
                </div>
              </div>
            </div>
            <div className="grid grid-cols-2 gap-2">
              {metrics.map(([label, val]) => (
                <div key={label} className="rounded-xl border border-zinc-200 px-3 py-2">
                  <div className="text-[10px] uppercase tracking-wider text-zinc-400">{label}</div>
                  <div className="mt-0.5 truncate font-mono text-[12px] text-zinc-800">{val}</div>
                </div>
              ))}
            </div>
            <SecurityNote tone="emerald">{t('api_configurator.wizard.ssrf_passed')}</SecurityNote>
          </div>
          <div className="overflow-hidden rounded-xl border border-zinc-200">
            <div className="flex items-center gap-2 border-b border-zinc-100 bg-zinc-50/60 px-3 py-2">
              <span className="text-[10.5px] font-medium uppercase tracking-wider text-zinc-400">
                {t('api_configurator.wizard.sample_label')}
              </span>
              <span className="ml-auto font-mono text-[10.5px] text-zinc-400">
                {result.http_status ?? 200} · json
              </span>
            </div>
            <div className="p-3">
              <JsonView value={parseSample(result.sample)} maxHeight={300} />
            </div>
          </div>
        </div>
      ) : null}

      {result !== null && !result.ok ? (
        <div className="mt-5 rounded-xl border border-rose-200 bg-rose-50/60 p-4">
          <div className="flex items-center gap-2">
            <AlertTriangle className="size-5 text-rose-600" aria-hidden="true" />
            <div className="text-[13px] font-semibold text-rose-900">
              {t('api_configurator.wizard.result_failed')}
            </div>
          </div>
          <p className="mt-1 font-mono text-[11.5px] text-rose-700">
            {result.error ?? `HTTP ${result.http_status ?? '—'}`}
          </p>
        </div>
      ) : null}

      {error !== null ? (
        <div className="mt-5 rounded-xl border border-rose-200 bg-rose-50/60 p-4 text-[12.5px] text-rose-700">
          {error}
        </div>
      ) : null}

      {result === null && error === null ? (
        <div className="mt-5 rounded-xl border border-dashed border-zinc-200 px-5 py-10 text-center text-[13px] text-zinc-400">
          {t('api_configurator.wizard.test_empty')}
        </div>
      ) : null}
    </div>
  );
}

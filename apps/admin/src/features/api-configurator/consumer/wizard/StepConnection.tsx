import { Plus, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

import { type AuthType, Field, SecurityNote, Segmented } from '../../components/primitives';
import { slugify, type WizardForm } from './types';

interface StepConnectionProps {
  form: WizardForm;
  set: (patch: Partial<WizardForm>) => void;
  /** #2630 — edit mode: `code` is immutable and credential fields start blank. */
  isEdit?: boolean;
}

/**
 * APIC-P1-08 — wizard step 1: connection identity, base URL, auth scheme with
 * conditional credential fields, default headers and the SSRF/encryption note.
 * Credentials are write-only (never returned by the API) and stored AES-GCM.
 */
export function StepConnection({ form, set, isEdit = false }: StepConnectionProps) {
  const { t } = useTranslation();

  const authOptions: ReadonlyArray<{ value: AuthType; label: string }> = [
    { value: 'none', label: t('api_configurator.wizard.auth.none') },
    { value: 'api_key', label: t('api_configurator.wizard.auth.api_key') },
    { value: 'bearer', label: t('api_configurator.wizard.auth.bearer') },
    { value: 'basic', label: t('api_configurator.wizard.auth.basic') },
    { value: 'oauth2_token', label: t('api_configurator.wizard.auth.oauth2_token') },
  ];

  const encHint = t('api_configurator.wizard.encrypted_hint');

  function setHeader(index: number, patch: Partial<{ k: string; v: string }>): void {
    const headers = form.headers.map((row, i) => (i === index ? { ...row, ...patch } : row));
    set({ headers });
  }

  return (
    <div className="soft-shadow rounded-2xl border border-zinc-200 bg-white p-6">
      <div className="grid grid-cols-1 gap-x-8 gap-y-5 lg:grid-cols-2">
        <Field label={t('api_configurator.wizard.name')} required>
          <Input
            value={form.name}
            onChange={(e) =>
              set(
                isEdit
                  ? { name: e.target.value }
                  : { name: e.target.value, code: slugify(e.target.value) },
              )
            }
            placeholder={t('api_configurator.wizard.name_placeholder')}
            aria-label={t('api_configurator.wizard.name')}
          />
        </Field>
        <Field
          label={t('api_configurator.wizard.code')}
          hint={t('api_configurator.wizard.code_hint')}
        >
          <Input
            value={form.code}
            onChange={(e) => set({ code: e.target.value })}
            placeholder="nexar-components"
            className="font-mono"
            disabled={isEdit}
            aria-label={t('api_configurator.wizard.code')}
          />
        </Field>

        <div className="lg:col-span-2">
          <Field
            label={t('api_configurator.wizard.base_url')}
            required
            hint={t('api_configurator.wizard.base_url_hint')}
          >
            <Input
              value={form.baseUrl}
              onChange={(e) => set({ baseUrl: e.target.value })}
              placeholder="https://api.example.com/v2"
              className="font-mono"
              inputMode="url"
              aria-label={t('api_configurator.wizard.base_url')}
            />
          </Field>
        </div>

        <div className="lg:col-span-2">
          <Field label={t('api_configurator.wizard.auth_type')}>
            <Segmented
              ariaLabel={t('api_configurator.wizard.auth_type')}
              options={authOptions}
              value={form.authType}
              onChange={(authType) => set({ authType })}
            />
          </Field>
          {isEdit && form.authType !== 'none' ? (
            <p className="mt-2 text-[12px] text-zinc-500">
              {t('api_configurator.wizard.credentials_keep_note')}
            </p>
          ) : null}
        </div>

        {form.authType === 'api_key' ? (
          <>
            <Field label={t('api_configurator.wizard.header_name')}>
              <Input
                value={form.apiKeyHeader}
                onChange={(e) => set({ apiKeyHeader: e.target.value })}
                className="font-mono"
                aria-label={t('api_configurator.wizard.header_name')}
              />
            </Field>
            <Field label={t('api_configurator.wizard.key_value')} hint={encHint}>
              <Input
                type="password"
                value={form.apiKeyValue}
                onChange={(e) => set({ apiKeyValue: e.target.value })}
                placeholder="••••••••••••"
                className="font-mono"
                autoComplete="off"
                aria-label={t('api_configurator.wizard.key_value')}
              />
            </Field>
          </>
        ) : null}

        {form.authType === 'bearer' ? (
          <div className="lg:col-span-2">
            <Field label={t('api_configurator.wizard.token')} hint={encHint}>
              <Input
                type="password"
                value={form.bearer}
                onChange={(e) => set({ bearer: e.target.value })}
                placeholder="eyJhbGciOi…"
                className="font-mono"
                autoComplete="off"
                aria-label={t('api_configurator.wizard.token')}
              />
            </Field>
          </div>
        ) : null}

        {form.authType === 'basic' ? (
          <>
            <Field label={t('api_configurator.wizard.basic_user')}>
              <Input
                value={form.basicUser}
                onChange={(e) => set({ basicUser: e.target.value })}
                className="font-mono"
                autoComplete="off"
                aria-label={t('api_configurator.wizard.basic_user')}
              />
            </Field>
            <Field label={t('api_configurator.wizard.basic_pass')} hint={encHint}>
              <Input
                type="password"
                value={form.basicPass}
                onChange={(e) => set({ basicPass: e.target.value })}
                className="font-mono"
                autoComplete="off"
                aria-label={t('api_configurator.wizard.basic_pass')}
              />
            </Field>
          </>
        ) : null}

        {form.authType === 'oauth2_token' ? (
          <div className="lg:col-span-2">
            <Field
              label={t('api_configurator.wizard.access_token')}
              hint={t('api_configurator.wizard.oauth_hint')}
            >
              <Input
                type="password"
                value={form.oauthToken}
                onChange={(e) => set({ oauthToken: e.target.value })}
                className="font-mono"
                autoComplete="off"
                aria-label={t('api_configurator.wizard.access_token')}
              />
            </Field>
          </div>
        ) : null}

        <div className="lg:col-span-2">
          <Field label={t('api_configurator.wizard.default_headers')}>
            <div className="space-y-1.5">
              {form.headers.map((row, i) => (
                // biome-ignore lint/suspicious/noArrayIndexKey: header rows have no stable id; order is the identity
                <div key={i} className="flex items-center gap-2">
                  <Input
                    value={row.k}
                    onChange={(e) => setHeader(i, { k: e.target.value })}
                    aria-label={t('api_configurator.wizard.header_key_aria', { n: i + 1 })}
                    className="w-48 font-mono"
                  />
                  <span className="font-mono text-zinc-300">:</span>
                  <Input
                    value={row.v}
                    onChange={(e) => setHeader(i, { v: e.target.value })}
                    aria-label={t('api_configurator.wizard.header_value_aria', { n: i + 1 })}
                    className="flex-1 font-mono"
                  />
                  <Button
                    type="button"
                    variant="ghost"
                    size="icon"
                    onClick={() => set({ headers: form.headers.filter((_, j) => j !== i) })}
                    aria-label={t('api_configurator.wizard.header_remove_aria', { n: i + 1 })}
                    className="text-zinc-400 hover:text-rose-600"
                  >
                    <Trash2 className="size-4" aria-hidden="true" />
                  </Button>
                </div>
              ))}
              <button
                type="button"
                onClick={() => set({ headers: [...form.headers, { k: '', v: '' }] })}
                className="mt-1 flex items-center gap-1 text-[12px] font-medium text-zinc-500 hover:text-zinc-900"
              >
                <Plus className="size-3.5" aria-hidden="true" />
                {t('api_configurator.wizard.add_header')}
              </button>
            </div>
          </Field>
        </div>

        <Field
          label={t('api_configurator.wizard.rate_limit')}
          hint={t('api_configurator.wizard.rate_limit_hint')}
        >
          <Input
            value={form.rateLimit}
            onChange={(e) => set({ rateLimit: e.target.value })}
            inputMode="numeric"
            className="font-mono"
            aria-label={t('api_configurator.wizard.rate_limit')}
          />
        </Field>
      </div>

      <div className="mt-5 border-t border-zinc-100 pt-5">
        <SecurityNote tone="emerald">
          <span className="font-semibold">{t('api_configurator.wizard.ssrf_title')}</span>{' '}
          {t('api_configurator.wizard.ssrf_body')}
        </SecurityNote>
      </div>
    </div>
  );
}

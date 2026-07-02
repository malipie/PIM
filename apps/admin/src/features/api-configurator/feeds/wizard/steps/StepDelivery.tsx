import { Clock, Link2, Lock, RefreshCw, ShieldCheck, Trash2 } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { useToast } from '@/components/ui/toast';
import { Segmented } from '@/features/api-configurator/components/primitives';
import { httpErrorDetail } from '@/lib/http';
import { cn } from '@/lib/utils';

import { useRevokeToken, useRotateToken } from '../../api/feeds';
import { CopyButton } from '../../components/primitives';

const CRON_PRESETS = [
  { value: '0 * * * *', labelKey: 'hourly' },
  { value: '0 3 * * *', labelKey: 'daily' },
  { value: '0 3,15 * * *', labelKey: 'twice' },
  { value: '', labelKey: 'manual' },
] as const;

/**
 * XMLF-P5-04 — wizard step 4 (design feed-wizard.jsx StepDelivery): the
 * regeneration schedule (cron presets + a raw expression — the human
 * next-run description ships with the P4-01 scheduler service follow-up),
 * the gzip toggle, URL auth (none | HTTP Basic with a write-only AES-GCM
 * password) and the FeedUrlCard with mint/rotate/revoke — the plaintext
 * token URL is shown exactly once per mint (the API-key pattern).
 */
export function StepDelivery({
  feedId,
  hasToken,
  cron,
  onCron,
  gzip,
  onGzip,
  authType,
  onAuthType,
  authUsername,
  onAuthUsername,
  authPassword,
  onAuthPassword,
}: {
  feedId: string | null;
  hasToken: boolean;
  cron: string | null;
  onCron: (value: string | null) => void;
  gzip: boolean;
  onGzip: (value: boolean) => void;
  authType: 'none' | 'basic';
  onAuthType: (value: 'none' | 'basic') => void;
  authUsername: string;
  onAuthUsername: (value: string) => void;
  authPassword: string;
  onAuthPassword: (value: string) => void;
}) {
  const { t } = useTranslation();
  const toast = useToast();
  const rotate = useRotateToken();
  const revoke = useRevokeToken();
  const [mintedUrl, setMintedUrl] = useState<string | null>(null);
  const [revoked, setRevoked] = useState(false);

  const presetValue = CRON_PRESETS.some((preset) => preset.value === (cron ?? ''))
    ? (cron ?? '')
    : 'custom';

  function onRotate(): void {
    if (feedId === null) {
      return;
    }
    rotate.mutate(feedId, {
      onSuccess: (minted) => {
        setMintedUrl(
          minted.url.startsWith('http') ? minted.url : `${window.location.origin}${minted.url}`,
        );
        setRevoked(false);
        toast.success(t('api_configurator.feeds.delivery.rotate_success'));
      },
      onError: (error) =>
        toast.error(httpErrorDetail(error) ?? t('api_configurator.feeds.card.action_error')),
    });
  }

  function onRevoke(): void {
    if (feedId === null) {
      return;
    }
    revoke.mutate(feedId, {
      onSuccess: () => {
        setMintedUrl(null);
        setRevoked(true);
        toast.success(t('api_configurator.feeds.delivery.revoke_success'));
      },
      onError: (error) =>
        toast.error(httpErrorDetail(error) ?? t('api_configurator.feeds.card.action_error')),
    });
  }

  const tokenState = mintedUrl !== null ? 'minted' : revoked || !hasToken ? 'none' : 'hidden';

  return (
    <div className="grid grid-cols-1 items-start gap-4 lg:grid-cols-2">
      <div className="space-y-4">
        <div className="rounded-3xl bg-white p-6 soft-shadow">
          <div className="mb-4 flex items-center gap-2.5">
            <span className="grid h-7 w-7 place-items-center rounded-xl bg-zinc-100 text-zinc-700">
              <Clock className="h-4 w-4" aria-hidden />
            </span>
            <div className="text-[14.5px] font-semibold tracking-tight">
              {t('api_configurator.feeds.delivery.schedule_title')}
            </div>
          </div>
          <fieldset
            className="flex flex-wrap gap-1.5 border-0 p-0"
            aria-label={t('api_configurator.feeds.delivery.presets_aria')}
          >
            {CRON_PRESETS.map((preset) => (
              <button
                key={preset.labelKey}
                type="button"
                aria-pressed={presetValue === preset.value}
                onClick={() => onCron(preset.value === '' ? null : preset.value)}
                className={cn(
                  'h-8 rounded-lg border px-2.5 text-[12px] font-medium',
                  presetValue === preset.value
                    ? 'border-zinc-900 bg-zinc-900 text-white'
                    : 'border-zinc-200 bg-white text-zinc-700 hover:bg-zinc-50',
                )}
              >
                {t(`api_configurator.feeds.delivery.preset.${preset.labelKey}`)}
              </button>
            ))}
          </fieldset>
          <label className="mt-3 block">
            <span className="text-[12px] font-medium text-zinc-700">
              {t('api_configurator.feeds.delivery.cron_label')}
              <span className="ml-1.5 text-[11px] font-normal text-zinc-500">
                {t('api_configurator.feeds.delivery.cron_hint')}
              </span>
            </span>
            <input
              value={cron ?? ''}
              onChange={(event) => onCron(event.target.value === '' ? null : event.target.value)}
              placeholder={t('api_configurator.feeds.delivery.cron_placeholder')}
              className="mt-1.5 h-10 w-full rounded-xl border border-zinc-200 bg-white px-3 font-mono text-[13px] outline-none focus:border-zinc-400"
            />
          </label>
          <div className="mt-4 flex items-center gap-3 border-t border-zinc-100 pt-4">
            <div className="flex-1">
              <div className="text-[13px] font-medium text-zinc-800">
                {t('api_configurator.feeds.delivery.gzip_title')}
              </div>
              <div className="text-[11.5px] text-zinc-500">
                {t('api_configurator.feeds.delivery.gzip_hint')}
              </div>
            </div>
            <button
              type="button"
              role="switch"
              aria-checked={gzip}
              aria-label={t('api_configurator.feeds.delivery.gzip_title')}
              onClick={() => onGzip(!gzip)}
              className={cn(
                'relative h-6 w-11 rounded-full transition',
                gzip ? 'bg-emerald-500' : 'bg-zinc-200',
              )}
            >
              <span
                className={cn(
                  'absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all',
                  gzip ? 'left-[22px]' : 'left-0.5',
                )}
              />
            </button>
          </div>
        </div>

        <div className="rounded-3xl bg-white p-6 soft-shadow">
          <div className="mb-4 flex items-center gap-2.5">
            <span className="grid h-7 w-7 place-items-center rounded-xl bg-zinc-100 text-zinc-700">
              <Lock className="h-4 w-4" aria-hidden />
            </span>
            <div className="text-[14.5px] font-semibold tracking-tight">
              {t('api_configurator.feeds.delivery.auth_title')}
            </div>
          </div>
          <div className="text-[12px] font-medium text-zinc-700">
            {t('api_configurator.feeds.delivery.auth_method')}
            <span className="ml-1.5 text-[11px] font-normal text-zinc-500">
              {t('api_configurator.feeds.delivery.auth_hint')}
            </span>
          </div>
          <div className="mt-1.5">
            <Segmented<'none' | 'basic'>
              full
              value={authType}
              onChange={onAuthType}
              ariaLabel={t('api_configurator.feeds.delivery.auth_method')}
              options={[
                { value: 'none', label: t('api_configurator.feeds.delivery.auth_none') },
                { value: 'basic', label: t('api_configurator.feeds.delivery.auth_basic') },
              ]}
            />
          </div>
          {authType === 'basic' && (
            <div className="mt-4 grid grid-cols-2 gap-x-5 gap-y-4">
              <label className="block">
                <span className="text-[12px] font-medium text-zinc-700">
                  {t('api_configurator.feeds.delivery.auth_user')}
                </span>
                <input
                  value={authUsername}
                  onChange={(event) => onAuthUsername(event.target.value)}
                  className="mt-1.5 h-10 w-full rounded-xl border border-zinc-200 bg-white px-3 font-mono text-[13px] outline-none focus:border-zinc-400"
                />
              </label>
              <label className="block">
                <span className="text-[12px] font-medium text-zinc-700">
                  {t('api_configurator.feeds.delivery.auth_password')}
                  <span className="ml-1.5 text-[11px] font-normal text-zinc-500">
                    {t('api_configurator.feeds.delivery.auth_password_hint')}
                  </span>
                </span>
                <input
                  type="password"
                  value={authPassword}
                  onChange={(event) => onAuthPassword(event.target.value)}
                  placeholder={hasToken ? '••••••••' : ''}
                  className="mt-1.5 h-10 w-full rounded-xl border border-zinc-200 bg-white px-3 font-mono text-[13px] outline-none focus:border-zinc-400"
                />
              </label>
            </div>
          )}
        </div>
      </div>

      <div className="space-y-4">
        <div className="rounded-3xl bg-white p-6 soft-shadow">
          <div className="mb-3 flex items-center gap-2.5">
            <span className="grid h-7 w-7 place-items-center rounded-xl bg-zinc-100 text-zinc-700">
              <Link2 className="h-4 w-4" aria-hidden />
            </span>
            <div className="text-[14.5px] font-semibold tracking-tight">
              {t('api_configurator.feeds.delivery.url_title')}
            </div>
          </div>
          {tokenState === 'minted' && mintedUrl !== null ? (
            <>
              <div className="rounded-xl border border-emerald-200 bg-emerald-50/60 px-3 py-2 text-[12px] text-emerald-900">
                {t('api_configurator.feeds.delivery.url_once')}
              </div>
              <div className="mt-2 flex items-center gap-2">
                <code className="min-w-0 flex-1 truncate rounded-lg border border-zinc-100 bg-zinc-50 px-2.5 py-1.5 font-mono text-[11.5px] text-zinc-700">
                  {mintedUrl}
                </code>
                <CopyButton value={mintedUrl} />
              </div>
            </>
          ) : (
            <p className="text-[12.5px] text-zinc-500">
              {t(
                tokenState === 'hidden'
                  ? 'api_configurator.feeds.delivery.url_hidden'
                  : 'api_configurator.feeds.delivery.url_none',
              )}
            </p>
          )}
          <div className="mt-4 flex items-center gap-2">
            <button
              type="button"
              onClick={onRotate}
              disabled={feedId === null || rotate.isPending}
              className="flex h-9 items-center gap-1.5 rounded-lg bg-zinc-900 px-3 text-[12px] font-medium text-white hover:bg-zinc-800 disabled:opacity-40"
            >
              <RefreshCw
                className={cn('h-3.5 w-3.5', rotate.isPending && 'animate-spin')}
                aria-hidden
              />
              {t(
                tokenState === 'none'
                  ? 'api_configurator.feeds.delivery.mint'
                  : 'api_configurator.feeds.delivery.rotate',
              )}
            </button>
            <button
              type="button"
              onClick={onRevoke}
              disabled={feedId === null || tokenState === 'none' || revoke.isPending}
              className="flex h-9 items-center gap-1.5 rounded-lg border border-rose-200 bg-white px-3 text-[12px] font-medium text-rose-700 hover:bg-rose-50 disabled:opacity-40"
            >
              <Trash2 className="h-3.5 w-3.5" aria-hidden />
              {t('api_configurator.feeds.delivery.revoke')}
            </button>
          </div>
        </div>

        <div className="flex items-start gap-2.5 rounded-2xl border border-emerald-200 bg-emerald-50/50 px-4 py-3 text-[12px] leading-relaxed text-emerald-900">
          <ShieldCheck className="mt-0.5 h-4 w-4 shrink-0 text-emerald-600" aria-hidden />
          <span>{t('api_configurator.feeds.delivery.pull_note')}</span>
        </div>
      </div>
    </div>
  );
}

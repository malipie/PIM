import { useList } from '@refinedev/core';
import { useTranslation } from 'react-i18next';

import { useCurrentWorkspace } from '@/lib/use-current-workspace';
import { Field, SectionLabel } from '../../components/primitives';

interface ChannelRow {
  id: string;
  code: string;
  name: string;
}

export interface OutboundSource {
  channel: string;
  locale: string;
}

/**
 * #2667 — the outbound value-source picker: which PIM channel / locale the
 * push reads its attribute values from (empty = global values). Scoped values
 * win per attribute with a fallback to the global one; the inbound leg of a
 * bidirectional binding still writes global values (hint spells that out).
 * Channels come from the API; locales from the workspace's enabled SHORT
 * codes (`/api/tenant-locales` is BCP-47 + permission-gated — wrong source).
 */
export function OutboundSourceSection({
  value,
  onChange,
}: {
  value: OutboundSource;
  onChange: (next: OutboundSource) => void;
}) {
  const { t } = useTranslation();
  const channelsQuery = useList<ChannelRow>({ resource: 'channels', pagination: { mode: 'off' } });
  const workspace = useCurrentWorkspace();
  const locales = workspace.data?.enabledLocales ?? [];

  const selectClass =
    'focus-ring h-10 w-full rounded-xl border border-zinc-200 bg-white px-3 text-[13px]';

  return (
    <section className="soft-shadow rounded-2xl border border-zinc-200 bg-white p-5">
      <SectionLabel>{t('api_configurator.sync.source.title')}</SectionLabel>
      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <Field label={t('api_configurator.sync.source.channel')}>
          <select
            value={value.channel}
            onChange={(e) => onChange({ ...value, channel: e.target.value })}
            aria-label={t('api_configurator.sync.source.channel')}
            className={selectClass}
          >
            <option value="">{t('api_configurator.sync.source.global')}</option>
            {channelsQuery.result.data.map((channel) => (
              <option key={channel.id} value={channel.code}>
                {channel.name} · {channel.code}
              </option>
            ))}
          </select>
        </Field>
        <Field label={t('api_configurator.sync.source.locale')}>
          <select
            value={value.locale}
            onChange={(e) => onChange({ ...value, locale: e.target.value })}
            aria-label={t('api_configurator.sync.source.locale')}
            className={selectClass}
          >
            <option value="">{t('api_configurator.sync.source.global')}</option>
            {locales.map((code) => (
              <option key={code} value={code}>
                {code.toUpperCase()}
              </option>
            ))}
          </select>
        </Field>
      </div>
      <p className="mt-3 text-[12.5px] leading-relaxed text-zinc-500">
        {t('api_configurator.sync.source.hint')}
      </p>
    </section>
  );
}

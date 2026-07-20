import { useTranslation } from 'react-i18next';
import { useSearchParams } from 'react-router';

import { ProducerKeysTab } from './ProducerKeysTab';
import { ProducerProfilesTab } from './ProducerProfilesTab';
import { ProducerWebhooksTab } from './ProducerWebhooksTab';
import { PRODUCER_TABS, type ProducerTab, toProducerTab } from './types';

/**
 * APIC-P4-06 — the producer hub (`integracje/api-producer.jsx`): the "My API"
 * side of the configurator with three deep-linkable tabs (`?tab=`): Profiles
 * (projections over the public API), Keys (API tokens) and Webhooks (per-profile
 * outbound config + latest delivery outcome from the P4-05 history).
 */
export function ProducerHubPage() {
  const { t } = useTranslation();
  const [searchParams, setSearchParams] = useSearchParams();
  const tab = toProducerTab(searchParams.get('tab'));

  function selectTab(next: ProducerTab): void {
    setSearchParams(next === 'profiles' ? {} : { tab: next });
  }

  return (
    <div className="space-y-5">
      {/* #2671 — visible header removed; sr-only h1 keeps a11y (see
          ConnectionsHubPage). */}
      <h1 className="sr-only">{t('api_configurator.producer.title')}</h1>
      <div
        role="tablist"
        aria-label={t('api_configurator.producer.tabs_label')}
        className="flex flex-wrap gap-1 border-b border-zinc-200"
      >
        {PRODUCER_TABS.map((id) => {
          const active = tab === id;
          return (
            <button
              key={id}
              type="button"
              role="tab"
              aria-selected={active}
              onClick={() => selectTab(id)}
              className={`-mb-px border-b-2 px-3 py-2 text-[13px] font-medium transition ${active ? 'border-zinc-900 text-zinc-900' : 'border-transparent text-zinc-500 hover:text-zinc-800'}`}
            >
              {t(`api_configurator.producer.tab.${id}`)}
            </button>
          );
        })}
      </div>

      {tab === 'profiles' ? <ProducerProfilesTab /> : null}
      {tab === 'keys' ? <ProducerKeysTab /> : null}
      {tab === 'webhooks' ? <ProducerWebhooksTab /> : null}
    </div>
  );
}

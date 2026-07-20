import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';

import { PillTabs } from '@/components/ui-v2/pill-tabs';

const BASE = '/integrations/api-configurator/feeds';

/**
 * XMLF-P5-07 — the two-tab sub-navigation of the Feeds area: the hub list and
 * the global cross-feed monitor. #2671 swapped the legacy underline tabs for
 * the shared v2 PillTabs, matching the Konfigurator API shell.
 */
export function FeedsSubnav({ active }: { active: 'hub' | 'monitor' }) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  return (
    <PillTabs
      ariaLabel={t('api_configurator.feeds.subnav.aria')}
      activeId={active}
      onChange={(id) => {
        void navigate(id === 'monitor' ? `${BASE}/monitor` : BASE);
      }}
      items={[
        { id: 'hub', label: t('api_configurator.feeds.subnav.hub') },
        { id: 'monitor', label: t('api_configurator.feeds.subnav.monitor') },
      ]}
    />
  );
}

import { useTranslation } from 'react-i18next';
import { Outlet, useLocation, useNavigate } from 'react-router';

import { PillTabs } from '@/components/ui-v2/pill-tabs';

const BASE = '/integrations/api-configurator';

type ShellTab = 'connections' | 'producer' | 'feeds' | 'monitor';

/**
 * APIC-P0-04 / P4-08 — shared shell unifying the faces of the Konfigurator API
 * area (ADR-0022, api-app.jsx prototype): a pill-tab split over an Outlet
 * between the **consumer** side (Połączenia — hub/wizard/detail/mapping/sync,
 * P1-07/P2/P3-11/P3-12), the **producer** side (Moje API — the hub with
 * Profile/Keys/Webhooks tabs + the profile builder, P4-06/P4-07), the product
 * XML **Feeds** hub (XMLF-P5-01 — the third citizen of the area, ADR-0023) and
 * the sync **Monitor** (P4-02). The active tab is derived from the path prefix
 * so every sub-route (connection detail, profile builder, feed detail, monitor
 * drill-down) keeps its face highlighted; switching tabs deep-links to each
 * face's landing.
 */
export function KonfiguratorApiLayout() {
  const { t } = useTranslation();
  const { pathname } = useLocation();
  const navigate = useNavigate();

  const activeId: ShellTab = pathname.startsWith(`${BASE}/connections`)
    ? 'connections'
    : pathname.startsWith(`${BASE}/feeds`)
      ? 'feeds'
      : pathname.startsWith(`${BASE}/monitor`)
        ? 'monitor'
        : 'producer';

  return (
    <div className="space-y-5">
      <PillTabs
        ariaLabel={t('api_configurator.shell.tabs_aria')}
        activeId={activeId}
        onChange={(id) => {
          void navigate(id === 'producer' ? BASE : `${BASE}/${id}`);
        }}
        items={[
          { id: 'connections', label: t('api_configurator.shell.tabs.connections') },
          { id: 'producer', label: t('api_configurator.shell.tabs.producer') },
          { id: 'feeds', label: t('api_configurator.shell.tabs.feeds') },
          { id: 'monitor', label: t('api_configurator.shell.tabs.monitor') },
        ]}
      />
      <Outlet />
    </div>
  );
}

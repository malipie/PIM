import { useList } from '@refinedev/core';
import { useTranslation } from 'react-i18next';

import {
  type EndpointRole,
  type HttpMethod,
  MethodPill,
  type PaginationKind,
  PaginationPill,
  RolePill,
  SectionLabel,
} from '../../components/primitives';
import type { RemoteEndpointRow } from '../wizard/steps/StepEndpoints';

/**
 * APIC-P3-12 — the connection-detail Endpoints tab: a read-only list of the
 * connection's REST descriptor endpoints. Editing happens in the wizard.
 */
export function DetailEndpoints({ connectionId }: { connectionId: string }) {
  const { t } = useTranslation();

  const query = useList<RemoteEndpointRow>({
    resource: 'remote_endpoints',
    filters: [{ field: 'connection', operator: 'eq', value: connectionId }],
    pagination: { mode: 'off' },
    queryOptions: { enabled: connectionId !== '' },
  });

  const endpoints = query.result.data;

  return (
    <section className="soft-shadow rounded-2xl border border-zinc-200 bg-white p-5">
      <SectionLabel>{t('api_configurator.detail.endpoints.title')}</SectionLabel>
      {endpoints.length === 0 ? (
        <p className="text-[12.5px] text-zinc-500">
          {t('api_configurator.detail.endpoints.empty')}
        </p>
      ) : (
        <div className="space-y-2">
          {endpoints.map((ep) => (
            <div key={ep.id} className="rounded-xl border border-zinc-200 bg-white p-3.5">
              <div className="flex flex-wrap items-center gap-2.5">
                <RolePill value={ep.role as EndpointRole} />
                <MethodPill method={ep.httpMethod as HttpMethod} />
                <span className="font-mono text-[13px] text-zinc-800">{ep.pathTemplate}</span>
                {ep.role.startsWith('write') && ep.requestFormat === 'form' ? (
                  <span className="rounded bg-violet-50 px-1.5 py-0.5 font-mono text-[10.5px] font-medium text-violet-800">
                    form
                  </span>
                ) : null}
                <span className="ml-auto">
                  <PaginationPill kind={(ep.pagination?.strategy ?? 'none') as PaginationKind} />
                </span>
              </div>
              {ep.responseIdSelector != null && ep.responseIdAttribute != null ? (
                <div className="mt-2 flex items-center gap-2 text-[11.5px]">
                  <span className="text-[10px] uppercase tracking-wider text-zinc-400">
                    {t('api_configurator.detail.endpoints.id_capture')}
                  </span>
                  <span className="font-mono text-zinc-600">
                    {ep.responseIdSelector} → {ep.responseIdAttribute}
                  </span>
                </div>
              ) : null}
              {ep.requestBodyTemplate != null ? (
                <div className="mt-2 flex items-center gap-2 text-[11.5px]">
                  <span className="text-[10px] uppercase tracking-wider text-zinc-400">
                    {t('api_configurator.detail.endpoints.body_template')}
                  </span>
                  <span className="truncate font-mono text-zinc-600">
                    {JSON.stringify(ep.requestBodyTemplate)}
                  </span>
                </div>
              ) : null}
              {ep.recordSelector != null && ep.recordSelector !== '' ? (
                <div className="mt-2 flex items-center gap-2 text-[11.5px]">
                  <span className="text-[10px] uppercase tracking-wider text-zinc-400">
                    {t('api_configurator.detail.endpoints.selector')}
                  </span>
                  <span className="font-mono text-zinc-600">{ep.recordSelector}</span>
                </div>
              ) : null}
            </div>
          ))}
        </div>
      )}
    </section>
  );
}

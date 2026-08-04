import { useApiUrl, useCreate, useCustomMutation, useList } from '@refinedev/core';
import { Check, Download } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { objectWithKeys } from '@/lib/runtime-guards';

import { JsonView, Segmented } from '../../../components/primitives';
import type { RemoteEndpointRow } from './StepEndpoints';

interface DiscoveredField {
  path: string;
  dataType: string;
  sampleValue: string | null;
}

interface DiscoverResult {
  fields: DiscoveredField[];
  sampleRecord: Record<string, unknown>;
  sampledRecords: number;
}

/**
 * APIC-P2-06 — wizard step 4: schema discovery. Samples a read endpoint through
 * `POST /api/connections/{id}/discover`, lets the user accept the proposed
 * fields, and persists the accepted ones as RemoteFields (P2-05 CRUD). Replaces
 * hand-typing the remote schema.
 */
export function StepSchema({ connectionId }: { connectionId: string | null }) {
  const { t } = useTranslation();
  const apiUrl = useApiUrl();
  const { result, query } = useList<RemoteEndpointRow>({
    resource: 'remote_endpoints',
    filters:
      connectionId === null ? [] : [{ field: 'connection', operator: 'eq', value: connectionId }],
    queryOptions: { enabled: connectionId !== null },
    pagination: { mode: 'off' },
  });
  const { mutate: discover, mutation: discoverMutation } = useCustomMutation<DiscoverResult>();
  const { mutateAsync: createField } = useCreate();

  const readEndpoints = useMemo(
    () => result.data.filter((endpoint) => endpoint.role.startsWith('read')),
    [result.data],
  );

  const [endpointId, setEndpointId] = useState<string | null>(null);
  const [discovered, setDiscovered] = useState<DiscoverResult | null>(null);
  const [accepted, setAccepted] = useState<Set<string>>(new Set());
  const [saved, setSaved] = useState(false);
  const [saving, setSaving] = useState(false);

  const activeEndpoint = endpointId ?? readEndpoints[0]?.id ?? null;

  function runDiscovery(): void {
    if (connectionId === null || activeEndpoint === null) {
      return;
    }
    setDiscovered(null);
    setSaved(false);
    discover(
      {
        url: `${apiUrl}/connections/${connectionId}/discover`,
        method: 'post',
        values: { endpoint: activeEndpoint },
      },
      {
        onSuccess: ({ data }) => {
          const payload = objectWithKeys<DiscoverResult>(data, ['fields']);
          if (payload === null) {
            return;
          }
          setDiscovered(payload);
          setAccepted(new Set(payload.fields.map((field) => field.path)));
        },
      },
    );
  }

  async function acceptFields(): Promise<void> {
    if (discovered === null || activeEndpoint === null) {
      return;
    }
    setSaving(true);
    try {
      for (const field of discovered.fields) {
        if (!accepted.has(field.path)) {
          continue;
        }
        await createField({
          resource: 'remote_fields',
          values: {
            endpoint: activeEndpoint,
            path: field.path,
            dataType: field.dataType,
            sampleValue: field.sampleValue,
          },
          successNotification: false,
        });
      }
      setSaved(true);
    } finally {
      setSaving(false);
    }
  }

  function toggle(path: string): void {
    setAccepted((prev) => {
      const next = new Set(prev);
      if (next.has(path)) {
        next.delete(path);
      } else {
        next.add(path);
      }
      return next;
    });
  }

  return (
    <div className="soft-shadow space-y-4 rounded-2xl border border-zinc-200 bg-white p-6">
      <div className="flex flex-wrap items-center gap-3">
        {readEndpoints.length > 0 ? (
          <Segmented
            ariaLabel={t('api_configurator.wizard.schema.endpoint')}
            size="sm"
            value={activeEndpoint ?? readEndpoints[0]?.id ?? ''}
            onChange={setEndpointId}
            options={readEndpoints.map((endpoint) => ({
              value: endpoint.id,
              label: endpoint.pathTemplate,
            }))}
          />
        ) : null}
        <Button
          type="button"
          onClick={runDiscovery}
          disabled={connectionId === null || activeEndpoint === null || discoverMutation.isPending}
        >
          <Download className="mr-1.5 size-4" aria-hidden="true" />
          {discoverMutation.isPending
            ? t('api_configurator.wizard.schema.fetching')
            : t('api_configurator.wizard.schema.fetch')}
        </Button>
      </div>

      {readEndpoints.length === 0 && !query.isLoading ? (
        <p className="text-[13px] text-zinc-500">
          {t('api_configurator.wizard.schema.no_read_endpoint')}
        </p>
      ) : null}

      {discovered === null ? (
        <div className="rounded-xl border border-dashed border-zinc-200 px-5 py-10 text-center text-[13px] text-zinc-500">
          {t('api_configurator.wizard.schema.empty')}
        </div>
      ) : (
        <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_1.1fr]">
          <div className="overflow-hidden rounded-xl border border-zinc-200">
            <div className="border-b border-zinc-100 bg-zinc-50/60 px-3 py-2 text-[10.5px] font-medium uppercase tracking-wider text-zinc-500">
              {t('api_configurator.wizard.schema.sample')}
            </div>
            <div className="p-3">
              <JsonView value={discovered.sampleRecord} maxHeight={360} />
            </div>
          </div>
          <div>
            <div className="mb-2 flex items-center justify-between">
              <div className="text-[12px] text-zinc-600">
                {t('api_configurator.wizard.schema.accepted', {
                  count: accepted.size,
                  total: discovered.fields.length,
                })}
              </div>
              <div className="flex items-center gap-1.5">
                <button
                  type="button"
                  onClick={() => setAccepted(new Set(discovered.fields.map((field) => field.path)))}
                  className="text-[11.5px] text-zinc-500 hover:text-zinc-900"
                >
                  {t('api_configurator.wizard.schema.all')}
                </button>
                <span className="text-zinc-300">·</span>
                <button
                  type="button"
                  onClick={() => setAccepted(new Set())}
                  className="text-[11.5px] text-zinc-500 hover:text-zinc-900"
                >
                  {t('api_configurator.wizard.schema.none')}
                </button>
              </div>
            </div>
            <div className="max-h-[340px] divide-y divide-zinc-50 overflow-y-auto rounded-xl border border-zinc-200">
              {discovered.fields.map((field) => (
                <label
                  key={field.path}
                  className="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-zinc-50/70"
                >
                  <input
                    type="checkbox"
                    checked={accepted.has(field.path)}
                    onChange={() => toggle(field.path)}
                    className="size-4 rounded"
                  />
                  <div className="min-w-0 flex-1">
                    <div className="truncate font-mono text-[12px] text-zinc-800">{field.path}</div>
                    {field.sampleValue !== null ? (
                      <div className="truncate font-mono text-[10.5px] text-zinc-500">
                        {t('api_configurator.wizard.schema.field_sample', {
                          value: field.sampleValue,
                        })}
                      </div>
                    ) : null}
                  </div>
                  <span className="shrink-0 rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[10.5px] text-zinc-600">
                    {field.dataType}
                  </span>
                </label>
              ))}
            </div>
            <div className="mt-3 flex items-center gap-3">
              <Button type="button" onClick={acceptFields} disabled={saving || accepted.size === 0}>
                <Check className="mr-1.5 size-4" aria-hidden="true" />
                {saving
                  ? t('api_configurator.wizard.schema.saving')
                  : t('api_configurator.wizard.schema.accept', { count: accepted.size })}
              </Button>
              {saved ? (
                <span className="text-[12.5px] text-emerald-600">
                  {t('api_configurator.wizard.schema.saved')}
                </span>
              ) : null}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}

import {
  useApiUrl,
  useCreate,
  useCustomMutation,
  useDelete,
  useList,
  useUpdate,
} from '@refinedev/core';
import { ArrowLeft, Plus, Trash2, TriangleAlert } from 'lucide-react';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { objectWithKeys } from '@/lib/runtime-guards';

import {
  CoverageBar,
  DirToggle,
  type SyncDirection,
  TypeCompat,
} from '../../components/primitives';
import type { RemoteEndpointRow } from '../wizard/steps/StepEndpoints';

type ApiDirection = 'inbound' | 'outbound' | 'both';

interface FieldMappingRow {
  id: string;
  connectionId: string;
  pimTarget: string;
  remoteFieldPath: string;
  direction: ApiDirection;
  isMatchKey: boolean;
  version: number;
  endpointId: string | null;
}

interface RemoteFieldRow {
  id: string;
  path: string;
  dataType: string;
}

interface ValidateWarning {
  pimTarget: string;
  remoteFieldPath: string;
  message: string;
}

interface ValidateResult {
  valid: boolean;
  errors: string[];
  warnings: ValidateWarning[];
}

/** Common PIM targets offered in the mapper (system fields); custom attribute codes can be typed. */
const PIM_TARGETS = ['sku', 'name', 'status', 'description', 'category', 'price'];

const HUB = '/integrations/api-configurator/connections';

const toToggle = (d: ApiDirection): SyncDirection => (d === 'both' ? 'bidirectional' : d);
const toApi = (d: SyncDirection): ApiDirection => (d === 'bidirectional' ? 'both' : d);

/**
 * APIC-P2-09 — the two-column field mapper (PIM ↔ remote). Lists a connection's
 * FieldMappings against the P2-08 CRUD API with direction + match-key toggles,
 * a coverage bar, and per-row type warnings from the validate endpoint. The
 * value-transform engine is a deferred §7 hook (disabled placeholder).
 *
 * The remote-field pool is sourced from the connection's first read endpoint
 * (the common single-stream case); multi-endpoint pooling lands with the
 * connection detail view (P3-12).
 */
export function MappingScreen({ embedded = false }: { embedded?: boolean } = {}) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const apiUrl = useApiUrl();
  const { id: connectionId = null } = useParams();

  const mappingsQuery = useList<FieldMappingRow>({
    resource: 'field_mappings',
    filters:
      connectionId === null ? [] : [{ field: 'connection', operator: 'eq', value: connectionId }],
    queryOptions: { enabled: connectionId !== null },
    pagination: { mode: 'off' },
  });
  const endpointsQuery = useList<RemoteEndpointRow>({
    resource: 'remote_endpoints',
    filters:
      connectionId === null ? [] : [{ field: 'connection', operator: 'eq', value: connectionId }],
    queryOptions: { enabled: connectionId !== null },
    pagination: { mode: 'off' },
  });

  const readEndpointId = useMemo(
    () => endpointsQuery.result.data.find((e) => e.role.startsWith('read'))?.id ?? null,
    [endpointsQuery.result.data],
  );

  const fieldsQuery = useList<RemoteFieldRow>({
    resource: 'remote_fields',
    filters:
      readEndpointId === null ? [] : [{ field: 'endpoint', operator: 'eq', value: readEndpointId }],
    queryOptions: { enabled: readEndpointId !== null },
    pagination: { mode: 'off' },
  });

  const { mutate: create } = useCreate();
  const { mutate: update } = useUpdate();
  const { mutate: remove } = useDelete();
  const { mutate: runValidate } = useCustomMutation<ValidateResult>();
  const [validation, setValidation] = useState<ValidateResult | null>(null);

  const mappings = mappingsQuery.result.data;
  const remoteFields = fieldsQuery.result.data;

  const validate = useCallback(() => {
    if (connectionId === null) {
      return;
    }
    runValidate(
      {
        url: `${apiUrl}/connections/${connectionId}/mappings/validate`,
        method: 'post',
        values: {},
      },
      {
        onSuccess: ({ data }) => {
          const parsed = objectWithKeys<ValidateResult>(data, ['valid', 'errors', 'warnings']);
          setValidation(
            parsed ?? {
              valid: false,
              errors: [
                t('api_configurator.mapping.validation_failed', {
                  defaultValue: 'Nie udało się zwalidować mapowań.',
                }),
              ],
              warnings: [],
            },
          );
        },
        // #2739 — without this the panel simply did not render on a failed
        // call, so "validation passed" and "validation crashed" looked
        // identical. Surface it as an invalid result with the reason.
        onError: (err) =>
          setValidation({
            valid: false,
            errors: [
              err?.message ??
                t('api_configurator.mapping.validation_failed', {
                  defaultValue: 'Nie udało się zwalidować mapowań.',
                }),
            ],
            warnings: [],
          }),
      },
    );
  }, [apiUrl, connectionId, runValidate, t]);

  // Re-validate whenever the mapping set changes.
  // biome-ignore lint/correctness/useExhaustiveDependencies: re-run on mapping count/version, not the callback identity
  useEffect(() => {
    if (mappings.length > 0) {
      validate();
    } else {
      setValidation(null);
    }
  }, [mappings.length, mappings.map((m) => `${m.id}:${m.version}`).join(','), validate]);

  const warningFor = useCallback(
    (m: FieldMappingRow): string | null =>
      validation?.warnings.find(
        (w) => w.pimTarget === m.pimTarget && w.remoteFieldPath === m.remoteFieldPath,
      )?.message ?? null,
    [validation],
  );

  const mappedTargets = useMemo(() => new Set(mappings.map((m) => m.pimTarget)), [mappings]);
  const mappedPaths = useMemo(() => new Set(mappings.map((m) => m.remoteFieldPath)), [mappings]);
  const unmappedTargets = PIM_TARGETS.filter((target) => !mappedTargets.has(target));
  const unmappedFields = remoteFields.filter((f) => !mappedPaths.has(f.path));
  const warningCount = validation?.warnings.length ?? 0;

  const [newTarget, setNewTarget] = useState('');
  const [newPath, setNewPath] = useState('');
  const [newEndpoint, setNewEndpoint] = useState('');

  const allEndpoints = endpointsQuery.result.data;
  const endpointLabel = useCallback(
    (endpointId: string | null): string | null => {
      if (endpointId === null) {
        return null;
      }
      const endpoint = allEndpoints.find((e) => e.id === endpointId);
      return endpoint === undefined
        ? endpointId
        : `${endpoint.httpMethod} ${endpoint.pathTemplate} · ${endpoint.role}`;
    },
    [allEndpoints],
  );

  function refresh(): void {
    void mappingsQuery.query.refetch();
  }

  function addMapping(): void {
    if (connectionId === null || newTarget.trim() === '' || newPath.trim() === '') {
      return;
    }
    create(
      {
        resource: 'field_mappings',
        values: {
          connection: connectionId,
          pimTarget: newTarget.trim(),
          remoteFieldPath: newPath.trim(),
          direction: 'inbound',
          isMatchKey: false,
          endpoint: newEndpoint === '' ? null : newEndpoint,
        },
        successNotification: false,
      },
      {
        onSuccess: () => {
          setNewTarget('');
          setNewPath('');
          refresh();
        },
      },
    );
  }

  function patch(id: string, values: Record<string, unknown>): void {
    update(
      { resource: 'field_mappings', id, values, successNotification: false },
      { onSuccess: refresh },
    );
  }

  return (
    <div className="space-y-5">
      {embedded ? null : (
        <div className="flex items-center gap-3">
          <Button
            variant="outline"
            size="icon"
            onClick={() => navigate(HUB)}
            aria-label={t('api_configurator.mapping.back')}
          >
            <ArrowLeft className="size-4" aria-hidden="true" />
          </Button>
          <div className="min-w-0 flex-1">
            <h1 className="font-display text-[22px] font-semibold tracking-tight">
              {t('api_configurator.mapping.title')}
            </h1>
            <p className="text-[12.5px] text-zinc-500">{t('api_configurator.mapping.subtitle')}</p>
          </div>
          <div className="flex items-center gap-4">
            {warningCount > 0 ? (
              <span className="inline-flex items-center gap-1.5 text-[11.5px] font-medium text-amber-800">
                <TriangleAlert className="size-4 text-amber-600" aria-hidden="true" />
                {t('api_configurator.mapping.warnings', { count: warningCount })}
              </span>
            ) : null}
            <CoverageBar
              mapped={mappings.length}
              total={PIM_TARGETS.length}
              width={160}
              ariaLabel={t('api_configurator.mapping.coverage')}
            />
          </div>
        </div>
      )}

      {validation !== null && !validation.valid ? (
        <div className="rounded-xl border border-rose-200 bg-rose-50/60 p-3 text-[12.5px] text-rose-800">
          {validation.errors.map((error) => (
            <div key={error}>{error}</div>
          ))}
        </div>
      ) : null}

      <div className="space-y-2">
        {mappings.map((m) => {
          const warning = warningFor(m);
          return (
            <div
              key={m.id}
              className={`rounded-2xl border bg-white p-3 ${warning === null ? 'border-zinc-200' : 'border-amber-200'}`}
            >
              <div className="grid grid-cols-1 items-center gap-3 lg:grid-cols-[1fr_140px_1fr]">
                <div className="rounded-xl border border-zinc-100 bg-zinc-50/70 px-3 py-2.5">
                  <span className="text-[13px] font-semibold text-zinc-900">{m.pimTarget}</span>
                  {m.isMatchKey ? (
                    <span className="ml-2 rounded bg-amber-50 px-1.5 py-0.5 text-[10px] font-medium text-amber-800">
                      {t('api_configurator.mapping.match_key')}
                    </span>
                  ) : null}
                </div>
                <div className="flex flex-col items-center gap-1.5">
                  <DirToggle
                    value={toToggle(m.direction)}
                    onChange={(next) => patch(m.id, { direction: toApi(next) })}
                    title={t('api_configurator.mapping.direction')}
                  />
                  <button
                    type="button"
                    onClick={() => patch(m.id, { isMatchKey: !m.isMatchKey })}
                    aria-pressed={m.isMatchKey}
                    className={`h-6 rounded-md border px-2 text-[10px] font-medium transition ${m.isMatchKey ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-zinc-200 bg-white text-zinc-500 hover:text-zinc-800'}`}
                  >
                    {t('api_configurator.mapping.key')}
                  </button>
                </div>
                <div className="rounded-xl border border-sky-100 bg-sky-50/40 px-3 py-2.5">
                  <div className="flex items-center gap-2">
                    <span className="truncate font-mono text-[12.5px] font-medium text-zinc-900">
                      {m.remoteFieldPath}
                    </span>
                    <TypeCompat
                      ok={warning === null}
                      title={warning ?? t('api_configurator.mapping.type_ok')}
                    />
                  </div>
                </div>
              </div>

              {warning !== null ? (
                <div className="mt-2 flex items-center gap-2.5 rounded-xl bg-amber-50/70 px-3 py-2 text-[11.5px] text-amber-900">
                  <TriangleAlert className="size-4 shrink-0 text-amber-600" aria-hidden="true" />
                  <span className="flex-1">{warning}</span>
                  <button
                    type="button"
                    disabled
                    title={t('api_configurator.mapping.transform_hint')}
                    className="shrink-0 cursor-not-allowed rounded-md border border-dashed border-zinc-300 px-2 py-0.5 text-[10.5px] font-medium text-zinc-500"
                  >
                    {t('api_configurator.mapping.transform')}
                  </button>
                </div>
              ) : null}

              <div className="mt-2 flex items-center justify-between gap-3">
                <div className="flex min-w-0 items-center gap-2 text-[11px] text-zinc-500">
                  <span className="shrink-0 text-[10px] uppercase tracking-wider text-zinc-500">
                    {t('api_configurator.mapping.endpoint_scope')}
                  </span>
                  <select
                    value={m.endpointId ?? ''}
                    onChange={(e) => patch(m.id, { endpoint: e.target.value })}
                    aria-label={t('api_configurator.mapping.endpoint_scope')}
                    className="focus-ring h-7 max-w-[280px] truncate rounded-md border border-zinc-200 bg-white px-1.5 text-[11px]"
                  >
                    <option value="">{t('api_configurator.mapping.endpoint_all')}</option>
                    {allEndpoints.map((ep) => (
                      <option key={ep.id} value={ep.id}>
                        {endpointLabel(ep.id)}
                      </option>
                    ))}
                  </select>
                </div>
                <button
                  type="button"
                  onClick={() =>
                    remove({ resource: 'field_mappings', id: m.id }, { onSuccess: refresh })
                  }
                  className="flex items-center gap-1 text-[11px] font-medium text-zinc-500 hover:text-rose-600"
                >
                  <Trash2 className="size-3.5" aria-hidden="true" />
                  {t('api_configurator.mapping.remove')}
                </button>
              </div>
            </div>
          );
        })}
      </div>

      <div className="grid grid-cols-1 items-end gap-3 rounded-xl border border-dashed border-zinc-300 p-4 lg:grid-cols-[1fr_1fr_220px_auto]">
        <Input
          value={newTarget}
          onChange={(e) => setNewTarget(e.target.value)}
          placeholder={t('api_configurator.mapping.pim_placeholder')}
          aria-label={t('api_configurator.mapping.pim_target')}
          list="pim-target-suggestions"
          className="h-9"
        />
        <datalist id="pim-target-suggestions">
          {unmappedTargets.map((target) => (
            <option key={target} value={target} />
          ))}
        </datalist>
        <Input
          value={newPath}
          onChange={(e) => setNewPath(e.target.value)}
          placeholder="$.sku"
          aria-label={t('api_configurator.mapping.remote_path')}
          list="remote-path-suggestions"
          className="h-9 font-mono"
        />
        <datalist id="remote-path-suggestions">
          {unmappedFields.map((field) => (
            <option key={field.path} value={field.path} />
          ))}
        </datalist>
        <select
          value={newEndpoint}
          onChange={(e) => setNewEndpoint(e.target.value)}
          aria-label={t('api_configurator.mapping.endpoint_scope')}
          className="focus-ring h-9 truncate rounded-xl border border-zinc-200 bg-white px-2 text-[12px]"
        >
          <option value="">{t('api_configurator.mapping.endpoint_all')}</option>
          {allEndpoints.map((ep) => (
            <option key={ep.id} value={ep.id}>
              {endpointLabel(ep.id)}
            </option>
          ))}
        </select>
        <Button type="button" onClick={addMapping}>
          <Plus className="mr-1 size-4" aria-hidden="true" />
          {t('api_configurator.mapping.add')}
        </Button>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div className="soft-shadow rounded-2xl border border-zinc-200 bg-white p-4">
          <div className="mb-3 text-[11px] font-medium uppercase tracking-wider text-zinc-500">
            {t('api_configurator.mapping.unmapped_pim', { count: unmappedTargets.length })}
          </div>
          <div className="flex flex-wrap gap-1.5">
            {unmappedTargets.length === 0 ? (
              <span className="text-[12px] text-emerald-700">
                {t('api_configurator.mapping.all_pim_mapped')}
              </span>
            ) : (
              unmappedTargets.map((target) => (
                <span
                  key={target}
                  className="rounded-lg border border-zinc-200 px-2 py-1 text-[11.5px] text-zinc-700"
                >
                  {target}
                </span>
              ))
            )}
          </div>
        </div>
        <div className="soft-shadow rounded-2xl border border-zinc-200 bg-white p-4">
          <div className="mb-3 text-[11px] font-medium uppercase tracking-wider text-zinc-500">
            {t('api_configurator.mapping.unmapped_remote', { count: unmappedFields.length })}
          </div>
          <div className="flex flex-wrap gap-1.5">
            {unmappedFields.length === 0 ? (
              <span className="text-[12px] text-zinc-500">
                {t('api_configurator.mapping.all_remote_mapped')}
              </span>
            ) : (
              unmappedFields.map((field) => (
                <span
                  key={field.path}
                  className="rounded-lg border border-zinc-200 px-2 py-1 font-mono text-[11.5px] text-zinc-700"
                >
                  {field.path}
                </span>
              ))
            )}
          </div>
        </div>
      </div>
    </div>
  );
}

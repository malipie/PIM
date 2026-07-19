import { useApiUrl, useCreate, useCustomMutation, useList, useUpdate } from '@refinedev/core';
import { ArrowLeft, Info, Play, Shield } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useToast } from '@/components/ui/toast';
import type { FilterDsl } from '@/lib/filters/filter-dsl';
import {
  ApiToggle,
  Field,
  SectionLabel,
  SecurityNote,
  Segmented,
  type SyncDirection,
} from '../../components/primitives';
import type { RemoteEndpointRow } from '../wizard/steps/StepEndpoints';
import { OutboundFilterSection } from './OutboundFilterSection';
import { DirDiagram, EndpointSelect, ReadonlyValue } from './sync-components';

type ConflictPolicy = 'lww' | 'pim_wins' | 'remote_wins';

interface SyncBindingRow {
  id: string;
  connectionId: string;
  objectTypeId: string;
  readEndpointId: string | null;
  writeEndpointId: string | null;
  direction: SyncDirection;
  schedule: string | null;
  conflictPolicy: ConflictPolicy;
  matchKeyMapping: string | null;
  cursor: { field?: string; type?: string; state?: unknown } | null;
  isEnabled: boolean;
  nextRun: string | null;
  outboundFilter: FilterDsl | null; // #2549 — outbound scope (null = send all)
}

interface ObjectTypeRow {
  id: string;
  code: string;
  kind?: string;
}

const HUB = '/integrations/api-configurator/connections';

const CRON_PRESETS = [
  { key: 'every_15m', cron: '*/15 * * * *' },
  { key: 'every_30m', cron: '*/30 * * * *' },
  { key: 'hourly', cron: '0 * * * *' },
  { key: 'daily_2', cron: '0 2 * * *' },
] as const;

/**
 * APIC-P3-11 — the SyncBinding configuration screen (`integracje/api-sync.jsx`).
 * Edits the connection's binding: direction (with conditional read/write
 * endpoint selects), cron schedule, conflict policy (bidirectional only), and
 * the active toggle, against the P3-10 CRUD + run action.
 *
 * Cursor field/type and the match key are surfaced read-only: the cursor is
 * owned by the sync engine (P3-03) and the match key is set in the Mapping tab
 * (P2-09) — neither is part of the binding write contract, so the screen never
 * pretends to persist them.
 */
export function SyncConfigScreen({ embedded = false }: { embedded?: boolean } = {}) {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const apiUrl = useApiUrl();
  const { id: connectionId = null } = useParams();

  const bindingsQuery = useList<SyncBindingRow>({
    resource: 'sync_bindings',
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
  const objectTypesQuery = useList<ObjectTypeRow>({
    resource: 'object_types',
    pagination: { mode: 'off' },
  });

  const binding = bindingsQuery.result.data[0] ?? null;
  const endpoints = endpointsQuery.result.data;
  const readEndpoints = useMemo(
    () => endpoints.filter((e) => e.role.startsWith('read')),
    [endpoints],
  );
  const writeEndpoints = useMemo(
    () => endpoints.filter((e) => e.role.startsWith('write')),
    [endpoints],
  );

  const toast = useToast();
  const { mutate: create, mutation: createState } = useCreate();
  const { mutate: update, mutation: updateState } = useUpdate();
  const { mutate: runNow, mutation: runState } = useCustomMutation();

  const [dir, setDir] = useState<SyncDirection>('inbound');
  const [cron, setCron] = useState('');
  const [conflict, setConflict] = useState<ConflictPolicy>('lww');
  const [readEndpointId, setReadEndpointId] = useState('');
  const [writeEndpointId, setWriteEndpointId] = useState('');
  const [enabled, setEnabled] = useState(true);
  const [newObjectTypeId, setNewObjectTypeId] = useState('');
  const [outboundFilterDsl, setOutboundFilterDsl] = useState<FilterDsl | null>(null);

  // Hydrate the form from the loaded binding.
  useEffect(() => {
    if (binding === null) {
      return;
    }
    setDir(binding.direction);
    setCron(binding.schedule ?? '');
    setConflict(binding.conflictPolicy);
    setReadEndpointId(binding.readEndpointId ?? '');
    setWriteEndpointId(binding.writeEndpointId ?? '');
    setEnabled(binding.isEnabled);
    setOutboundFilterDsl(binding.outboundFilter);
  }, [binding]);

  const cronHuman =
    CRON_PRESETS.find((p) => p.cron === cron) !== undefined
      ? t(`api_configurator.sync.schedule.preset.${CRON_PRESETS.find((p) => p.cron === cron)?.key}`)
      : cron.trim() === ''
        ? t('api_configurator.sync.schedule.manual')
        : t('api_configurator.sync.schedule.custom');

  function refresh(): void {
    void bindingsQuery.query.refetch();
  }

  function createBinding(): void {
    if (connectionId === null || newObjectTypeId === '') {
      return;
    }
    create(
      {
        resource: 'sync_bindings',
        values: {
          connection: connectionId,
          objectTypeId: newObjectTypeId,
          direction: 'inbound',
          conflictPolicy: 'lww',
        },
        successNotification: false,
      },
      {
        onSuccess: () => {
          toast.success(t('api_configurator.sync.toast.created'));
          refresh();
        },
        onError: () => toast.error(t('api_configurator.sync.toast.error')),
      },
    );
  }

  function save(): void {
    if (binding === null) {
      return;
    }
    update(
      {
        resource: 'sync_bindings',
        id: binding.id,
        values: {
          direction: dir,
          schedule: cron.trim() === '' ? null : cron.trim(),
          conflictPolicy: conflict,
          readEndpoint: dir === 'outbound' ? null : readEndpointId === '' ? null : readEndpointId,
          writeEndpoint: dir === 'inbound' ? null : writeEndpointId === '' ? null : writeEndpointId,
          enabled,
          // #2549 — `[]` clears; inbound never carries a PIM-side filter.
          outboundFilter: dir === 'inbound' ? [] : (outboundFilterDsl ?? []),
        },
        successNotification: false,
      },
      {
        onSuccess: () => {
          toast.success(t('api_configurator.sync.toast.saved'));
          refresh();
        },
        onError: () => toast.error(t('api_configurator.sync.toast.error')),
      },
    );
  }

  function triggerRun(): void {
    if (binding === null) {
      return;
    }
    runNow(
      { url: `${apiUrl}/sync_bindings/${binding.id}/run`, method: 'post', values: {} },
      {
        onSuccess: () => {
          toast.success(t('api_configurator.sync.toast.run_started'));
          refresh();
        },
        onError: () => toast.error(t('api_configurator.sync.toast.error')),
      },
    );
  }

  const header = (
    <div className="flex items-center gap-3">
      <Button
        variant="outline"
        size="icon"
        onClick={() => navigate(HUB)}
        aria-label={t('api_configurator.sync.back')}
      >
        <ArrowLeft className="size-4" aria-hidden="true" />
      </Button>
      <div className="min-w-0 flex-1">
        <h1 className="font-display text-[22px] font-semibold tracking-tight">
          {t('api_configurator.sync.title')}
        </h1>
        <p className="text-[12.5px] text-zinc-500">{t('api_configurator.sync.subtitle')}</p>
      </div>
    </div>
  );

  if (binding === null) {
    return (
      <div className="space-y-5">
        {embedded ? null : header}
        <div className="soft-shadow max-w-[680px] rounded-2xl border border-dashed border-zinc-300 bg-white p-6">
          <SectionLabel>{t('api_configurator.sync.empty.title')}</SectionLabel>
          <p className="mb-4 text-[12.5px] leading-relaxed text-zinc-600">
            {t('api_configurator.sync.empty.body')}
          </p>
          <div className="flex items-end gap-3">
            <Field label={t('api_configurator.sync.empty.object_type')}>
              <select
                value={newObjectTypeId}
                onChange={(e) => setNewObjectTypeId(e.target.value)}
                aria-label={t('api_configurator.sync.empty.object_type')}
                className="focus-ring h-10 w-64 rounded-xl border border-zinc-200 bg-white px-3 text-[13px]"
              >
                <option value="">{t('api_configurator.sync.empty.object_type_placeholder')}</option>
                {objectTypesQuery.result.data.map((ot) => (
                  <option key={ot.id} value={ot.id}>
                    {ot.code}
                  </option>
                ))}
              </select>
            </Field>
            <Button
              type="button"
              onClick={createBinding}
              disabled={newObjectTypeId === '' || createState.isPending}
            >
              {t('api_configurator.sync.empty.create')}
            </Button>
          </div>
        </div>
      </div>
    );
  }

  return (
    <div className="space-y-5">
      {embedded ? null : header}
      <div className="max-w-[980px] space-y-4">
        {/* Direction */}
        <section className="soft-shadow rounded-2xl border border-zinc-200 bg-white p-5">
          <SectionLabel>{t('api_configurator.sync.direction.title')}</SectionLabel>
          <div className="grid grid-cols-1 items-center gap-5 lg:grid-cols-[260px_1fr]">
            <DirDiagram dir={dir} apiLabel={t('api_configurator.sync.direction.api')} />
            <div className="space-y-3">
              <Segmented
                full
                ariaLabel={t('api_configurator.sync.direction.title')}
                value={dir}
                onChange={setDir}
                options={[
                  { value: 'inbound', label: t('api_configurator.sync.direction.inbound') },
                  { value: 'outbound', label: t('api_configurator.sync.direction.outbound') },
                  {
                    value: 'bidirectional',
                    label: t('api_configurator.sync.direction.bidirectional'),
                  },
                ]}
              />
              <p className="text-[12.5px] leading-relaxed text-zinc-600">
                {t(`api_configurator.sync.direction.hint.${dir}`)}
              </p>
            </div>
          </div>
          <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">
            {dir !== 'outbound' ? (
              <Field label={t('api_configurator.sync.direction.read_endpoint')}>
                <EndpointSelect
                  value={readEndpointId}
                  onChange={setReadEndpointId}
                  endpoints={readEndpoints}
                  placeholder={t('api_configurator.sync.direction.endpoint_placeholder')}
                  ariaLabel={t('api_configurator.sync.direction.read_endpoint')}
                />
              </Field>
            ) : null}
            {dir !== 'inbound' ? (
              <Field label={t('api_configurator.sync.direction.write_endpoint')}>
                <EndpointSelect
                  value={writeEndpointId}
                  onChange={setWriteEndpointId}
                  endpoints={writeEndpoints}
                  placeholder={t('api_configurator.sync.direction.endpoint_placeholder')}
                  ariaLabel={t('api_configurator.sync.direction.write_endpoint')}
                />
              </Field>
            ) : null}
          </div>
        </section>

        {/* Schedule */}
        <section className="soft-shadow rounded-2xl border border-zinc-200 bg-white p-5">
          <SectionLabel>{t('api_configurator.sync.schedule.title')}</SectionLabel>
          <div className="grid grid-cols-1 gap-5 lg:grid-cols-2">
            <div className="space-y-3">
              <div className="flex items-center gap-2">
                <Input
                  value={cron}
                  onChange={(e) => setCron(e.target.value)}
                  placeholder="0 2 * * *"
                  aria-label={t('api_configurator.sync.schedule.cron')}
                  className="h-9 w-44 font-mono"
                />
                <div className="text-[12.5px] text-zinc-600">{cronHuman}</div>
              </div>
              <div className="flex flex-wrap gap-1.5">
                {CRON_PRESETS.map((p) => (
                  <button
                    key={p.cron}
                    type="button"
                    onClick={() => setCron(p.cron)}
                    aria-pressed={cron === p.cron}
                    className={`h-7 rounded-lg border px-2.5 text-[11.5px] font-medium transition ${cron === p.cron ? 'border-zinc-900 bg-zinc-900 text-white' : 'border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50'}`}
                  >
                    {t(`api_configurator.sync.schedule.preset.${p.key}`)}
                  </button>
                ))}
              </div>
              <div className="flex items-center gap-1.5 text-[11.5px] text-zinc-500">
                <Info className="size-3.5 text-zinc-400" aria-hidden="true" />
                {t('api_configurator.sync.schedule.jitter')}
              </div>
            </div>
            <div className="rounded-xl border border-zinc-100 bg-zinc-50/60 p-3">
              <div className="mb-2 text-[10.5px] font-medium uppercase tracking-wider text-zinc-500">
                {t('api_configurator.sync.schedule.next_run')}
              </div>
              {/* Loose `!= null`: the API omits `nextRun` (undefined) when
                  unscheduled, so `!== null` would hit `new Date(undefined)`. */}
              {binding.nextRun != null ? (
                <div className="flex items-center gap-2 text-[12px]">
                  <span className="h-1.5 w-1.5 rounded-full bg-zinc-900" />
                  <span className="font-medium text-zinc-900">
                    {new Date(binding.nextRun).toLocaleString()}
                  </span>
                </div>
              ) : (
                <div className="text-[12px] text-zinc-500">
                  {t('api_configurator.sync.schedule.manual_only')}
                </div>
              )}
            </div>
          </div>
        </section>

        {/* Cursor — inbound / bidirectional, read-only (engine-owned) */}
        {dir !== 'outbound' ? (
          <section className="soft-shadow rounded-2xl border border-zinc-200 bg-white p-5">
            <SectionLabel
              right={
                <span className="text-[11px] text-zinc-500">
                  {t('api_configurator.sync.cursor.tag')}
                </span>
              }
            >
              {t('api_configurator.sync.cursor.title')}
            </SectionLabel>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
              <Field label={t('api_configurator.sync.cursor.field')}>
                <ReadonlyValue value={binding.cursor?.field ?? '—'} mono />
              </Field>
              <Field label={t('api_configurator.sync.cursor.type')}>
                <ReadonlyValue value={binding.cursor?.type ?? '—'} mono />
              </Field>
              <Field label={t('api_configurator.sync.cursor.state')}>
                <ReadonlyValue
                  value={
                    binding.cursor?.state !== undefined && binding.cursor?.state !== null
                      ? String(binding.cursor.state)
                      : '—'
                  }
                  mono
                />
              </Field>
            </div>
            <div className="mt-3">
              <SecurityNote tone="zinc" icon={<Info className="size-4" />}>
                {t('api_configurator.sync.cursor.note')}
              </SecurityNote>
            </div>
          </section>
        ) : null}

        {/* #2549 — outbound scope; absent for inbound (no PIM-side import filter). */}
        {dir !== 'inbound' ? (
          <OutboundFilterSection
            dsl={outboundFilterDsl}
            onDslChange={setOutboundFilterDsl}
            objectType={
              binding === null
                ? null
                : (objectTypesQuery.result.data.find((ot) => ot.id === binding.objectTypeId) ??
                  null)
            }
          />
        ) : null}

        {/* Conflict — bidirectional only */}
        {dir === 'bidirectional' ? (
          <section className="soft-shadow rounded-2xl border border-zinc-200 bg-white p-5">
            <SectionLabel
              right={
                <span className="text-[11px] text-zinc-500">
                  {t('api_configurator.sync.conflict.tag')}
                </span>
              }
            >
              {t('api_configurator.sync.conflict.title')}
            </SectionLabel>
            <Segmented
              ariaLabel={t('api_configurator.sync.conflict.title')}
              value={conflict}
              onChange={setConflict}
              options={[
                { value: 'lww', label: t('api_configurator.sync.conflict.lww') },
                { value: 'pim_wins', label: t('api_configurator.sync.conflict.pim_wins') },
                { value: 'remote_wins', label: t('api_configurator.sync.conflict.remote_wins') },
              ]}
            />
            <p className="mt-3 text-[12.5px] leading-relaxed text-zinc-600">
              {t(`api_configurator.sync.conflict.hint.${conflict}`)}
            </p>
            <div className="mt-3">
              <SecurityNote tone="emerald" icon={<Shield className="size-4" />}>
                {t('api_configurator.sync.conflict.anti_loop')}
              </SecurityNote>
            </div>
          </section>
        ) : null}

        {/* Match key — read-only (set in the Mapping tab) */}
        <section className="soft-shadow rounded-2xl border border-zinc-200 bg-white p-5">
          <SectionLabel>{t('api_configurator.sync.match_key.title')}</SectionLabel>
          <div className="flex flex-wrap items-center gap-3">
            <div className="inline-flex items-center gap-2 rounded-xl border border-zinc-200 bg-zinc-50 px-3 py-2">
              <span className="text-[13px] font-semibold">{binding.matchKeyMapping ?? '—'}</span>
            </div>
            <span className="text-[12px] text-zinc-500">
              {t('api_configurator.sync.match_key.hint')}
            </span>
          </div>
        </section>

        {/* Footer */}
        <div className="flex items-center gap-3 pt-1">
          <div className="flex items-center gap-2.5">
            <ApiToggle
              on={enabled}
              onChange={setEnabled}
              ariaLabel={t('api_configurator.sync.footer.toggle')}
            />
            <span className="text-[13px] font-medium">
              {enabled
                ? t('api_configurator.sync.footer.active')
                : t('api_configurator.sync.footer.paused')}
            </span>
          </div>
          <div className="flex-1" />
          <Button
            type="button"
            variant="outline"
            onClick={triggerRun}
            disabled={runState.isPending}
          >
            <Play className="mr-1 size-4" aria-hidden="true" />
            {t('api_configurator.sync.footer.run_now')}
          </Button>
          <Button type="button" onClick={save} disabled={updateState.isPending}>
            {t('api_configurator.sync.footer.save')}
          </Button>
        </div>
      </div>
    </div>
  );
}

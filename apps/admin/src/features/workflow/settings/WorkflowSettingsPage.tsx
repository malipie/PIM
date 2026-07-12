import { useQuery, useQueryClient } from '@tanstack/react-query';
import { GitBranch, Info, Send } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Combobox, type ComboboxOption } from '@/components/ui/combobox';
import { toast } from '@/components/ui/toast';
import { httpErrorDetail } from '@/lib/http';
import { hasFeature, useIdentity } from '@/lib/identity';
import {
  createDefinition,
  type DefinitionReviewer,
  extractViolations,
  fetchDefinitions,
  setDefinitionEnabled,
  updateDefinition,
} from '@/lib/workflow/definitions-api';
import { fetchApproverDirectory, fetchBuiltInObjectTypes } from '@/lib/workflow/directory-api';

import { editorialPlaces, editorialTransitions, gateFromTransitions } from './editorial-shape';
import { RoleLegend } from './RoleLegend';
import { StateDiagram } from './StateDiagram';

const REVIEWER_ROLE_PREFIX = 'role:';
const REVIEWER_USER_PREFIX = 'user:';

/**
 * WFL redesign (#2515) — the "Ustawienia przepływu" page. Configures the
 * built-in editorial machine per built-in ObjectType: the operator picks
 * the approver (a role OR a specific person) and the completeness gate,
 * the flow itself is fixed. Wired to the tenant WorkflowDefinition CRUD
 * (#2432) + the reviewer field (#2513); definitions only take runtime
 * effect when WORKFLOW_CUSTOM_DEFINITIONS is on.
 */
export function WorkflowSettingsPage() {
  const { t, i18n } = useTranslation();
  const { identity } = useIdentity();
  const flagOn = hasFeature(identity, 'workflow_custom_definitions');

  const queryClient = useQueryClient();
  const [selectedTypeId, setSelectedTypeId] = useState<string | null>(null);

  const [reviewer, setReviewer] = useState<string>('');
  const [gatePct, setGatePct] = useState<number | null>(80);
  const [original, setOriginal] = useState<{ reviewer: string; gatePct: number | null }>({
    reviewer: '',
    gatePct: 80,
  });
  const [saving, setSaving] = useState(false);

  const lang = i18n.language;
  const objectTypesQuery = useQuery({
    queryKey: ['workflow-settings', 'object-types', lang],
    queryFn: () => fetchBuiltInObjectTypes(lang),
  });
  const objectTypes = useMemo(() => objectTypesQuery.data ?? [], [objectTypesQuery.data]);

  const approverQuery = useQuery({
    queryKey: ['workflow-settings', 'approver-options'],
    queryFn: fetchApproverDirectory,
  });
  const approverOptions = useMemo<ComboboxOption[]>(() => {
    const data = approverQuery.data;
    if (data === undefined) return [];
    return [
      ...data.roles.map((role) => ({
        value: REVIEWER_ROLE_PREFIX + role.code,
        label: t('workflow.settings.approver.role_option', {
          defaultValue: '{{name}} (rola)',
          name: role.name || role.code,
        }),
      })),
      ...data.users.map((user) => ({
        value: REVIEWER_USER_PREFIX + user.id,
        label: `${user.display_name || user.email} (${user.email})`,
      })),
    ];
  }, [approverQuery.data, t]);

  const definitionsQuery = useQuery({
    queryKey: ['workflow-settings', 'definitions'],
    queryFn: () => fetchDefinitions().then((body) => body.items),
  });
  const definitions = useMemo(() => definitionsQuery.data ?? [], [definitionsQuery.data]);
  const loaded = definitionsQuery.isSuccess;

  // Default the selected type to the first built-in once types arrive.
  useEffect(() => {
    setSelectedTypeId((current) => current ?? objectTypes[0]?.id ?? null);
  }, [objectTypes]);

  const currentDefinition = useMemo(
    () => definitions.find((d) => d.object_type_id === selectedTypeId) ?? null,
    [definitions, selectedTypeId],
  );

  // Hydrate the editable fields from the selected ObjectType's definition.
  // selectedTypeId is a dep on purpose: switching between two types that
  // both lack a definition keeps currentDefinition === null, and we still
  // want the form reset to defaults for the newly selected type.
  // biome-ignore lint/correctness/useExhaustiveDependencies: reset on type switch, not only on definition change
  useEffect(() => {
    const next = {
      reviewer: reviewerToValue(currentDefinition?.reviewer ?? null),
      gatePct: currentDefinition ? gateFromTransitions(currentDefinition.transitions) : 80,
    };
    setReviewer(next.reviewer);
    setGatePct(next.gatePct);
    setOriginal(next);
  }, [currentDefinition, selectedTypeId]);

  const dirty = reviewer !== original.reviewer || gatePct !== original.gatePct;

  const save = () => {
    if (selectedTypeId === null) return;
    setSaving(true);
    const selected = objectTypes.find((type) => type.id === selectedTypeId);
    const payload = {
      name: t('workflow.settings.definition_name', {
        defaultValue: 'Przepływ — {{type}}',
        type: selected?.label ?? selectedTypeId,
      }),
      object_type_id: selectedTypeId,
      places: editorialPlaces(),
      transitions: editorialTransitions(gatePct),
      reviewer: valueToReviewer(reviewer),
    };

    const request = currentDefinition
      ? updateDefinition(currentDefinition.id, payload)
      : createDefinition(payload);

    request
      .then((definition) =>
        definition.enabled ? definition : setDefinitionEnabled(definition.id, true),
      )
      .then(() => queryClient.invalidateQueries({ queryKey: ['workflow-settings', 'definitions'] }))
      .then(() => {
        setOriginal({ reviewer, gatePct });
        toast.success(t('workflow.settings.saved', { defaultValue: 'Definicja zapisana.' }));
      })
      .catch((error: unknown) => {
        const violations = extractViolations(error);
        toast.error(
          violations[0]?.message ??
            httpErrorDetail(error) ??
            t('workflow.settings.save_failed', { defaultValue: 'Zapis nie powiódł się.' }),
        );
      })
      .finally(() => setSaving(false));
  };

  return (
    <div className="mx-auto max-w-6xl space-y-5 px-6 py-6" data-testid="workflow-settings-page">
      <RoleLegend />

      <section className="flex items-center justify-between rounded-2xl border border-zinc-200 bg-white p-5">
        <div className="flex items-start gap-3">
          <span className="grid size-9 place-items-center rounded-xl bg-emerald-50 text-emerald-600">
            <GitBranch className="size-4" aria-hidden="true" />
          </span>
          <div>
            <p className="text-[14px] font-semibold text-zinc-900">
              {t('workflow.settings.flag.title', { defaultValue: 'Własne definicje przepływu' })}{' '}
              <code className="rounded bg-zinc-100 px-1.5 py-0.5 font-mono text-[11px] text-zinc-500">
                WORKFLOW_CUSTOM_DEFINITIONS
              </code>
            </p>
            <p className="text-[12px] text-zinc-500">
              {flagOn
                ? t('workflow.settings.flag.on', {
                    defaultValue:
                      'Włączone — możesz wskazać własnego akceptanta (rolę lub osobę) per ObjectType.',
                  })
                : t('workflow.settings.flag.off', {
                    defaultValue:
                      'Wyłączone globalnie — definicje możesz zapisać, ale zaczną działać po włączeniu flagi przez administratora.',
                  })}
            </p>
          </div>
        </div>
        <span
          data-testid="workflow-flag-state"
          className={`inline-flex items-center gap-2 rounded-full px-3 py-1 text-[12px] font-medium ${flagOn ? 'bg-emerald-50 text-emerald-700' : 'bg-zinc-100 text-zinc-500'}`}
        >
          <span
            className={`size-2 rounded-full ${flagOn ? 'bg-emerald-500' : 'bg-zinc-400'}`}
            aria-hidden="true"
          />
          {flagOn
            ? t('workflow.settings.flag.enabled', { defaultValue: 'Włączone' })
            : t('workflow.settings.flag.disabled', { defaultValue: 'Wyłączone' })}
        </span>
      </section>

      <div className="flex flex-wrap items-center gap-2">
        <span className="text-[13px] text-zinc-500">
          {t('workflow.settings.definition_for', { defaultValue: 'Definicja dla:' })}
        </span>
        {objectTypes.map((type) => {
          const active = type.id === selectedTypeId;
          const configured = definitions.some((d) => d.object_type_id === type.id && d.enabled);
          return (
            <button
              key={type.id}
              type="button"
              onClick={() => setSelectedTypeId(type.id)}
              data-testid={`workflow-def-type-${type.code}`}
              className={`inline-flex items-center gap-2 rounded-xl border px-3.5 py-2 text-[13px] font-medium transition ${
                active
                  ? 'border-zinc-900 bg-zinc-900 text-white'
                  : 'border-zinc-200 bg-white text-zinc-700 hover:border-zinc-300'
              }`}
            >
              {type.label}
              {configured ? (
                <span
                  className={`rounded-full px-1.5 py-0.5 text-[10px] ${active ? 'bg-white/15 text-white' : 'bg-emerald-50 text-emerald-600'}`}
                >
                  {t('workflow.settings.own', { defaultValue: 'własny' })}
                </span>
              ) : null}
            </button>
          );
        })}
      </div>

      <StateDiagram />

      <section
        className="rounded-2xl border border-zinc-200 bg-white p-5"
        data-testid="workflow-approver"
      >
        <div className="flex items-center gap-2">
          <span className="grid size-8 place-items-center rounded-xl bg-zinc-900 text-white">
            <Send className="size-4" aria-hidden="true" />
          </span>
          <div>
            <h3 className="text-[15px] font-semibold text-zinc-900">
              {t('workflow.settings.approver.title', { defaultValue: 'Do kogo trafiają zadania' })}
            </h3>
            <p className="text-[12px] text-zinc-500">
              {t('workflow.settings.approver.subtitle', {
                defaultValue:
                  'Jeden akceptant na całą definicję. Obsługuje zadania Przegląd i Prośba o depublikację.',
              })}
            </p>
          </div>
        </div>

        <div className="mt-4 grid grid-cols-1 gap-4 lg:grid-cols-2">
          <div>
            <label
              className="text-[13px] font-medium text-zinc-700"
              htmlFor="workflow-approver-picker"
            >
              {t('workflow.settings.approver.label', {
                defaultValue: 'Akceptant — rola albo konkretna osoba',
              })}
            </label>
            <div
              className="mt-1.5"
              id="workflow-approver-picker"
              data-testid="workflow-approver-picker"
            >
              <Combobox
                options={approverOptions}
                value={reviewer === '' ? null : reviewer}
                onChange={(value) => setReviewer(value ?? '')}
                placeholder={t('workflow.settings.approver.placeholder', {
                  defaultValue: 'Domyślnie: rola Akceptant',
                })}
              />
            </div>
            <p className="mt-1.5 text-[11px] text-zinc-500">
              {t('workflow.settings.approver.hint', {
                defaultValue:
                  'Ta osoba/rola zobaczy zadanie w „Moje zadania" od razu po zgłoszeniu.',
              })}
            </p>
          </div>

          <div className="rounded-2xl border border-zinc-200 bg-zinc-50/60 p-4">
            <p className="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">
              {t('workflow.settings.approver.audience', { defaultValue: 'Zadanie zobaczą' })}
            </p>
            <ol className="mt-2 space-y-2 text-[12px] text-zinc-600">
              <li className="flex items-center gap-2">
                <span className="grid size-5 place-items-center rounded-full bg-white text-[10px] font-semibold text-zinc-500 shadow-sm">
                  1
                </span>
                {reviewer === ''
                  ? t('workflow.settings.approver.default_role', {
                      defaultValue: 'Rola: Akceptant — wskazany odbiorca',
                    })
                  : (approverOptions.find((o) => o.value === reviewer)?.label ?? reviewer)}
              </li>
              <li className="flex items-center gap-2">
                <span className="grid size-5 place-items-center rounded-full bg-white text-[10px] font-semibold text-zinc-500 shadow-sm">
                  2
                </span>
                {t('workflow.settings.approver.approvers', {
                  defaultValue: 'Każdy z uprawnieniem „Zatwierdzanie/Odrzucanie"',
                })}
              </li>
            </ol>
            <p className="mt-3 flex items-start gap-1.5 text-[11px] text-zinc-500">
              <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
              {t('workflow.settings.approver.safety', {
                defaultValue:
                  'Bezpiecznik — zadanie nigdy nie utknie, gdy wskazana osoba jest niedostępna.',
              })}
            </p>
          </div>
        </div>
      </section>

      <section
        className="rounded-2xl border border-zinc-200 bg-white p-5"
        data-testid="workflow-rules"
      >
        <h3 className="text-[15px] font-semibold text-zinc-900">
          {t('workflow.settings.rules.title', { defaultValue: 'Reguły przejść' })}
        </h3>
        <div className="mt-3 space-y-3">
          <div className="flex flex-wrap items-center gap-4 rounded-2xl border border-zinc-200 p-4">
            <label className="flex items-center gap-2 text-[13px] text-zinc-700">
              <input
                type="checkbox"
                className="size-4 rounded border-zinc-300"
                checked={gatePct !== null}
                onChange={(event) => setGatePct(event.target.checked ? (gatePct ?? 80) : null)}
                data-testid="workflow-gate-toggle"
              />
              <span className="font-medium">
                {t('workflow.settings.rules.gate', {
                  defaultValue: 'Wymagaj kompletności przed publikacją',
                })}
              </span>
            </label>
            <input
              type="range"
              min={0}
              max={100}
              step={5}
              value={gatePct ?? 0}
              disabled={gatePct === null}
              onChange={(event) => setGatePct(Number(event.target.value))}
              data-testid="workflow-gate-slider"
              className="h-1.5 flex-1 cursor-pointer accent-zinc-900 disabled:opacity-40"
            />
            <span className="w-12 text-right text-[13px] font-semibold tabular-nums text-zinc-700">
              {gatePct ?? '—'}%
            </span>
          </div>
          <div className="flex items-center justify-between rounded-2xl border border-zinc-200 p-4">
            <div>
              <p className="text-[13px] font-medium text-zinc-700">
                {t('workflow.settings.rules.comment', {
                  defaultValue: 'Komentarz wymagany przy „Odrzuć"',
                })}
              </p>
              <p className="text-[11px] text-zinc-500">
                {t('workflow.settings.rules.comment_desc', {
                  defaultValue: 'Autor musi wiedzieć, co poprawić. Zawsze włączone.',
                })}
              </p>
            </div>
            <span className="rounded-full bg-zinc-100 px-3 py-1 text-[11px] text-zinc-500">
              {t('workflow.settings.rules.locked', { defaultValue: 'zablokowane' })}
            </span>
          </div>
        </div>
      </section>

      <div className="sticky bottom-0 flex items-center justify-between gap-4 rounded-2xl border border-zinc-200 bg-white/95 p-4 backdrop-blur">
        <p className="text-[12px] text-zinc-500">
          {t('workflow.settings.footer', {
            defaultValue:
              'Zmiana odbiorcy zacznie obowiązywać dla nowych zgłoszeń. Bieżące zadania zostają u obecnego akceptanta.',
          })}
        </p>
        <div className="flex items-center gap-2">
          <Button
            variant="outline"
            disabled={!dirty || saving}
            onClick={() => {
              setReviewer(original.reviewer);
              setGatePct(original.gatePct);
            }}
          >
            {t('workflow.settings.discard', { defaultValue: 'Odrzuć zmiany' })}
          </Button>
          <Button
            onClick={save}
            disabled={!dirty || saving || selectedTypeId === null || !loaded}
            data-testid="workflow-settings-save"
          >
            {t('workflow.settings.save', { defaultValue: 'Zapisz definicję' })}
          </Button>
        </div>
      </div>
    </div>
  );
}

function reviewerToValue(reviewer: DefinitionReviewer): string {
  if (reviewer === null) return '';
  if ('role_code' in reviewer) return REVIEWER_ROLE_PREFIX + reviewer.role_code;
  return REVIEWER_USER_PREFIX + reviewer.user_id;
}

function valueToReviewer(value: string): DefinitionReviewer {
  if (value.startsWith(REVIEWER_ROLE_PREFIX)) {
    return { role_code: value.slice(REVIEWER_ROLE_PREFIX.length) };
  }
  if (value.startsWith(REVIEWER_USER_PREFIX)) {
    return { user_id: value.slice(REVIEWER_USER_PREFIX.length) };
  }
  return null;
}

import { useQuery } from '@tanstack/react-query';
import { ArrowLeft, ArrowRight, Check, ChevronRight, Rss } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router';

import { useToast } from '@/components/ui/toast';
import { useExportPreflight } from '@/features/exports/wizard/use-export-preflight';
import { dslToFlatConditions, type FilterDsl } from '@/lib/filters/filter-dsl';
import { useFilterDslState } from '@/lib/filters/use-filter-dsl-state';
import { httpErrorDetail } from '@/lib/http';
import { cn } from '@/lib/utils';

import { createFeed, fetchFeed, patchFeed } from '../api/feeds';
import { type FeedTemplateInfo, useFeedTemplates, useProductObjectTypeId } from '../api/templates';
import { TemplateBadge } from '../components/primitives';
import { StepScope } from './steps/StepScope';
import { StepTemplate } from './steps/StepTemplate';
import {
  canLeaveStep,
  createPayload,
  draftFromFeed,
  emptyDraft,
  type FeedDraft,
  patchPayload,
  slugifyCode,
  WIZARD_STEP_IDS,
} from './wizard-state';

const HUB_PATH = '/integrations/api-configurator/feeds';

/**
 * XMLF-P5-02 — the 5-step full-page feed wizard shell with steps 1 (template
 * + identity) and 2 (scope + assortment filter with a live preflight count).
 * Steps 3-5 render in-shell placeholders until P5-03 (mapper) and P5-04
 * (delivery + preview) replace them. Leaving step 2 persists the draft
 * (POST on first pass, PATCH afterwards) so the mapper steps always operate
 * on a saved FeedProfile.
 */
export function FeedWizardPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const toast = useToast();
  const { id: editId } = useParams();
  const templates = useFeedTemplates();
  const productTypeId = useProductObjectTypeId();

  const [step, setStep] = useState(0);
  const [draft, setDraft] = useState<FeedDraft>(emptyDraft);
  const [saving, setSaving] = useState(false);
  const [loadedForEdit, setLoadedForEdit] = useState(false);
  const filterState = useFilterDslState(draft.filterDsl);
  const setFilterConditions = filterState.setConditions;

  // Edit mode loads through useQuery (ADR-0021 — an effect-driven raw read
  // would be invisible to the query cache); the effect below only copies
  // the arrived row into the draft once.
  const editFeed = useQuery({
    queryKey: ['xmlf', 'feed', editId ?? 'none'],
    enabled: editId !== undefined,
    queryFn: async () => fetchFeed(editId ?? ''),
  });
  const editRow = editFeed.data;
  useEffect(() => {
    if (editRow === undefined || loadedForEdit) {
      return;
    }
    setDraft(draftFromFeed(editRow));
    // The filter state hook only reads its initial value on mount —
    // push the loaded DSL into it explicitly for the edit flow.
    setFilterConditions(dslToFlatConditions((editRow.filter as FilterDsl | null) ?? null) ?? []);
    setLoadedForEdit(true);
  }, [editRow, loadedForEdit, setFilterConditions]);

  const preflight = useExportPreflight({
    entityType: 'product',
    objectTypeId: null,
    targetScope: filterState.dsl === null ? 'all' : 'filter',
    filterDsl: filterState.dsl,
    selectedIds: null,
    enabled: step === 1,
  });
  const resultCount = preflight.result?.count ?? null;

  const editing = draft.id !== null;
  const currentDraft = useMemo(
    () => ({ ...draft, filterDsl: filterState.dsl }),
    [draft, filterState.dsl],
  );

  function chooseTemplate(template: FeedTemplateInfo): void {
    setDraft((prev) => {
      const nextName =
        prev.name.trim() === ''
          ? `${t(`api_configurator.feeds.template.${template.kind}`)} — PL`
          : prev.name;
      return {
        ...prev,
        kind: template.kind,
        mappings: template.default_mappings.map((mapping) => ({ ...mapping })),
        name: nextName,
        code: prev.codeTouched ? prev.code : slugifyCode(nextName),
      };
    });
  }

  async function persistDraft(): Promise<boolean> {
    setSaving(true);
    try {
      let feedId = currentDraft.id;
      if (feedId === null) {
        const typeId = productTypeId.data;
        if (typeId === null || typeId === undefined) {
          toast.error(t('api_configurator.feeds.wizard.save_error'));
          return false;
        }
        const created = await createFeed(createPayload(currentDraft, typeId));
        feedId = created.id;
        setDraft((prev) => ({ ...prev, id: created.id }));
      }
      // The create endpoint owns identity + template seed; the assortment
      // filter (and later renames) go through PATCH — one follow-up call
      // covers both the fresh-draft and the edit flow.
      await patchFeed(feedId, patchPayload(currentDraft));
      return true;
    } catch (error) {
      toast.error(httpErrorDetail(error) ?? t('api_configurator.feeds.wizard.save_error'));
      return false;
    } finally {
      setSaving(false);
    }
  }

  async function next(): Promise<void> {
    if (!canLeaveStep(step, currentDraft)) {
      return;
    }
    // The mapper (P5-03) needs a persisted FeedProfile — save when leaving scope.
    if (step === 1 && !(await persistDraft())) {
      return;
    }
    setStep((value) => Math.min(value + 1, WIZARD_STEP_IDS.length - 1));
  }

  const last = WIZARD_STEP_IDS.length - 1;
  // Leaving scope needs the resolved product ObjectType id for the create
  // payload — hold the button until the lookup lands (fresh drafts only).
  const waitingForTypeId =
    step === 1 && currentDraft.id === null && productTypeId.data === undefined;
  const stepAllowed = canLeaveStep(step, currentDraft) && !waitingForTypeId;

  return (
    <div className="max-w-[1180px] space-y-5">
      <div className="flex items-center gap-3">
        <button
          type="button"
          onClick={() => void navigate(HUB_PATH)}
          aria-label={t('api_configurator.feeds.wizard.back_to_hub')}
          className="grid h-9 w-9 shrink-0 place-items-center rounded-xl bg-white text-zinc-500 soft-shadow hover:text-zinc-900"
        >
          <ArrowLeft className="h-4 w-4" aria-hidden />
        </button>
        <div className="min-w-0 flex-1">
          <h1 className="font-display text-[22px] font-semibold tracking-tight">
            {t(
              editing
                ? 'api_configurator.feeds.wizard.title_edit'
                : 'api_configurator.feeds.wizard.title_new',
            )}
          </h1>
          <p className="text-[12.5px] text-zinc-500">
            {t('api_configurator.feeds.wizard.subtitle')}
          </p>
        </div>
        {draft.kind !== null && <TemplateBadge kind={draft.kind} />}
      </div>

      <nav aria-label={t('api_configurator.feeds.wizard.steps_aria')}>
        <ol className="flex items-stretch gap-2 rounded-3xl bg-white p-3 soft-shadow">
          {WIZARD_STEP_IDS.map((stepId, index) => {
            const state = index < step ? 'done' : index === step ? 'active' : 'pending';
            const clickable = index <= step;
            return (
              <li key={stepId} className="flex min-w-0 flex-1 items-center gap-2">
                <button
                  type="button"
                  onClick={() => clickable && setStep(index)}
                  aria-current={state === 'active' ? 'step' : undefined}
                  disabled={!clickable}
                  className={cn(
                    'min-w-0 flex-1 rounded-xl border px-3 py-2 text-left transition',
                    state === 'done' && 'border-emerald-200/70 bg-emerald-50/60',
                    state === 'active' && 'border-zinc-900/15 bg-white shadow-sm',
                    state === 'pending' && 'cursor-default border-zinc-100 bg-zinc-50/60',
                  )}
                >
                  <div className="flex items-center gap-1.5">
                    <span
                      className={cn(
                        'grid h-5 w-5 place-items-center rounded-full text-[10px] font-semibold',
                        state === 'done' && 'bg-emerald-500 text-white',
                        state === 'active' && 'bg-zinc-900 text-white',
                        state === 'pending' && 'bg-zinc-200 text-zinc-500',
                      )}
                    >
                      {state === 'done' ? <Check className="h-3 w-3" aria-hidden /> : index + 1}
                    </span>
                    <span
                      className={cn(
                        'truncate text-[12.5px] font-semibold tracking-tight',
                        state === 'active' && 'text-zinc-900',
                        state === 'done' && 'text-emerald-800',
                        state === 'pending' && 'text-zinc-500',
                      )}
                    >
                      {t(`api_configurator.feeds.wizard.step.${stepId}`)}
                    </span>
                  </div>
                  <div
                    className={cn(
                      'mt-0.5 truncate font-mono text-[10.5px]',
                      state === 'pending' ? 'text-zinc-400' : 'text-zinc-500',
                    )}
                  >
                    {t(`api_configurator.feeds.wizard.step_note.${stepId}`)}
                  </div>
                </button>
                {index < last && (
                  <ChevronRight className="h-3 w-3 shrink-0 text-zinc-300" aria-hidden />
                )}
              </li>
            );
          })}
        </ol>
      </nav>

      <div key={step}>
        {step === 0 && (
          <StepTemplate
            templates={templates.data ?? []}
            kind={draft.kind}
            onChoose={chooseTemplate}
            name={draft.name}
            onName={(value) =>
              setDraft((prev) => ({
                ...prev,
                name: value,
                code: prev.codeTouched ? prev.code : slugifyCode(value),
              }))
            }
            code={draft.code}
            onCode={(value) => setDraft((prev) => ({ ...prev, code: value, codeTouched: true }))}
          />
        )}
        {step === 1 && (
          <StepScope
            locale={draft.locale}
            onLocale={(value) => setDraft((prev) => ({ ...prev, locale: value }))}
            currency={draft.currency}
            onCurrency={(value) => setDraft((prev) => ({ ...prev, currency: value }))}
            channelId={draft.channelId}
            onChannelId={(value) => setDraft((prev) => ({ ...prev, channelId: value }))}
            filterState={filterState}
            resultCount={resultCount}
            countLoading={preflight.isLoading}
          />
        )}
        {step >= 2 && (
          <div className="rounded-3xl bg-white p-10 text-center soft-shadow">
            <h2 className="font-display text-[16px] font-semibold tracking-tight">
              {t(`api_configurator.feeds.wizard.placeholder.${WIZARD_STEP_IDS[step]}_title`)}
            </h2>
            <p className="mt-2 text-[13px] text-zinc-500">
              {t(`api_configurator.feeds.wizard.placeholder.${WIZARD_STEP_IDS[step]}_hint`)}
            </p>
          </div>
        )}
      </div>

      <div className="flex items-center gap-3 pt-1">
        <button
          type="button"
          onClick={() => (step === 0 ? void navigate(HUB_PATH) : setStep(step - 1))}
          className="h-10 rounded-xl border border-zinc-200 bg-white px-4 text-[13px] font-medium text-zinc-700 hover:bg-zinc-50"
        >
          {t(
            step === 0
              ? 'api_configurator.feeds.wizard.cancel'
              : 'api_configurator.feeds.wizard.back',
          )}
        </button>
        <div className="flex-1" />
        <div className="hidden text-[12px] text-zinc-500 sm:block">
          {t('api_configurator.feeds.wizard.progress', {
            current: step + 1,
            total: WIZARD_STEP_IDS.length,
          })}
        </div>
        {step < last ? (
          <button
            type="button"
            onClick={() => void next()}
            disabled={!stepAllowed || saving}
            className={cn(
              'flex h-10 items-center gap-1.5 rounded-xl px-5 text-[13px] font-bold soft-shadow',
              stepAllowed && !saving
                ? 'bg-orange-500 text-zinc-900 hover:bg-orange-600'
                : 'cursor-not-allowed bg-zinc-200 text-zinc-500',
            )}
          >
            {t('api_configurator.feeds.wizard.next')}
            <ArrowRight className="h-4 w-4" aria-hidden />
          </button>
        ) : (
          <button
            type="button"
            onClick={() => {
              void persistDraft().then((ok) => {
                if (ok) {
                  toast.success(t('api_configurator.feeds.wizard.saved'));
                  void navigate(HUB_PATH);
                }
              });
            }}
            disabled={saving}
            className="flex h-10 items-center gap-1.5 rounded-xl bg-zinc-900 px-5 text-[13px] font-bold text-white soft-shadow hover:bg-zinc-800 disabled:opacity-50"
          >
            <Rss className="h-4 w-4" aria-hidden />
            {t('api_configurator.feeds.wizard.save_and_generate')}
          </button>
        )}
      </div>
    </div>
  );
}

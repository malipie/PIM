import { Boxes, FolderTree, Group, Layers, Loader2, Package, Tags } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { toast } from '@/components/ui/toast';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { FormatPill } from '@/components/ui-v2/format-pill';
import { isFilterGroup } from '@/lib/filters/filter-dsl';
import { HttpError } from '@/lib/http';
import { cn } from '@/lib/utils';

import { entityTypeLabelKey } from '../../sessions/session-format';
import { saveProfile, updateProfile } from '../use-run-export';
import { useWizard } from '../wizard-store';

const ENTITY_ICONS: Record<string, typeof Package> = {
  product: Package,
  custom_module: Boxes,
  module_schema: Layers,
  attributes_groups: Tags,
  attribute_groups: Group,
  categories: FolderTree,
};

/**
 * EXR-12 — wizard step 4 (screen 5): configuration review cards,
 * optional save-as-profile (never runs the export) and the run CTA.
 * Sync/async routing follows the backend response, the info note
 * follows the EXR-07 preflight mode.
 */
export function StepSummary() {
  const { t } = useTranslation();
  const { state, dispatch } = useWizard();
  const [profileNameInput, setProfileNameInput] = useState(state.profileName);
  const [profileError, setProfileError] = useState<string | null>(null);
  const [savingProfile, setSavingProfile] = useState(false);

  const EntityIcon = ENTITY_ICONS[state.entityType] ?? Package;
  const mode = state.preflight?.mode ?? 'async';
  const count = state.preflight?.count ?? null;

  const filterChips: string[] = [];
  if (state.filterDsl !== null) {
    if (isFilterGroup(state.filterDsl)) {
      for (const condition of state.filterDsl.conditions) {
        if (!isFilterGroup(condition)) filterChips.push(condition.attr);
      }
    } else {
      filterChips.push(state.filterDsl.attr);
    }
  }
  const visibleChips = filterChips.slice(0, 2);
  const hiddenChips = filterChips.slice(2);

  const scopeText =
    state.targetScope === 'selected'
      ? t('exports.wizard.summary.scope_selected', { count: state.selectedIds?.length ?? 0 })
      : state.entityType === 'product' || state.entityType === 'custom_module'
        ? t('exports.wizard.summary.scope_rows', { count: count ?? 0 })
        : t('exports.wizard.summary.scope_structural');

  const onSaveProfile = async () => {
    const name = profileNameInput.trim();
    if (name.length === 0 || name.length > 120) {
      setProfileError(t('exports.wizard.summary.profile_name_invalid'));
      return;
    }
    setSavingProfile(true);
    setProfileError(null);
    try {
      if (state.editingProfileId !== null) {
        await updateProfile(state, name, state.editingProfileId);
      } else {
        await saveProfile(state, name);
      }
      dispatch({ type: 'SET_PROFILE_NAME', profileName: name });
      toast.success(t('exports.wizard.summary.profile_saved', { name }));
    } catch (error) {
      if (error instanceof HttpError && error.status === 409) {
        setProfileError(t('exports.wizard.summary.profile_name_taken'));
      } else {
        setProfileError(t('exports.wizard.summary.profile_save_failed'));
      }
    } finally {
      setSavingProfile(false);
    }
  };

  const cards: Array<{ key: string; title: string; body: React.ReactNode }> = [
    {
      key: 'entity',
      title: t('exports.wizard.summary.entity'),
      body: (
        <span className="flex items-center gap-2 text-[13.5px] font-medium text-ink">
          <span className="grid h-7 w-7 place-items-center rounded-md bg-zinc-100 text-zinc-600">
            <EntityIcon className="size-3.5" aria-hidden />
          </span>
          {t(entityTypeLabelKey(state.entityType))}
        </span>
      ),
    },
    {
      key: 'format',
      title: t('exports.wizard.summary.format'),
      body: <FormatPill format={state.format} />,
    },
    {
      key: 'scope',
      title: t('exports.wizard.summary.scope'),
      body: (
        <span className="flex flex-wrap items-center gap-1.5 text-[13px] text-zinc-700">
          {scopeText}
          {visibleChips.map((chip) => (
            <span
              key={chip}
              className="rounded-md bg-zinc-100 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600"
            >
              {chip}
            </span>
          ))}
          {hiddenChips.length > 0 && (
            <Tooltip>
              <TooltipTrigger asChild>
                <span className="cursor-default rounded-md bg-zinc-100 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600">
                  +{hiddenChips.length}
                </span>
              </TooltipTrigger>
              <TooltipContent>{hiddenChips.join(', ')}</TooltipContent>
            </Tooltip>
          )}
        </span>
      ),
    },
    {
      key: 'columns',
      title: t('exports.wizard.summary.columns', { count: state.columns.length }),
      body: (
        <span className="flex flex-wrap gap-1.5">
          {state.columns.slice(0, 12).map((column) => (
            <span
              key={column}
              className="rounded-md bg-zinc-100 px-1.5 py-0.5 font-mono text-[11px] text-zinc-600"
            >
              {column}
            </span>
          ))}
          {state.columns.length > 12 && (
            <span className="text-[11.5px] text-zinc-500">+{state.columns.length - 12}</span>
          )}
        </span>
      ),
    },
    {
      key: 'profile',
      title: t('exports.wizard.summary.profile'),
      body: (
        <span className="text-[13px] text-zinc-700">
          {state.profileName !== '' ? state.profileName : t('exports.wizard.summary.profile_none')}
        </span>
      ),
    },
  ];

  return (
    <div className="space-y-5">
      <section className="rounded-2xl border border-zinc-200 bg-surface p-7 shadow-card">
        <h2 className="text-[16px] font-semibold tracking-tight text-ink">
          {t('exports.wizard.summary.title')}
        </h2>
        <dl className="mt-5 grid grid-cols-1 gap-x-8 gap-y-4 sm:grid-cols-2">
          {cards.map((card) => (
            <div key={card.key}>
              <dt className="text-[11px] font-medium tracking-wider text-zinc-500 uppercase">
                {card.title}
              </dt>
              <dd className="mt-1.5">{card.body}</dd>
            </div>
          ))}
        </dl>
      </section>

      <section className="rounded-2xl border border-zinc-200 bg-surface p-7 shadow-card">
        <h3 className="text-[11px] font-medium tracking-wider text-zinc-500 uppercase">
          {t('exports.wizard.summary.save_profile_title')}
        </h3>
        <div className="mt-3 flex max-w-lg flex-wrap items-start gap-2">
          <div className="min-w-0 flex-1">
            <input
              type="text"
              value={profileNameInput}
              onChange={(event) => {
                setProfileNameInput(event.target.value);
                setProfileError(null);
              }}
              maxLength={120}
              placeholder={t('exports.wizard.summary.profile_name_placeholder')}
              aria-label={t('exports.wizard.summary.profile_name_placeholder')}
              className={cn(
                'focus-ring h-10 w-full rounded-xl border bg-surface px-3 text-[13px] placeholder:text-zinc-500',
                profileError === null ? 'border-zinc-200' : 'border-brick-300',
              )}
            />
            {profileError !== null && (
              <p className="mt-1 text-[12px] text-brick-600">{profileError}</p>
            )}
          </div>
          <button
            type="button"
            disabled={savingProfile}
            onClick={() => void onSaveProfile()}
            className="focus-ring h-10 rounded-xl border border-zinc-200 bg-surface px-4 text-[13px] font-medium text-zinc-700 transition enabled:hover:border-zinc-400 disabled:cursor-not-allowed disabled:text-zinc-300"
          >
            {savingProfile && (
              <Loader2 className="mr-1.5 inline size-3.5 animate-spin" aria-hidden />
            )}
            {t('exports.wizard.summary.save_profile_cta')}
          </button>
        </div>
        <p className="mt-2 text-[11.5px] text-zinc-500">
          {t('exports.wizard.summary.save_profile_hint')}
        </p>
      </section>

      <div className="rounded-2xl border border-emerald-200 bg-emerald-50 px-5 py-4 text-[13px] text-emerald-800">
        {mode === 'sync'
          ? t('exports.wizard.summary.note_sync')
          : t('exports.wizard.summary.note_async')}
      </div>
    </div>
  );
}

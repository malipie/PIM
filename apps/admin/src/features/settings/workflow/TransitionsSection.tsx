import { Plus, Trash2, TriangleAlert } from 'lucide-react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Combobox, type ComboboxOption } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MultiSelect } from '@/components/ui/multi-select';

import type { PlaceDraft, TransitionDraft } from './definition-form';
import { isCanonicalTransition, permissionLabel, transitionNameFor } from './flow-vocabulary';

interface TransitionsSectionProps {
  transitions: TransitionDraft[];
  places: PlaceDraft[];
  /** Permission codes from /api/permissions; empty when not readable. */
  permissionCodes: string[];
  onChange: (next: TransitionDraft[]) => void;
  advanced: boolean;
  error: (field: string) => string | undefined;
}

/**
 * #3002 — one transition per row, written as a sentence: "Zgłoś do
 * przeglądu przenosi z Szkic do W przeglądzie — może to zrobić
 * Wprowadzający dane". The previous four-column form spoke in machine
 * names and raw permission codes, which is the reason a non-technical
 * operator could not configure a flow at all.
 *
 * The yellow warning is the point of the whole section: task automation
 * and notifications are wired to canonical transition names, so a custom
 * step runs the state machine but produces no task. That used to be a
 * discovery made in production.
 */
export function TransitionsSection({
  transitions,
  places,
  permissionCodes,
  onChange,
  advanced,
  error,
}: TransitionsSectionProps) {
  const { t, i18n } = useTranslation();

  const placeOptions = useMemo<ComboboxOption[]>(
    () =>
      places
        .filter((place) => place.name.trim() !== '')
        .map((place) => ({
          value: place.name.trim(),
          label: place.labelPl.trim() === '' ? place.name.trim() : place.labelPl.trim(),
        })),
    [places],
  );

  const permissionOptions = useMemo<ComboboxOption[]>(
    () =>
      permissionCodes.map((code) => ({
        value: code,
        label: permissionLabel(code, i18n.language),
      })),
    [permissionCodes, i18n.language],
  );

  const update = (index: number, patch: Partial<TransitionDraft>) => {
    onChange(
      transitions.map((transition, i) => {
        if (i !== index) return transition;
        const next = { ...transition, ...patch };
        if (patch.label !== undefined && !transition.nameLocked) {
          const others = transitions.filter((_, other) => other !== index).map((item) => item.name);
          next.name = transitionNameFor(patch.label, others);
        }
        return next;
      }),
    );
  };

  return (
    <section className="space-y-3" data-testid="definition-transitions">
      <div className="flex items-center gap-3">
        <div className="flex-1">
          <h3 className="text-[15px] font-semibold text-zinc-900">
            {t('workflow.definitions.transitions.title', { defaultValue: 'Kto co może zrobić' })}
          </h3>
          <p className="text-[12px] text-zinc-500">
            {t('workflow.definitions.transitions.subtitle', {
              defaultValue:
                'Każdy wiersz to jeden przycisk, który użytkownik zobaczy na produkcie.',
            })}
          </p>
        </div>
        <Button
          variant="outline"
          size="sm"
          onClick={() =>
            onChange([
              ...transitions,
              {
                name: '',
                label: '',
                from: [],
                to: '',
                permission: '',
                commentRequired: false,
                gatePct: '',
                nameLocked: false,
              },
            ])
          }
          data-testid="transition-add"
        >
          <Plus className="mr-1 size-3.5" />
          {t('workflow.definitions.transitions.add', { defaultValue: 'Dodaj akcję' })}
        </Button>
      </div>

      <ul className="space-y-2">
        {transitions.map((transition, index) => {
          const custom = transition.name !== '' && !isCanonicalTransition(transition.name);
          return (
            <li
              // biome-ignore lint/suspicious/noArrayIndexKey: rows are positional editor slots
              key={index}
              className={`space-y-3 rounded-2xl border p-3 ${
                custom ? 'border-amber-200 bg-amber-50/40' : 'border-zinc-200 bg-white'
              }`}
              data-testid={`transition-row-${index}`}
            >
              <div className="flex flex-wrap items-end gap-2.5">
                <div className="w-52">
                  <Label htmlFor={`transition-label-${index}`}>
                    {t('workflow.definitions.transitions.label', { defaultValue: 'Nazwa akcji' })}
                  </Label>
                  <Input
                    id={`transition-label-${index}`}
                    value={transition.label}
                    placeholder={t('workflow.definitions.transitions.label_placeholder', {
                      defaultValue: 'np. Zgłoś do przeglądu',
                    })}
                    onChange={(event) => update(index, { label: event.target.value })}
                    data-testid={`transition-label-${index}`}
                    aria-invalid={error(`transitions[${index}].name`) !== undefined}
                  />
                </div>
                <span className="mb-2 text-[13px] text-zinc-500">
                  {t('workflow.definitions.transitions.moves_from', { defaultValue: 'przenosi z' })}
                </span>
                <div className="w-48" data-testid={`transition-from-${index}`}>
                  <MultiSelect
                    options={placeOptions}
                    value={transition.from}
                    onChange={(next) => update(index, { from: next })}
                  />
                </div>
                <span className="mb-2 text-[13px] text-zinc-500">
                  {t('workflow.definitions.transitions.moves_to', { defaultValue: 'do' })}
                </span>
                <div className="w-44" data-testid={`transition-to-${index}`}>
                  <Combobox
                    options={placeOptions}
                    value={transition.to === '' ? null : transition.to}
                    onChange={(value) => update(index, { to: value ?? '' })}
                  />
                </div>
                <span className="mb-2 text-[13px] text-zinc-500">
                  {t('workflow.definitions.transitions.who_can', { defaultValue: '— może' })}
                </span>
                <div className="w-56" data-testid={`transition-permission-${index}`}>
                  {permissionOptions.length > 0 ? (
                    <Combobox
                      options={permissionOptions}
                      value={transition.permission === '' ? null : transition.permission}
                      onChange={(value) => update(index, { permission: value ?? '' })}
                      placeholder={t('workflow.definitions.transitions.anyone', {
                        defaultValue: 'Każdy, kto widzi produkt',
                      })}
                    />
                  ) : (
                    <Input
                      value={transition.permission}
                      onChange={(event) => update(index, { permission: event.target.value })}
                      placeholder="workflow.approve_reject"
                    />
                  )}
                </div>
                <Button
                  variant="ghost"
                  size="icon"
                  className="ml-auto"
                  onClick={() => onChange(transitions.filter((_, i) => i !== index))}
                  aria-label={t('workflow.definitions.transitions.remove', {
                    defaultValue: 'Usuń akcję',
                  })}
                >
                  <Trash2 className="size-4" />
                </Button>
              </div>

              <div className="flex flex-wrap items-center gap-5 text-[12px] text-zinc-600">
                <label className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    className="size-4 rounded border-zinc-300"
                    checked={transition.commentRequired}
                    onChange={(event) => update(index, { commentRequired: event.target.checked })}
                    data-testid={`transition-comment-${index}`}
                  />
                  {t('workflow.definitions.transitions.comment', {
                    defaultValue: 'wymaga komentarza',
                  })}
                </label>
                <label className="flex items-center gap-2">
                  <input
                    type="checkbox"
                    className="size-4 rounded border-zinc-300"
                    checked={transition.gatePct !== ''}
                    onChange={(event) =>
                      update(index, { gatePct: event.target.checked ? '80' : '' })
                    }
                    data-testid={`transition-gate-toggle-${index}`}
                  />
                  {t('workflow.definitions.transitions.gate', {
                    defaultValue: 'wymaga kompletności',
                  })}
                </label>
                {transition.gatePct === '' ? null : (
                  <span className="flex flex-1 items-center gap-2" style={{ minWidth: '180px' }}>
                    <input
                      type="range"
                      min={0}
                      max={100}
                      step={5}
                      value={Number(transition.gatePct)}
                      onChange={(event) => update(index, { gatePct: event.target.value })}
                      data-testid={`transition-gate-slider-${index}`}
                      className="h-1.5 flex-1 cursor-pointer accent-zinc-900"
                      aria-label={t('workflow.definitions.transitions.gate', {
                        defaultValue: 'wymaga kompletności',
                      })}
                    />
                    <span className="w-10 text-right font-semibold tabular-nums text-zinc-700">
                      {transition.gatePct}%
                    </span>
                  </span>
                )}
                {advanced ? (
                  <span className="font-mono text-[11px] text-zinc-400">{transition.name}</span>
                ) : null}
              </div>

              {custom ? (
                <p
                  className="flex items-start gap-2 rounded-xl border border-amber-200 bg-amber-50 p-2.5 text-[11.5px] text-amber-800"
                  data-testid={`transition-custom-warning-${index}`}
                >
                  <TriangleAlert className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                  {t('workflow.definitions.transitions.custom_warning', {
                    defaultValue:
                      'To Twój własny krok — PIM nie utworzy dla niego zadania ani powiadomienia. Osoba z tym uprawnieniem znajdzie produkty przez filtr statusu.',
                  })}
                </p>
              ) : null}

              {(['name', 'from', 'to', 'permission', 'completeness_gate'] as const).map((field) => {
                const message = error(`transitions[${index}].${field}`);
                return message === undefined ? null : (
                  <p key={field} className="text-[12px] text-brick-600" role="alert">
                    {message}
                  </p>
                );
              })}
            </li>
          );
        })}
      </ul>
    </section>
  );
}

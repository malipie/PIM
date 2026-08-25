import { ArrowRight } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import type { PlaceDraft, TransitionDraft } from './definition-form';

interface StateDiagramProps {
  places: PlaceDraft[];
  transitions: TransitionDraft[];
}

/**
 * WFL redesign (#2515) — picture of the editorial machine (ADR-0029).
 *
 * #3003 made it read the definition being edited. Until then it drew a
 * hardcoded four-state chain, which told the truth only for the built-in
 * flow and quietly lied about every custom one — the opposite of what a
 * preview is for.
 *
 * Deliberately not a graph canvas: pills and arrows, one row per action.
 * That stays legible for the handful of transitions a real flow has and
 * needs no layout engine.
 */
export function StateDiagram({ places, transitions }: StateDiagramProps) {
  const { t } = useTranslation();

  const named = places.filter((place) => place.name.trim() !== '');
  const labelFor = (name: string): string => {
    const place = named.find((candidate) => candidate.name === name);
    if (place === undefined) return name;
    return place.labelPl.trim() === '' ? place.name : place.labelPl.trim();
  };
  const colorFor = (name: string): string =>
    named.find((candidate) => candidate.name === name)?.color ?? '#71717a';

  const drawable = transitions.filter(
    (transition) => transition.to !== '' && transition.from.length > 0,
  );

  return (
    <div className="space-y-3 overflow-x-auto" data-testid="workflow-state-diagram">
      <div className="flex flex-wrap gap-1.5">
        {named.map((place) => (
          <Pill key={place.name} label={labelFor(place.name)} color={place.color} />
        ))}
      </div>

      {drawable.length === 0 ? (
        <p className="text-[12px] text-zinc-500">
          {t('workflow.definitions.preview.no_transitions', {
            defaultValue: 'Dodaj pierwszą akcję, żeby zobaczyć ścieżkę.',
          })}
        </p>
      ) : (
        <ul className="space-y-1.5">
          {drawable.map((transition, index) => (
            <li
              // biome-ignore lint/suspicious/noArrayIndexKey: rows mirror positional editor slots
              key={index}
              className="flex flex-wrap items-center gap-1.5 text-[11.5px]"
            >
              {transition.from.map((from) => (
                <Pill key={from} label={labelFor(from)} color={colorFor(from)} />
              ))}
              <span className="inline-flex items-center gap-1 text-zinc-400">
                <ArrowRight className="size-3" aria-hidden="true" />
                <span className="text-zinc-500">
                  {transition.label.trim() === '' ? transition.name : transition.label}
                </span>
                <ArrowRight className="size-3" aria-hidden="true" />
              </span>
              <Pill label={labelFor(transition.to)} color={colorFor(transition.to)} />
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

function Pill({ label, color }: { label: string; color: string }) {
  return (
    <span className="inline-flex items-center gap-1.5 rounded-full border border-zinc-200 bg-white px-2.5 py-0.5 text-[11.5px] font-medium text-zinc-700">
      <span className="size-2 rounded-full" style={{ backgroundColor: color }} aria-hidden="true" />
      {label}
    </span>
  );
}

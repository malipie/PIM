import { useQuery } from '@tanstack/react-query';
import type { TFunction } from 'i18next';
import { CircleCheck, TriangleAlert } from 'lucide-react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';

import { jsonFetch } from '@/lib/http';

import type { DefinitionDraft } from './definition-form';
import { analyseFlow, type FlowFinding } from './flow-analysis';
import { StateDiagram } from './StateDiagram';

interface FlowPreviewProps {
  draft: DefinitionDraft;
  /** True when the definition is live — drives the impact sentence. */
  enabled: boolean;
}

interface LdCollection {
  totalItems?: number;
  'hydra:totalItems'?: number;
}

/**
 * #3003 — what the flow will actually do, next to the form that defines
 * it. Three answers: how it looks (diagram), what happens to people
 * (narrative), and whether it holds together (readiness).
 *
 * The narrative is generated from CANONICAL transitions only. Tasks and
 * notifications exist for those names alone, so promising an inbox item
 * for a custom step would be a lie the operator only discovers in
 * production — the readiness list says the opposite, on purpose.
 */
export function FlowPreview({ draft, enabled }: FlowPreviewProps) {
  const { t } = useTranslation();

  const findings = useMemo(() => analyseFlow(draft), [draft]);
  const names = useMemo(
    () => new Set(draft.transitions.map((transition) => transition.name)),
    [draft.transitions],
  );

  const gate = draft.transitions.find(
    (transition) => transition.gatePct !== '' && transition.name === 'submit_for_review',
  )?.gatePct;

  // Reuses the sidebar's counting pattern (`use-nav-counts`): the objects
  // endpoint with itemsPerPage=1 answers "how many" without a new API.
  const impactQuery = useQuery({
    enabled: draft.objectTypeId !== '',
    queryKey: ['workflow-definitions', 'impact', draft.objectTypeId],
    queryFn: async () => {
      const data = await jsonFetch<LdCollection>('/api/objects', {
        accept: 'application/ld+json',
        query: { objectType: draft.objectTypeId, itemsPerPage: 1 },
      });
      return data.totalItems ?? data['hydra:totalItems'] ?? null;
    },
  });

  const narrative: string[] = [];
  if (names.has('submit_for_review')) {
    narrative.push(
      gate === undefined
        ? t('workflow.definitions.preview.narrative.submit', {
            defaultValue: 'Wprowadzający wypełnia dane i zgłasza obiekt do przeglądu.',
          })
        : t('workflow.definitions.preview.narrative.submit_gate', {
            defaultValue:
              'Wprowadzający wypełnia dane; przycisk zgłoszenia odblokuje się przy kompletności {{pct}}%.',
            pct: gate,
          }),
    );
    narrative.push(
      t('workflow.definitions.preview.narrative.review_task', {
        defaultValue: 'Akceptant dostaje zadanie w „Moje zadania" i powiadomienie.',
      }),
    );
  }
  if (names.has('reject')) {
    narrative.push(
      t('workflow.definitions.preview.narrative.reject', {
        defaultValue: 'Po odrzuceniu autor dostaje zadanie „Poprawka" z komentarzem akceptanta.',
      }),
    );
  }
  if (names.has('approve') || names.has('publish')) {
    narrative.push(
      t('workflow.definitions.preview.narrative.publish', {
        defaultValue: 'Opublikowany obiekt trafia do eksportów i kanałów sprzedaży.',
      }),
    );
  }
  if (narrative.length === 0) {
    narrative.push(
      t('workflow.definitions.preview.narrative.empty', {
        defaultValue:
          'Ten przepływ nie ma żadnego wbudowanego kroku, więc PIM nie utworzy w nim zadań ani powiadomień.',
      }),
    );
  }

  const impact = impactQuery.data;

  return (
    <aside className="space-y-4 lg:sticky lg:top-4" data-testid="definition-preview">
      <section className="rounded-2xl border border-zinc-200 bg-white p-4">
        <h3 className="text-[14px] font-semibold text-zinc-900">
          {t('workflow.definitions.preview.title', { defaultValue: 'Podgląd' })}
        </h3>
        <p className="mb-3 text-[11.5px] text-zinc-500">
          {t('workflow.definitions.preview.subtitle', {
            defaultValue: 'Rysowany z tego, co masz teraz w formularzu.',
          })}
        </p>
        <StateDiagram places={draft.places} transitions={draft.transitions} />
      </section>

      <section className="rounded-2xl border border-zinc-200 bg-white p-4">
        <h3 className="text-[14px] font-semibold text-zinc-900">
          {t('workflow.definitions.preview.narrative_title', { defaultValue: 'Jak to zadziała' })}
        </h3>
        <ul className="mt-2 space-y-2 text-[12px] text-zinc-600">
          {narrative.map((line) => (
            <li key={line}>{line}</li>
          ))}
        </ul>
      </section>

      <section
        className="rounded-2xl border border-zinc-200 bg-white p-4"
        data-testid="definition-readiness"
      >
        <h3 className="text-[14px] font-semibold text-zinc-900">
          {t('workflow.definitions.preview.readiness_title', { defaultValue: 'Gotowość' })}
        </h3>
        <ul className="mt-2 space-y-2 text-[12px]">
          {findings.length === 0 ? (
            <li className="flex items-start gap-2 text-emerald-700">
              <CircleCheck className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
              {t('workflow.definitions.preview.all_good', {
                defaultValue:
                  'Przepływ trzyma się kupy — każdy etap osiągalny, każda akcja opisana.',
              })}
            </li>
          ) : (
            findings.map((finding) => (
              <li
                key={finding.code}
                className={`flex items-start gap-2 ${
                  finding.level === 'error' ? 'text-brick-600' : 'text-amber-700'
                }`}
                data-testid={`readiness-${finding.code}`}
              >
                <TriangleAlert className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                <span>{findingMessage(finding, t)}</span>
              </li>
            ))
          )}
        </ul>

        {impact === null || impact === undefined ? null : (
          <p className="mt-3 rounded-xl border border-zinc-200 bg-zinc-50/60 p-2.5 text-[11.5px] text-zinc-600">
            {enabled
              ? t('workflow.definitions.preview.impact_enabled', {
                  defaultValue: 'Definicja jest aktywna — zapis obejmie {{count}} obiektów.',
                  count: impact,
                })
              : t('workflow.definitions.preview.impact_disabled', {
                  defaultValue:
                    'Definicja jest wyłączona — {{count}} obiektów zostaje na wbudowanym przepływie, dopóki jej nie włączysz.',
                  count: impact,
                })}
          </p>
        )}
      </section>
    </aside>
  );
}

function findingMessage(finding: FlowFinding, t: TFunction): string {
  const items = finding.items.join(', ');
  switch (finding.code) {
    case 'no_start':
      return t('workflow.definitions.preview.finding.no_start', {
        defaultValue: 'Brakuje etapu początkowego „Szkic" — od niego zaczyna każdy obiekt.',
      });
    case 'unreachable_places':
      return t('workflow.definitions.preview.finding.unreachable', {
        defaultValue: 'Nie da się dojść do: {{items}}. Dodaj akcję prowadzącą do tego etapu.',
        items,
      });
    case 'no_publish_path':
      return t('workflow.definitions.preview.finding.no_publish', {
        defaultValue:
          'Żadna ścieżka nie kończy się publikacją — obiekt nigdy nie trafi do kanałów.',
      });
    case 'incomplete_transition':
      return t('workflow.definitions.preview.finding.incomplete', {
        defaultValue: 'Niedokończone akcje: {{items}} — brakuje nazwy albo etapów.',
        items,
      });
    case 'missing_permission':
      return t('workflow.definitions.preview.finding.ungated', {
        defaultValue: 'Bez wskazania „kto może": {{items}}. Zrobi to każdy, kto widzi obiekt.',
        items,
      });
    default:
      return t('workflow.definitions.preview.finding.custom', {
        defaultValue:
          'Własne kroki: {{items}}. PIM nie utworzy dla nich zadania ani powiadomienia — trzeba je pilnować filtrem statusu.',
        items,
      });
  }
}

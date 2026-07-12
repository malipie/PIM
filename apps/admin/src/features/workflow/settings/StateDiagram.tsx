import { ArrowRight } from 'lucide-react';
import { useTranslation } from 'react-i18next';

/**
 * WFL redesign (#2515) — read-only visualization of the editorial
 * machine (ADR-0029). The flow itself is fixed; the settings page lets
 * the operator configure only the approver + gate, so this is a static
 * diagram, not an editor.
 */
interface Stage {
  place: string;
  transition?: string;
  captionKey: string;
  captionDefault: string;
}

const STAGES: Stage[] = [
  {
    place: 'draft',
    captionKey: 'workflow.settings.diagram.draft',
    captionDefault: 'Wprowadzający wypełnia i zgłasza',
  },
  {
    place: 'review',
    transition: 'submit_for_review',
    captionKey: 'workflow.settings.diagram.review',
    captionDefault: 'Akceptant zatwierdza / odrzuca',
  },
  {
    place: 'published',
    transition: 'approve',
    captionKey: 'workflow.settings.diagram.published',
    captionDefault: 'Widoczny w kanałach sprzedaży',
  },
  {
    place: 'archived',
    transition: 'archive',
    captionKey: 'workflow.settings.diagram.archived',
    captionDefault: 'Wycofany z obiegu',
  },
];

const PLACE_TONE: Record<string, string> = {
  draft: 'bg-zinc-100 text-zinc-600',
  review: 'bg-amber-100 text-amber-700',
  published: 'bg-emerald-100 text-emerald-700',
  archived: 'bg-zinc-100 text-zinc-400',
};

export function StateDiagram() {
  const { t } = useTranslation();

  return (
    <section
      className="rounded-2xl border border-zinc-200 bg-white p-5"
      data-testid="workflow-state-diagram"
    >
      <div className="flex items-center justify-between">
        <div>
          <h3 className="text-[15px] font-semibold text-zinc-900">
            {t('workflow.settings.diagram.title', { defaultValue: 'Przepływ stanów' })}
          </h3>
          <p className="text-[12px] text-zinc-500">
            {t('workflow.settings.diagram.subtitle', {
              defaultValue: 'Ścieżka wpisu od szkicu do publikacji.',
            })}
          </p>
        </div>
        <span className="text-[11px] text-zinc-400">
          {t('workflow.settings.diagram.meta', { defaultValue: '7 przejść · maszyna stanów' })}
        </span>
      </div>

      <ol className="mt-4 flex flex-wrap items-start gap-1">
        {STAGES.map((stage, index) => (
          <li key={stage.place} className="flex items-start gap-1">
            {index > 0 && stage.transition ? (
              <div className="flex flex-col items-center px-1 pt-2 text-center">
                <span className="text-[10px] font-medium text-zinc-500">
                  {t(`workflow.transition.${stage.transition}`, { defaultValue: stage.transition })}
                </span>
                <ArrowRight className="mt-0.5 size-4 text-zinc-300" aria-hidden="true" />
              </div>
            ) : null}
            <div className="flex w-36 flex-col items-center text-center">
              <span
                className={`inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-[12px] font-medium ${PLACE_TONE[stage.place] ?? 'bg-zinc-100 text-zinc-600'}`}
              >
                <span className="size-1.5 rounded-full bg-current opacity-70" aria-hidden="true" />
                {t(`workflow.place.${stage.place}`, { defaultValue: stage.place })}
              </span>
              <span className="mt-1.5 text-[11px] leading-tight text-zinc-500">
                {t(stage.captionKey, { defaultValue: stage.captionDefault })}
              </span>
            </div>
          </li>
        ))}
      </ol>

      <div className="mt-4 flex flex-wrap gap-x-6 gap-y-1 border-t border-zinc-100 pt-3 text-[11px] text-zinc-500">
        <span>
          <span className="font-medium text-zinc-700">
            {t('workflow.transition.reject', { defaultValue: 'Odrzuć' })}
          </span>{' '}
          {t('workflow.settings.diagram.reject_note', {
            defaultValue: '— wraca do Szkic, zadanie „Poprawka" idzie do autora',
          })}
        </span>
        <span>
          <span className="font-medium text-zinc-700">
            {t('workflow.transition.unpublish', { defaultValue: 'Cofnij publikację' })}
          </span>{' '}
          {t('workflow.settings.diagram.unpublish_note', {
            defaultValue: '— z Opublikowany do Szkic',
          })}
        </span>
      </div>
    </section>
  );
}

import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';

import type { DefinitionDraft } from './definition-form';
import { FLOW_TEMPLATES, type TemplateId } from './flow-templates';
import { PLACE_BLOCKS } from './flow-vocabulary';

interface TemplatePickerProps {
  onPick: (draft: DefinitionDraft) => void;
  onBlank: () => void;
}

const COPY: Record<TemplateId, { title: string; body: string }> = {
  no_approval: {
    title: 'Bez akceptacji',
    body: 'Redaktor publikuje sam. Dla katalogów, w których nikt nic nie sprawdza.',
  },
  one_approval: {
    title: 'Jedna akceptacja',
    body: 'Redaktor zgłasza, akceptant zatwierdza albo odsyła z komentarzem. Wbudowany proces PIM-a.',
  },
  approval_then_publish: {
    title: 'Akceptacja i publikacja osobno',
    body: 'Treść zatwierdza akceptant, do kanałów wypuszcza osoba od integracji i eksportów.',
  },
};

/**
 * #3004 — the first screen of "Nowy przepływ". An empty form with a
 * `publish_direct` row asked the operator to invent a process; three
 * named starting points ask which one they already run.
 *
 * Picking only fills the form — every field stays editable, so no path
 * the editor supported is closed off by starting here.
 */
export function TemplatePicker({ onPick, onBlank }: TemplatePickerProps) {
  const { t, i18n } = useTranslation();

  const placeLabel = (name: string): string =>
    PLACE_BLOCKS.find((block) => block.name === name)?.labelPl ?? name;
  const placeColor = (name: string): string =>
    PLACE_BLOCKS.find((block) => block.name === name)?.color ?? '#71717a';

  return (
    <div className="space-y-4" data-testid="definition-template-picker">
      <div>
        <h2 className="text-[22px] font-semibold tracking-tight text-zinc-900">
          {t('workflow.definitions.template.title', {
            defaultValue: 'Jak ma wyglądać droga do publikacji?',
          })}
        </h2>
        <p className="text-[13px] text-zinc-500">
          {t('workflow.definitions.template.subtitle', {
            defaultValue: 'Wybierz najbliższy wariant. Wszystko doprecyzujesz za chwilę.',
          })}
        </p>
      </div>

      <div className="grid grid-cols-1 gap-3 md:grid-cols-3">
        {FLOW_TEMPLATES.map((template) => {
          const copy = COPY[template.id];
          return (
            <button
              key={template.id}
              type="button"
              onClick={() => onPick(template.build(i18n.language))}
              data-testid={`definition-template-${template.id}`}
              className="focus-ring flex flex-col gap-2.5 rounded-2xl border border-zinc-200 bg-white p-4 text-left transition hover:border-zinc-300"
            >
              <span className="flex items-center gap-2 text-[14px] font-semibold text-zinc-900">
                {t(`workflow.definitions.template.${template.id}.title`, {
                  defaultValue: copy.title,
                })}
                {template.id === 'one_approval' ? (
                  <span className="rounded-full bg-orange-50 px-2 py-0.5 text-[10.5px] font-medium text-orange-600">
                    {t('workflow.definitions.template.recommended', { defaultValue: 'polecany' })}
                  </span>
                ) : null}
              </span>

              <span className="flex flex-wrap items-center gap-1 text-[10.5px] text-zinc-400">
                {template.chain.map((name, index) => (
                  <span key={name} className="flex items-center gap-1">
                    {index > 0 ? <span aria-hidden="true">→</span> : null}
                    <span className="inline-flex items-center gap-1 rounded-full border border-zinc-200 px-2 py-0.5 text-[10.5px] text-zinc-600">
                      <span
                        className="size-1.5 rounded-full"
                        style={{ backgroundColor: placeColor(name) }}
                        aria-hidden="true"
                      />
                      {placeLabel(name)}
                    </span>
                  </span>
                ))}
              </span>

              <span className="text-[12px] leading-relaxed text-zinc-500">
                {t(`workflow.definitions.template.${template.id}.body`, {
                  defaultValue: copy.body,
                })}
              </span>

              {template.id === 'approval_then_publish' ? (
                <span className="rounded-xl border border-amber-200 bg-amber-50 p-2 text-[11px] text-amber-800">
                  {t('workflow.definitions.template.custom_step_note', {
                    defaultValue:
                      'Krok „Przekaż do publikacji" jest własny — nie utworzy zadania, publikujący znajdzie obiekty przez filtr statusu.',
                  })}
                </span>
              ) : null}
            </button>
          );
        })}
      </div>

      <Button variant="outline" onClick={onBlank} data-testid="definition-template-blank">
        {t('workflow.definitions.template.blank', { defaultValue: 'Zacznij od pustego' })}
      </Button>
    </div>
  );
}

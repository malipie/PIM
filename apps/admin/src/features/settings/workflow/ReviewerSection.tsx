import { useQuery } from '@tanstack/react-query';
import { Info, Send } from 'lucide-react';
import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';

import { Combobox, type ComboboxOption } from '@/components/ui/combobox';
import { fetchApproverDirectory } from '@/lib/workflow/directory-api';

import { RoleLegend } from './RoleLegend';

interface ReviewerSectionProps {
  /** Picker value: `role:<code>`, `user:<uuid>` or '' for the built-in role. */
  value: string;
  onChange: (next: string) => void;
  /** Server-side violation for the `reviewer` field, if any. */
  error?: string;
}

/**
 * #3001 — the task recipient, moved here from the retired "Ustawienia
 * przepływu" page (#3000). One reviewer per definition handles the
 * `review` and `request_unpublish` tasks (ADR-0029 §4a); the backend has
 * accepted the field since #2513, only the form was missing.
 *
 * The audience panel is not decoration: a definition pointing at one
 * person would strand its tasks whenever that person is away, so the
 * automation also serves anyone holding `workflow.approve_reject`. The
 * operator needs to see that, otherwise the picker reads as an exclusive
 * assignment.
 */
export function ReviewerSection({ value, onChange, error }: ReviewerSectionProps) {
  const { t } = useTranslation();

  const approverQuery = useQuery({
    queryKey: ['workflow-definitions', 'approver-options'],
    queryFn: fetchApproverDirectory,
  });

  const options = useMemo<ComboboxOption[]>(() => {
    const data = approverQuery.data;
    if (data === undefined) return [];
    return [
      ...data.roles.map((role) => ({
        value: `role:${role.code}`,
        label: t('workflow.definitions.approver.role_option', {
          defaultValue: '{{name}} (rola)',
          name: role.name || role.code,
        }),
      })),
      ...data.users.map((user) => ({
        value: `user:${user.id}`,
        label: `${user.display_name || user.email} (${user.email})`,
      })),
    ];
  }, [approverQuery.data, t]);

  const selectedLabel = options.find((option) => option.value === value)?.label ?? value;

  return (
    <section className="space-y-3" data-testid="definition-reviewer">
      <div className="flex items-center gap-2">
        <span className="grid size-8 place-items-center rounded-xl bg-zinc-900 text-white">
          <Send className="size-4" aria-hidden="true" />
        </span>
        <div>
          <h3 className="text-[15px] font-semibold text-zinc-900">
            {t('workflow.definitions.approver.title', { defaultValue: 'Kto dostaje zadania' })}
          </h3>
          <p className="text-[12px] text-zinc-500">
            {t('workflow.definitions.approver.subtitle', {
              defaultValue:
                'Jeden akceptant na całą definicję. Obsługuje zadania Przegląd i Prośba o depublikację.',
            })}
          </p>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <div>
          <label
            className="text-[13px] font-medium text-zinc-700"
            htmlFor="definition-approver-picker"
          >
            {t('workflow.definitions.approver.label', {
              defaultValue: 'Akceptant — rola albo konkretna osoba',
            })}
          </label>
          <div className="mt-1.5" id="definition-approver-picker">
            <Combobox
              options={options}
              value={value === '' ? null : value}
              onChange={(next) => onChange(next ?? '')}
              placeholder={t('workflow.definitions.approver.placeholder', {
                defaultValue: 'Domyślnie: rola Akceptant',
              })}
            />
          </div>
          {error === undefined ? (
            <p className="mt-1.5 text-[11px] text-zinc-500">
              {t('workflow.definitions.approver.hint', {
                defaultValue:
                  'Ta osoba/rola zobaczy zadanie w „Moje zadania" od razu po zgłoszeniu.',
              })}
            </p>
          ) : (
            <p className="mt-1.5 text-[11px] text-brick-600" role="alert">
              {error}
            </p>
          )}
        </div>

        <div className="rounded-2xl border border-zinc-200 bg-zinc-50/60 p-4">
          <p className="text-[11px] font-semibold uppercase tracking-wide text-zinc-400">
            {t('workflow.definitions.approver.audience', { defaultValue: 'Zadanie zobaczą' })}
          </p>
          <ol className="mt-2 space-y-2 text-[12px] text-zinc-600">
            <li className="flex items-center gap-2">
              <span className="grid size-5 place-items-center rounded-full bg-white text-[10px] font-semibold text-zinc-500 shadow-sm">
                1
              </span>
              {value === ''
                ? t('workflow.definitions.approver.default_role', {
                    defaultValue: 'Rola: Akceptant — wskazany odbiorca',
                  })
                : selectedLabel}
            </li>
            <li className="flex items-center gap-2">
              <span className="grid size-5 place-items-center rounded-full bg-white text-[10px] font-semibold text-zinc-500 shadow-sm">
                2
              </span>
              {t('workflow.definitions.approver.approvers', {
                defaultValue: 'Każdy z uprawnieniem „Zatwierdzanie/Odrzucanie"',
              })}
            </li>
          </ol>
          <p className="mt-3 flex items-start gap-1.5 text-[11px] text-zinc-500">
            <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
            {t('workflow.definitions.approver.safety', {
              defaultValue:
                'Bezpiecznik — zadanie nigdy nie utknie, gdy wskazana osoba jest niedostępna.',
            })}
          </p>
        </div>
      </div>

      <p className="text-[12px] text-zinc-500">
        {t('workflow.definitions.approver.footer', {
          defaultValue:
            'Zmiana odbiorcy zacznie obowiązywać dla nowych zgłoszeń. Bieżące zadania zostają u obecnego akceptanta.',
        })}
      </p>

      <RoleLegend />
    </section>
  );
}

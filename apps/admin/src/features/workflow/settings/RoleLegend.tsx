import { Pencil, ShieldCheck } from 'lucide-react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

/**
 * WFL redesign (#2515) — the "two roles, two settings" primer at the top
 * of the flow-settings page. Answers the operator's recurring confusion:
 * WHO may approve is an RBAC permission (Settings → Roles), WHOSE inbox a
 * task lands in is configured here.
 */
export function RoleLegend() {
  const { t } = useTranslation();

  return (
    <section
      className="rounded-2xl border border-zinc-200 bg-white p-5"
      data-testid="workflow-role-legend"
    >
      <h3 className="text-[15px] font-semibold text-zinc-900">
        {t('workflow.settings.legend.title', {
          defaultValue: 'Dwie role, dwa ustawienia — nie myl ich',
        })}
      </h3>
      <p className="mt-1 max-w-3xl text-[13px] text-zinc-500">
        {t('workflow.settings.legend.body', {
          defaultValue:
            'Kto może zatwierdzać ustawiasz w Uprawnieniach (rola). Tutaj ustalasz tylko, do czyjej skrzynki trafia zadanie do zrobienia.',
        })}
      </p>

      <div className="mt-4 grid grid-cols-1 gap-3 lg:grid-cols-2">
        <div className="rounded-2xl border border-zinc-200 bg-zinc-50/60 p-4">
          <div className="flex items-center gap-2">
            <span className="grid size-8 place-items-center rounded-xl bg-white text-indigo-500 shadow-sm">
              <Pencil className="size-4" aria-hidden="true" />
            </span>
            <div>
              <p className="text-[13px] font-semibold text-zinc-900">
                {t('workflow.settings.legend.editor', { defaultValue: 'Wprowadzający dane' })}
              </p>
              <p className="text-[11px] text-zinc-500">
                {t('workflow.settings.legend.editor_roles', {
                  defaultValue: 'Redaktor · Menedżer katalogu · Młodszy redaktor',
                })}
              </p>
            </div>
          </div>
          <p className="mt-3 text-[12px] text-zinc-600">
            {t('workflow.settings.legend.editor_desc', {
              defaultValue: 'Tworzy szkic, wypełnia dane i klika „Zgłoś do przeglądu".',
            })}
          </p>
          <p className="mt-2 text-[11px] text-zinc-500">
            {t('workflow.settings.legend.permission', { defaultValue: 'Uprawnienie' })}:{' '}
            <span className="font-medium text-zinc-700">
              {t('workflow.settings.legend.edit', { defaultValue: 'Edycja' })}
            </span>
          </p>
        </div>

        <div className="rounded-2xl border border-zinc-200 bg-zinc-50/60 p-4">
          <div className="flex items-center gap-2">
            <span className="grid size-8 place-items-center rounded-xl bg-white text-amber-500 shadow-sm">
              <ShieldCheck className="size-4" aria-hidden="true" />
            </span>
            <div>
              <p className="text-[13px] font-semibold text-zinc-900">
                {t('workflow.settings.legend.reviewer', {
                  defaultValue: 'Przeglądający / Akceptant',
                })}
              </p>
              <p className="text-[11px] text-zinc-500">
                {t('workflow.settings.legend.reviewer_roles', {
                  defaultValue: 'Akceptant · Menedżer katalogu · Administrator',
                })}
              </p>
            </div>
          </div>
          <p className="mt-3 text-[12px] text-zinc-600">
            {t('workflow.settings.legend.reviewer_desc', {
              defaultValue:
                'Dostaje zadanie w skrzynce, zatwierdza (→ Opublikowany) lub odrzuca z komentarzem.',
            })}
          </p>
          <p className="mt-2 text-[11px] text-zinc-500">
            {t('workflow.settings.legend.permission', { defaultValue: 'Uprawnienie' })}:{' '}
            <span className="font-medium text-zinc-700">
              {t('workflow.settings.legend.approve', {
                defaultValue: 'Zatwierdzanie / Odrzucanie',
              })}
            </span>
          </p>
        </div>
      </div>

      <Link
        to="/settings/roles"
        className="mt-4 inline-flex items-center gap-1.5 text-[12px] font-medium text-indigo-600 hover:text-indigo-500"
      >
        <ShieldCheck className="size-3.5" aria-hidden="true" />
        {t('workflow.settings.legend.grant', {
          defaultValue: 'Nadaj te uprawnienia rolom w Ustawienia → Role i uprawnienia →',
        })}
      </Link>
    </section>
  );
}

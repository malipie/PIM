import { Rss } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/utils';

import type { FeedTemplateKind } from '../../api/feeds';
import type { FeedTemplateInfo } from '../../api/templates';

const TONES: Record<string, string> = {
  google_shopping: 'bg-sky-50 text-sky-600',
  ceneo: 'bg-emerald-50 text-emerald-600',
  meta: 'bg-violet-50 text-violet-600',
  custom: 'bg-zinc-100 text-zinc-600',
};

/**
 * XMLF-P5-02 — wizard step 1 (design feed-wizard.jsx StepTemplate): the
 * SelectableCard grid over GET /api/feeds/templates plus the name/code
 * identity card (code auto-slugs from the name until touched).
 */
export function StepTemplate({
  templates,
  kind,
  onChoose,
  name,
  onName,
  code,
  onCode,
}: {
  templates: FeedTemplateInfo[];
  kind: FeedTemplateKind | null;
  onChoose: (template: FeedTemplateInfo) => void;
  name: string;
  onName: (value: string) => void;
  code: string;
  onCode: (value: string) => void;
}) {
  const { t } = useTranslation();

  return (
    <div className="space-y-4">
      <div className="rounded-3xl bg-white p-6 soft-shadow">
        <div className="text-[15px] font-semibold tracking-tight">
          {t('api_configurator.feeds.wizard.template.title')}
        </div>
        <div className="mt-0.5 text-[12.5px] text-zinc-500">
          {t('api_configurator.feeds.wizard.template.subtitle')}
        </div>
        <div className="mt-5 grid grid-cols-1 gap-3 md:grid-cols-2">
          {templates.map((template) => {
            const active = kind === template.kind;
            return (
              <button
                key={template.kind}
                type="button"
                onClick={() => onChoose(template)}
                aria-pressed={active}
                className={cn(
                  'rounded-2xl border bg-white p-4 text-left transition',
                  active
                    ? 'border-zinc-900 ring-1 ring-zinc-900 soft-shadow'
                    : 'border-zinc-200 hover:border-zinc-400',
                  template.kind === 'custom' && !active && 'border-dashed',
                )}
              >
                <div className="flex items-center gap-3">
                  <div
                    className={cn(
                      'grid h-11 w-11 shrink-0 place-items-center rounded-2xl',
                      active ? 'bg-zinc-900 text-white' : (TONES[template.kind] ?? TONES.custom),
                    )}
                  >
                    <Rss className="h-5 w-5" aria-hidden />
                  </div>
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2">
                      <div className="text-[14.5px] font-semibold tracking-tight">
                        {t(`api_configurator.feeds.template.${template.kind}`)}
                      </div>
                      <span
                        className={cn(
                          'rounded px-1.5 py-0.5 text-[9.5px] font-semibold uppercase tracking-wider',
                          template.built_in
                            ? 'bg-zinc-100 text-zinc-500'
                            : 'bg-violet-100/70 text-violet-700',
                        )}
                      >
                        {t(
                          template.built_in
                            ? 'api_configurator.feeds.wizard.template.predef'
                            : 'api_configurator.feeds.wizard.template.custom',
                        )}
                      </span>
                      {active && (
                        <span className="rounded bg-orange-500 px-1.5 py-0.5 text-[9.5px] font-semibold uppercase tracking-wider text-zinc-900">
                          {t('api_configurator.feeds.wizard.template.chosen')}
                        </span>
                      )}
                    </div>
                    <div className="mt-0.5 font-mono text-[10.5px] text-zinc-500">
                      root: {template.root_element ?? '—'} · ns:{' '}
                      {template.namespaces.length > 0 ? template.namespaces.join(', ') : '—'}
                    </div>
                  </div>
                </div>
                <div className="mt-3 text-[12px] leading-relaxed text-zinc-500">
                  {t(`api_configurator.feeds.wizard.template.desc.${template.kind}`)}
                </div>
              </button>
            );
          })}
        </div>
      </div>

      {kind !== null && (
        <div className="rounded-3xl bg-white p-6 soft-shadow">
          <div className="grid grid-cols-1 gap-x-8 gap-y-5 sm:grid-cols-2">
            <label className="block">
              <span className="text-[12px] font-medium text-zinc-700">
                {t('api_configurator.feeds.wizard.template.name_label')}
                <span className="ml-0.5 text-rose-600">*</span>
              </span>
              <input
                value={name}
                onChange={(event) => onName(event.target.value)}
                placeholder={t('api_configurator.feeds.wizard.template.name_placeholder')}
                className="mt-1.5 h-10 w-full rounded-xl border border-zinc-200 bg-white px-3 text-[13px] outline-none focus:border-zinc-400"
              />
            </label>
            <label className="block">
              <span className="text-[12px] font-medium text-zinc-700">
                {t('api_configurator.feeds.wizard.template.code_label')}
                <span className="ml-1.5 text-[11px] font-normal text-zinc-500">
                  {t('api_configurator.feeds.wizard.template.code_hint')}
                </span>
              </span>
              <input
                value={code}
                onChange={(event) => onCode(event.target.value)}
                placeholder="google_pl"
                className="mt-1.5 h-10 w-full rounded-xl border border-zinc-200 bg-white px-3 font-mono text-[13px] outline-none focus:border-zinc-400"
              />
            </label>
          </div>
        </div>
      )}
    </div>
  );
}

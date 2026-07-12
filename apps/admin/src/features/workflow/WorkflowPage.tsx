import { SlidersHorizontal } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

import { PillTabs } from '@/components/ui-v2/pill-tabs';
import { usePageActions } from '@/layout/page-actions-context';
import { useIdentity } from '@/lib/identity';

import { ReviewQueuePage } from './ReviewQueuePage';
import { TasksPanel } from './TasksPanel';

/**
 * WFL-P3-02 + WFL-P4-03 — the workflow hub: the review queue (what
 * awaits a decision) next to the task inbox (what awaits MY work) and
 * the tenant-wide task list for deciders. Pill-tab layout per the
 * catalogs/exports hub pattern. Deciders who can manage definitions get
 * the "Ustawienia przepływu" CTA (WFL redesign #2515).
 */
export function WorkflowPage() {
  const { t } = useTranslation();
  const { identity } = useIdentity();
  const canDecide = identity?.permissions.has('workflow.approve_reject') ?? false;
  const canManageDefinitions = identity?.permissions.has('workflow.manage_definitions') ?? false;
  const [tab, setTab] = useState('review');

  usePageActions(
    useMemo(
      () =>
        canManageDefinitions ? (
          <Link
            to="/workflow/settings"
            data-testid="workflow-settings-cta"
            className="focus-ring inline-flex h-9 items-center gap-1.5 rounded-xl bg-zinc-900 px-3.5 text-[13px] font-semibold text-white transition hover:bg-zinc-800"
          >
            <SlidersHorizontal className="size-4" aria-hidden="true" />
            {t('workflow.settings_cta', { defaultValue: 'Ustawienia przepływu' })}
          </Link>
        ) : null,
      [canManageDefinitions, t],
    ),
  );

  const tabs = [
    { id: 'review', label: t('workflow.tabs.review', { defaultValue: 'Do przeglądu' }) },
    { id: 'mine', label: t('workflow.tabs.mine', { defaultValue: 'Moje zadania' }) },
    ...(canDecide
      ? [{ id: 'all', label: t('workflow.tabs.all', { defaultValue: 'Wszystkie zadania' }) }]
      : []),
  ];

  return (
    <div className="mx-auto max-w-6xl px-6 py-6" data-testid="workflow-page">
      {/* Page title comes from the shell topbar breadcrumb (hub pattern). */}
      <PillTabs
        items={tabs}
        activeId={tab}
        onChange={setTab}
        ariaLabel={t('workflow.tabs.aria', { defaultValue: 'Sekcje workflow' })}
      />
      {tab === 'review' ? <ReviewQueuePage /> : null}
      {tab === 'mine' ? <TasksPanel mine /> : null}
      {tab === 'all' && canDecide ? <TasksPanel mine={false} /> : null}
    </div>
  );
}

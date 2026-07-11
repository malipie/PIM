import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { PillTabs } from '@/components/ui-v2/pill-tabs';
import { useIdentity } from '@/lib/identity';

import { ReviewQueuePage } from './ReviewQueuePage';
import { TasksPanel } from './TasksPanel';

/**
 * WFL-P3-02 + WFL-P4-03 — the workflow hub: the review queue (what
 * awaits a decision) next to the task inbox (what awaits MY work) and
 * the tenant-wide task list for deciders. Pill-tab layout per the
 * catalogs/exports hub pattern.
 */
export function WorkflowPage() {
  const { t } = useTranslation();
  const { identity } = useIdentity();
  const canDecide = identity?.permissions.has('workflow.approve_reject') ?? false;
  const [tab, setTab] = useState('review');

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

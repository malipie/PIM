import { useApiUrl, useCustom, useDelete, useList } from '@refinedev/core';
import { Plus } from 'lucide-react';
import * as React from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import { jsonFetch } from '@/lib/http';

import { NextRunsTimeline } from './NextRunsTimeline';
import { ScheduleCard } from './ScheduleCard';
import { ScheduleFormDialog, type ScheduleFormInput } from './ScheduleFormDialog';
import type { ImportScheduleRow, UpcomingScheduleEntry } from './types';

interface UpcomingResponse {
  member: ReadonlyArray<UpcomingScheduleEntry>;
  horizonHours: number;
}

const HORIZON_HOURS = 24;

/**
 * VIEW-IMP-04 (#502) — schedule hub. Timeline of upcoming runs + two
 * card sections (Active / Paused). Cron worker daemon ships in the
 * follow-up — MVP relies on manual `run-now` to test the wiring.
 */
export function ImportScheduleView() {
  const { t } = useTranslation();
  const apiUrl = useApiUrl();
  const [dialogState, setDialogState] = React.useState<{
    open: boolean;
    mode: 'create' | 'edit';
    schedule?: ImportScheduleRow;
  }>({ open: false, mode: 'create' });
  const [deleteTarget, setDeleteTarget] = React.useState<ImportScheduleRow | null>(null);
  const [togglingId, setTogglingId] = React.useState<string | null>(null);
  const [runningId, setRunningId] = React.useState<string | null>(null);

  const { result, query } = useList<ImportScheduleRow>({
    resource: 'import-schedules',
    pagination: { pageSize: 100, currentPage: 1 },
  });
  const schedules = result.data ?? [];
  const refetch = () => {
    void query.refetch();
  };

  const upcomingCustom = useCustom<UpcomingResponse>({
    url: `${apiUrl}/import-schedules/upcoming?hours=${HORIZON_HOURS}`,
    method: 'get',
    queryOptions: { refetchInterval: 60_000, staleTime: 30_000 },
  });
  const upcoming = upcomingCustom.result?.data?.member ?? [];

  const { mutate: deleteSchedule } = useDelete<ImportScheduleRow>();

  const enabled = schedules.filter((s) => s.enabled);
  const disabled = schedules.filter((s) => !s.enabled);

  async function handleToggle(schedule: ImportScheduleRow) {
    setTogglingId(schedule.id);
    try {
      await jsonFetch(`/api/import-schedules/${schedule.id}/toggle`, { method: 'POST' });
      refetch();
    } finally {
      setTogglingId(null);
    }
  }

  async function handleRunNow(schedule: ImportScheduleRow) {
    setRunningId(schedule.id);
    try {
      await jsonFetch(`/api/import-schedules/${schedule.id}/run-now`, { method: 'POST' });
      refetch();
    } finally {
      setRunningId(null);
    }
  }

  function handleEdit(schedule: ImportScheduleRow) {
    setDialogState({ open: true, mode: 'edit', schedule });
  }

  function handleDelete(schedule: ImportScheduleRow) {
    setDeleteTarget(schedule);
  }

  function confirmDelete() {
    if (!deleteTarget) {
      return;
    }
    deleteSchedule(
      { resource: 'import-schedules', id: deleteTarget.id },
      { onSettled: () => setDeleteTarget(null) },
    );
  }

  function handleCreate() {
    setDialogState({ open: true, mode: 'create' });
  }

  async function handleSubmit(input: ScheduleFormInput) {
    if (dialogState.mode === 'create') {
      await jsonFetch('/api/import-schedules', {
        method: 'POST',
        body: input,
        contentType: 'application/ld+json',
      });
    } else if (dialogState.schedule) {
      await jsonFetch(`/api/import-schedules/${dialogState.schedule.id}`, {
        method: 'PATCH',
        body: input,
        contentType: 'application/merge-patch+json',
      });
    }
    refetch();
  }

  return (
    <div className="space-y-6">
      <div className="flex items-start justify-end gap-6">
        <div className="flex items-center gap-2 shrink-0">
          <Button onClick={handleCreate}>
            <Plus className="size-4" />
            {t('imports.schedule.new_schedule')}
          </Button>
        </div>
      </div>

      <NextRunsTimeline entries={upcoming} horizonHours={HORIZON_HOURS} />

      {query.isLoading ? (
        <div
          className="rounded-2xl border border-zinc-100 bg-white px-5 py-10 text-center text-[13px] text-zinc-500"
          aria-busy="true"
        >
          {t('app.loading')}
        </div>
      ) : schedules.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-zinc-200 bg-zinc-50/60 px-6 py-16 text-center">
          <h3 className="text-base font-semibold text-zinc-900">
            {t('imports.schedule.empty.title')}
          </h3>
          <p className="mt-1 text-[13px] text-muted-foreground max-w-md mx-auto">
            {t('imports.schedule.empty.subtitle')}
          </p>
          <Button className="mt-4" onClick={handleCreate}>
            <Plus className="size-4" />
            {t('imports.schedule.new_schedule')}
          </Button>
        </div>
      ) : (
        <>
          <section className="space-y-3">
            <div className="flex items-center gap-3">
              <h3 className="font-display text-[15px] font-semibold tracking-tight">
                {t('imports.schedule.active.heading')}
              </h3>
              <span className="text-[11.5px] num px-1.5 py-0.5 rounded bg-emerald-50 text-emerald-700 font-medium">
                {enabled.length}
              </span>
            </div>
            {enabled.length > 0 ? (
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
                {enabled.map((s) => (
                  <ScheduleCard
                    key={s.id}
                    schedule={s}
                    toggling={togglingId === s.id}
                    running={runningId === s.id}
                    onToggle={handleToggle}
                    onRunNow={handleRunNow}
                    onEdit={handleEdit}
                    onDelete={handleDelete}
                  />
                ))}
              </div>
            ) : (
              <div className="rounded-xl border border-dashed border-zinc-200 px-4 py-8 text-center text-[12.5px] text-zinc-500">
                {t('imports.schedule.active.empty')}
              </div>
            )}
          </section>

          {disabled.length > 0 ? (
            <section className="space-y-3">
              <div className="flex items-center gap-3">
                <h3 className="font-display text-[15px] font-semibold tracking-tight">
                  {t('imports.schedule.paused.heading')}
                </h3>
                <span className="text-[11.5px] num px-1.5 py-0.5 rounded bg-zinc-100 text-zinc-600 font-medium">
                  {disabled.length}
                </span>
              </div>
              <div className="grid grid-cols-1 lg:grid-cols-2 gap-3">
                {disabled.map((s) => (
                  <ScheduleCard
                    key={s.id}
                    schedule={s}
                    toggling={togglingId === s.id}
                    running={runningId === s.id}
                    onToggle={handleToggle}
                    onRunNow={handleRunNow}
                    onEdit={handleEdit}
                    onDelete={handleDelete}
                  />
                ))}
              </div>
            </section>
          ) : null}
        </>
      )}

      <ScheduleFormDialog
        open={dialogState.open}
        mode={dialogState.mode}
        schedule={dialogState.schedule}
        onClose={() => setDialogState({ open: false, mode: dialogState.mode })}
        onSubmit={handleSubmit}
      />

      <Dialog
        open={deleteTarget !== null}
        onOpenChange={(next) => {
          if (!next) {
            setDeleteTarget(null);
          }
        }}
      >
        <DialogContent>
          <DialogHeader>
            <DialogTitle>{t('imports.schedule.delete.title')}</DialogTitle>
            <DialogDescription>
              {deleteTarget
                ? t('imports.schedule.delete.confirm', { name: deleteTarget.name })
                : ''}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDeleteTarget(null)}>
              {t('app.cancel')}
            </Button>
            <Button variant="destructive" onClick={confirmDelete}>
              {t('imports.schedule.delete.confirm_button')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

import { useDelete, useList } from '@refinedev/core';
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

import { SourceCard } from './SourceCard';
import { SourceFormDialog, type SourceFormInput } from './SourceFormDialog';
import type { ImportSourceRow } from './types';

interface TestResponse {
  health: string;
  note: string | null;
  latency_ms: number;
  checked_at: string | null;
}

/**
 * VIEW-IMP-03 (#500) — sources hub. Grid of `SourceCard` per
 * `ImportSource`, each exposing test-connection / edit / delete via a
 * dropdown menu. Polling daemon is intentionally not scoped here; the
 * MVP relies on manual test-connection probes against the configured
 * transport.
 */
export function ImportSourcesView() {
  const { t } = useTranslation();
  const [dialogState, setDialogState] = React.useState<{
    open: boolean;
    mode: 'create' | 'edit';
    source?: ImportSourceRow;
  }>({ open: false, mode: 'create' });
  const [deleteTarget, setDeleteTarget] = React.useState<ImportSourceRow | null>(null);
  const [testingId, setTestingId] = React.useState<string | null>(null);
  const [testNotice, setTestNotice] = React.useState<{
    message: string;
    tone: 'ok' | 'warn' | 'error';
  } | null>(null);

  const { result, query } = useList<ImportSourceRow>({
    resource: 'import-sources',
    pagination: { pageSize: 100, currentPage: 1 },
  });
  const sources = result.data ?? [];
  const refetch = () => {
    void query.refetch();
  };

  const { mutate: deleteSource } = useDelete<ImportSourceRow>();

  async function handleTest(source: ImportSourceRow) {
    setTestingId(source.id);
    setTestNotice(null);
    try {
      const response = await jsonFetch<TestResponse>(
        `/api/import-sources/${source.id}/test-connection`,
        {
          method: 'POST',
        },
      );
      const tone = response.health === 'ok' ? 'ok' : response.health === 'warn' ? 'warn' : 'error';
      setTestNotice({
        message: t('imports.sources.test.result', {
          health: t(`imports.sources.health.${response.health}`),
          note: response.note ?? '',
          latency: response.latency_ms,
        }),
        tone,
      });
      refetch();
    } catch (e) {
      setTestNotice({
        message: e instanceof Error ? e.message : String(e),
        tone: 'error',
      });
    } finally {
      setTestingId(null);
    }
  }

  function handleEdit(source: ImportSourceRow) {
    setDialogState({ open: true, mode: 'edit', source });
  }

  function handleDelete(source: ImportSourceRow) {
    setDeleteTarget(source);
  }

  function confirmDelete() {
    if (!deleteTarget) {
      return;
    }
    deleteSource(
      { resource: 'import-sources', id: deleteTarget.id },
      { onSettled: () => setDeleteTarget(null) },
    );
  }

  function handleCreate() {
    setDialogState({ open: true, mode: 'create' });
  }

  async function handleSubmit(input: SourceFormInput) {
    if (dialogState.mode === 'create') {
      await jsonFetch('/api/import-sources', {
        method: 'POST',
        body: input,
        contentType: 'application/ld+json',
      });
    } else if (dialogState.source) {
      await jsonFetch(`/api/import-sources/${dialogState.source.id}`, {
        method: 'PATCH',
        body: input,
        contentType: 'application/merge-patch+json',
      });
    }
    refetch();
  }

  const noticeBg = testNotice
    ? testNotice.tone === 'ok'
      ? 'bg-emerald-50 text-emerald-700'
      : testNotice.tone === 'warn'
        ? 'bg-amber-50 text-amber-800'
        : 'bg-rose-50 text-rose-700'
    : '';

  return (
    <div className="space-y-6">
      <div className="flex items-start justify-end gap-6">
        <div className="flex items-center gap-2 shrink-0">
          <Button onClick={handleCreate}>
            <Plus className="size-4" />
            {t('imports.sources.add_source')}
          </Button>
        </div>
      </div>

      {testNotice ? (
        <div
          role="status"
          aria-live="polite"
          className={`rounded-xl px-3 py-2 text-[12.5px] ${noticeBg}`}
        >
          {testNotice.message}
        </div>
      ) : null}

      {query.isLoading ? (
        <div
          className="rounded-2xl border border-zinc-100 bg-white px-5 py-10 text-center text-[13px] text-zinc-500"
          aria-busy="true"
        >
          {t('app.loading')}
        </div>
      ) : sources.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-zinc-200 bg-zinc-50/60 px-6 py-16 text-center">
          <h3 className="text-base font-semibold text-zinc-900">
            {t('imports.sources.empty.title')}
          </h3>
          <p className="mt-1 text-[13px] text-muted-foreground max-w-md mx-auto">
            {t('imports.sources.empty.subtitle')}
          </p>
          <Button className="mt-4" onClick={handleCreate}>
            <Plus className="size-4" />
            {t('imports.sources.add_source')}
          </Button>
        </div>
      ) : (
        <div className="grid grid-cols-1 lg:grid-cols-2 gap-4">
          {sources.map((s) => (
            <SourceCard
              key={s.id}
              source={s}
              testing={testingId === s.id}
              onTest={handleTest}
              onEdit={handleEdit}
              onDelete={handleDelete}
            />
          ))}
        </div>
      )}

      <SourceFormDialog
        open={dialogState.open}
        mode={dialogState.mode}
        source={dialogState.source}
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
            <DialogTitle>{t('imports.sources.delete.title')}</DialogTitle>
            <DialogDescription>
              {deleteTarget ? t('imports.sources.delete.confirm', { name: deleteTarget.name }) : ''}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDeleteTarget(null)}>
              {t('app.cancel')}
            </Button>
            <Button variant="destructive" onClick={confirmDelete}>
              {t('imports.sources.delete.confirm_button')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

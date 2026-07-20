import { useApiUrl, useDelete, useList } from '@refinedev/core';
import { LayoutGrid, List, Plus, Search } from 'lucide-react';
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
import { toast } from '@/components/ui/toast';
import { downloadWithAuth } from '@/lib/download';
import { jsonFetch } from '@/lib/http';
import { cn } from '@/lib/utils';

import { NewProfileCard } from './NewProfileCard';
import { ProfileCard } from './ProfileCard';
import { ProfileEditDialog, type ProfileFormInput } from './ProfileEditDialog';
import { ProfileRow } from './ProfileRow';
import type { ImportProfileRow, ProfileViewMode } from './types';

/**
 * VIEW-IMP-02 (#498) — profiles hub. Toggle grid/list, search, CRUD
 * (via the existing AP4 resource), duplicate / export / import via
 * the new custom controllers. The legacy `ImportProfileManager`
 * Sheet is still available behind a fallback button so we don't
 * regress the wizard's edit flow while the dedicated dialog matures.
 */
export function ImportProfilesView() {
  const { t } = useTranslation();
  const apiUrl = useApiUrl();
  const [q, setQ] = React.useState('');
  const [view, setView] = React.useState<ProfileViewMode>('grid');
  const [dialogState, setDialogState] = React.useState<{
    open: boolean;
    mode: 'create' | 'edit';
    profile?: ImportProfileRow;
  }>({ open: false, mode: 'create' });
  const [deleteTarget, setDeleteTarget] = React.useState<ImportProfileRow | null>(null);

  const { result, query } = useList<ImportProfileRow>({
    resource: 'import-profiles',
    pagination: { pageSize: 100, currentPage: 1 },
  });
  const profiles = result.data ?? [];
  const refetch = () => {
    void query.refetch();
  };

  const { mutate: deleteProfile } = useDelete<ImportProfileRow>();

  const filtered =
    q.length === 0
      ? profiles
      : profiles.filter(
          (p) =>
            p.name.toLowerCase().includes(q.toLowerCase()) ||
            p.code.toLowerCase().includes(q.toLowerCase()),
        );

  function handleEdit(profile: ImportProfileRow) {
    setDialogState({ open: true, mode: 'edit', profile });
  }

  function handleCreate() {
    setDialogState({ open: true, mode: 'create' });
  }

  async function handleDuplicate(profile: ImportProfileRow) {
    await jsonFetch(`/api/import-profiles/${profile.id}/duplicate`, { method: 'POST' });
    refetch();
  }

  async function handleExport(profile: ImportProfileRow) {
    try {
      await downloadWithAuth(
        `${apiUrl}/import-profiles/${profile.id}/export`,
        `import-profile-${profile.code}.json`,
      );
    } catch {
      toast.error(
        t('imports.profiles.export_failed', { defaultValue: 'Eksport profilu nie powiódł się' }),
      );
    }
  }

  function handleDelete(profile: ImportProfileRow) {
    setDeleteTarget(profile);
  }

  function confirmDelete() {
    if (!deleteTarget) {
      return;
    }
    deleteProfile(
      { resource: 'import-profiles', id: deleteTarget.id },
      { onSettled: () => setDeleteTarget(null) },
    );
  }

  async function handleSubmit(input: ProfileFormInput) {
    if (dialogState.mode === 'create') {
      await jsonFetch('/api/import-profiles', {
        method: 'POST',
        body: input,
        contentType: 'application/ld+json',
      });
    } else if (dialogState.profile) {
      await jsonFetch(`/api/import-profiles/${dialogState.profile.id}`, {
        method: 'PATCH',
        body: {
          name: input.name,
          code: input.code,
          mode: input.mode,
          encoding: input.encoding,
          delimiter: input.delimiter,
          locale: input.locale,
        },
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
            {t('imports.profiles.new_profile')}
          </Button>
        </div>
      </div>

      <div className="flex items-center gap-3 flex-wrap">
        <label className="flex items-center gap-2 bg-white soft-shadow rounded-xl pl-3 pr-2 h-9">
          <Search className="h-4 w-4 text-zinc-500" aria-hidden="true" />
          <input
            value={q}
            onChange={(e) => setQ(e.target.value)}
            placeholder={t('imports.profiles.search_placeholder')}
            aria-label={t('imports.profiles.search_aria')}
            className="w-72 bg-transparent outline-none text-[13px] placeholder:text-zinc-400"
          />
        </label>
        <fieldset className="ml-auto flex items-center gap-0.5 bg-white soft-shadow rounded-xl p-1 h-9 border-0">
          <legend className="sr-only">{t('imports.profiles.view.aria_label')}</legend>
          <button
            type="button"
            aria-pressed={view === 'grid'}
            aria-label={t('imports.profiles.view.grid')}
            onClick={() => setView('grid')}
            className={cn(
              'h-7 w-8 grid place-items-center rounded-lg transition',
              view === 'grid' ? 'bg-zinc-900 text-white' : 'text-zinc-500 hover:bg-zinc-100',
            )}
          >
            <LayoutGrid className="h-4 w-4" />
          </button>
          <button
            type="button"
            aria-pressed={view === 'list'}
            aria-label={t('imports.profiles.view.list')}
            onClick={() => setView('list')}
            className={cn(
              'h-7 w-8 grid place-items-center rounded-lg transition',
              view === 'list' ? 'bg-zinc-900 text-white' : 'text-zinc-500 hover:bg-zinc-100',
            )}
          >
            <List className="h-4 w-4" />
          </button>
        </fieldset>
      </div>

      {query.isLoading ? (
        <div
          className="rounded-2xl border border-zinc-100 bg-white px-5 py-10 text-center text-[13px] text-zinc-500"
          aria-busy="true"
        >
          {t('app.loading')}
        </div>
      ) : view === 'grid' ? (
        <div className="grid grid-cols-1 md:grid-cols-2 xl:grid-cols-3 gap-4">
          {filtered.map((p) => (
            <ProfileCard
              key={p.id}
              profile={p}
              onEdit={handleEdit}
              onDuplicate={handleDuplicate}
              onExport={handleExport}
              onDelete={handleDelete}
            />
          ))}
          <NewProfileCard onClick={handleCreate} />
        </div>
      ) : (
        <div className="overflow-hidden rounded-2xl border border-zinc-100 bg-white soft-shadow">
          <div className="grid grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)_90px_120px_90px_110px_110px_36px] gap-3 text-[10.5px] uppercase tracking-wider text-zinc-500 font-medium px-5 py-2.5 border-b border-zinc-100 bg-zinc-50/40">
            <div>{t('imports.profiles.list.col_name')}</div>
            <div>{t('imports.profiles.list.col_target')}</div>
            <div>{t('imports.profiles.list.col_format')}</div>
            <div>{t('imports.profiles.list.col_mode')}</div>
            <div className="text-right">{t('imports.profiles.list.col_columns')}</div>
            <div>{t('imports.profiles.list.col_locale')}</div>
            <div>{t('imports.profiles.list.col_last_used')}</div>
            <div />
          </div>
          <div className="divide-y divide-zinc-50">
            {filtered.length > 0 ? (
              filtered.map((p) => (
                <ProfileRow
                  key={p.id}
                  profile={p}
                  onEdit={handleEdit}
                  onDuplicate={handleDuplicate}
                  onExport={handleExport}
                  onDelete={handleDelete}
                />
              ))
            ) : (
              <div className="px-5 py-8 text-center text-[13px] text-zinc-500">
                {t('imports.profiles.list.empty_filtered')}
              </div>
            )}
          </div>
        </div>
      )}

      <ProfileEditDialog
        open={dialogState.open}
        mode={dialogState.mode}
        profile={dialogState.profile}
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
            <DialogTitle>{t('imports.profiles.delete.title')}</DialogTitle>
            <DialogDescription>
              {deleteTarget
                ? t('imports.profiles.delete.confirm', { name: deleteTarget.name })
                : ''}
            </DialogDescription>
          </DialogHeader>
          <DialogFooter>
            <Button variant="outline" onClick={() => setDeleteTarget(null)}>
              {t('app.cancel')}
            </Button>
            <Button variant="destructive" onClick={confirmDelete}>
              {t('imports.profiles.delete.confirm_button')}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </div>
  );
}

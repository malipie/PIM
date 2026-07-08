import { useList } from '@refinedev/core';
import {
  ArrowUp,
  Check,
  ChevronRight,
  LayoutGrid,
  List as ListIcon,
  Loader2,
  Search,
  Upload,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { usePageActions } from '@/layout/page-actions-context';
import type { DuplicateAssetError, UploadAssetResult } from '@/lib/asset-upload';
import { jsonFetch } from '@/lib/http';
import { useDebouncedCallback } from '@/lib/use-debounced-callback';
import { cn } from '@/lib/utils';
import { AssetBulkActionsBar } from './AssetBulkActionsBar';
import { AssetDrawer } from './AssetDrawer';
import { AssetDuplicateDialog } from './AssetDuplicateDialog';
import { AssetUploadModal } from './AssetUploadModal';
import { type AssetEntry, type AssetMeta, AssetThumb, toAssetMeta } from './asset-meta';

interface FolderEntry {
  code: string;
  displayName: string;
  assetCount: number;
}

interface FoldersResponse {
  member: FolderEntry[];
  totalItems: number;
}

interface DuplicateState {
  open: boolean;
  existingAssetId: string;
  existingCode: string;
}

const POLL_INTERVAL_MS = 3500;
const VIEW_STORAGE_KEY = 'pim.assets.view';

type AssetsView = 'grid' | 'list';
type TypeFilter = 'all' | 'image' | 'pdf';

/**
 * NUI-08 (#1427) — Multimedia v2, Explorer-style file manager per
 * `Multimedia.html`: type pills + search + grid/list toggle, path bar with
 * up-arrow + breadcrumb + storage bar (MOCK), folder tiles, asset cards with
 * kind-tinted placeholders, 460px detail drawer, fixed bulk bar.
 *
 * Folder semantics: `null` = "Wszystkie zasoby" (no folder param — every
 * asset), pseudo-folder `root` = "Bez przypisania". Flat folder list — the
 * backend has no nesting (backlog: Retrofit_v2/multimedia-do-oprogramowania.md).
 */
export function AssetsListPage() {
  const { t } = useTranslation();
  const [search, setSearch] = useState('');
  const [debouncedSearch, setDebouncedSearch] = useState('');
  const [typeFilter, setTypeFilter] = useState<TypeFilter>('all');
  const [view, setView] = useState<AssetsView>(() => {
    if (typeof window === 'undefined') return 'grid';
    return window.localStorage.getItem(VIEW_STORAGE_KEY) === 'list' ? 'list' : 'grid';
  });
  const [selected, setSelected] = useState<Set<string>>(new Set());
  const [duplicate, setDuplicate] = useState<DuplicateState | null>(null);
  const [refreshTick, setRefreshTick] = useState(0);
  const [currentFolder, setCurrentFolder] = useState<string | null>(null);
  const [folders, setFolders] = useState<FolderEntry[]>([]);
  const [drawerAsset, setDrawerAsset] = useState<AssetMeta | null>(null);
  const [uploadOpen, setUploadOpen] = useState(false);

  const setSearchSoon = useDebouncedCallback((value: string) => setDebouncedSearch(value), 300);
  useEffect(() => {
    setSearchSoon(search);
  }, [search, setSearchSoon]);

  useEffect(() => {
    if (typeof window !== 'undefined') window.localStorage.setItem(VIEW_STORAGE_KEY, view);
  }, [view]);

  // Topbar CTA — design places "Prześlij pliki" in the topbar (PageActions).
  usePageActions(
    useMemo(
      () => (
        <button
          type="button"
          onClick={() => setUploadOpen(true)}
          className="focus-ring inline-flex h-9 items-center gap-1.5 rounded-xl bg-cta px-3.5 text-[13px] font-semibold text-cta-foreground transition hover:bg-accent-hover"
        >
          <Upload className="size-4" aria-hidden />
          {t('assets.upload_cta', { defaultValue: 'Prześlij pliki' })}
        </button>
      ),
      [t],
    ),
  );

  // Folder list — cheap GROUP BY; refresh after uploads/deletes.
  // biome-ignore lint/correctness/useExhaustiveDependencies: refreshTick is an intentional refetch trigger
  useEffect(() => {
    let cancelled = false;
    void (async () => {
      try {
        const response = await jsonFetch<FoldersResponse>('/api/asset-folders', {
          accept: 'application/json',
        });
        if (!cancelled) setFolders(response.member ?? []);
      } catch {
        if (!cancelled) setFolders([]);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [refreshTick]);

  const filterParams = useMemo(() => {
    const params: Array<{ field: string; operator: 'eq'; value: string }> = [];
    // null = all assets (no folder narrowing); 'root' = unassigned;
    // other values match the literal folder code.
    if (currentFolder !== null) {
      params.push({ field: 'folder', operator: 'eq', value: currentFolder });
    }
    if (debouncedSearch) params.push({ field: 'search', operator: 'eq', value: debouncedSearch });
    if (typeFilter !== 'all')
      params.push({ field: 'mimeGroup', operator: 'eq', value: typeFilter });
    return params;
  }, [currentFolder, debouncedSearch, typeFilter]);

  const { result, query } = useList<AssetEntry>({
    resource: 'assets',
    pagination: { mode: 'off' },
    filters: filterParams,
    queryOptions: {
      refetchInterval: (q) => {
        const data = q.state.data?.data as AssetEntry[] | undefined;
        return data?.some((item) => toAssetMeta(item).thumbnailsPending) ? POLL_INTERVAL_MS : false;
      },
    },
    meta: { refreshTick },
  });

  const assets = useMemo(() => (result?.data ?? []).map(toAssetMeta), [result?.data]);
  const isLoading = query.isLoading;
  const showFolderTiles = currentFolder === null && !debouncedSearch;
  const currentFolderEntry =
    currentFolder !== null && currentFolder !== 'root'
      ? folders.find((entry) => entry.code === currentFolder)
      : null;

  const refresh = () => {
    setRefreshTick((tick) => tick + 1);
    void query.refetch();
  };

  const onCompleted = (results: UploadAssetResult[]) => {
    if (results.length > 0) refresh();
  };

  const onDuplicate = (error: DuplicateAssetError) => {
    setDuplicate({
      open: true,
      existingAssetId: error.existingAssetId,
      existingCode: error.existingCode,
    });
  };

  const toggleSelect = (id: string) => {
    setSelected((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  };

  const enterFolder = (code: string | null) => {
    setCurrentFolder(code);
    setSelected(new Set());
  };

  const crumbLabel =
    currentFolder === 'root'
      ? t('assets.folder.unassigned', { defaultValue: 'Bez przypisania' })
      : (currentFolderEntry?.displayName ?? currentFolder);

  const typeFilters: Array<{ id: TypeFilter; label: string }> = [
    { id: 'all', label: t('assets.filters.mime_all') },
    { id: 'image', label: t('assets.filters.mime_images') },
    { id: 'pdf', label: t('assets.filters.mime_pdf') },
  ];

  return (
    <div className="space-y-4">
      {/* Control bar — type pills · search · view toggle */}
      <div className="flex flex-wrap items-center gap-2">
        <div
          className="flex items-center gap-0.5"
          role="tablist"
          aria-label={t('assets.filters.mime_label')}
        >
          {typeFilters.map((f) => (
            <button
              key={f.id}
              type="button"
              role="tab"
              aria-selected={typeFilter === f.id}
              onClick={() => setTypeFilter(f.id)}
              className={cn(
                'h-[30px] rounded-lg px-3 text-[12.5px] font-medium transition',
                typeFilter === f.id ? 'bg-zinc-900 text-white' : 'text-zinc-600 hover:bg-zinc-100',
              )}
            >
              {f.label}
            </button>
          ))}
        </div>
        <div className="ml-auto flex items-center gap-2">
          <div className="relative">
            <Search
              className="absolute left-2.5 top-1/2 size-4 -translate-y-1/2 text-zinc-500"
              aria-hidden
            />
            <input
              type="search"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
              placeholder={t('assets.filters.search_placeholder')}
              className="h-9 w-[240px] rounded-xl border border-zinc-200 bg-white pl-8 pr-3 text-[12.5px] placeholder:text-zinc-400 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900"
            />
          </div>
          <div className="flex items-center rounded-xl border border-zinc-200 bg-white p-0.5">
            <button
              type="button"
              onClick={() => setView('grid')}
              aria-pressed={view === 'grid'}
              aria-label={t('assets.view.grid', { defaultValue: 'Siatka' })}
              className={cn(
                'grid h-8 w-8 place-items-center rounded-lg',
                view === 'grid' ? 'bg-zinc-900 text-white' : 'text-zinc-500',
              )}
            >
              <LayoutGrid className="size-4" />
            </button>
            <button
              type="button"
              onClick={() => setView('list')}
              aria-pressed={view === 'list'}
              aria-label={t('assets.view.list', { defaultValue: 'Lista' })}
              className={cn(
                'grid h-8 w-8 place-items-center rounded-lg',
                view === 'list' ? 'bg-zinc-900 text-white' : 'text-zinc-500',
              )}
            >
              <ListIcon className="size-4" />
            </button>
          </div>
        </div>
      </div>

      {/* Path bar — up arrow · breadcrumb · storage (MOCK) */}
      <div className="flex items-center gap-1">
        <button
          type="button"
          onClick={() => enterFolder(null)}
          disabled={currentFolder === null}
          title={t('assets.folder.up', { defaultValue: 'Folder nadrzędny' })}
          className={cn(
            'mr-1 grid h-8 w-8 place-items-center rounded-lg transition',
            currentFolder !== null
              ? 'text-zinc-600 hover:bg-zinc-100'
              : 'cursor-default text-zinc-300',
          )}
        >
          <ArrowUp className="size-4" />
        </button>
        <button
          type="button"
          onClick={() => enterFolder(null)}
          className={cn(
            'h-8 rounded-lg px-2.5 text-[12.5px] font-medium transition',
            currentFolder === null
              ? 'bg-zinc-100/80 text-zinc-900'
              : 'text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900',
          )}
        >
          {t('assets.folder.all')}
        </button>
        {currentFolder !== null ? (
          <>
            <ChevronRight className="size-4 text-zinc-300" aria-hidden />
            <span className="h-8 rounded-lg bg-zinc-100/80 px-2.5 text-[12.5px] font-medium leading-8 text-zinc-900">
              {crumbLabel}
            </span>
          </>
        ) : null}
      </div>

      {/* Folder tiles */}
      {showFolderTiles ? (
        <div>
          <div className="mb-1.5 text-[11px] font-medium uppercase tracking-wider text-zinc-500">
            {t('assets.folders_label', { defaultValue: 'Foldery' })}
          </div>
          <div className="flex flex-wrap gap-1.5">
            {folders.map((folder) => (
              <FolderTile
                key={folder.code}
                name={folder.displayName}
                count={folder.assetCount}
                onOpen={() => enterFolder(folder.code)}
              />
            ))}
            <FolderTile
              name={t('assets.folder.unassigned', { defaultValue: 'Bez przypisania' })}
              count={null}
              warning
              onOpen={() => enterFolder('root')}
            />
          </div>
        </div>
      ) : null}

      {/* Files */}
      <div>
        <div className="mb-2.5 flex items-center gap-2">
          <div className="text-[11px] font-medium uppercase tracking-wider text-zinc-500">
            {t('assets.files_label', { defaultValue: 'Pliki' })}
          </div>
          <div className="text-[11.5px] text-zinc-500">
            <span className="num font-semibold text-zinc-600">{assets.length}</span>
          </div>
          {isLoading ? (
            <Loader2 className="size-3.5 animate-spin text-zinc-500" aria-hidden />
          ) : null}
        </div>

        {assets.length === 0 && !isLoading ? (
          <div className="rounded-2xl border border-dashed border-zinc-200 px-6 py-16 text-center text-[13px] text-zinc-500">
            {t('assets.empty')}
          </div>
        ) : view === 'grid' ? (
          <ul
            className="grid grid-cols-[repeat(auto-fill,minmax(140px,1fr))] gap-3"
            aria-label={t('assets.list_title')}
          >
            {assets.map((asset) => (
              <li key={asset.id}>
                <AssetCard
                  asset={asset}
                  selected={selected.has(asset.id)}
                  onToggle={() => toggleSelect(asset.id)}
                  onOpen={() => setDrawerAsset(asset)}
                />
              </li>
            ))}
          </ul>
        ) : (
          <div className="overflow-hidden rounded-2xl border border-zinc-100 bg-white shadow-sm">
            <div className="grid grid-cols-[40px_44px_1fr_110px_90px] gap-3 border-b border-zinc-100 px-4 py-2.5 text-[10.5px] font-medium uppercase tracking-wider text-zinc-500">
              <div />
              <div />
              <div>{t('assets.columns.name', { defaultValue: 'Nazwa' })}</div>
              <div>{t('assets.columns.format', { defaultValue: 'Format' })}</div>
              <div className="text-right">
                {t('assets.columns.size', { defaultValue: 'Rozmiar' })}
              </div>
            </div>
            <div className="divide-y divide-zinc-50">
              {assets.map((asset) => (
                <div
                  key={asset.id}
                  className="grid w-full grid-cols-[40px_44px_1fr_110px_90px] items-center gap-3 px-4 py-2 text-left hover:bg-zinc-50/70"
                >
                  <button
                    type="button"
                    aria-pressed={selected.has(asset.id)}
                    aria-label={asset.filename}
                    onClick={() => toggleSelect(asset.id)}
                    className={cn(
                      'grid h-6 w-6 place-items-center rounded-md',
                      selected.has(asset.id)
                        ? 'bg-zinc-900 text-white'
                        : 'border border-zinc-200 text-transparent hover:text-zinc-300',
                    )}
                  >
                    <Check className="size-3.5" />
                  </button>
                  <span className="block h-9 w-9 overflow-hidden rounded-lg">
                    <AssetThumb asset={asset} iconSize={16} />
                  </span>
                  <button
                    type="button"
                    onClick={() => setDrawerAsset(asset)}
                    className="min-w-0 text-left"
                  >
                    <span className="block truncate text-[12.5px] font-medium hover:underline">
                      {asset.filename}
                    </span>
                    {asset.folder !== null ? (
                      <span className="block font-mono text-[11px] text-zinc-500">
                        {asset.folder}
                      </span>
                    ) : null}
                  </button>
                  <span className="font-mono text-[11.5px] uppercase text-zinc-500">
                    {asset.typeLabel}
                  </span>
                  <span className="num text-right text-[12px] text-zinc-600">
                    {asset.sizeLabel ?? '—'}
                  </span>
                </div>
              ))}
            </div>
          </div>
        )}
      </div>

      <AssetBulkActionsBar
        selectedIds={[...selected]}
        onDeleted={refresh}
        onClearSelection={() => setSelected(new Set())}
      />

      <AssetDrawer asset={drawerAsset} onClose={() => setDrawerAsset(null)} onDeleted={refresh} />

      <AssetUploadModal
        open={uploadOpen}
        onClose={() => setUploadOpen(false)}
        folderCode={currentFolder !== null && currentFolder !== 'root' ? currentFolder : undefined}
        onCompleted={onCompleted}
        onDuplicate={onDuplicate}
      />

      {duplicate ? (
        <AssetDuplicateDialog
          open={duplicate.open}
          existingAssetId={duplicate.existingAssetId}
          existingCode={duplicate.existingCode}
          onOpenChange={(open) => setDuplicate(open ? duplicate : null)}
        />
      ) : null}
    </div>
  );
}

interface AssetCardProps {
  asset: AssetMeta;
  selected: boolean;
  onToggle: () => void;
  onOpen: () => void;
}

function AssetCard({ asset, selected, onToggle, onOpen }: AssetCardProps) {
  return (
    <div
      className={cn(
        'group relative cursor-pointer overflow-hidden rounded-2xl border bg-white shadow-sm transition',
        selected
          ? 'border-zinc-900 ring-1 ring-zinc-900'
          : 'border-transparent hover:border-zinc-200',
      )}
    >
      <button type="button" onClick={onOpen} className="block w-full text-left">
        <div className="relative aspect-square">
          <AssetThumb asset={asset} />
          {asset.thumbnailsPending ? (
            <span className="absolute right-2 top-2 rounded bg-white/90 px-1.5 py-0.5">
              <Loader2 className="size-3 animate-spin text-zinc-500" />
            </span>
          ) : null}
        </div>
        <div className="p-2">
          <div className="truncate text-[11.5px] font-medium" title={asset.filename}>
            {asset.filename}
          </div>
          <div className="mt-0.5 flex items-center gap-1.5 text-[10.5px] text-zinc-500">
            <span className="font-mono uppercase">{asset.ext}</span>
            {asset.sizeLabel !== null ? (
              <>
                <span>·</span>
                <span className="num">{asset.sizeLabel}</span>
              </>
            ) : null}
          </div>
        </div>
      </button>
      <button
        type="button"
        onClick={(e) => {
          e.stopPropagation();
          onToggle();
        }}
        aria-pressed={selected}
        aria-label={asset.filename}
        className={cn(
          'absolute left-2 top-2 grid h-6 w-6 place-items-center rounded-md transition',
          selected
            ? 'bg-zinc-900 text-white'
            : 'border border-zinc-200 bg-white/80 text-transparent group-hover:text-zinc-500',
        )}
      >
        <Check className="size-3.5" />
      </button>
    </div>
  );
}

interface FolderTileProps {
  name: string;
  count: number | null;
  warning?: boolean;
  onOpen: () => void;
}

function FolderTile({ name, count, warning = false, onOpen }: FolderTileProps) {
  const { t } = useTranslation();
  return (
    <button
      type="button"
      onClick={onOpen}
      title={name}
      className="group flex w-[128px] flex-col items-center gap-1 rounded-2xl border border-transparent px-2 pb-2.5 pt-3.5 transition hover:border-zinc-200 hover:bg-white focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900"
    >
      <div className="relative transition-transform group-hover:-translate-y-0.5">
        <FolderGlyph />
        {warning ? (
          <span className="absolute -right-1.5 -top-1.5 grid h-[18px] w-[18px] place-items-center rounded-full bg-amber-500 text-[10px] font-bold text-white ring-2 ring-surface-muted">
            !
          </span>
        ) : null}
      </div>
      <div className="mt-0.5 w-full truncate text-center text-[12.5px] font-medium leading-tight text-zinc-800">
        {name}
      </div>
      <div className="num text-[10.5px] text-zinc-500">
        {count !== null
          ? t('assets.folder.count_short', { defaultValue: '{{count}} elem.', count })
          : ' '}
      </div>
    </button>
  );
}

function FolderGlyph({ size = 58 }: { size?: number }) {
  return (
    <svg width={size} height={size * 0.8} viewBox="0 0 56 45" fill="none" aria-hidden="true">
      <path
        d="M2 7.5A5.5 5.5 0 0 1 7.5 2h12.6a5.5 5.5 0 0 1 3.9 1.6l3.4 3.4h21.1A5.5 5.5 0 0 1 54 12.5V37a6 6 0 0 1-6 6H8a6 6 0 0 1-6-6V7.5Z"
        fill="#e39c33"
      />
      <path
        d="M2 17a5 5 0 0 1 5-5h42a5 5 0 0 1 5 5v20a6 6 0 0 1-6 6H8a6 6 0 0 1-6-6V17Z"
        fill="#f8c963"
      />
      <path
        d="M3.5 17A3.5 3.5 0 0 1 7 13.5h42a3.5 3.5 0 0 1 3.5 3.5"
        stroke="#ffffff"
        strokeOpacity="0.6"
        strokeWidth="1.6"
        strokeLinecap="round"
      />
    </svg>
  );
}

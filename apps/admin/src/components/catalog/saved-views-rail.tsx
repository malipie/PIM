import { Eye, Plus } from 'lucide-react';
import { type KeyboardEvent, useEffect, useRef, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { jsonFetch } from '@/lib/http';
import { cn } from '@/lib/utils';

interface SavedView {
  id: string;
  slug: string;
  name: string;
  description: string | null;
  resource: string;
  config: Record<string, unknown>;
  is_default: boolean;
  is_system: boolean;
}

interface SavedViewsRailProps {
  resource?: string;
  /** GRID-P4-02 — bump to force a refetch after a view is saved. */
  reloadToken?: number;
  activeSlug: string | null;
  onApply: (view: SavedView) => void;
  onSaveCurrent: () => void;
  currentTotal?: number | null;
}

/**
 * VIEW-05 (#411) — pixel-perfect horizontal pill rail replacing the
 * previous SavedViewsDropdown. Mockup ref:
 * `Zrodla/Front_Claude_Design/design_handoff_modelowanie/src/produkty/list-view.jsx`
 * lines 62–79. Active pill is dark, system views show an Eye icon, and
 * the trailing dashed-border button triggers SaveViewModal via
 * `onSaveCurrent`. Per-view counts beyond the active view are deferred
 * to follow-up VIEW-05.7 (a dedicated `/api/saved-views/counts` endpoint
 * would let us avoid N requests).
 */
export function SavedViewsRail({
  resource = 'products',
  activeSlug,
  onApply,
  onSaveCurrent,
  currentTotal = null,
  reloadToken = 0,
}: SavedViewsRailProps) {
  const { t } = useTranslation();
  const [views, setViews] = useState<SavedView[]>([]);
  const [error, setError] = useState<string | null>(null);
  const tabRefs = useRef<Array<HTMLButtonElement | null>>([]);

  useEffect(() => {
    let cancelled = false;
    jsonFetch<{ views?: SavedView[] }>(`/api/saved-views?resource=${encodeURIComponent(resource)}`)
      .then((body) => {
        // Defensive: jsonFetch's generic is a runtime hint, not a guarantee.
        // If the backend ever omits `views` (auth race during refresh, future
        // pagination shape change) the older `setViews(body.views)` left the
        // hook with `views = undefined` and the next `views.map(...)` blew up
        // the whole tree (white screen on /products, 2026-05-12).
        if (!cancelled) {
          setViews(body.views ?? []);
          setError(null);
        }
      })
      .catch((err: unknown) => {
        if (cancelled) return;
        // 2026-05-15 — operator saw `HTTP 200` errors from `jsonFetch`'s
        // white-screen guard (PHP fatal-page disguised as JSON 200 or auth
        // race where the cached response slipped past the JSON content-type
        // check). The saved-views rail is non-critical UX — if the fetch
        // can't return rows, degrade silently to "no views" instead of
        // blocking the catalog page with a red alarm. Log the original
        // failure so DevTools shows the smell for follow-up debugging.
        // eslint-disable-next-line no-console
        console.warn('saved-views fetch failed; degrading to empty list', err);
        setViews([]);
        setError(null);
      });
    return () => {
      cancelled = true;
    };
  }, [resource, reloadToken]);

  const focusTabAt = (index: number): void => {
    const target = tabRefs.current[index];
    if (target !== null && target !== undefined) target.focus();
  };

  const handleKey = (event: KeyboardEvent<HTMLButtonElement>, index: number): void => {
    if (event.key === 'ArrowRight') {
      event.preventDefault();
      focusTabAt(Math.min(index + 1, views.length - 1));
    } else if (event.key === 'ArrowLeft') {
      event.preventDefault();
      focusTabAt(Math.max(index - 1, 0));
    } else if (event.key === 'Home') {
      event.preventDefault();
      focusTabAt(0);
    } else if (event.key === 'End') {
      event.preventDefault();
      focusTabAt(views.length - 1);
    }
  };

  if (error !== null) {
    return (
      <div role="alert" className="text-[12px] text-rose-600">
        {t('products.saved_views.fetch_error', {
          defaultValue: 'Nie udało się pobrać widoków: {{error}}',
          error,
        })}
      </div>
    );
  }

  return (
    <div className="scrollbar-thin -mb-px flex items-center gap-1.5 overflow-x-auto">
      {/* NUI-13 — an empty tablist is zero-size (axe "hidden"); render it
          only when saved views exist. The save-view CTA stays outside. */}
      {views.length > 0 && (
        <div
          role="tablist"
          aria-label={t('products.saved_views.rail_aria', { defaultValue: 'Zapisane widoki' })}
          aria-orientation="horizontal"
          className="flex items-center gap-1.5"
        >
          {views.map((view, index) => {
            const active = view.slug === activeSlug;
            const countLabel =
              active && currentTotal !== null ? currentTotal.toLocaleString('pl-PL') : '—';
            return (
              <button
                key={view.id}
                ref={(el) => {
                  tabRefs.current[index] = el;
                }}
                type="button"
                role="tab"
                aria-selected={active}
                tabIndex={active ? 0 : -1}
                onClick={() => {
                  onApply(view);
                }}
                onKeyDown={(event) => {
                  handleKey(event, index);
                }}
                className={cn(
                  'shrink-0 inline-flex items-center gap-2 h-9 px-3.5 rounded-t-xl text-[13px] font-medium transition border-b-2 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900',
                  active
                    ? 'bg-white border-zinc-900 text-zinc-900 shadow-sm'
                    : 'border-transparent text-zinc-500 hover:text-zinc-900 hover:bg-zinc-50',
                )}
              >
                {view.is_system ? (
                  <Eye
                    className={cn('size-3.5', active ? 'text-zinc-700' : 'text-zinc-500')}
                    aria-label={t('products.saved_views.system_view_aria', {
                      defaultValue: 'Widok systemowy',
                    })}
                  />
                ) : null}
                <span>{view.name}</span>
                <span
                  className={cn(
                    'text-[11px] tabular-nums',
                    active ? 'text-zinc-500' : 'text-zinc-300',
                  )}
                >
                  {countLabel}
                </span>
              </button>
            );
          })}
        </div>
      )}
      <button
        type="button"
        onClick={onSaveCurrent}
        className="shrink-0 inline-flex items-center gap-1.5 h-9 px-3 rounded-t-xl text-[13px] text-zinc-500 hover:text-zinc-900 hover:bg-zinc-50 focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900"
      >
        <Plus className="size-3.5" aria-hidden="true" />
        <span>{t('products.saved_views.save_view', { defaultValue: 'Zapisz widok' })}</span>
      </button>
    </div>
  );
}

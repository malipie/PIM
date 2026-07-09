import { useTranslation } from 'react-i18next';

import { Tooltip, TooltipContent, TooltipProvider, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';

export interface PillTabItem {
  id: string;
  /** Already-translated tab label. */
  label: string;
  /** Optional count rendered in a lighter badge inside the pill. */
  count?: number;
  /** Disabled tab with a "coming soon" tooltip (D3: Cele / Harmonogram). */
  disabled?: boolean;
}

interface PillTabsProps {
  items: PillTabItem[];
  activeId: string;
  onChange: (id: string) => void;
  /** Accessible name of the tablist. */
  ariaLabel?: string;
  className?: string;
}

/**
 * Pill tabs from screen 1: active tab is a navy pill with a lighter count
 * badge; inactive tabs are zinc-500 text; disabled tabs show a "soon"
 * tooltip and are skipped by activation.
 */
export function PillTabs({ items, activeId, onChange, ariaLabel, className }: PillTabsProps) {
  const { t } = useTranslation();
  return (
    <div role="tablist" aria-label={ariaLabel} className={cn('flex items-center gap-1', className)}>
      {items.map((item) => {
        const active = item.id === activeId;
        const tab = (
          <button
            key={item.id}
            type="button"
            role="tab"
            aria-selected={active}
            aria-disabled={item.disabled || undefined}
            tabIndex={active ? 0 : -1}
            onClick={() => {
              if (!item.disabled) {
                onChange(item.id);
              }
            }}
            className={cn(
              'focus-ring inline-flex h-9 items-center gap-1.5 rounded-xl px-3.5 text-[13px] font-medium transition',
              active && 'bg-zinc-900 text-white',
              !active && !item.disabled && 'text-zinc-600 hover:bg-zinc-100 hover:text-ink',
              item.disabled && 'cursor-not-allowed text-zinc-300',
            )}
          >
            {item.label}
            {item.count !== undefined && (
              <span
                className={cn(
                  'num rounded-md px-1.5 py-0.5 font-mono text-[10.5px] font-semibold',
                  active ? 'bg-white/15 text-white' : 'bg-zinc-100 text-zinc-600',
                )}
              >
                {item.count}
              </span>
            )}
            {item.disabled && (
              <span className="rounded bg-zinc-100 px-1.5 py-0.5 text-[9.5px] font-semibold tracking-wider text-zinc-600 uppercase">
                {t('ui_v2.soon')}
              </span>
            )}
          </button>
        );
        if (!item.disabled) {
          return tab;
        }
        return (
          <TooltipProvider key={item.id}>
            <Tooltip>
              <TooltipTrigger asChild>{tab}</TooltipTrigger>
              <TooltipContent>{t('ui_v2.soon_tooltip')}</TooltipContent>
            </Tooltip>
          </TooltipProvider>
        );
      })}
    </div>
  );
}

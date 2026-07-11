import { Rows2, Rows4 } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import type { GridDensity } from '@/lib/grid/use-density';
import { cn } from '@/lib/utils';

/**
 * GRID-P2-03 (#2391) — compact/normal row density for the list views.
 * Same segmented-control shape as ViewModeToggle so the toolbar reads
 * as one family of controls.
 */
export function DensityToggle({
  density,
  onChange,
}: {
  density: GridDensity;
  onChange: (next: GridDensity) => void;
}) {
  const { t } = useTranslation();
  const options: ReadonlyArray<{ value: GridDensity; label: string; Icon: typeof Rows2 }> = [
    {
      value: 'normal',
      label: t('grid.density.normal', { defaultValue: 'Komfort' }),
      Icon: Rows2,
    },
    {
      value: 'compact',
      label: t('grid.density.compact', { defaultValue: 'Kompakt' }),
      Icon: Rows4,
    },
  ];

  return (
    <div
      className="inline-flex h-11 items-center rounded-2xl bg-white p-1 shadow-sm"
      role="tablist"
      aria-label={t('grid.density.aria', { defaultValue: 'Gęstość wierszy' })}
    >
      {options.map(({ value, label, Icon }) => {
        const active = density === value;
        return (
          <button
            key={value}
            type="button"
            role="tab"
            aria-selected={active}
            data-testid={`density-${value}`}
            onClick={() => onChange(value)}
            className={cn(
              'inline-flex h-9 items-center gap-1.5 rounded-xl px-3 text-[12.5px] font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-zinc-900',
              active ? 'bg-zinc-900 text-white' : 'text-zinc-500 hover:text-zinc-700',
            )}
          >
            <Icon className="size-3.5" />
            {label}
          </button>
        );
      })}
    </div>
  );
}

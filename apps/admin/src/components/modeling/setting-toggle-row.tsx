import { Lock } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/utils';

interface SettingToggleRowProps {
  label: string;
  description: string;
  checked: boolean;
  onChange?: (next: boolean) => void;
  locked?: boolean;
}

/**
 * VIEW-01 (#372) — toggle row for Settings card (object-types.jsx
 * lines 282–295). Apple-style switch (h-6 w-11) with smooth slide.
 * Locked variant dims the switch and disables interaction; the lock
 * icon flags it next to the label.
 */
export function SettingToggleRow({
  label,
  description,
  checked,
  onChange,
  locked = false,
}: SettingToggleRowProps) {
  const { t } = useTranslation();
  return (
    <div className="flex items-center justify-between">
      <div>
        <div className="flex items-center gap-2 text-[13.5px] font-medium tracking-tight">
          {label}
          {locked ? (
            <Lock aria-label={t('modeling.built_in_lock.label')} className="size-3 text-zinc-500" />
          ) : null}
        </div>
        <div className="mt-0.5 text-[11.5px] text-zinc-500">{description}</div>
      </div>
      <button
        type="button"
        role="switch"
        aria-label={label}
        aria-checked={checked}
        aria-disabled={locked}
        disabled={locked}
        onClick={() => !locked && onChange?.(!checked)}
        className={cn(
          'relative h-6 w-11 rounded-full transition focus:outline-none focus-visible:ring-2 focus-visible:ring-ring',
          checked ? 'bg-zinc-900' : 'bg-zinc-200',
          locked ? 'cursor-not-allowed opacity-60' : 'cursor-pointer',
        )}
      >
        <span
          className={cn(
            'absolute top-0.5 h-5 w-5 rounded-full bg-white shadow transition-all',
            checked ? 'left-[22px]' : 'left-0.5',
          )}
        />
      </button>
    </div>
  );
}

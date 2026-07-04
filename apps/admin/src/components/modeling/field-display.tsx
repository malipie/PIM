import { Lock, Pencil } from 'lucide-react';
import type { ReactNode } from 'react';
import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/utils';

interface FieldDisplayProps {
  label: ReactNode;
  value: ReactNode;
  mono?: boolean;
  locked?: boolean;
  editable?: boolean;
  onEdit?: () => void;
}

/**
 * VIEW-01 (#372) — read-only / inline-editable field tile
 * (object-types.jsx lines 246–258).
 *
 * Editable + not locked: white bg + zinc-200 border + pencil hover.
 * Locked or non-editable: subtle zinc-50 bg.
 */
export function FieldDisplay({
  label,
  value,
  mono = false,
  locked = false,
  editable = false,
  onEdit,
}: FieldDisplayProps) {
  const { t } = useTranslation();
  return (
    <div>
      <div className="mb-1.5 flex items-center gap-1.5 text-[11.5px] font-medium text-zinc-500">
        <span>{label}</span>
        {locked ? (
          <Lock aria-label={t('modeling.built_in_lock.label')} className="size-3 text-zinc-500" />
        ) : null}
      </div>
      <div
        className={cn(
          'flex h-10 items-center gap-2 rounded-xl border px-3',
          editable && !locked ? 'border-zinc-200 bg-white' : 'border-zinc-100 bg-zinc-50',
        )}
      >
        <span
          className={cn(
            'flex-1 text-[13px]',
            mono ? 'font-mono' : '',
            locked ? 'text-zinc-500' : 'text-zinc-900',
          )}
        >
          {value}
        </span>
        {editable && !locked ? (
          <button
            type="button"
            onClick={onEdit}
            aria-label={t('modeling.field_display.edit')}
            className="text-zinc-300 transition hover:text-zinc-700"
          >
            <Pencil className="size-3.5" />
          </button>
        ) : null}
      </div>
    </div>
  );
}

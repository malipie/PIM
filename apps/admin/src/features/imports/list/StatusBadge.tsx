import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/utils';

export type ImportStatus =
  | 'pending'
  | 'running'
  | 'paused'
  | 'success'
  | 'partial'
  | 'failed'
  | 'cancelled'
  // #2818 — the rollback runs in the worker, so "undoing" is a state the
  // operator sees for minutes, not an instant between two page loads.
  | 'rolling_back'
  | 'rolled_back';

interface StatusBadgeProps {
  status: ImportStatus;
  className?: string;
}

const STATUS_STYLES: Record<ImportStatus, string> = {
  pending: 'bg-muted text-muted-foreground',
  running: 'bg-blue-100 text-blue-900',
  paused: 'bg-amber-100 text-amber-900',
  success: 'bg-green-100 text-green-900',
  partial: 'bg-amber-100 text-amber-900',
  failed: 'bg-red-100 text-red-900',
  cancelled: 'bg-muted text-muted-foreground',
  rolling_back: 'bg-purple-100 text-purple-900',
  rolled_back: 'bg-purple-100 text-purple-900',
};

const STATUS_GLYPHS: Record<ImportStatus, string> = {
  pending: '…',
  running: '🔄',
  paused: '⏸',
  success: '✅',
  partial: '⚠️',
  failed: '❌',
  cancelled: '🛑',
  rolling_back: '🔄',
  rolled_back: '↶',
};

export function StatusBadge({ status, className }: StatusBadgeProps): React.ReactElement {
  const { t } = useTranslation();
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
        STATUS_STYLES[status],
        className,
      )}
    >
      <span aria-hidden="true">{STATUS_GLYPHS[status]}</span>
      <span>{t(`imports.list.status.${status}`)}</span>
    </span>
  );
}

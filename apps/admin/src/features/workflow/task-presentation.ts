/**
 * WFL redesign (#2517) — shared presentation helpers for the workflow
 * surfaces (review queue, task cards in the hub and on the dashboard):
 * kind badges, submitter avatars, relative time and deadline framing.
 */

export type ObjectKind = 'product' | 'category' | 'asset' | string;

/** Route to an object's detail page by kind (sugar paths per kind). */
export function objectHref(kind: ObjectKind, id: string): string {
  if (kind === 'category') return `/categories/${id}`;
  if (kind === 'asset') return `/assets/${id}`;
  return `/products/${id}`;
}

/** Polish default label for a built-in kind (i18n key overrides it). */
export function kindLabelDefault(kind: ObjectKind): string {
  if (kind === 'category') return 'Kategoria';
  if (kind === 'asset') return 'Zasób';
  if (kind === 'product') return 'Produkt';
  return kind;
}

/** Uppercase first letter for an avatar chip. */
export function avatarInitial(name: string | null, fallback = '?'): string {
  const source = name?.trim();
  if (source === undefined || source === '') return fallback;
  return source.slice(0, 1).toUpperCase();
}

/** A deterministic pastel background for a submitter avatar. */
export function avatarTone(seed: string): string {
  const tones = [
    'bg-indigo-100 text-indigo-700',
    'bg-emerald-100 text-emerald-700',
    'bg-amber-100 text-amber-700',
    'bg-rose-100 text-rose-700',
    'bg-sky-100 text-sky-700',
    'bg-violet-100 text-violet-700',
  ];
  let hash = 0;
  for (let i = 0; i < seed.length; i += 1) hash = (hash * 31 + seed.charCodeAt(i)) | 0;
  return tones[Math.abs(hash) % tones.length] ?? 'bg-zinc-100 text-zinc-700';
}

export type WorkflowTaskType = 'review' | 'fix' | 'request_unpublish' | 'custom' | string;

/** Colored badge tone + i18n key/default for a task type. */
export function taskTypeBadge(type: WorkflowTaskType): {
  tone: string;
  key: string;
  fallback: string;
} {
  switch (type) {
    case 'review':
      return {
        tone: 'bg-amber-100 text-amber-700',
        key: 'workflow.tasks.type.review',
        fallback: 'Przegląd',
      };
    case 'fix':
      return {
        tone: 'bg-rose-100 text-rose-700',
        key: 'workflow.tasks.type.fix',
        fallback: 'Poprawka',
      };
    case 'request_unpublish':
      return {
        tone: 'bg-sky-100 text-sky-700',
        key: 'workflow.tasks.type.request_unpublish',
        fallback: 'Prośba o depublikację',
      };
    default:
      return {
        tone: 'bg-violet-100 text-violet-700',
        key: 'workflow.tasks.type.custom',
        fallback: 'Własne',
      };
  }
}

export interface DeadlineFraming {
  overdue: boolean;
  key: string;
  fallback: string;
  date?: string;
}

/** Frame a due date as dziś / jutro / po terminie / a concrete date. */
export function deadlineFraming(dueDate: string | null, lang: string): DeadlineFraming | null {
  if (dueDate === null) return null;
  const today = new Date();
  today.setHours(0, 0, 0, 0);
  const due = new Date(`${dueDate}T00:00:00`);
  const diffDays = Math.round((due.getTime() - today.getTime()) / 86_400_000);
  const shortDate = new Intl.DateTimeFormat(lang, { day: '2-digit', month: '2-digit' }).format(due);

  if (diffDays < 0) {
    return {
      overdue: true,
      key: 'workflow.tasks.overdue',
      fallback: 'Po terminie: {{date}}',
      date: shortDate,
    };
  }
  if (diffDays === 0) {
    return { overdue: false, key: 'workflow.tasks.due_today', fallback: 'Termin: dziś' };
  }
  if (diffDays === 1) {
    return { overdue: false, key: 'workflow.tasks.due_tomorrow', fallback: 'Termin: jutro' };
  }
  return {
    overdue: false,
    key: 'workflow.tasks.due_on',
    fallback: 'Termin: {{date}}',
    date: shortDate,
  };
}

/** "2 godz. temu" / "wczoraj" style relative time from an ISO timestamp. */
export function relativeTime(iso: string, lang: string): string {
  const then = new Date(iso).getTime();
  if (Number.isNaN(then)) return '';
  const diffMs = Date.now() - then;
  const minutes = Math.round(diffMs / 60_000);
  const rtf = new Intl.RelativeTimeFormat(lang, { numeric: 'auto' });
  if (minutes < 60) return rtf.format(-minutes, 'minute');
  const hours = Math.round(minutes / 60);
  if (hours < 24) return rtf.format(-hours, 'hour');
  const days = Math.round(hours / 24);
  return rtf.format(-days, 'day');
}

import type { ReactNode } from 'react';

interface ModelingPageHeaderProps {
  /** Small caption above the heading, e.g. "7 typów obiektów". */
  caption: string;
  /** Display heading, e.g. "Object Types". */
  title: string;
  /** Long description paragraph beneath the title. */
  description: ReactNode;
  /** Slot rendered beneath the description (e.g. extra controls). */
  trailing?: ReactNode;
}

/**
 * UI-03c — shared header for the Modelowanie sub-tabs.
 *
 * Mirrors the handoff `Project Plan/UI/Wdrozenie_grafiki/...` and the
 * Object Types reference shot in `docs/Tests/UI/Modelowanie/`. Caption,
 * display heading and long description make up the consistent shell every
 * sub-tab gets. The primary "+ Nowy ___" CTA moved to the topbar action
 * slot (#2671) — pages register it via `usePageActions`.
 */
export function ModelingPageHeader({
  caption,
  title,
  description,
  trailing,
}: ModelingPageHeaderProps) {
  return (
    <header className="space-y-3">
      <p className="text-[12px] font-medium uppercase tracking-wider text-muted-foreground">
        {caption}
      </p>
      <h1 className="display text-[32px] font-semibold leading-tight text-ink">{title}</h1>
      <div className="max-w-3xl text-[14px] leading-relaxed text-ink-2">{description}</div>
      {trailing ? <div className="flex flex-wrap items-center gap-3 pt-1">{trailing}</div> : null}
    </header>
  );
}

import type { ReactNode } from 'react';

interface ModelingPageHeaderProps {
  /** Display heading, e.g. "Object Types". */
  title: string;
  /** Optional slot rendered beneath the title (e.g. extra controls). */
  trailing?: ReactNode;
}

/**
 * UI-03c — shared header for the Modelowanie sub-tabs. #2671 aligned it with
 * the v2 hub chrome (Connections hub): a single 22px display heading — the
 * caption/count, long description and the primary CTA are gone (the CTA moved
 * to the topbar action slot via `usePageActions`).
 */
export function ModelingPageHeader({ title, trailing }: ModelingPageHeaderProps) {
  return (
    <header className="space-y-3">
      <h1 className="font-display text-[22px] font-semibold tracking-tight">{title}</h1>
      {trailing ? <div className="flex flex-wrap items-center gap-3">{trailing}</div> : null}
    </header>
  );
}

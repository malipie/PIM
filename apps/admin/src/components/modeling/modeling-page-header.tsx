import { Plus } from 'lucide-react';
import type { ReactNode } from 'react';
import { Link } from 'react-router';

const CTA_CLASSES =
  'focus-ring inline-flex h-9 items-center gap-1.5 rounded-xl bg-cta px-3.5 text-[13px] font-semibold text-cta-foreground transition hover:bg-accent-hover disabled:pointer-events-none disabled:opacity-50';

interface ModelingPageHeaderProps {
  /** Small caption above the heading, e.g. "7 typów obiektów". */
  caption: string;
  /** Display heading, e.g. "Object Types". */
  title: string;
  /** Long description paragraph beneath the title. */
  description: ReactNode;
  /** Primary CTA label. */
  ctaLabel: string;
  /** Click handler for the CTA. */
  onCtaClick?: () => void;
  /** Optional `to` for the CTA — renders the button as a link. Mutually exclusive with onCtaClick. */
  ctaTo?: string;
  /** Slot rendered to the right of the CTA (e.g. extra controls). */
  trailing?: ReactNode;
}

/**
 * UI-03c — shared header for the four Modelowanie sub-tabs.
 *
 * Mirrors the handoff `Project Plan/UI/Wdrozenie_grafiki/...` and the
 * Object Types reference shot in `docs/Tests/UI/Modelowanie/`. Caption,
 * display heading, long description and a primary "+ Nowy ___" CTA make
 * up the consistent shell every sub-tab gets.
 */
export function ModelingPageHeader({
  caption,
  title,
  description,
  ctaLabel,
  onCtaClick,
  ctaTo,
  trailing,
}: ModelingPageHeaderProps) {
  const ctaButton = ctaTo ? (
    <Link to={ctaTo} className={CTA_CLASSES}>
      <Plus className="size-4" aria-hidden />
      {ctaLabel}
    </Link>
  ) : (
    <button type="button" onClick={onCtaClick} disabled={!onCtaClick} className={CTA_CLASSES}>
      <Plus className="size-4" aria-hidden />
      {ctaLabel}
    </button>
  );

  return (
    <header className="space-y-3">
      <p className="text-[12px] font-medium uppercase tracking-wider text-muted-foreground">
        {caption}
      </p>
      <h1 className="display text-[32px] font-semibold leading-tight text-ink">{title}</h1>
      <div className="max-w-3xl text-[14px] leading-relaxed text-ink-2">{description}</div>
      <div className="flex flex-wrap items-center gap-3 pt-1">
        {ctaButton}
        {trailing}
      </div>
    </header>
  );
}

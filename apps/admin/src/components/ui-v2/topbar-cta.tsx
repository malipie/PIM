import { Plus } from 'lucide-react';
import type { ReactNode } from 'react';
import { Link } from 'react-router';

const CTA_CLASSES =
  'focus-ring inline-flex h-9 items-center gap-1.5 rounded-xl bg-cta px-3.5 text-[13px] font-semibold text-cta-foreground transition hover:bg-accent-hover';

interface TopbarCtaProps {
  /** Navigation target — renders a Link. Mutually exclusive with onClick. */
  to?: string;
  /** Click handler — renders a button (e.g. opens a modal). */
  onClick?: () => void;
  /** Already-translated CTA label. */
  children: ReactNode;
}

/**
 * #2671 — the standard orange "+ …" create CTA registered into the topbar
 * action slot via `usePageActions` (same styling as the Exports hub CTA).
 * Keeps the six Modeling sub-tabs from copy-pasting the class string.
 */
export function TopbarCta({ to, onClick, children }: TopbarCtaProps) {
  if (to) {
    return (
      <Link to={to} className={CTA_CLASSES}>
        <Plus className="size-4" aria-hidden />
        {children}
      </Link>
    );
  }
  return (
    <button type="button" onClick={onClick} className={CTA_CLASSES}>
      <Plus className="size-4" aria-hidden />
      {children}
    </button>
  );
}

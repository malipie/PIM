import { cn } from '@/lib/utils';

/**
 * "harmon" brand sygnet — four bars where the orange element is the point of
 * harmony. Geometry taken verbatim from the design system (Design System.html,
 * "Lockup podstawowy · poziomy"). Navy bars follow `currentColor`; the accent
 * bar stays brand orange (orange-500 = #ff4f00 in the v2 palette).
 */
export function BrandSygnet({ className }: { className?: string }): React.JSX.Element {
  return (
    <svg
      viewBox="7.5 6 49.5 52"
      className={cn('block text-zinc-950', className)}
      aria-hidden="true"
    >
      <rect x="7.5" y="6" width="9" height="52" rx="4.5" fill="currentColor" />
      <rect x="21" y="24" width="9" height="19" rx="4.5" fill="currentColor" />
      <rect x="34.5" y="24" width="9" height="13" rx="4.5" className="fill-orange-500" />
      <rect x="48" y="24" width="9" height="34" rx="4.5" fill="currentColor" />
    </svg>
  );
}

/**
 * Detachable "PIM" descriptor badge (orange chip) that rides after the wordmark
 * in the primary lockup.
 */
export function BrandBadge({ className }: { className?: string }): React.JSX.Element {
  return (
    <span
      className={cn(
        'inline-flex items-center rounded-md bg-orange-500 font-extrabold text-white',
        className,
      )}
      style={{ letterSpacing: '0.18em', padding: '3px 5px 3px 7px' }}
    >
      PIM
    </span>
  );
}

/**
 * Primary horizontal "harmon PIM" lockup — sygnet + Manrope wordmark + PIM
 * badge. When `subtitle` is provided (e.g. the tenant/workspace name) it renders
 * beneath the wordmark, keeping the sygnet as a leading anchor.
 */
export function BrandLogo({
  subtitle,
  className,
}: {
  subtitle?: string;
  className?: string;
}): React.JSX.Element {
  return (
    <div className={cn('flex items-center gap-2.5', className)}>
      <BrandSygnet className="h-7 w-[26.65px] shrink-0" />
      <div className="min-w-0 leading-tight">
        <span className="flex items-center gap-1.5">
          <span
            className="text-[19px] font-bold tracking-[0.025em] text-zinc-950"
            style={{ fontFamily: "'Manrope', var(--font-sans)" }}
          >
            harmon
          </span>
          <BrandBadge className="text-[9px]" />
        </span>
        {subtitle ? (
          <span className="block truncate text-[11px] text-zinc-500">{subtitle}</span>
        ) : null}
      </div>
    </div>
  );
}

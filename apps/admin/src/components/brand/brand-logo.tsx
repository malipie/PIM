import { cn } from '@/lib/utils';

/**
 * "harmon PIM" primary horizontal lockup — the exact logo from the design
 * system ("Lockup podstawowy · poziomy"), shipped as a single raster asset
 * (public/brand-logo.png) so it renders 1:1 regardless of loaded page fonts.
 */
export function BrandLogo({ className }: { className?: string }): React.JSX.Element {
  return (
    <img
      src="/brand-logo.png"
      alt="harmon PIM"
      className={cn('h-8 w-auto max-w-full select-none', className)}
      draggable={false}
    />
  );
}

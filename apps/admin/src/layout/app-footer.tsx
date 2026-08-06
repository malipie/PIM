/**
 * UI-03c — application footer rendered below the main outlet.
 *
 * Shows the real app version injected at build time from package.json
 * (`__APP_VERSION__`, see vite.config.ts). The mock schema-rev segment and
 * the workspace / ADR segment (#2624) are gone — the footer never fabricates
 * data it cannot back.
 */
export function AppFooter() {
  return (
    <footer className="border-t border-line/60 bg-background px-6 py-3 text-[11px] text-muted-foreground">
      <div className="flex flex-wrap items-center justify-end gap-2">
        <span className="num">{`v${__APP_VERSION__}`}</span>
      </div>
    </footer>
  );
}

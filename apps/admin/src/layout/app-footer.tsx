import { useTranslation } from 'react-i18next';

/**
 * UI-03c — application footer rendered below the main outlet.
 *
 * App version + model schema rev on the right; both MOCK until backend
 * endpoints ship for app version and schema_revision. The workspace / ADR
 * segment was removed in #2624 (mock with no backing ADR registry).
 */
export function AppFooter() {
  const { t } = useTranslation();

  return (
    <footer className="border-t border-line/60 bg-background px-6 py-3 text-[11px] text-muted-foreground">
      <div className="flex flex-wrap items-center justify-end gap-2">
        <span className="num">
          {t('footer.version', { defaultValue: 'v1.0.0-rc.4' })}
          <span aria-hidden> · </span>
          {t('footer.schema_rev', { defaultValue: 'model schema rev 47' })}
        </span>
      </div>
    </footer>
  );
}

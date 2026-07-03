import { useTranslation } from 'react-i18next';

/**
 * VIEW-13 (#2143) — two-tone page headline above the agent hero. The
 * "Dzień dobry, {name}" hello line from the original mock was dropped per
 * operator decision (2026-07-03).
 */
export function DashboardGreeting() {
  const { t } = useTranslation();

  return (
    <h1 className="display text-[32px] font-semibold leading-tight text-ink sm:text-[38px]">
      {t('dashboard.greeting.title_main', { defaultValue: 'Centrum dowodzenia katalogiem.' })}{' '}
      <span className="text-ink-2/80">
        {t('dashboard.greeting.title_accent', { defaultValue: 'Co dziś chcesz zmienić?' })}
      </span>
    </h1>
  );
}

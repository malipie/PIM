import { useTranslation } from 'react-i18next';

import { GREETING_NAME } from '../mocks';

/**
 * VIEW-13 (#2143) — greeting block above the agent hero: small hello line
 * plus the two-tone page headline. The first name is mock data until the
 * greeting reads the session identity (backend follow-up).
 */
export function DashboardGreeting() {
  const { t } = useTranslation();

  return (
    <div>
      <p className="text-[14px] text-ink-2">
        {t('dashboard.greeting.hello', {
          defaultValue: 'Dzień dobry, {{name}} 👋',
          name: GREETING_NAME,
        })}
      </p>
      <h1 className="display mt-1 text-[32px] font-semibold leading-tight text-ink sm:text-[38px]">
        {t('dashboard.greeting.title_main', { defaultValue: 'Centrum dowodzenia katalogiem.' })}{' '}
        <span className="text-ink-2/80">
          {t('dashboard.greeting.title_accent', { defaultValue: 'Co dziś chcesz zmienić?' })}
        </span>
      </h1>
    </div>
  );
}

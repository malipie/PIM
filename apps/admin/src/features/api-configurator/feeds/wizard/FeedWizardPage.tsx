import { useTranslation } from 'react-i18next';
import { Link } from 'react-router';

/**
 * XMLF-P5-01 — route placeholder for the 5-step feed wizard (XMLF-P5-02..04).
 * The hub's "New feed" CTA needs a stable target today; the wizard shell,
 * mapper and delivery steps replace this screen in their own tickets.
 */
export function FeedWizardPage() {
  const { t } = useTranslation();
  return (
    <div className="rounded-3xl bg-white p-10 text-center soft-shadow">
      <h1 className="font-display text-[18px] font-semibold tracking-tight">
        {t('api_configurator.feeds.wizard.placeholder_title')}
      </h1>
      <p className="mt-2 text-[13px] text-zinc-500">
        {t('api_configurator.feeds.wizard.placeholder_hint')}
      </p>
      <Link
        to="/integrations/api-configurator/feeds"
        className="mt-6 inline-flex h-9 items-center rounded-lg bg-zinc-900 px-4 text-[13px] font-medium text-white hover:bg-zinc-800"
      >
        {t('api_configurator.feeds.wizard.back_to_hub')}
      </Link>
    </div>
  );
}

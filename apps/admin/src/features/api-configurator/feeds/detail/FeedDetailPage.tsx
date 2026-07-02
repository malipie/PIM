import { useTranslation } from 'react-i18next';
import { Link, useParams } from 'react-router';

/**
 * XMLF-P5-01 — route placeholder for the feed detail / monitor screen
 * (XMLF-P5-05). Cards deep-link here today; the pipeline view, run history
 * and drill-down land in their own ticket.
 */
export function FeedDetailPage() {
  const { t } = useTranslation();
  const { id } = useParams();
  return (
    <div className="rounded-3xl bg-white p-10 text-center soft-shadow">
      <h1 className="font-display text-[18px] font-semibold tracking-tight">
        {t('api_configurator.feeds.detail.placeholder_title')}
      </h1>
      <p className="mt-2 font-mono text-[12px] text-zinc-500">{id}</p>
      <p className="mt-2 text-[13px] text-zinc-500">
        {t('api_configurator.feeds.detail.placeholder_hint')}
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

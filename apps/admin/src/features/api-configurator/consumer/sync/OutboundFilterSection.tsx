import { Send } from 'lucide-react';
import { useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';

import { AdvancedFilterPanel } from '@/components/catalog/advanced-filter-panel';
import { Button } from '@/components/ui/button';
import {
  conditionsToDsl,
  dslToFlatConditions,
  type FilterCondition,
  type FilterDsl,
} from '@/lib/filters/filter-dsl';

import { SectionLabel } from '../../components/primitives';

/**
 * #2549 — the "Z PIM (wysyłka)" outbound scope for a SyncBinding. Rendered only
 * for a write flow (SyncConfigScreen gates it on `dir !== 'inbound'`), so a
 * PIM-side filter can never be set on an import. Reuses the same
 * {@link AdvancedFilterPanel} (FilterDsl) as the grid / export / feeds, so the
 * operator builds `status = published AND kategoria = X` visually. The parent
 * owns the persisted DSL; this component edits it and reports changes back.
 */
export function OutboundFilterSection({
  dsl,
  onDslChange,
}: {
  dsl: FilterDsl | null;
  onDslChange: (dsl: FilterDsl | null) => void;
}) {
  const { t } = useTranslation();
  const [conditions, setConditions] = useState<FilterCondition[]>([]);
  const [matchOperator, setMatchOperator] = useState<'AND' | 'OR'>('AND');
  const [open, setOpen] = useState(false);

  // Hydrate the flat editor from the stored DSL. A nested (AND/OR-of-groups)
  // DSL can't render flat; fall back to an empty editor (the stored filter
  // still applies on the backend until re-saved).
  useEffect(() => {
    setConditions(dslToFlatConditions(dsl) ?? []);
    setMatchOperator(
      dsl !== null && typeof dsl === 'object' && 'operator' in dsl && dsl.operator === 'OR'
        ? 'OR'
        : 'AND',
    );
  }, [dsl]);

  const commit = (next: FilterCondition[], operator: 'AND' | 'OR'): void => {
    setConditions(next);
    setMatchOperator(operator);
    onDslChange(conditionsToDsl(next, operator));
  };

  return (
    <section
      className="soft-shadow rounded-2xl border border-zinc-200 bg-white p-5"
      data-testid="outbound-filter-section"
    >
      <SectionLabel
        right={
          <span className="inline-flex items-center gap-1 text-[11px] font-medium text-zinc-500">
            <Send className="size-3.5" aria-hidden="true" />
            {t('api_configurator.sync.outbound_filter.tag', { defaultValue: 'Z PIM' })}
          </span>
        }
      >
        {t('api_configurator.sync.outbound_filter.title', {
          defaultValue: 'Zakres wysyłki — które obiekty wysyłać',
        })}
      </SectionLabel>
      <p className="mb-3 text-[12.5px] leading-relaxed text-zinc-600">
        {t('api_configurator.sync.outbound_filter.hint', {
          defaultValue:
            'Filtr dotyczy wyłącznie danych wychodzących z PIM. Pusty = wysyłamy wszystkie obiekty tego typu.',
        })}
      </p>
      <div className="flex flex-wrap items-center gap-3">
        <Button
          type="button"
          variant="outline"
          size="sm"
          onClick={() => setOpen((prev) => !prev)}
          data-testid="outbound-filter-toggle"
        >
          {conditions.length === 0
            ? t('api_configurator.sync.outbound_filter.set', {
                defaultValue: 'Ustaw filtr wysyłki',
              })
            : t('api_configurator.sync.outbound_filter.edit', {
                defaultValue: 'Edytuj filtr wysyłki',
              })}
        </Button>
        <span className="text-[12px] text-zinc-500" data-testid="outbound-filter-summary">
          {conditions.length === 0
            ? t('api_configurator.sync.outbound_filter.none', {
                defaultValue: 'Bez filtra — wszystkie obiekty',
              })
            : t('api_configurator.sync.outbound_filter.count', {
                defaultValue: '{{count}} warunków',
                count: conditions.length,
              })}
        </span>
      </div>
      <div className="relative mt-3">
        <AdvancedFilterPanel
          open={open}
          conditions={conditions}
          setConditions={(next) => commit(next, matchOperator)}
          matchOperator={matchOperator}
          setMatchOperator={(operator) => commit(conditions, operator)}
          onApply={() => setOpen(false)}
          onClose={() => setOpen(false)}
          onClear={() => {
            commit([], 'AND');
            setOpen(false);
          }}
        />
      </div>
    </section>
  );
}

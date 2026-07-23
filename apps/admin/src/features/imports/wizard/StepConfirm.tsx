import type * as React from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { BackupTriggerCheckbox } from '@/features/imports/components/BackupTriggerCheckbox';
import {
  isStructuralImportKind,
  type useImportWizard,
} from '@/features/imports/hooks/useImportWizard';

type BackupStatus = 'idle' | 'pending' | 'running' | 'completed' | 'failed';

interface StepConfirmProps {
  wizard: ReturnType<typeof useImportWizard>;
  /**
   * #2680 — the "Uruchom import" CTA lives in the topbar now, so backup
   * status/id bubble up to the page (which owns the submit + run gating).
   */
  onBackupStatusChange: (status: BackupStatus) => void;
  onBackupCreated: (id: string | null) => void;
  /** Submit error surfaced from the topbar run action. */
  submitError: string | null;
}

/**
 * Spec §5.5 — Step 4 confirm. Renders the summary card, optional pgBackRest
 * trigger (IMP-06) and email notification toggle. The run CTA moved to the
 * persistent topbar (#2680); the backup checkbox reports its status upward so
 * the topbar can gate "Uruchom import" on a completed snapshot.
 */
export function StepConfirmPlaceholder({
  wizard,
  onBackupStatusChange,
  onBackupCreated,
  submitError,
}: StepConfirmProps): React.ReactElement {
  const { t } = useTranslation();
  const { state, setField } = wizard;

  const structural = isStructuralImportKind(state.entityType);

  return (
    <div className="space-y-6 rounded-md border bg-card p-6">
      <header>
        <h2 className="text-lg font-semibold">
          {t('imports.confirm.summary', { defaultValue: 'Podsumowanie' })}
        </h2>
      </header>

      <Card className="space-y-2 p-4 text-sm">
        <SummaryRow label={t('imports.wizard.confirm.file')} value={state.file?.name ?? '—'} />
        <SummaryRow label="Encoding" value={state.encoding} />
        <SummaryRow label="Delimiter" value={state.delimiter} />
        {structural ? (
          <SummaryRow
            label={t('imports.wizard.confirm.type')}
            value={
              state.entityType === 'attribute_groups'
                ? t('imports.wizard.confirm.type_attribute_groups')
                : t('imports.wizard.confirm.type_attributes')
            }
          />
        ) : (
          <>
            <SummaryRow label="Locale" value={state.locale ?? 'auto'} />
            <SummaryRow
              label={t('imports.wizard.confirm.mapping')}
              value={t('imports.wizard.confirm.mapping_columns', {
                count: Object.keys(state.mapping).length,
              })}
            />
            <SummaryRow label={t('imports.wizard.confirm.images')} value={state.imageSource} />
            {state.validation !== null && (
              <SummaryRow
                label={t('imports.wizard.confirm.to_import')}
                value={t('imports.wizard.confirm.to_import_value', {
                  ok: state.validation.successCount,
                  skipped: state.validation.errorCount,
                })}
              />
            )}
          </>
        )}
      </Card>

      {structural ? (
        <p className="rounded-md border border-sky-500/40 bg-sky-50 px-3 py-2 text-xs">
          {t('imports.confirm.structural_hint', {
            defaultValue:
              'Nowe i zmienione definicje trafią do panelu (Modelowanie → Atrybuty / Grupy atrybutów). Przypisania do typów obiektów odtworzą się z kolumny object_types. Istniejące rekordy są aktualizowane po kodzie.',
          })}
        </p>
      ) : (
        <BackupTriggerCheckbox
          checked={state.doBackup}
          onChange={(next) => setField('doBackup', next)}
          onStatusChange={onBackupStatusChange}
          onBackupCreated={onBackupCreated}
        />
      )}

      <label className="flex items-center gap-2 text-sm">
        <input
          type="checkbox"
          checked={state.emailNotification}
          onChange={(event) => setField('emailNotification', event.target.checked)}
        />
        <span>
          {t('imports.confirm.email', {
            defaultValue: 'Wyślij email po zakończeniu (>5 min runtime)',
          })}
        </span>
      </label>

      {!structural && (
        <p className="rounded-md border border-amber-500/40 bg-amber-50 px-3 py-2 text-xs">
          ⚠️{' '}
          {t('imports.confirm.warning', {
            defaultValue: 'Akcja jest finalna. Możesz wycofać import w 24h.',
          })}
        </p>
      )}

      {submitError !== null && (
        <p role="alert" className="text-sm text-destructive">
          {submitError}
        </p>
      )}

      {/* The run CTA moved to the topbar (#2680); Wstecz stays because the
          import stepper is not clickable, so this is the only way back. */}
      <div className="flex justify-between">
        <Button variant="ghost" onClick={() => wizard.back()}>
          ← {t('imports.wizard.back', { defaultValue: 'Wstecz' })}
        </Button>
      </div>
    </div>
  );
}

function SummaryRow({ label, value }: { label: string; value: string }): React.ReactElement {
  return (
    <div className="flex items-center justify-between gap-4">
      <span className="text-muted-foreground">{label}</span>
      <span className="font-mono text-xs">{value}</span>
    </div>
  );
}

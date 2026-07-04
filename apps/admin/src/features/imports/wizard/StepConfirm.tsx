import * as React from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { BackupTriggerCheckbox } from '@/features/imports/components/BackupTriggerCheckbox';
import {
  isStructuralImportKind,
  type useImportWizard,
} from '@/features/imports/hooks/useImportWizard';
import { HttpError, jsonFetch } from '@/lib/http';

interface StepConfirmProps {
  wizard: ReturnType<typeof useImportWizard>;
}

/**
 * Spec §5.5 — Step 4 confirm. Renders the summary card, optional
 * pgBackRest trigger (IMP-06), email notification toggle, and the
 * "Uruchom import" CTA. The CTA is gated on the backup being either
 * `idle` (operator opted out) or `completed` so the backend always
 * runs on a snapshot when the user asked for one.
 */
export function StepConfirmPlaceholder({ wizard }: StepConfirmProps): React.ReactElement {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { state, setField } = wizard;

  const [backupStatus, setBackupStatus] = React.useState<
    'idle' | 'pending' | 'running' | 'completed' | 'failed'
  >('idle');
  // IMP2-2.10 (#1486) — id of the pre-import backup, forwarded to the start
  // request so the session records which snapshot preceded it.
  const [backupId, setBackupId] = React.useState<string | null>(null);
  const [submitting, setSubmitting] = React.useState(false);
  const [submitError, setSubmitError] = React.useState<string | null>(null);

  const structural = isStructuralImportKind(state.entityType);
  const fileReady = state.file !== null || state.stagedFileId !== null;

  const canRun = structural
    ? fileReady && !submitting
    : fileReady &&
      state.targetObjectTypeId !== null &&
      !submitting &&
      (state.doBackup === false || backupStatus === 'completed' || backupStatus === 'idle');

  const runStructural = (): void => {
    const kind = state.entityType === 'attribute_groups' ? 'attribute_groups' : 'attributes';
    const formData = new FormData();
    formData.set('structural_kind', kind);
    if (state.stagedFileId !== null) {
      formData.set('staged_file_id', state.stagedFileId);
    } else if (state.file !== null) {
      formData.set('file', state.file);
    }

    jsonFetch<{ id: string }>('/api/structural-import-sessions', {
      method: 'POST',
      body: formData,
    })
      .then((data) => {
        wizard.reset();
        navigate(`/integrations/imports/${data.id}`);
      })
      .catch((err: unknown) => {
        setSubmitError(err instanceof HttpError ? `HTTP ${err.status}` : 'unknown');
        setSubmitting(false);
      });
  };

  const handleRun = (): void => {
    if (!fileReady) {
      return;
    }
    if (structural) {
      setSubmitting(true);
      setSubmitError(null);
      runStructural();
      return;
    }
    if (state.targetObjectTypeId === null) {
      return;
    }
    setSubmitting(true);
    setSubmitError(null);

    const formData = new FormData();
    // IMP2-2.2 — reuse the file staged at parse-preview; fall back to the raw
    // File only when no staged id is present (e.g. after a page round-trip).
    if (state.stagedFileId !== null) {
      formData.set('staged_file_id', state.stagedFileId);
    } else if (state.file !== null) {
      formData.set('file', state.file);
    }
    formData.set('target_object_type_id', state.targetObjectTypeId);
    formData.set('mapping', JSON.stringify(state.mapping));
    formData.set('encoding', state.encoding);
    formData.set('delimiter', state.delimiter);
    formData.set('do_backup', state.doBackup ? '1' : '0');
    // IMP2-2.10 (#1486) — when a backup was requested, the CTA only enables
    // once it is `completed`, so backupId is set here; forward it so the
    // backend links the snapshot to the session.
    if (state.doBackup && backupId !== null) {
      formData.set('backup_id', backupId);
    }
    formData.set('mode', state.mode);
    // #1718 — opt-in: mint missing select/multiselect options during the run.
    formData.set('create_missing_options', state.createMissingOptions ? '1' : '0');
    // IMP2-1.13 — image source + optional ZIP of images (was never sent before).
    formData.set('image_source', state.imageSource);
    if (state.imageSource === 'zip' && state.zipFile) {
      formData.append('zip_file', state.zipFile);
    }

    jsonFetch<{ id: string }>('/api/import-sessions', {
      method: 'POST',
      body: formData,
    })
      .then((data) => {
        wizard.reset();
        navigate(`/integrations/imports/${data.id}`);
      })
      .catch((err: unknown) => {
        if (err instanceof HttpError) {
          setSubmitError(`HTTP ${err.status}`);
        } else {
          setSubmitError(err instanceof Error ? err.message : 'unknown');
        }
        setSubmitting(false);
      });
  };

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
          onStatusChange={setBackupStatus}
          onBackupCreated={setBackupId}
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

      <div className="flex justify-between">
        <Button variant="ghost" onClick={() => wizard.back()}>
          ← {t('imports.wizard.back', { defaultValue: 'Wstecz' })}
        </Button>
        <Button onClick={handleRun} disabled={!canRun}>
          ▶ {t('imports.wizard.run', { defaultValue: 'Uruchom import' })}
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

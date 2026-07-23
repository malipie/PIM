import { Play } from 'lucide-react';
import { type ReactElement, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';

import { Button } from '@/components/ui/button';
import { isStructuralImportKind, useImportWizard } from '@/features/imports/hooks/useImportWizard';
import { HttpError, jsonFetch } from '@/lib/http';

import { StepConfirmPlaceholder } from './StepConfirm';
import { StepDetect } from './StepDetect';
import { StepEntityType } from './StepEntityType';
import { StepMapping } from './StepMapping';
import { StepRules } from './StepRules';
import { StepSource } from './StepSource';
import { StepValidationPlaceholder } from './StepValidation';
import { type WizardStep, WizardStepper } from './WizardStepper';

/**
 * VIEW-IMP-05 (#504) → NUI-10 (#1429) — wizard host, now six steps per
 * the Import-nowy.html design: Źródło → Wykrywanie → Mapowanie → Reguły
 * → Podgląd → Start. Endpoints and payloads are identical to the 4-step
 * flow; Detect/Rules surface existing parse-preview data and the target
 * rules scope (mocked controls carry MockBadges).
 */
export function ImportWizardPage(): ReactElement {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const wizard = useImportWizard();
  const { state } = wizard;
  const structural = isStructuralImportKind(state.entityType);

  // #2680 — the "Uruchom import" CTA moved to the persistent topbar, so its
  // submit + backup gating is lifted here from StepConfirm (the backup
  // checkbox stays in the step and reports its status up via callbacks).
  const [backupStatus, setBackupStatus] = useState<
    'idle' | 'pending' | 'running' | 'completed' | 'failed'
  >('idle');
  const [backupId, setBackupId] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState(false);
  const [submitError, setSubmitError] = useState<string | null>(null);

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

  const allSteps: ReadonlyArray<WizardStep> = [
    {
      id: 'entity',
      label: t('imports.wizard.steps.entity', { defaultValue: 'Dane' }),
      description: t('imports.wizard.descriptions.entity', {
        defaultValue: 'co importujesz',
      }),
    },
    {
      id: 'source',
      label: t('imports.wizard.steps.source', { defaultValue: 'Źródło' }),
      description: t('imports.wizard.descriptions.source', {
        defaultValue: 'skąd plik · CSV / XLSX / ZIP',
      }),
    },
    {
      id: 'detect',
      label: t('imports.wizard.steps.detect', { defaultValue: 'Wykrywanie' }),
      description: t('imports.wizard.descriptions.detect', {
        defaultValue: 'encoding / separator / arkusz',
      }),
    },
    {
      id: 'mapping',
      label: t('imports.wizard.steps.mapping', { defaultValue: 'Mapowanie' }),
      description: t('imports.wizard.descriptions.mapping', {
        defaultValue: 'kolumny → atrybuty + kategorie',
      }),
    },
    {
      id: 'rules',
      label: t('imports.wizard.steps.rules', { defaultValue: 'Reguły' }),
      description: t('imports.wizard.descriptions.rules', {
        defaultValue: 'tryb · walidacja',
      }),
    },
    {
      id: 'validation',
      label: t('imports.wizard.steps.validation', { defaultValue: 'Podgląd' }),
      description: t('imports.wizard.descriptions.validation', {
        defaultValue: 'dry-run, błędy walidacji',
      }),
    },
    {
      id: 'confirm',
      label: t('imports.wizard.steps.confirm', { defaultValue: 'Start' }),
      description: t('imports.wizard.descriptions.confirm', {
        defaultValue: 'backup + commit do bazy',
      }),
    },
  ];

  // Structural imports (attribute / attribute-group definitions) run a
  // simplified 4-step flow — mapping, rules and dry-run are meaningless for a
  // fixed-schema config import (the columns are the export's own headers).
  const steps: ReadonlyArray<WizardStep> = structural
    ? allSteps.filter((step) => !['mapping', 'rules', 'validation'].includes(step.id))
    : allSteps;
  const currentId = steps[wizard.state.step]?.id;

  return (
    <div className="space-y-6">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div className="min-w-0 space-y-1">
          <div className="text-[13px] text-zinc-500 font-medium">
            {t('imports.wizard.eyebrow', { defaultValue: 'Krok wizard — self-service import' })}
          </div>
          <h2 className="font-display text-[24px] font-semibold tracking-tight">
            {t('imports.wizard.title', { defaultValue: 'Nowy import' })}
          </h2>
          <p className="text-[13.5px] text-zinc-500 leading-relaxed max-w-3xl">
            {t('imports.wizard.subtitle', {
              defaultValue:
                'Każdy plik przechodzi przez 7 kroków: wybór danych, źródło, wykrywanie formatu, mapowanie kolumn, reguły, dry-run i commit do bazy. Po commicie sesja trafia do zakładki „Sesje" gdzie możesz ją wycofać w oknie 24h.',
            })}
          </p>
        </div>
        {/* Persistent action bar (ObjectType-style): ghost Anuluj + a navy run
            CTA that stays greyed until the confirm step is ready to submit. */}
        <div className="flex shrink-0 items-center gap-2">
          <Button
            variant="ghost"
            size="sm"
            onClick={() => navigate('/integrations/imports/sessions')}
            disabled={submitting}
            className="h-9 rounded-xl px-3 text-[12.5px] text-zinc-600"
          >
            {t('imports.wizard.cancel', { defaultValue: 'Anuluj' })}
          </Button>
          <Button
            onClick={handleRun}
            disabled={currentId !== 'confirm' || !canRun}
            className="h-9 rounded-xl bg-zinc-900 px-4 text-[12.5px] font-medium text-white hover:bg-zinc-800"
          >
            <Play className="size-4" aria-hidden="true" />
            {t('imports.wizard.run', { defaultValue: 'Uruchom import' })}
          </Button>
        </div>
      </header>

      <WizardStepper steps={steps} currentIndex={wizard.state.step} />

      <section
        role="tabpanel"
        id={`wizard-step-${currentId ?? 'unknown'}`}
        aria-labelledby={`wizard-step-${currentId ?? 'unknown'}-label`}
      >
        {currentId === 'entity' && <StepEntityType wizard={wizard} />}
        {currentId === 'source' && <StepSource wizard={wizard} />}
        {currentId === 'detect' && <StepDetect wizard={wizard} />}
        {currentId === 'mapping' && <StepMapping wizard={wizard} />}
        {currentId === 'rules' && <StepRules wizard={wizard} />}
        {currentId === 'validation' && <StepValidationPlaceholder wizard={wizard} />}
        {currentId === 'confirm' && (
          <StepConfirmPlaceholder
            wizard={wizard}
            onBackupStatusChange={setBackupStatus}
            onBackupCreated={setBackupId}
            submitError={submitError}
          />
        )}
      </section>
    </div>
  );
}

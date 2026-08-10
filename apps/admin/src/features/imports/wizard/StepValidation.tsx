import * as React from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import type { useImportWizard, ValidationFinding } from '@/features/imports/hooks/useImportWizard';
import { HttpError, httpErrorDetail, jsonFetch } from '@/lib/http';
import { cn } from '@/lib/utils';

interface StepValidationProps {
  wizard: ReturnType<typeof useImportWizard>;
}

interface DryRunResponse {
  total_rows: number;
  /** #2810 — dry-run skanuje ograniczoną liczbę wierszy; wtedy liczniki opisują próbkę. */
  truncated?: boolean;
  success_count: number;
  error_count: number;
  top_errors: ApiError[];
  all_errors: ApiError[];
}

interface ApiError {
  row_number: number;
  sku: string | null;
  error_type: string;
  level: 'info' | 'warning' | 'error';
  message: string;
  column_name: string | null;
  column_value: string | null;
}

/**
 * Spec §5.4 — wizard Step 3 dry-run preview. Calls validate-dry-run
 * with the uploaded file + the mapping picked on Step 2 and renders
 * the IMP-03 response: two KPI cards + top-10 errors + a "show all"
 * modal that streams the full list. The decision RadioGroup just
 * forwards control to Step 4 — the back-to-mapping branch routes
 * the wizard to step index 1.
 */
export function StepValidationPlaceholder({ wizard }: StepValidationProps): React.ReactElement {
  const { t } = useTranslation();
  const [showAll, setShowAll] = React.useState(false);
  const [response, setResponse] = React.useState<DryRunResponse | null>(null);
  const [error, setError] = React.useState<string | null>(null);
  const [loading, setLoading] = React.useState(false);
  const [decision, setDecision] = React.useState<'import_ok' | 'back_to_mapping'>('import_ok');

  const file = wizard.state.file;
  const stagedFileId = wizard.state.stagedFileId;
  const targetId = wizard.state.targetObjectTypeId;
  const mapping = wizard.state.mapping;
  const setValidation = wizard.setField;
  const encoding = wizard.state.encoding;
  const delimiter = wizard.state.delimiter;

  React.useEffect(() => {
    // IMP2-2.2 — prefer the staged_file_id (single upload reused across
    // steps); fall back to the raw File only when no staged id is available.
    if (targetId === null || (stagedFileId === null && file === null)) {
      return;
    }
    let cancelled = false;
    // Debounce 500ms so rapid mapping edits fire a single dry-run on pause,
    // not one request per keystroke.
    const timer = setTimeout(() => {
      const formData = new FormData();
      if (stagedFileId !== null) {
        formData.set('staged_file_id', stagedFileId);
      } else if (file !== null) {
        formData.set('file', file);
      }
      formData.set('target_object_type_id', targetId);
      formData.set('mapping', JSON.stringify(mapping));
      formData.set('encoding', encoding);
      formData.set('delimiter', delimiter);

      setLoading(true);
      setError(null);
      jsonFetch<DryRunResponse>('/api/import-sessions/validate-dry-run', {
        method: 'POST',
        body: formData,
      })
        .then((data) => {
          if (cancelled) {
            return;
          }
          setResponse(data);
          setValidation('validation', {
            totalRows: data.total_rows,
            successCount: data.success_count,
            errorCount: data.error_count,
            topErrors: data.top_errors.map(toFinding),
          });
        })
        .catch((err: unknown) => {
          if (cancelled) {
            return;
          }
          if (err instanceof HttpError) {
            // #2810 — `HTTP 200` samo w sobie nic nie mówi (a właśnie tak
            // wygląda fatal PHP przebrany za sukces). Detail z Problem
            // Details niesie powód; status zostaje jako ostatnia deska.
            setError(httpErrorDetail(err) ?? `HTTP ${err.status}`);
          } else {
            setError(err instanceof Error ? err.message : 'unknown');
          }
        })
        .finally(() => {
          if (!cancelled) {
            setLoading(false);
          }
        });
    }, 500);

    return () => {
      cancelled = true;
      clearTimeout(timer);
    };
  }, [file, stagedFileId, targetId, mapping, encoding, delimiter, setValidation]);

  const handleNext = (): void => {
    if (decision === 'back_to_mapping') {
      wizard.back();
      return;
    }
    wizard.next();
  };

  return (
    <div className="space-y-6 rounded-md border bg-card p-6">
      <header className="space-y-1">
        <h2 className="text-lg font-semibold">
          {t('imports.wizard.steps.validation', { defaultValue: 'Walidacja' })}
        </h2>
      </header>

      {loading && (
        <p className="text-sm text-muted-foreground" aria-busy="true">
          {t('app.loading', { defaultValue: 'Ładowanie…' })}
        </p>
      )}

      {error !== null && (
        <p role="alert" className="text-sm text-destructive">
          {t('imports.errors.upload_failed', {
            defaultValue: 'Walidacja nie powiodła się.',
          })}
          : {error}
        </p>
      )}

      {response !== null && (
        <>
          {response.truncated === true && (
            <p
              role="status"
              className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900"
            >
              {t('imports.validation.truncated', {
                count: response.total_rows,
                defaultValue:
                  'Sprawdzono pierwsze {{count}} wierszy pliku. Pełną walidację wykona właściwy import — te liczniki opisują próbkę, nie cały plik.',
              })}
            </p>
          )}

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Kpi
              label={t('imports.validation.ok', {
                count: response.success_count,
                defaultValue: '{{count}} produktów OK',
              })}
              tone="ok"
            />
            <Kpi
              label={t('imports.validation.errors', {
                count: response.error_count,
                defaultValue: '{{count}} błędów / ostrzeżeń',
              })}
              tone="warn"
            />
          </div>

          {response.top_errors.length > 0 && (
            <ErrorTable
              errors={response.top_errors}
              onShowAll={() => setShowAll(true)}
              showAllLabel={t('imports.validation.show_all', {
                count: response.error_count,
                defaultValue: 'Pokaż wszystkie {{count}} błędy',
              })}
            />
          )}

          <fieldset className="space-y-2">
            <legend className="text-sm font-medium">
              {t('imports.validation.decide.label', { defaultValue: 'Co dalej?' })}
            </legend>
            <label className="flex items-center gap-2 text-sm">
              <input
                type="radio"
                name="decision"
                checked={decision === 'import_ok'}
                onChange={() => setDecision('import_ok')}
              />
              <span>
                {t('imports.validation.decide.import_ok', {
                  defaultValue: 'Zaimportuj OK, pomiń błędne',
                })}
              </span>
            </label>
            <label className="flex items-center gap-2 text-sm">
              <input
                type="radio"
                name="decision"
                checked={decision === 'back_to_mapping'}
                onChange={() => setDecision('back_to_mapping')}
              />
              <span>
                {t('imports.validation.decide.back_to_mapping', {
                  defaultValue: 'Wróć do mappingu, popraw błędy',
                })}
              </span>
            </label>
          </fieldset>
        </>
      )}

      <div className="flex justify-between">
        <Button variant="ghost" onClick={() => wizard.back()}>
          ← {t('imports.wizard.back', { defaultValue: 'Wstecz' })}
        </Button>
        <Button onClick={handleNext} disabled={response === null}>
          {t('imports.wizard.next', { defaultValue: 'Dalej →' })}
        </Button>
      </div>

      {showAll && response !== null && (
        <Dialog open={showAll} onOpenChange={setShowAll}>
          <DialogContent className="max-w-3xl">
            <DialogHeader>
              <DialogTitle>
                {t('imports.validation.show_all', {
                  count: response.error_count,
                  defaultValue: 'Wszystkie błędy',
                })}
              </DialogTitle>
            </DialogHeader>
            <div className="max-h-[60vh] overflow-auto">
              <ErrorTable errors={response.all_errors} />
            </div>
          </DialogContent>
        </Dialog>
      )}
    </div>
  );
}

function Kpi({ label, tone }: { label: string; tone: 'ok' | 'warn' }): React.ReactElement {
  return (
    <Card
      className={cn(
        'p-4 text-center text-lg font-semibold',
        tone === 'ok' ? 'border-green-500/40 bg-green-50' : 'border-amber-500/40 bg-amber-50',
      )}
    >
      {label}
    </Card>
  );
}

function ErrorTable({
  errors,
  onShowAll,
  showAllLabel,
}: {
  errors: ApiError[];
  onShowAll?: () => void;
  showAllLabel?: string;
}): React.ReactElement {
  const { t } = useTranslation();

  return (
    <div>
      <div className="overflow-x-auto rounded-md border">
        <table className="w-full text-sm">
          <thead className="border-b bg-muted/40 text-xs uppercase">
            <tr>
              <th className="px-3 py-2 text-left">
                {t('imports.validation.errors_table.row', { defaultValue: 'Wiersz' })}
              </th>
              <th className="px-3 py-2 text-left">
                {t('imports.validation.errors_table.sku', { defaultValue: 'SKU' })}
              </th>
              <th className="px-3 py-2 text-left">
                {t('imports.validation.errors_table.type', { defaultValue: 'Typ błędu' })}
              </th>
              <th className="px-3 py-2 text-left">
                {t('imports.validation.errors_table.message', { defaultValue: 'Komunikat' })}
              </th>
            </tr>
          </thead>
          <tbody>
            {errors.map((row) => (
              <tr
                key={`${row.row_number}-${row.error_type}-${row.column_name ?? ''}`}
                className="border-b last:border-0"
              >
                <td className="px-3 py-2 font-mono text-xs">{row.row_number}</td>
                <td className="px-3 py-2 font-mono text-xs">{row.sku ?? '—'}</td>
                <td className="px-3 py-2 text-xs">
                  {t(`imports.validation.error_types.${row.error_type}`, {
                    defaultValue: row.error_type,
                  })}
                </td>
                <td className="px-3 py-2 text-xs text-muted-foreground">{row.message}</td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {onShowAll !== undefined && showAllLabel !== undefined && (
        <Button variant="link" size="sm" onClick={onShowAll} className="mt-2">
          {showAllLabel}
        </Button>
      )}
    </div>
  );
}

function toFinding(error: ApiError): ValidationFinding {
  return {
    rowNumber: error.row_number,
    sku: error.sku,
    errorType: error.error_type,
    level: error.level,
    message: error.message,
    columnName: error.column_name,
    columnValue: error.column_value,
  };
}

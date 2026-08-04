import { Layers, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { AttributePicker } from '@/components/catalog/attribute-picker';
import { BulkValueInput } from '@/components/catalog/bulk-wizard/bulk-value-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { toast } from '@/components/ui/toast';
import { jsonFetch } from '@/lib/http';
import { cn } from '@/lib/utils';

/**
 * VIEW-12 (#543) — 3-step bulk action wizard.
 *
 * MVP scope: synchronous `set_attribute` only. Wizard collects:
 *   - Step 1: attribute code + new value (the action target).
 *   - Step 2: locale + channels (placeholder — full scoping in VIEW-13).
 *   - Step 3: preview diff (sample 5 + aggregate counts) → Apply.
 *
 * Pixel-perfect mockup `list-v2-overlays.jsx` l. 151-360. Async path
 * (Messenger + Mercure SSE progress) lands in VIEW-12.1.
 */

interface BulkWizardProps {
  open: boolean;
  selectedIds: string[];
  onClose: () => void;
  onApplied: (result: BulkActionResult) => void;
}

interface BulkActionPreview {
  action: string;
  target_count: number;
  /** #2735 — counts are scoped to the 5-object sample, not extrapolated. */
  sample_size: number;
  sample_error_count: number;
  sample_skipped_count: number;
  sample: Array<{ id: string; sku: string; before: unknown; after: unknown }>;
}

interface BulkActionResult {
  session_id: string;
  action: string;
  target_count: number;
  success_count: number;
  skipped_count: number;
  error_count: number;
  rollback_available_until?: string;
  completed_at?: string;
}

type BulkMode =
  | 'set_attribute'
  | 'clear_attribute'
  | 'append_value'
  | 'remove_value'
  | 'increment_numeric';

const MODE_LABELS: Record<BulkMode, string> = {
  set_attribute: 'Ustaw wartość',
  clear_attribute: 'Wyczyść',
  append_value: 'Dodaj do listy',
  remove_value: 'Usuń z listy',
  increment_numeric: 'Operacja arytm.',
};

export function BulkWizard({ open, selectedIds, onClose, onApplied }: BulkWizardProps) {
  const { t } = useTranslation();
  const [step, setStep] = useState<1 | 2 | 3>(1);
  const [mode, setMode] = useState<BulkMode>('set_attribute');
  const [attrCode, setAttrCode] = useState('');
  // VIEW-25b (#556) — typ atrybutu wybranego w Pickerze (text / number /
  // select / multiselect / boolean / date / metric). Determinuje shape
  // <BulkValueInput> + payload zwracanego do BE.
  const [attrType, setAttrType] = useState<string | undefined>(undefined);
  const [newValue, setNewValue] = useState<unknown>('');
  const [operator, setOperator] = useState<'+' | '-' | '*' | '/' | '%'>('*');
  const [operand, setOperand] = useState('1.10');
  const [preview, setPreview] = useState<BulkActionPreview | null>(null);
  const [isLoading, setIsLoading] = useState(false);

  if (!open) return null;

  const buildPayload = (): Record<string, unknown> => {
    if (mode === 'increment_numeric') {
      return { attr: attrCode.trim(), operator, operand: Number(operand) };
    }
    if (mode === 'clear_attribute') {
      return { attr: attrCode.trim() };
    }
    return { attr: attrCode.trim(), value: newValue };
  };

  const canAdvance = (() => {
    if (step !== 1) return true;
    if (mode === 'clear_attribute') {
      return attrCode.trim() !== '';
    }
    if (mode === 'increment_numeric') {
      return attrCode.trim() !== '' && operand.trim() !== '' && !Number.isNaN(Number(operand));
    }
    if (attrCode.trim() === '') return false;
    if (newValue === undefined || newValue === null) return false;
    if (typeof newValue === 'string') return newValue.trim() !== '';
    if (Array.isArray(newValue)) return newValue.length > 0;
    return true;
  })();

  const fetchPreview = async (): Promise<void> => {
    setIsLoading(true);
    try {
      const response = await jsonFetch<BulkActionPreview>('/api/products/bulk-actions/preview', {
        method: 'POST',
        body: {
          action: mode,
          target_ids: selectedIds,
          payload: buildPayload(),
        },
      });
      setPreview(response);
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'preview failed');
    } finally {
      setIsLoading(false);
    }
  };

  const apply = async (): Promise<void> => {
    setIsLoading(true);
    try {
      const response = await jsonFetch<BulkActionResult>(`/api/products/bulk-actions/${mode}`, {
        method: 'POST',
        body: {
          target_ids: selectedIds,
          payload: buildPayload(),
        },
      });
      onApplied(response);
      toast.success(
        t('products.bulk_wizard.applied_success', {
          count: response.success_count,
          defaultValue: `Zastosowano do ${response.success_count} produktów`,
        }),
      );
      onClose();
    } catch (e) {
      toast.error(e instanceof Error ? e.message : 'apply failed');
    } finally {
      setIsLoading(false);
    }
  };

  const next = async (): Promise<void> => {
    if (step === 1) {
      setStep(2);
    } else if (step === 2) {
      await fetchPreview();
      setStep(3);
    } else if (step === 3) {
      await apply();
    }
  };

  return (
    <div className="fixed inset-0 z-50 bg-zinc-900/30 backdrop-blur-sm grid place-items-center">
      <button
        type="button"
        aria-label="Close backdrop"
        onClick={onClose}
        className="absolute inset-0 cursor-default"
      />
      <div
        className="relative bg-white rounded-3xl shadow-2xl w-[860px] max-w-[94vw] max-h-[88vh] overflow-hidden flex flex-col"
        role="dialog"
        aria-modal="true"
        aria-labelledby="bulk-wizard-title"
      >
        {/* Header */}
        <div className="px-6 h-14 flex items-center gap-3 border-b border-zinc-100">
          <span className="h-8 w-8 rounded-xl bg-zinc-900 text-white grid place-items-center">
            <Layers className="size-4" />
          </span>
          <div className="leading-tight">
            <div id="bulk-wizard-title" className="text-[14.5px] font-semibold tracking-tight">
              {t('products.bulk_wizard.title', { defaultValue: 'Akcja zbiorcza · Ustaw atrybut' })}
            </div>
            <div className="text-[11.5px] text-zinc-500 tabular-nums">
              {selectedIds.length}{' '}
              {t('products.bulk_wizard.target_count_label', {
                defaultValue: 'produktów wybranych',
              })}
            </div>
          </div>
          <div className="ml-auto inline-flex items-center gap-1">
            {(['Wybór akcji', 'Konfiguracja', 'Podgląd zmian'] as const).map((label, i) => {
              const stepNo = (i + 1) as 1 | 2 | 3;
              const active = stepNo === step;
              const done = stepNo < step;
              return (
                <div key={label} className="flex items-center gap-1">
                  {i > 0 && (
                    <span
                      className={cn('h-px w-6', done ? 'bg-emerald-500' : 'bg-zinc-200')}
                      aria-hidden="true"
                    />
                  )}
                  <span
                    className={cn(
                      'inline-flex items-center gap-1.5 h-7 px-2.5 rounded-lg text-[11.5px] font-medium',
                      active
                        ? 'bg-zinc-900 text-white'
                        : done
                          ? 'text-emerald-700 bg-emerald-50'
                          : 'text-zinc-500 bg-zinc-50',
                    )}
                  >
                    <span className="h-4 w-4 grid place-items-center rounded-full text-[10px] font-mono">
                      {done ? '✓' : stepNo}
                    </span>
                    {label}
                  </span>
                </div>
              );
            })}
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label="Close"
            className="ml-2 h-8 w-8 grid place-items-center rounded-lg text-zinc-500 hover:bg-zinc-100"
          >
            <X className="size-4" />
          </button>
        </div>

        {/* Body */}
        <div className="flex-1 overflow-y-auto p-6">
          {step === 1 && (
            <div className="space-y-4">
              <div>
                <div className="text-[11px] uppercase tracking-wider font-semibold text-zinc-500 mb-2">
                  {t('products.bulk_wizard.mode_label', { defaultValue: 'Tryb operacji' })}
                </div>
                <div className="grid grid-cols-3 gap-2">
                  {(Object.keys(MODE_LABELS) as BulkMode[]).map((m) => (
                    <button
                      key={m}
                      type="button"
                      onClick={() => setMode(m)}
                      className={cn(
                        'h-9 px-3 rounded-lg text-[12px] font-medium border',
                        mode === m
                          ? 'bg-zinc-900 text-white border-zinc-900'
                          : 'bg-white text-zinc-700 border-zinc-200 hover:border-zinc-300',
                      )}
                    >
                      {MODE_LABELS[m]}
                    </button>
                  ))}
                </div>
              </div>
              <div>
                <div className="text-[11px] uppercase tracking-wider font-semibold text-zinc-500 mb-2">
                  {t('products.bulk_wizard.attr_label', { defaultValue: 'Atrybut' })}
                </div>
                <AttributePicker
                  value={attrCode}
                  onChange={(picked) => {
                    setAttrCode(picked?.code ?? '');
                    setAttrType(picked?.type);
                    // reset value when typ atrybutu zmienia się żeby
                    // stara wartość (np. string) nie wpadła w bool/select input.
                    setNewValue(picked?.type === 'multiselect' ? [] : '');
                  }}
                  allowedTypes={mode === 'increment_numeric' ? ['number', 'metric'] : undefined}
                />
              </div>
              {(mode === 'set_attribute' || mode === 'append_value' || mode === 'remove_value') && (
                <div>
                  <div className="text-[11px] uppercase tracking-wider font-semibold text-zinc-500 mb-2">
                    {mode === 'set_attribute'
                      ? t('products.bulk_wizard.value_label', { defaultValue: 'Nowa wartość' })
                      : mode === 'append_value'
                        ? t('products.bulk_wizard.append_value_label', {
                            defaultValue: 'Wartość do dodania',
                          })
                        : t('products.bulk_wizard.remove_value_label', {
                            defaultValue: 'Wartość do usunięcia',
                          })}
                  </div>
                  <BulkValueInput
                    attrCode={attrCode}
                    attrType={attrType}
                    value={newValue}
                    onChange={setNewValue}
                  />
                </div>
              )}
              {mode === 'increment_numeric' && (
                <div className="grid grid-cols-2 gap-3">
                  <div>
                    <label
                      htmlFor="bulk-operator"
                      className="text-[11px] uppercase tracking-wider font-semibold text-zinc-500"
                    >
                      Operator
                    </label>
                    <select
                      id="bulk-operator"
                      value={operator}
                      onChange={(e) => setOperator(e.target.value as '+' | '-' | '*' | '/' | '%')}
                      className="mt-2 h-9 w-full rounded-lg border border-zinc-200 px-2 text-[13px] font-mono"
                    >
                      <option value="+">+ dodaj</option>
                      <option value="-">- odejmij</option>
                      <option value="*">* pomnóż</option>
                      <option value="/">/ podziel</option>
                      <option value="%">% modulo</option>
                    </select>
                  </div>
                  <div>
                    <label
                      htmlFor="bulk-operand"
                      className="text-[11px] uppercase tracking-wider font-semibold text-zinc-500"
                    >
                      Wartość
                    </label>
                    <Input
                      id="bulk-operand"
                      value={operand}
                      onChange={(e) => setOperand(e.target.value)}
                      placeholder="np. 1.10 (price *= 1.10)"
                      className="mt-2 font-mono"
                    />
                  </div>
                </div>
              )}
            </div>
          )}
          {step === 2 && (
            <div className="rounded-2xl border border-zinc-200 bg-white p-4 text-[12.5px] text-zinc-700">
              {t('products.bulk_wizard.step2_placeholder', {
                defaultValue:
                  'Zakres locale + kanały — w VIEW-13. W MVP set_attribute trafia w global lane.',
              })}
            </div>
          )}
          {step === 3 && preview && (
            <div>
              <div className="grid grid-cols-4 gap-4 mb-5 rounded-2xl bg-zinc-50/70 border border-zinc-100 p-4">
                <Stat
                  n={preview.target_count}
                  label={t('products.bulk_wizard.stat_selected', { defaultValue: 'zaznaczonych' })}
                  tone="zinc"
                />
                <Stat
                  n={preview.sample_size}
                  label={t('products.bulk_wizard.stat_sample', { defaultValue: 'w próbce' })}
                  tone="emerald"
                />
                <Stat
                  n={preview.sample_skipped_count}
                  label={t('products.bulk_wizard.stat_sample_skipped', {
                    defaultValue: 'pominięte (próbka)',
                  })}
                  tone="amber"
                />
                <Stat
                  n={preview.sample_error_count}
                  label={t('products.bulk_wizard.stat_sample_errors', {
                    defaultValue: 'błędy (próbka)',
                  })}
                  tone="rose"
                />
              </div>
              <div className="rounded-2xl border border-zinc-200 overflow-hidden">
                <div
                  className="grid items-center text-[10.5px] uppercase tracking-wider text-zinc-500 font-semibold bg-zinc-50/70 border-b border-zinc-100"
                  style={{ gridTemplateColumns: '120px 1fr 140px 140px' }}
                >
                  <div className="px-3 py-2">SKU</div>
                  <div className="px-3 py-2">ID</div>
                  <div className="px-3 py-2">Przed</div>
                  <div className="px-3 py-2">Po</div>
                </div>
                {preview.sample.map((row) => (
                  <div
                    key={row.id}
                    className="grid items-center text-[12.5px] border-b border-zinc-50 last:border-b-0"
                    style={{ gridTemplateColumns: '120px 1fr 140px 140px' }}
                  >
                    <div className="px-3 py-2 font-mono">{row.sku}</div>
                    <div className="px-3 py-2 font-mono text-zinc-500 text-[11px] truncate">
                      {row.id}
                    </div>
                    <div className="px-3 py-2 text-zinc-500 line-through font-mono text-[11.5px]">
                      {String(row.before ?? '—')}
                    </div>
                    <div className="px-3 py-2 text-emerald-700 font-semibold font-mono text-[11.5px]">
                      {String(row.after ?? '—')}
                    </div>
                  </div>
                ))}
              </div>
            </div>
          )}
        </div>

        {/* Footer */}
        <div className="px-6 h-14 flex items-center gap-3 border-t border-zinc-100 bg-zinc-50/50">
          <span className="text-[11.5px] text-zinc-500">
            {t('products.bulk_wizard.rollback_hint', {
              defaultValue: 'Każda akcja zbiorcza ma 24h soft-rollback.',
            })}
          </span>
          <div className="ml-auto flex items-center gap-2">
            {step > 1 && (
              <Button
                variant="ghost"
                onClick={() => setStep((s) => (s === 3 ? 2 : 1) as 1 | 2)}
                disabled={isLoading}
              >
                {t('app.back', { defaultValue: 'Wstecz' })}
              </Button>
            )}
            <Button variant="ghost" onClick={onClose} disabled={isLoading}>
              {t('app.cancel', { defaultValue: 'Anuluj' })}
            </Button>
            <Button onClick={() => void next()} disabled={!canAdvance || isLoading}>
              {step < 3
                ? t('products.bulk_wizard.next', { defaultValue: 'Dalej →' })
                : t('products.bulk_wizard.apply', { defaultValue: 'Zastosuj' })}
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
}

function Stat({ n, label, tone }: { n: number; label: string; tone: string }) {
  const map: Record<string, string> = {
    zinc: 'text-zinc-900',
    emerald: 'text-emerald-700',
    amber: 'text-amber-700',
    rose: 'text-rose-700',
  };
  return (
    <div>
      <div
        className={cn(
          'font-display text-[28px] font-semibold tracking-tight leading-none tabular-nums',
          map[tone],
        )}
      >
        {n}
      </div>
      <div className="text-[11.5px] text-zinc-500 mt-1">{label}</div>
    </div>
  );
}

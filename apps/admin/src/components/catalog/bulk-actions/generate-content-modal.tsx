import { Sparkles, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { toast } from '@/components/ui/toast';
import { startAgentRun } from '@/features/agent/api';
import { OPEN_AGENT_CHAT_EVENT } from '@/features/agent/chat/AgentChatSheet';
import { cn } from '@/lib/utils';

/**
 * AICG-P5-03 (#2341) — bulk "Generuj opisy / Generuj SEO" from the
 * product list: the selection scope goes into ONE agent run whose
 * write-tool calls accumulate into ONE pending batch (the multi-step
 * plan mechanics of the loop), reviewed in the agent inbox.
 *
 * The cost preview is a client-side estimate from the live-measured
 * per-product averages of the content tools; AICG-P6-03 replaces it
 * with the dedicated backend preview and lifts the per-run cap.
 */

/**
 * Live-measured on the dev stack (AICG-P3-02/P4-02 smokes): a grounded
 * description round-trip lands around 1.3k input / 0.4k output tokens.
 * Priced at the default-tier list rates ($3 / $15 per MTok).
 */
const EST_COST_PER_PRODUCT_USD = 0.012;

/**
 * The loop's tool-call budget per run is 10 — one grounding+generation
 * call per product plus headroom. Larger selections need the dedicated
 * bulk path (AICG-P6-03).
 */
const MVP_RUN_CAP = 8;

export function BulkGenerateContentModal({
  selectedIds,
  totalMatching,
  objectTypeCode,
  filterDsl,
  onClose,
  onStarted,
}: {
  selectedIds: string[];
  totalMatching: number;
  objectTypeCode: string;
  filterDsl: unknown;
  onClose: () => void;
  onStarted: () => void;
}) {
  const { t } = useTranslation();
  const [mode, setMode] = useState<'descriptions' | 'seo'>('descriptions');
  const [isStarting, setIsStarting] = useState(false);

  const count = selectedIds.length;
  const overCap = count > MVP_RUN_CAP;
  const estimate = (count * EST_COST_PER_PRODUCT_USD).toFixed(2);

  const start = async () => {
    setIsStarting(true);
    try {
      const intent =
        mode === 'descriptions'
          ? `Dla KAŻDEGO z ${count} zaznaczonych produktów wywołaj narzędzie generate_product_description podając wyłącznie product_id (target i przepis są domyślne). Wszystkie propozycje mają trafić do jednego batcha. Nie zadawaj pytań — jeśli produktowi brakuje danych, narzędzie samo to zgłosi.`
          : `Dla KAŻDEGO z ${count} zaznaczonych produktów wywołaj narzędzie generate_seo_text podając wyłącznie product_id (target i przepis są domyślne). Wszystkie propozycje mają trafić do jednego batcha. Nie zadawaj pytań — jeśli produktowi brakuje danych, narzędzie samo to zgłosi.`;
      const run = await startAgentRun(intent, 'cmdk', {
        object_type_code: objectTypeCode,
        filter_dsl: filterDsl ?? null,
        selected_ids: selectedIds,
        total_matching: totalMatching,
      });
      window.dispatchEvent(new CustomEvent(OPEN_AGENT_CHAT_EVENT, { detail: { runId: run.id } }));
      toast.success(
        t('aicg.bulk.started', {
          defaultValue: 'Generowanie wystartowało — propozycje trafią do skrzynki agenta.',
        }),
      );
      onStarted();
      onClose();
    } catch (error) {
      toast.error(error instanceof Error ? error.message : String(error));
    } finally {
      setIsStarting(false);
    }
  };

  return (
    <div
      className="fixed inset-0 z-50 grid place-items-center bg-zinc-900/30 backdrop-blur-sm"
      role="dialog"
      aria-modal="true"
      aria-labelledby="bulk-generate-title"
    >
      <div className="relative flex w-[520px] max-w-[94vw] flex-col overflow-hidden rounded-3xl bg-white shadow-2xl">
        <div className="flex h-14 items-center gap-3 border-b border-zinc-100 px-6">
          <span className="grid h-8 w-8 place-items-center rounded-xl bg-purple-50 text-purple-600">
            <Sparkles className="size-4" aria-hidden />
          </span>
          <div className="leading-tight">
            <div id="bulk-generate-title" className="text-[14.5px] font-semibold">
              {t('aicg.bulk.title', { defaultValue: 'Akcja zbiorcza · Generuj treść AI' })}
            </div>
            <div className="text-[11.5px] text-zinc-500" data-testid="bulk-generate-count">
              {t('aicg.bulk.count', {
                defaultValue: '{{count}} produktów wybranych',
                count,
              })}
            </div>
          </div>
          <button
            type="button"
            onClick={onClose}
            aria-label={t('app.close', { defaultValue: 'Zamknij' })}
            className="ml-auto grid h-8 w-8 place-items-center rounded-lg text-zinc-500 hover:bg-zinc-100"
          >
            <X className="size-4" aria-hidden />
          </button>
        </div>

        <div className="flex-1 space-y-4 overflow-y-auto p-6">
          <div>
            <span className="text-[11px] font-semibold uppercase tracking-wider text-zinc-500">
              {t('aicg.bulk.mode_label', { defaultValue: 'Co wygenerować' })}
            </span>
            <div className="mt-2 grid grid-cols-2 gap-2">
              <button
                type="button"
                onClick={() => setMode('descriptions')}
                aria-pressed={mode === 'descriptions'}
                className={cn(
                  'h-10 rounded-lg border px-3 text-[12px] font-medium',
                  mode === 'descriptions'
                    ? 'border-zinc-900 bg-zinc-900 text-white'
                    : 'border-zinc-200 bg-white text-zinc-700 hover:border-zinc-300',
                )}
              >
                {t('aicg.bulk.mode_descriptions', { defaultValue: 'Opisy produktów' })}
              </button>
              <button
                type="button"
                onClick={() => setMode('seo')}
                aria-pressed={mode === 'seo'}
                className={cn(
                  'h-10 rounded-lg border px-3 text-[12px] font-medium',
                  mode === 'seo'
                    ? 'border-zinc-900 bg-zinc-900 text-white'
                    : 'border-zinc-200 bg-white text-zinc-700 hover:border-zinc-300',
                )}
              >
                {t('aicg.bulk.mode_seo', { defaultValue: 'Meta SEO' })}
              </button>
            </div>
          </div>

          <div
            className="rounded-2xl border border-amber-200 bg-amber-50/60 px-4 py-3 text-[12.5px] text-amber-900"
            data-testid="bulk-generate-estimate"
          >
            <div className="font-semibold">
              {t('aicg.bulk.estimate_label', { defaultValue: 'Szacowany koszt' })}
            </div>
            <div>
              {t('aicg.bulk.estimate', {
                defaultValue:
                  '≈{{estimate}} USD ({{count}} × ~0.012 USD za produkt, stawki modelu domyślnego)',
                estimate,
                count,
              })}
            </div>
          </div>

          {overCap ? (
            <div
              className="rounded-2xl border border-red-200 bg-red-50 px-4 py-3 text-[12.5px] text-red-900"
              data-testid="bulk-generate-cap"
            >
              {t('aicg.bulk.cap', {
                defaultValue:
                  'W tej wersji jeden przebieg obsługuje do {{cap}} produktów (budżet narzędzi runu). Zawęź zaznaczenie.',
                cap: MVP_RUN_CAP,
              })}
            </div>
          ) : null}
        </div>

        <div className="flex h-14 items-center gap-3 border-t border-zinc-100 bg-zinc-50/50 px-6">
          <span className="text-[11.5px] text-zinc-500">
            {t('aicg.bulk.approval_hint', {
              defaultValue:
                'Propozycje trafiają do skrzynki agenta — nic nie zapisze się bez akceptacji.',
            })}
          </span>
          <div className="ml-auto flex items-center gap-2">
            <Button variant="ghost" onClick={onClose} disabled={isStarting}>
              {t('app.cancel', { defaultValue: 'Anuluj' })}
            </Button>
            <Button onClick={() => void start()} disabled={isStarting || overCap || count === 0}>
              {t('aicg.bulk.start', { defaultValue: 'Generuj' })}
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
}

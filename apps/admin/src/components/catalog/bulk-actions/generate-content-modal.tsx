import { useQuery } from '@tanstack/react-query';
import { Sparkles, X } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Button } from '@/components/ui/button';
import { toast } from '@/components/ui/toast';
import {
  type BulkContentMode,
  bulkGenerateContent,
  previewContentCost,
} from '@/features/agent/api';
import { OPEN_AGENT_CHAT_EVENT } from '@/features/agent/chat/AgentChatSheet';
import { useContentRecipes } from '@/features/agent/hooks/use-content-recipes';
import { cn } from '@/lib/utils';

/**
 * The SEO tool (generate_seo_text) applies title/meta length rules; everything
 * else uses the general description writer. The backend picks the tool from
 * `mode`, so derive it from the recipe's target attribute.
 */
function modeForTarget(targetAttribute: string): BulkContentMode {
  return targetAttribute === 'meta_description' ? 'seo' : 'descriptions';
}

/**
 * AICG-P5-03 (#2341) / AICG-P6-03 (#2346) — bulk "Generuj opisy /
 * Generuj SEO" from the product list. The selection goes to the
 * dedicated bulk path (/api/agent/content/bulk-generate): ONE run whose
 * write tool runs per product in a memory-bounded worker batch, all
 * proposals in ONE pending batch reviewed in the agent inbox. The cost
 * is a real backend pre-flight (/cost-preview) and the §8.5 day cap is
 * enforced server-side (429 → surfaced as an error toast).
 */

export function BulkGenerateContentModal({
  selectedIds,
  objectTypeCode,
  onClose,
  onStarted,
}: {
  selectedIds: string[];
  objectTypeCode: string;
  onClose: () => void;
  onStarted: () => void;
}) {
  const { t } = useTranslation();
  const { recipes, isLoading: recipesLoading } = useContentRecipes();
  // #2603 — the modal offers every content recipe (built-in + custom), not two
  // hardcoded modes; a custom recipe was invisible here even though it showed
  // in Settings → AI content. Default to the first recipe once they load.
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [isStarting, setIsStarting] = useState(false);

  const count = selectedIds.length;
  const selectedRecipe = recipes.find((recipe) => recipe.id === selectedId) ?? recipes[0] ?? null;
  const mode: BulkContentMode = selectedRecipe
    ? modeForTarget(selectedRecipe.targetAttribute)
    : 'descriptions';
  const recipeId = selectedRecipe?.id;

  const { data: estimate, isLoading: isEstimating } = useQuery({
    queryKey: ['content-cost-preview', count, mode, recipeId],
    queryFn: () => previewContentCost(count, mode, recipeId),
    enabled: count > 0 && recipeId !== undefined,
  });

  const start = async () => {
    setIsStarting(true);
    try {
      const result = await bulkGenerateContent({ objectTypeCode, mode, selectedIds, recipeId });
      window.dispatchEvent(
        new CustomEvent(OPEN_AGENT_CHAT_EVENT, { detail: { runId: result.run_id } }),
      );
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
            {recipesLoading ? (
              <div className="mt-2 text-[12.5px] text-zinc-500">
                {t('aicg.bulk.recipes_loading', { defaultValue: 'Wczytuję przepisy…' })}
              </div>
            ) : recipes.length === 0 ? (
              <div className="mt-2 text-[12.5px] text-zinc-500">
                {t('aicg.bulk.recipes_empty', {
                  defaultValue: 'Brak przepisów treści — dodaj je w Ustawienia → AI content.',
                })}
              </div>
            ) : (
              <div className="mt-2 grid gap-2" data-testid="bulk-generate-recipes">
                {recipes.map((recipe) => {
                  const active = selectedRecipe?.id === recipe.id;
                  return (
                    <button
                      key={recipe.id}
                      type="button"
                      onClick={() => setSelectedId(recipe.id)}
                      aria-pressed={active}
                      className={cn(
                        'flex flex-col items-start rounded-lg border px-3 py-2 text-left',
                        active
                          ? 'border-zinc-900 bg-zinc-900 text-white'
                          : 'border-zinc-200 bg-white text-zinc-700 hover:border-zinc-300',
                      )}
                    >
                      <span className="text-[12.5px] font-medium">{recipe.name}</span>
                      <span
                        className={cn(
                          'font-mono text-[11px]',
                          active ? 'text-zinc-300' : 'text-zinc-500',
                        )}
                      >
                        → {recipe.targetAttribute}
                      </span>
                    </button>
                  );
                })}
              </div>
            )}
          </div>

          <div
            className="rounded-2xl border border-amber-200 bg-amber-50/60 px-4 py-3 text-[12.5px] text-amber-900"
            data-testid="bulk-generate-estimate"
          >
            <div className="font-semibold">
              {t('aicg.bulk.estimate_label', { defaultValue: 'Szacowany koszt' })}
            </div>
            <div>
              {isEstimating || !estimate
                ? t('aicg.bulk.estimate_loading', { defaultValue: 'Liczę szacunek…' })
                : t('aicg.bulk.estimate_backend', {
                    defaultValue:
                      '≈{{estimate}} USD ({{count}} produktów · ~{{tokens}} tokenów, model {{model}})',
                    estimate: Number(estimate.est_cost_usd).toFixed(2),
                    count,
                    tokens: estimate.est_input_tokens + estimate.est_output_tokens,
                    model: estimate.model,
                  })}
            </div>
          </div>
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
            <Button
              onClick={() => void start()}
              disabled={isStarting || count === 0 || recipeId === undefined}
            >
              {t('aicg.bulk.start', { defaultValue: 'Generuj' })}
            </Button>
          </div>
        </div>
      </div>
    </div>
  );
}

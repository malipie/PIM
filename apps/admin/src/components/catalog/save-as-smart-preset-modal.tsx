import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import type { FilterDsl } from '@/lib/filters/filter-dsl';
import type { SmartFilterPreset } from '@/lib/filters/use-smart-presets';
import type { GridColumnOverride } from '@/lib/grid/types';
import { httpErrorDetail } from '@/lib/http';

const ICON_CHOICES = ['🔧', '⚙️', '⚡', '🛠️', '🏷️', '📦', '🔍', '🌟', '🚀', '🎯', '💡', '📊'];

/** Empty filter group — a preset can carry columns only, with no conditions. */
const EMPTY_FILTER: FilterDsl = { operator: 'AND', conditions: [] };

interface SaveAsSmartPresetModalProps {
  query: FilterDsl | null;
  /**
   * PTR-01 — the current column layout, snapshotted into the preset so a
   * saved preset also restores columns (the old "saved view" role).
   */
  columns?: GridColumnOverride[];
  onClose: () => void;
  onSaved: (preset: SmartFilterPreset) => void;
  create: (input: {
    name: { pl: string; en: string };
    icon: string;
    query: FilterDsl;
    columns?: GridColumnOverride[] | null;
  }) => Promise<SmartFilterPreset>;
}

/**
 * VIEW-09 (#535) — modal "Zapisz jako Smart Preset" wywoływany z
 * Advanced filter panel footer + SmartFilterPresetsRow "Własny preset"
 * button.
 *
 * PTR-01 — preset absorbuje rolę "widoku kolumn": zapisuje bieżący filtr
 * (może być pusty) ORAZ układ kolumn. Można zapisać preset bez żadnego
 * warunku (sam układ kolumn). Nazwa multilingual {pl, en} zgodnie z
 * CLAUDE.md punkt 8. #2578 — bez wyboru ikony; preset dostaje domyślną
 * ikonę cicho (kontrakt BE wymaga `icon`).
 */
export function SaveAsSmartPresetModal({
  query,
  columns,
  onClose,
  onSaved,
  create,
}: SaveAsSmartPresetModalProps) {
  const { t } = useTranslation();
  const [namePl, setNamePl] = useState('');
  const [nameEn, setNameEn] = useState('');
  // #2578 — the icon is no longer user-pickable; keep a default so the BE
  // contract (icon required) stays satisfied without any UI.
  const icon = ICON_CHOICES[0] ?? '🔧';
  const [isPending, setIsPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // PTR-01 — a preset may carry only a column layout, so an empty filter is
  // allowed; the name is the only hard requirement.
  const canSubmit = namePl.trim().length >= 3 && nameEn.trim().length >= 3;

  const handleSubmit = async (event: React.FormEvent): Promise<void> => {
    event.preventDefault();
    if (!canSubmit) return;
    setIsPending(true);
    setError(null);
    try {
      const preset = await create({
        name: { pl: namePl.trim(), en: nameEn.trim() },
        icon,
        query: query ?? EMPTY_FILTER,
        columns,
      });
      onSaved(preset);
      onClose();
    } catch (e) {
      // #1218 — surface the server's Problem Details reason (e.g. "Operator
      // '=' not supported for attribute 'created_by' of type 'reference'")
      // instead of the opaque "HTTP 400", so the operator knows what to fix.
      setError(httpErrorDetail(e) ?? (e instanceof Error ? e.message : 'unknown'));
    } finally {
      setIsPending(false);
    }
  };

  return (
    <Sheet
      open
      onOpenChange={(next) => {
        if (!next) onClose();
      }}
    >
      <SheetContent side="right" className="w-[460px] p-6">
        <SheetTitle>
          {t('products.smart_filters.save_as_preset_title', {
            defaultValue: 'Zapisz jako Smart Preset',
          })}
        </SheetTitle>
        <form onSubmit={(e) => void handleSubmit(e)} className="mt-4 space-y-4">
          <div className="space-y-2">
            <label htmlFor="smart-preset-name-pl" className="text-sm font-medium">
              {t('products.smart_filters.name_pl', { defaultValue: 'Nazwa (PL)' })}
              <span className="ml-1 text-rose-600">*</span>
            </label>
            <Input
              id="smart-preset-name-pl"
              value={namePl}
              onChange={(e) => setNamePl(e.target.value)}
              minLength={3}
              maxLength={60}
              placeholder={t('products.smart_filters.save_as_preset_name_placeholder', {
                defaultValue: 'np. Festo niski stock',
              })}
            />
          </div>

          <div className="space-y-2">
            <label htmlFor="smart-preset-name-en" className="text-sm font-medium">
              {t('products.smart_filters.name_en', { defaultValue: 'Nazwa (EN)' })}
              <span className="ml-1 text-rose-600">*</span>
            </label>
            <Input
              id="smart-preset-name-en"
              value={nameEn}
              onChange={(e) => setNameEn(e.target.value)}
              minLength={3}
              maxLength={60}
              placeholder="e.g. Festo low stock"
            />
          </div>

          <div className="rounded-md border bg-muted/40 p-3 text-xs">
            <div className="mb-1 font-medium">
              {t('products.smart_filters.preview_title', { defaultValue: 'Warunki presetu:' })}
            </div>
            {query === null ? (
              <p className="text-muted-foreground">
                {t('products.smart_filters.preview_empty', {
                  defaultValue: 'Brak warunków filtrowania — preset zapisze bieżący układ kolumn.',
                })}
              </p>
            ) : (
              <pre className="font-mono whitespace-pre-wrap text-zinc-600">
                {JSON.stringify(query, null, 2)}
              </pre>
            )}
            {columns && columns.length > 0 ? (
              <p className="mt-2 text-muted-foreground">
                {t('products.smart_filters.preview_columns', {
                  count: columns.length,
                  defaultValue: 'Zapisze też układ {{count}} kolumn.',
                })}
              </p>
            ) : null}
          </div>

          {error !== null ? <p className="text-sm text-rose-600">{error}</p> : null}

          <div className="flex justify-end gap-2 pt-2">
            <Button variant="ghost" type="button" onClick={onClose} disabled={isPending}>
              {t('products.smart_filters.save_as_preset_cancel', { defaultValue: 'Anuluj' })}
            </Button>
            <Button type="submit" disabled={!canSubmit || isPending}>
              {isPending
                ? t('products.smart_filters.submitting', { defaultValue: 'Zapisuję…' })
                : t('products.smart_filters.save_as_preset_save', { defaultValue: 'Zapisz' })}
            </Button>
          </div>
        </form>
      </SheetContent>
    </Sheet>
  );
}

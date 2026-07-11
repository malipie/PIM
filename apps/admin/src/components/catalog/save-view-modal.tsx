import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { jsonFetch } from '@/lib/http';

interface SaveViewModalProps {
  resource: string;
  /** GRID-P4-03 — scopes the saved view (and its default) to this ObjectType. */
  objectTypeId?: string;
  config: Record<string, unknown>;
  onClose: () => void;
  onSaved: (slug: string) => void;
}

/**
 * UI-02.15 follow-up — SaveViewModal replacing `window.prompt` in the
 * list page. Posts to `/api/saved-views` (UI-02.7). Includes an
 * "is default" checkbox + readable preview of what will be persisted.
 */
export function SaveViewModal({
  resource,
  objectTypeId,
  config,
  onClose,
  onSaved,
}: SaveViewModalProps) {
  const { t } = useTranslation();
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [isDefault, setIsDefault] = useState(false);
  const [isPending, setIsPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const handleSubmit = async (event: React.FormEvent): Promise<void> => {
    event.preventDefault();
    if (name.trim() === '') return;
    setIsPending(true);
    setError(null);
    try {
      const response = await jsonFetch<{ slug: string }>('/api/saved-views', {
        method: 'POST',
        body: {
          name: name.trim(),
          description: description.trim() === '' ? undefined : description.trim(),
          resource,
          object_type_id: objectTypeId,
          config,
          is_default: isDefault,
        },
      });
      onSaved(response.slug);
      onClose();
    } catch (e) {
      setError(e instanceof Error ? e.message : 'unknown');
    } finally {
      setIsPending(false);
    }
  };

  const previewLines = describeConfig(config);

  return (
    <Sheet
      open
      onOpenChange={(next) => {
        if (!next) onClose();
      }}
    >
      <SheetContent side="right" className="w-[420px] p-6">
        <SheetTitle>
          {t('products.saved_views.modal_title', { defaultValue: 'Save current view' })}
        </SheetTitle>
        <form onSubmit={(e) => void handleSubmit(e)} className="mt-4 space-y-4">
          <div className="space-y-2">
            <label htmlFor="save-view-name" className="text-sm font-medium">
              {t('products.saved_views.field_name', { defaultValue: 'Name' })}
              <span className="ml-1 text-rose-600">*</span>
            </label>
            <Input
              id="save-view-name"
              value={name}
              onChange={(e) => setName(e.target.value)}
              placeholder={t('products.saved_views.name_placeholder', {
                defaultValue: 'Festo niski completeness',
              })}
            />
          </div>

          <div className="space-y-2">
            <label htmlFor="save-view-description" className="text-sm font-medium">
              {t('products.saved_views.field_description', {
                defaultValue: 'Description (optional)',
              })}
            </label>
            <Input
              id="save-view-description"
              value={description}
              onChange={(e) => setDescription(e.target.value)}
            />
          </div>

          <label className="inline-flex cursor-pointer items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={isDefault}
              onChange={(e) => setIsDefault(e.target.checked)}
            />
            {t('products.saved_views.field_default', {
              defaultValue: 'Set as default view for this resource',
            })}
          </label>

          <div className="rounded-md border bg-muted/40 p-3 text-xs">
            <div className="mb-1 font-medium">
              {t('products.saved_views.preview_title', { defaultValue: 'Includes:' })}
            </div>
            {previewLines.length === 0 ? (
              <p className="text-muted-foreground">
                {t('products.saved_views.preview_empty', { defaultValue: 'Empty config.' })}
              </p>
            ) : (
              <ul className="space-y-0.5">
                {previewLines.map((line) => (
                  <li key={line}>✓ {line}</li>
                ))}
              </ul>
            )}
          </div>

          {error !== null ? <p className="text-sm text-rose-600">{error}</p> : null}

          <div className="flex justify-end gap-2 pt-2">
            <Button variant="ghost" type="button" onClick={onClose} disabled={isPending}>
              {t('app.cancel', { defaultValue: 'Cancel' })}
            </Button>
            <Button type="submit" disabled={isPending || name.trim() === ''}>
              {isPending
                ? t('products.saved_views.submitting', { defaultValue: 'Saving…' })
                : t('products.saved_views.save_view', { defaultValue: 'Save view' })}
            </Button>
          </div>
        </form>
      </SheetContent>
    </Sheet>
  );
}

function describeConfig(config: Record<string, unknown>): string[] {
  const lines: string[] = [];
  const filters = config.filters;
  if (filters !== null && typeof filters === 'object' && !Array.isArray(filters)) {
    const keys = Object.keys(filters as Record<string, unknown>);
    if (keys.length > 0) lines.push(`Filters (${keys.length})`);
  }
  if (typeof config.variants_mode === 'string') {
    lines.push(`Variants mode: ${config.variants_mode}`);
  }
  if (Array.isArray(config.columns)) {
    const visible = (config.columns as Array<{ hidden?: boolean }>).filter(
      (c) => c?.hidden !== true,
    );
    lines.push(`Kolumny: ${visible.length} widocznych`);
  }
  const sort = config.sort;
  if (
    sort !== null &&
    typeof sort === 'object' &&
    typeof (sort as { key?: unknown }).key === 'string'
  ) {
    lines.push(
      `Sortowanie: ${(sort as { key: string }).key} ${(sort as { dir?: string }).dir ?? ''}`,
    );
  }
  if (typeof config.density === 'string') {
    lines.push(`Gęstość: ${config.density}`);
  }
  if (typeof config.page_size === 'number') {
    lines.push(`Rozmiar strony: ${config.page_size}`);
  }
  return lines;
}

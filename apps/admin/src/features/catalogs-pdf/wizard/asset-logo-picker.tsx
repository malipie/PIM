import { useList } from '@refinedev/core';
import { useTranslation } from 'react-i18next';

import { Sheet, SheetContent, SheetTitle } from '@/components/ui/sheet';
import { type AssetEntry, AssetThumb, toAssetMeta } from '@/features/asset/assets/asset-meta';

interface AssetLogoPickerProps {
  onClose: () => void;
  onSelect: (url: string) => void;
}

/**
 * #2569 — pick a catalog logo from the Multimedia library instead of pasting a
 * URL. Lists the tenant's image assets (with a ready preview) and returns the
 * chosen asset's URL into the branding step.
 */
export function AssetLogoPicker({ onClose, onSelect }: AssetLogoPickerProps) {
  const { t } = useTranslation();
  const { result, query } = useList<AssetEntry>({
    resource: 'assets',
    pagination: { mode: 'off' },
    filters: [{ field: 'mimeGroup', operator: 'eq', value: 'image' }],
  });
  const images = (result?.data ?? []).map(toAssetMeta).filter((asset) => asset.previewUrl !== null);

  return (
    <Sheet
      open
      onOpenChange={(next) => {
        if (!next) onClose();
      }}
    >
      <SheetContent side="right" className="w-[480px] p-6">
        <SheetTitle>
          {t('catalogs_pdf.wizard.branding_logo_picker_title', {
            defaultValue: 'Wybierz logo z Multimediów',
          })}
        </SheetTitle>

        {query.isLoading ? (
          <p className="mt-6 text-[13px] text-zinc-500">{t('app.loading')}</p>
        ) : images.length === 0 ? (
          <p className="mt-6 text-[13px] text-zinc-500">
            {t('catalogs_pdf.wizard.branding_logo_picker_empty', {
              defaultValue: 'Brak zdjęć w Multimediach. Najpierw prześlij plik.',
            })}
          </p>
        ) : (
          <ul className="mt-4 grid max-h-[72vh] grid-cols-3 gap-3 overflow-y-auto">
            {images.map((asset) => (
              <li key={asset.id}>
                <button
                  type="button"
                  onClick={() => {
                    if (asset.previewUrl !== null) onSelect(asset.previewUrl);
                    onClose();
                  }}
                  title={asset.filename}
                  className="focus-ring group flex w-full flex-col items-center gap-1 rounded-xl border border-zinc-200 p-2 transition hover:border-orange-300 hover:bg-orange-50/40"
                >
                  <span className="block h-16 w-full overflow-hidden rounded-lg">
                    <AssetThumb asset={asset} />
                  </span>
                  <span className="w-full truncate text-center text-[11.5px] text-zinc-600">
                    {asset.filename}
                  </span>
                </button>
              </li>
            ))}
          </ul>
        )}
      </SheetContent>
    </Sheet>
  );
}

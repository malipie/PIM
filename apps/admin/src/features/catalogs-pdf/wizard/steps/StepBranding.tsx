import { ImageIcon } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/utils';

import { AssetLogoPicker } from '../asset-logo-picker';
import { useCatalogWizard } from '../wizard-store';

const HEX_PATTERN = /^#[0-9a-fA-F]{6}$/;

/**
 * CPDF-P5-02 step 3 — branding: brand colour (hex, with a native colour
 * swatch), company name and logo (pick from Multimedia — #2569 — or a URL).
 * All optional; written straight into store.branding and posted as the catalog
 * `branding` block.
 */
export function StepBranding() {
  const { t } = useTranslation();
  const { state, dispatch } = useCatalogWizard();
  const { color, company_name, logo } = state.branding;
  const colorValid = HEX_PATTERN.test(color);
  const [logoPickerOpen, setLogoPickerOpen] = useState(false);

  return (
    <div className="space-y-5 rounded-2xl border border-zinc-200 bg-surface p-7 shadow-card">
      <div>
        <h2 className="text-[16px] font-semibold tracking-tight text-ink">
          {t('catalogs_pdf.wizard.branding_title')}
        </h2>
        <p className="mt-1 text-[13px] text-zinc-500">
          {t('catalogs_pdf.wizard.branding_subtitle')}
        </p>
      </div>

      <div className="space-y-1.5">
        <label
          htmlFor="catalog-branding-color"
          className="block text-[11px] font-medium tracking-wider text-zinc-500 uppercase"
        >
          {t('catalogs_pdf.wizard.branding_color_label')}
        </label>
        <div className="flex items-center gap-3">
          <input
            type="color"
            aria-label={t('catalogs_pdf.wizard.branding_color_swatch_aria')}
            value={colorValid ? color : '#1f2937'}
            onChange={(event) =>
              dispatch({ type: 'SET_BRANDING', patch: { color: event.target.value } })
            }
            className="focus-ring h-10 w-12 cursor-pointer rounded-xl border border-zinc-200 bg-surface p-1"
          />
          <input
            id="catalog-branding-color"
            type="text"
            value={color}
            onChange={(event) =>
              dispatch({ type: 'SET_BRANDING', patch: { color: event.target.value } })
            }
            placeholder="#1f2937"
            className={cn(
              'focus-ring h-10 w-40 rounded-xl border bg-surface px-3 font-mono text-[13px]',
              color !== '' && !colorValid ? 'border-brick-300' : 'border-zinc-200',
            )}
          />
        </div>
        {color !== '' && !colorValid && (
          <p className="text-[12px] text-brick-600">
            {t('catalogs_pdf.wizard.branding_color_invalid')}
          </p>
        )}
      </div>

      <div className="space-y-1.5">
        <label
          htmlFor="catalog-branding-company"
          className="block text-[11px] font-medium tracking-wider text-zinc-500 uppercase"
        >
          {t('catalogs_pdf.wizard.branding_company_label')}
        </label>
        <input
          id="catalog-branding-company"
          type="text"
          value={company_name}
          onChange={(event) =>
            dispatch({ type: 'SET_BRANDING', patch: { company_name: event.target.value } })
          }
          placeholder={t('catalogs_pdf.wizard.branding_company_placeholder')}
          className="focus-ring h-10 w-full max-w-md rounded-xl border border-zinc-200 bg-surface px-3 text-[13px]"
        />
      </div>

      <div className="space-y-1.5">
        <label
          htmlFor="catalog-branding-logo"
          className="block text-[11px] font-medium tracking-wider text-zinc-500 uppercase"
        >
          {t('catalogs_pdf.wizard.branding_logo_label')}
        </label>
        <div className="flex flex-wrap items-center gap-3">
          {logo !== '' ? (
            <img
              src={logo}
              alt={t('catalogs_pdf.wizard.branding_logo_preview_alt', {
                defaultValue: 'Podgląd logo',
              })}
              className="h-10 w-10 rounded-lg border border-zinc-200 bg-white object-contain"
            />
          ) : null}
          <button
            type="button"
            onClick={() => setLogoPickerOpen(true)}
            className="focus-ring inline-flex h-10 items-center gap-1.5 rounded-xl border border-zinc-200 bg-surface px-3.5 text-[13px] font-medium text-ink transition hover:border-zinc-300"
          >
            <ImageIcon className="size-4 text-zinc-500" aria-hidden />
            {t('catalogs_pdf.wizard.branding_logo_pick', { defaultValue: 'Wybierz z Multimediów' })}
          </button>
          {logo !== '' ? (
            <button
              type="button"
              onClick={() => dispatch({ type: 'SET_BRANDING', patch: { logo: '' } })}
              className="text-[12px] text-zinc-500 transition hover:text-ink"
            >
              {t('catalogs_pdf.wizard.branding_logo_clear', { defaultValue: 'Usuń' })}
            </button>
          ) : null}
        </div>
        <input
          id="catalog-branding-logo"
          type="url"
          value={logo}
          onChange={(event) =>
            dispatch({ type: 'SET_BRANDING', patch: { logo: event.target.value } })
          }
          placeholder="https://…/logo.png"
          className="focus-ring h-10 w-full max-w-md rounded-xl border border-zinc-200 bg-surface px-3 text-[13px]"
        />
        <p className="text-[12px] text-zinc-500">{t('catalogs_pdf.wizard.branding_logo_hint')}</p>
      </div>

      {logoPickerOpen ? (
        <AssetLogoPicker
          onClose={() => setLogoPickerOpen(false)}
          onSelect={(url) => dispatch({ type: 'SET_BRANDING', patch: { logo: url } })}
        />
      ) : null}
    </div>
  );
}

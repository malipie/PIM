import { Plus, Trash2 } from 'lucide-react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';

import type { PlaceDraft } from './definition-form';
import { PLACE_BLOCKS, uniqueSlug } from './flow-vocabulary';

interface PlacesSectionProps {
  places: PlaceDraft[];
  onChange: (next: PlaceDraft[]) => void;
  advanced: boolean;
  error: (field: string) => string | undefined;
}

/**
 * #3002 — the stages of the flow, in the operator's words. The machine
 * name is derived from the Polish label (`W przeglądzie` ->
 * `w_przegladzie`) and only shown in advanced mode: nobody outside the
 * team should have to know what snake_case is to configure a workflow.
 *
 * An existing place keeps its name forever (`nameLocked`) — objects carry
 * it as their status, so a rename on save would strand every one of them
 * and the backend validator would reject it anyway.
 */
export function PlacesSection({ places, onChange, advanced, error }: PlacesSectionProps) {
  const { t } = useTranslation();
  const takenNames = places.map((place) => place.name);

  const update = (index: number, patch: Partial<PlaceDraft>) => {
    onChange(
      places.map((place, i) => {
        if (i !== index) return place;
        const next = { ...place, ...patch };
        // Derive the machine name from the label while the row is new.
        if (patch.labelPl !== undefined && !place.nameLocked) {
          const others = takenNames.filter((_, other) => other !== index);
          next.name = uniqueSlug(patch.labelPl, others);
        }
        return next;
      }),
    );
  };

  const addBlock = (block: (typeof PLACE_BLOCKS)[number]) => {
    onChange([...places, { ...block, nameLocked: false }]);
  };

  const unusedBlocks = PLACE_BLOCKS.filter((block) => !takenNames.includes(block.name));

  return (
    <section className="space-y-3" data-testid="definition-places">
      <div className="flex items-center gap-3">
        <div className="flex-1">
          <h3 className="text-[15px] font-semibold text-zinc-900">
            {t('workflow.definitions.places.title', { defaultValue: 'Etapy' })}
          </h3>
          <p className="text-[12px] text-zinc-500">
            {t('workflow.definitions.places.subtitle', {
              defaultValue: 'Statusy, które zobaczy użytkownik na karcie produktu i w filtrach.',
            })}
          </p>
        </div>
        <Button
          variant="outline"
          size="sm"
          onClick={() =>
            onChange([
              ...places,
              { name: '', labelPl: '', labelEn: '', color: '#71717a', nameLocked: false },
            ])
          }
          data-testid="place-add"
        >
          <Plus className="mr-1 size-3.5" />
          {t('workflow.definitions.places.add', { defaultValue: 'Dodaj etap' })}
        </Button>
      </div>

      {error('places') === undefined ? null : (
        <p className="text-[12px] text-brick-600" role="alert">
          {error('places')}
        </p>
      )}

      <ul className="space-y-2">
        {places.map((place, index) => (
          <li
            // biome-ignore lint/suspicious/noArrayIndexKey: rows are positional editor slots
            key={index}
            className="flex flex-wrap items-end gap-3 rounded-2xl border border-zinc-200 bg-white p-3"
            data-testid={`place-row-${index}`}
          >
            <div>
              <Label htmlFor={`place-color-${index}`}>
                {t('workflow.definitions.places.color', { defaultValue: 'Kolor' })}
              </Label>
              <input
                id={`place-color-${index}`}
                type="color"
                className="block h-9 w-12 cursor-pointer rounded-md border border-zinc-200 bg-white p-1"
                value={place.color}
                onChange={(event) => update(index, { color: event.target.value })}
              />
            </div>
            <div className="w-48">
              <Label htmlFor={`place-label-pl-${index}`}>
                {t('workflow.definitions.places.label_pl', { defaultValue: 'Nazwa etapu' })}
              </Label>
              <Input
                id={`place-label-pl-${index}`}
                value={place.labelPl}
                placeholder={t('workflow.definitions.places.label_placeholder', {
                  defaultValue: 'np. W przeglądzie',
                })}
                onChange={(event) => update(index, { labelPl: event.target.value })}
                data-testid={`place-label-pl-${index}`}
              />
            </div>
            <div className="w-44">
              <Label htmlFor={`place-label-en-${index}`}>
                {t('workflow.definitions.places.label_en', { defaultValue: 'Po angielsku' })}
              </Label>
              <Input
                id={`place-label-en-${index}`}
                value={place.labelEn}
                onChange={(event) => update(index, { labelEn: event.target.value })}
              />
            </div>

            {place.name === 'draft' ? (
              <span className="mb-2 rounded-full border border-zinc-200 px-2 py-0.5 text-[11px] text-zinc-500">
                {t('workflow.definitions.places.start_badge', { defaultValue: 'start' })}
              </span>
            ) : null}
            {place.name === 'published' ? (
              <span className="mb-2 rounded-full border border-emerald-200 bg-emerald-50 px-2 py-0.5 text-[11px] text-emerald-700">
                {t('workflow.definitions.places.published_badge', {
                  defaultValue: 'widoczny w kanałach',
                })}
              </span>
            ) : null}

            {advanced ? (
              <div className="w-44">
                <Label htmlFor={`place-name-${index}`}>
                  {t('workflow.definitions.places.machine_name', {
                    defaultValue: 'Nazwa techniczna',
                  })}
                </Label>
                <Input
                  id={`place-name-${index}`}
                  value={place.name}
                  onChange={(event) =>
                    update(index, { name: event.target.value, nameLocked: true })
                  }
                  data-testid={`place-name-${index}`}
                  aria-invalid={error(`places[${index}].name`) !== undefined}
                />
              </div>
            ) : (
              <span className="mb-2 font-mono text-[11px] text-zinc-400">{place.name}</span>
            )}

            {error(`places[${index}].name`) === undefined ? null : (
              <p className="w-full text-[12px] text-brick-600" role="alert">
                {error(`places[${index}].name`)}
              </p>
            )}

            <Button
              variant="ghost"
              size="icon"
              className="ml-auto"
              disabled={place.name === 'draft'}
              onClick={() => onChange(places.filter((_, i) => i !== index))}
              aria-label={t('workflow.definitions.places.remove', { defaultValue: 'Usuń etap' })}
            >
              <Trash2 className="size-4" />
            </Button>
          </li>
        ))}
      </ul>

      {unusedBlocks.length === 0 ? null : (
        <div className="flex flex-wrap items-center gap-2">
          <span className="text-[12px] text-zinc-500">
            {t('workflow.definitions.places.blocks', { defaultValue: 'Gotowe etapy:' })}
          </span>
          {unusedBlocks.map((block) => (
            <button
              key={block.name}
              type="button"
              onClick={() => addBlock(block)}
              data-testid={`place-block-${block.name}`}
              className="focus-ring inline-flex items-center gap-1.5 rounded-lg border border-zinc-200 bg-white px-2.5 py-1 text-[12px] font-medium text-zinc-700 transition hover:border-zinc-300"
            >
              <span
                className="size-2.5 rounded-sm"
                style={{ backgroundColor: block.color }}
                aria-hidden="true"
              />
              {block.labelPl}
            </button>
          ))}
        </div>
      )}
    </section>
  );
}

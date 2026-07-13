import { useCustomMutation } from '@refinedev/core';
import { FlaskConical, Info } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { Button } from '@/components/ui/button';

import { SectionLabel, SecurityNote } from '../../components/primitives';

interface PreviewItem {
  id: string;
  code: string;
  kind: string;
  attributes: Record<string, unknown>;
}

/**
 * #2550 — live profile preview. POSTs the CURRENT (possibly-unsaved) builder
 * config to `/api/profiles/preview`, which scopes real objects by the
 * profile's filters + ObjectType list and projects them to the attribute
 * allow-list — so the operator sees exactly what a partner integration would
 * receive over the API, without minting a key or saving first (draft-config).
 */
export function ProfilePreviewPanel({
  apiUrl,
  includedAttributes,
  objectTypeIds,
  filters,
}: {
  apiUrl: string;
  includedAttributes: string[];
  objectTypeIds: string[];
  filters: Record<string, unknown>;
}) {
  const { t } = useTranslation();
  const { mutate, mutation } = useCustomMutation();
  const [items, setItems] = useState<PreviewItem[] | null>(null);

  const runTest = (): void => {
    mutate(
      {
        url: `${apiUrl}/profiles/preview`,
        method: 'post',
        values: { includedAttributes, objectTypeIds, filters, limit: 5 },
      },
      {
        onSuccess: (response) => {
          const data = response.data as { items?: PreviewItem[] };
          setItems(data.items ?? []);
        },
      },
    );
  };

  return (
    <section
      className="soft-shadow rounded-2xl border border-zinc-200 bg-white p-5"
      data-testid="profile-preview-panel"
    >
      <SectionLabel
        right={
          <Button type="button" size="sm" onClick={runTest} disabled={mutation.isPending}>
            <FlaskConical className="mr-1 size-4" aria-hidden="true" />
            {t('api_configurator.builder.preview.test', { defaultValue: 'Testuj' })}
          </Button>
        }
      >
        {t('api_configurator.builder.preview.title', { defaultValue: 'Podgląd / Test wyników' })}
      </SectionLabel>
      <p className="mb-3 text-[12.5px] leading-relaxed text-zinc-600">
        {t('api_configurator.builder.preview.hint', {
          defaultValue:
            'To widzi integrator przez API — realne obiekty przefiltrowane wg profilu, z wybranymi atrybutami.',
        })}
      </p>

      {items === null ? (
        <SecurityNote tone="zinc" icon={<Info className="size-4" />}>
          {t('api_configurator.builder.preview.idle', {
            defaultValue: 'Kliknij „Testuj”, aby zobaczyć próbkę realnych danych dla tego profilu.',
          })}
        </SecurityNote>
      ) : items.length === 0 ? (
        <p className="text-[12.5px] text-zinc-500" data-testid="profile-preview-empty">
          {t('api_configurator.builder.preview.empty', {
            defaultValue: 'Brak obiektów pasujących do filtra profilu.',
          })}
        </p>
      ) : (
        <div className="space-y-2" data-testid="profile-preview-items">
          {items.map((item) => (
            <div key={item.id} className="rounded-xl border border-zinc-100 bg-zinc-50/60 p-3">
              <div className="mb-1 flex items-center gap-2">
                <span className="font-mono text-[12px] font-medium text-zinc-900">{item.code}</span>
                <span className="rounded bg-zinc-200/70 px-1.5 py-0.5 text-[10.5px] text-zinc-600">
                  {item.kind}
                </span>
              </div>
              <pre className="overflow-x-auto whitespace-pre-wrap break-words text-[11.5px] text-zinc-600">
                {JSON.stringify(item.attributes, null, 2)}
              </pre>
            </div>
          ))}
        </div>
      )}
    </section>
  );
}

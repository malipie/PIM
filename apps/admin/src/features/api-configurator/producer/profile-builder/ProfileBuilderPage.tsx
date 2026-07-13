import { useApiUrl, useCreate, useCustom, useList, useOne, useUpdate } from '@refinedev/core';
import { ArrowLeft, Info } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router';

import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';

import { Field, SecurityNote } from '../../components/primitives';
import { AttributePicker } from './AttributePicker';
import {
  type BuilderAttribute,
  labelText,
  type ObjectTypeRow,
  type ProfileDetail,
  slugify,
} from './builder-helpers';
import { ProfilePreviewPanel } from './ProfilePreviewPanel';

const HUB = '/integrations/api-configurator';

/**
 * APIC-P4-07 — the full-screen API-profile builder (`integracje/api-producer.jsx`
 * ApiProfilePage). Left panel: name→slug, access (read-only — profiles are read
 * projections in MVP), a JSON filter, and the ObjectType multiselect. Right
 * panel: the searchable attribute picker (P4-04 builder_options). New mode
 * (POST) when there is no :id; edit mode (PATCH) hydrates from the profile.
 */
export function ProfileBuilderPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const apiUrl = useApiUrl();
  const { id = null } = useParams();
  const editing = id !== null;

  const profileQuery = useOne<ProfileDetail>({
    resource: 'api_profiles',
    id: id ?? '',
    queryOptions: { enabled: editing },
  });
  const objectTypesQuery = useList<ObjectTypeRow>({
    resource: 'object_types',
    pagination: { mode: 'off' },
  });
  const optionsQuery = useCustom<{ attributes: BuilderAttribute[] }>({
    url: `${apiUrl}/profiles/builder_options`,
    method: 'get',
  });

  const objectTypes = objectTypesQuery.result.data;
  const attributePool = optionsQuery.result?.data?.attributes ?? [];

  const [name, setName] = useState('');
  const [code, setCode] = useState('');
  const [codeTouched, setCodeTouched] = useState(false);
  const [filters, setFilters] = useState('');
  const [selectedTypes, setSelectedTypes] = useState<Set<string>>(new Set());
  const [selectedAttrs, setSelectedAttrs] = useState<Set<string>>(new Set());
  const [saveError, setSaveError] = useState<string | null>(null);

  // Hydrate from the loaded profile (edit mode).
  const profile = profileQuery.result ?? null;
  useEffect(() => {
    if (profile === null) {
      return;
    }
    setName(profile.name);
    setCode(profile.code);
    setCodeTouched(true);
    setSelectedTypes(new Set(profile.objectTypeIds ?? []));
    setSelectedAttrs(new Set(profile.includedAttributes ?? []));
    setFilters(
      profile.filters !== undefined && Object.keys(profile.filters).length > 0
        ? JSON.stringify(profile.filters, null, 2)
        : '',
    );
  }, [profile]);

  const effectiveCode = codeTouched ? code : slugify(name);

  const { mutate: create, mutation: createState } = useCreate();
  const { mutate: update, mutation: updateState } = useUpdate();
  const saving = createState.isPending || updateState.isPending;

  function parseFilters(): Record<string, unknown> | null {
    const raw = filters.trim();
    if (raw === '') {
      return {};
    }
    try {
      const parsed: unknown = JSON.parse(raw);
      if (parsed === null || typeof parsed !== 'object' || Array.isArray(parsed)) {
        return null;
      }
      return parsed as Record<string, unknown>;
    } catch {
      return null;
    }
  }

  function save(): void {
    const parsedFilters = parseFilters();
    if (parsedFilters === null) {
      setSaveError(t('api_configurator.builder.filters_invalid'));
      return;
    }
    setSaveError(null);

    const values = {
      name: name.trim(),
      objectTypeIds: [...selectedTypes],
      includedAttributes: [...selectedAttrs],
      filters: parsedFilters,
    };

    if (editing && id !== null) {
      update(
        { resource: 'api_profiles', id, values, successNotification: false },
        { onSuccess: () => navigate(HUB) },
      );
      return;
    }
    create(
      {
        resource: 'api_profiles',
        values: { ...values, code: effectiveCode, outputFormat: 'json_ld' },
        successNotification: false,
      },
      { onSuccess: () => navigate(HUB) },
    );
  }

  const footerSummary = useMemo(
    () =>
      t('api_configurator.builder.summary', {
        types: selectedTypes.size,
        attrs: selectedAttrs.size,
      }),
    [selectedTypes.size, selectedAttrs.size, t],
  );

  return (
    <div className="space-y-5">
      <div className="flex items-center gap-3">
        <Button
          variant="outline"
          size="icon"
          onClick={() => navigate(HUB)}
          aria-label={t('api_configurator.builder.back')}
        >
          <ArrowLeft className="size-4" aria-hidden="true" />
        </Button>
        <div className="min-w-0 flex-1">
          <h1 className="font-display text-[22px] font-semibold tracking-tight">
            {editing
              ? t('api_configurator.builder.title_edit')
              : t('api_configurator.builder.title_new')}
          </h1>
          <p className="text-[12.5px] text-zinc-500">{t('api_configurator.builder.subtitle')}</p>
        </div>
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1fr_1fr]">
        <div className="space-y-4">
          <section className="soft-shadow space-y-5 rounded-2xl border border-zinc-200 bg-white p-5">
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <Field label={t('api_configurator.builder.name')} required>
                <Input
                  value={name}
                  onChange={(e) => setName(e.target.value)}
                  placeholder={t('api_configurator.builder.name_placeholder')}
                  aria-label={t('api_configurator.builder.name')}
                  className="h-10"
                />
              </Field>
              <Field
                label={t('api_configurator.builder.code')}
                hint={t('api_configurator.builder.code_hint')}
              >
                <Input
                  value={effectiveCode}
                  onChange={(e) => {
                    setCodeTouched(true);
                    setCode(e.target.value);
                  }}
                  disabled={editing}
                  aria-label={t('api_configurator.builder.code')}
                  className="h-10 font-mono"
                />
              </Field>
            </div>

            <Field label={t('api_configurator.builder.access')}>
              <div className="inline-flex items-center rounded-xl bg-zinc-100 p-1">
                <span className="rounded-lg bg-white px-3 py-1.5 text-[12.5px] font-medium text-zinc-900 soft-shadow">
                  {t('api_configurator.builder.access_read_only')}
                </span>
              </div>
            </Field>

            <Field
              label={t('api_configurator.builder.filters')}
              hint={t('api_configurator.builder.filters_hint')}
            >
              <textarea
                value={filters}
                onChange={(e) => setFilters(e.target.value)}
                placeholder={'{ "status": "active" }'}
                aria-label={t('api_configurator.builder.filters')}
                rows={3}
                className="focus-ring w-full rounded-xl border border-zinc-200 bg-white px-3 py-2 font-mono text-[12px]"
              />
            </Field>
          </section>

          <section className="soft-shadow rounded-2xl border border-zinc-200 bg-white p-5">
            <Field
              label={t('api_configurator.builder.object_types')}
              hint={t('api_configurator.builder.multiselect')}
            >
              <div className="flex flex-wrap gap-2">
                {objectTypes.map((ot) => {
                  const on = selectedTypes.has(ot.id);
                  return (
                    <button
                      key={ot.id}
                      type="button"
                      aria-pressed={on}
                      onClick={() =>
                        setSelectedTypes((prev) => {
                          const next = new Set(prev);
                          if (next.has(ot.id)) {
                            next.delete(ot.id);
                          } else {
                            next.add(ot.id);
                          }
                          return next;
                        })
                      }
                      className={`h-8 rounded-lg border px-3 text-[12px] font-medium transition ${on ? 'border-zinc-900 bg-zinc-900 text-white' : 'border-zinc-200 bg-white text-zinc-600 hover:bg-zinc-50'}`}
                    >
                      {labelText(ot.label, ot.code)}
                    </button>
                  );
                })}
              </div>
            </Field>
          </section>

          <SecurityNote tone="zinc" icon={<Info className="size-4" />}>
            {t('api_configurator.builder.projection_note')}
          </SecurityNote>
        </div>

        <AttributePicker
          attributes={attributePool}
          selected={selectedAttrs}
          onToggle={(c) =>
            setSelectedAttrs((prev) => {
              const next = new Set(prev);
              if (next.has(c)) {
                next.delete(c);
              } else {
                next.add(c);
              }
              return next;
            })
          }
          onSelectAll={() => setSelectedAttrs(new Set(attributePool.map((a) => a.code)))}
          onSelectNone={() => setSelectedAttrs(new Set())}
        />
      </div>

      {/* #2550 — live preview of what the integrator receives for this config. */}
      <ProfilePreviewPanel
        apiUrl={apiUrl}
        includedAttributes={[...selectedAttrs]}
        objectTypeIds={[...selectedTypes]}
        filters={parseFilters() ?? {}}
      />

      {saveError !== null ? (
        <div className="rounded-xl border border-rose-200 bg-rose-50/60 p-3 text-[12.5px] text-rose-800">
          {saveError}
        </div>
      ) : null}

      <div className="flex items-center gap-3 pt-1">
        <div className="hidden text-[12px] text-zinc-500 sm:block">{footerSummary}</div>
        <div className="flex-1" />
        <Button type="button" onClick={save} disabled={saving || name.trim() === ''}>
          {editing
            ? t('api_configurator.builder.save_edit')
            : t('api_configurator.builder.save_new')}
        </Button>
      </div>
    </div>
  );
}

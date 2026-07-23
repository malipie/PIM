import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ArrowLeft, Check, Library, Lock, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { Link, useNavigate } from 'react-router';

import { AddAttributesToObjectTypeDialog } from '@/components/modeling/add-attributes-to-object-type-dialog';
import { AuditLogIndicator } from '@/components/modeling/audit-log-indicator';
import { DEFAULT_WIZARD_COLORS } from '@/components/modeling/color-picker';
import { CreateAttributeForObjectTypeDialog } from '@/components/modeling/create-attribute-for-object-type-dialog';
import { CreateGroupInlineDialog } from '@/components/modeling/create-group-inline-dialog';
import { DeclareObjectTypeAttributeGroupDialog } from '@/components/modeling/declare-object-type-attribute-group-dialog';
import { DisplayModeSegmented } from '@/components/modeling/group-card';
import { DEFAULT_WIZARD_ICONS } from '@/components/modeling/icon-picker';
import { LocaleTabsField } from '@/components/modeling/locale-tabs-field';
import { SettingToggleRow } from '@/components/modeling/setting-toggle-row';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { HttpError, jsonFetch } from '@/lib/http';
import { useCurrentWorkspace, useInvalidateCurrentWorkspace } from '@/lib/use-current-workspace';
import { cn } from '@/lib/utils';

interface CreatedObjectType {
  id: string;
  code: string;
  kind: string;
  label: Record<string, string>;
  builtIn: boolean;
}

interface AttributeGroupRow {
  id: string;
  code: string;
  label?: Record<string, string> | string | null;
  icon?: string | null;
  color?: string | null;
  is_system_group?: boolean;
  isSystemGroup?: boolean;
}

interface AttributeRow {
  id: string;
  code: string;
  type: string;
  label?: Record<string, string> | string | null;
  system?: boolean;
}

function resolveGroupLabel(
  label: Record<string, string> | string | null | undefined,
  language: string,
): string {
  if (typeof label === 'string') return label;
  if (label && typeof label === 'object') {
    return label[language] ?? label.pl ?? label.en ?? Object.values(label)[0] ?? '';
  }
  return '';
}

const STEP_KEYS = ['identification', 'attributes', 'settings', 'summary'] as const;

/**
 * VIEW-01 (#372) — full-screen 4-step wizard for `/modeling/object-types/new`.
 * Renders inline within the modeling shell (NOT a dialog/sheet) so the
 * sidebar workspace + topbar stay visible. Layout matches
 * `NewObjectTypeView` (object-types.jsx 304–478) — content column +
 * 320px sidebar with live preview and tips.
 */
export function ObjectTypeWizard() {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const workspace = useCurrentWorkspace();
  const invalidateWorkspace = useInvalidateCurrentWorkspace();

  const enabledLocales = workspace.data?.enabledLocales ?? ['pl', 'en'];
  const primaryLocale = workspace.data?.primaryLocale ?? 'pl';

  const [step, setStep] = useState<number>(1);
  const [label, setLabel] = useState<Record<string, string>>({});
  const [code, setCode] = useState<string>('');
  // #2578 — icon/color are no longer user-pickable; keep sensible defaults so
  // the badge stays consistent and the payload contract is unchanged.
  const icon = DEFAULT_WIZARD_ICONS[0];
  const color = DEFAULT_WIZARD_COLORS[0];
  const [hasVariants, setHasVariants] = useState(false);
  const [hasMultimedia, setHasMultimedia] = useState(false);
  const [isCategorizable, setIsCategorizable] = useState(false);
  const [exposeToMainMenu, setExposeToMainMenu] = useState(false);
  const [pickedGroupIds, setPickedGroupIds] = useState<Set<string>>(new Set());
  // MODR-04 (#926) — display_mode per picked group. Defaults to 'tab'
  // (matching the DB column default from MODR-01); the user can flip
  // any row to 'stacked' via the segmented control on step 2.
  const [pickedGroupDisplayModes, setPickedGroupDisplayModes] = useState<
    Record<string, 'tab' | 'stacked'>
  >({});
  const [pickedAttributeIds, setPickedAttributeIds] = useState<Set<string>>(new Set());
  const [declareGroupOpen, setDeclareGroupOpen] = useState(false);
  const [createGroupOpen, setCreateGroupOpen] = useState(false);
  const [addAttrOpen, setAddAttrOpen] = useState(false);
  const [createAttrOpen, setCreateAttrOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const namePrimary = label[primaryLocale]?.trim() ?? '';
  const queryClient = useQueryClient();

  const { data: attributeGroups = [] } = useQuery<AttributeGroupRow[]>({
    queryKey: ['attribute_groups', 'picker'],
    queryFn: async () => {
      const data = await jsonFetch<{ member?: AttributeGroupRow[] }>(
        '/api/attribute_groups?itemsPerPage=200',
      );
      return data.member ?? [];
    },
    staleTime: 30_000,
  });

  const { data: attributes = [] } = useQuery<AttributeRow[]>({
    queryKey: ['attributes', 'picker'],
    queryFn: async () => {
      const data = await jsonFetch<{ member?: AttributeRow[] }>('/api/attributes?itemsPerPage=200');
      return data.member ?? [];
    },
    staleTime: 30_000,
  });

  const pickedGroupRows = useMemo(
    () => attributeGroups.filter((g) => pickedGroupIds.has(g.id)),
    [attributeGroups, pickedGroupIds],
  );

  const pickedAttributeRows = useMemo(
    () => attributes.filter((a) => pickedAttributeIds.has(a.id)),
    [attributes, pickedAttributeIds],
  );

  const removeGroup = (id: string) => {
    setPickedGroupIds((prev) => {
      const next = new Set(prev);
      next.delete(id);
      return next;
    });
    setPickedGroupDisplayModes((prev) => {
      const { [id]: _removed, ...rest } = prev;
      return rest;
    });
  };

  const setGroupDisplayMode = (id: string, mode: 'tab' | 'stacked') => {
    setPickedGroupDisplayModes((prev) => ({ ...prev, [id]: mode }));
  };

  const removeAttribute = (id: string) => {
    setPickedAttributeIds((prev) => {
      const next = new Set(prev);
      next.delete(id);
      return next;
    });
  };

  const validateStep = (n: number): string | null => {
    if (n === 1) {
      if (namePrimary === '') {
        return t('object_type_wizard.validation_name_pl_required', {
          defaultValue: 'Nazwa PL jest wymagana.',
        });
      }
      if (code === '') {
        return t('object_type_wizard.validation_code_required', {
          defaultValue: 'Code jest wymagany.',
        });
      }
      if (!/^[a-z0-9_]+$/.test(code)) {
        return t('object_type_wizard.validation_code_format', {
          defaultValue: 'Code musi być w snake_case (małe litery, cyfry, _).',
        });
      }
    }
    return null;
  };

  const goNext = () => {
    setError(null);
    const issue = validateStep(step);
    if (issue) {
      setError(issue);
      return;
    }
    setStep((s) => Math.min(STEP_KEYS.length, s + 1));
  };

  const goPrev = () => {
    setError(null);
    setStep((s) => Math.max(1, s - 1));
  };

  const handleSubmit = async () => {
    const issue = validateStep(1);
    if (issue) {
      setError(issue);
      setStep(1);
      return;
    }
    setSubmitting(true);
    setError(null);

    try {
      const created = await jsonFetch<CreatedObjectType>('/api/object_types', {
        method: 'POST',
        body: {
          code,
          label: Object.fromEntries(
            Object.entries(label).filter(([, v]) => v && v.trim().length > 0),
          ),
          icon,
          color,
          hasVariants,
        },
      });

      const failedGroups: string[] = [];
      for (const groupId of pickedGroupIds) {
        try {
          await jsonFetch(`/api/object_types/${created.id}/groups/${groupId}`, {
            method: 'POST',
          });
          // MODR-04 (#926) — apply the chosen display_mode only when it
          // differs from the column default ('tab'). Failures here are
          // surfaced via the same failedGroups bucket — the junction
          // exists with default placement, the operator can re-toggle
          // from the detail page.
          const mode = pickedGroupDisplayModes[groupId];
          if (mode && mode !== 'tab') {
            await jsonFetch(`/api/object_types/${created.id}/groups/${groupId}`, {
              method: 'PATCH',
              contentType: 'application/json',
              body: { display_mode: mode },
            });
          }
        } catch {
          failedGroups.push(groupId);
        }
      }

      let failedAttrs = 0;
      if (pickedAttributeIds.size > 0) {
        try {
          await jsonFetch(`/api/object_types/${created.id}/attributes/bulk-attach`, {
            method: 'POST',
            contentType: 'application/json',
            body: { attributeIds: Array.from(pickedAttributeIds) },
          });
        } catch {
          failedAttrs = pickedAttributeIds.size;
        }
      }

      // VIEW-08 / UX-07 follow-up — POST /api/object_types nie obsługuje
      // exposeToMainMenu / hasMultimedia / isCategorizable w body, więc
      // gdy operator włączył któryś z tych toggles w step 3, dosyłamy
      // pojedynczy PATCH zaraz po stworzeniu (jeden round-trip dla
      // wszystkich trzech).
      let exposeFailed = false;
      const postCreatePayload: Record<string, unknown> = {};
      if (exposeToMainMenu) postCreatePayload.exposeToMainMenu = true;
      if (hasMultimedia) postCreatePayload.hasMultimedia = true;
      if (isCategorizable) postCreatePayload.isCategorizable = true;
      if (Object.keys(postCreatePayload).length > 0) {
        try {
          await jsonFetch(`/api/object_types/${created.id}`, {
            method: 'PATCH',
            contentType: 'application/merge-patch+json',
            body: postCreatePayload,
          });
        } catch {
          exposeFailed = true;
        }
      }

      const issues: string[] = [];
      if (failedGroups.length > 0) {
        issues.push(
          t('object_type_wizard.attach_groups_partial_error', {
            defaultValue: '{{count}} grup atrybutów nie zostało dołączonych',
            count: failedGroups.length,
          }),
        );
      }
      if (failedAttrs > 0) {
        issues.push(
          t('object_type_wizard.attach_attrs_partial_error', {
            defaultValue: '{{count}} atrybutów nie zostało dołączonych',
            count: failedAttrs,
          }),
        );
      }
      if (exposeFailed) {
        issues.push(
          t('object_type_wizard.expose_menu_failed', {
            defaultValue:
              'Nie udało się włączyć „Udostępnij do głównego menu" — ustaw ręcznie w detalu',
          }),
        );
      }
      if (issues.length > 0) {
        setError(
          t('object_type_wizard.attach_partial_prefix', {
            defaultValue: 'Typ utworzono, ale: {{issues}}. Dokończ na widoku detalu.',
            issues: issues.join(', '),
          }),
        );
      }

      void invalidateWorkspace();
      navigate(`/modeling/object-types/${created.id}`, { replace: true });
    } catch (e) {
      if (e instanceof HttpError && e.status === 409) {
        setError(
          t('object_type_wizard.conflict_code_taken', {
            defaultValue: 'Code „{{code}}" jest już zajęty w tym tenancie.',
            code,
          }),
        );
        setStep(1);
      } else {
        setError(e instanceof Error ? e.message : 'unknown');
      }
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div>
      <div className="mb-4 flex items-center justify-between">
        <Button
          asChild
          variant="ghost"
          size="sm"
          className="-ml-3 gap-1.5 text-[12.5px] font-medium text-zinc-500 hover:text-zinc-900"
        >
          <Link to="/modeling/object-types">
            <ArrowLeft className="size-3.5" />
            {t('object_types.back_to_list', { defaultValue: 'Wstecz do listy Object Types' })}
          </Link>
        </Button>
        <AuditLogIndicator />
      </div>

      <header className="mb-6 flex items-start justify-between gap-6">
        <div className="flex-1">
          <div className="text-[13px] font-medium text-zinc-500">
            {t('object_type_wizard.title_new', { defaultValue: 'Nowy ObjectType' })}
          </div>
          <div className="display text-[28px] font-semibold tracking-tight">
            {namePrimary || t('object_type_wizard.default_name', { defaultValue: 'Bez nazwy' })}
          </div>
          <p className="mt-1 max-w-2xl text-[13px] text-zinc-500">
            {t('object_type_wizard.intro', {
              defaultValue:
                'Stwórz nowy rodzaj obiektu w swoim PIM-ie. Po utworzeniu będzie można dodawać instancje, podłączać atrybuty i mapować integracje.',
            })}
          </p>
        </div>
        {/* Single navy action bar (ObjectType-style); the finish CTA lives
            here, not duplicated in the step footer. */}
        <div className="flex shrink-0 items-center gap-2">
          <Button asChild variant="ghost" className="h-9 rounded-xl px-3 text-[13px]">
            <Link to="/modeling/object-types">
              {t('object_type_wizard.cancel', { defaultValue: 'Anuluj' })}
            </Link>
          </Button>
          <Button
            type="button"
            disabled={submitting}
            onClick={() => void handleSubmit()}
            className="h-9 gap-1.5 rounded-xl bg-zinc-900 px-4 text-[13px] text-white hover:bg-zinc-800"
          >
            <Check className="size-3.5" />
            {submitting
              ? t('object_type_wizard.submitting', { defaultValue: 'Tworzenie…' })
              : t('object_type_wizard.submit', { defaultValue: 'Utwórz typ' })}
          </Button>
        </div>
      </header>

      <ol
        className="mb-6 flex flex-wrap items-center gap-2"
        role="progressbar"
        aria-valuenow={step}
        aria-valuemin={1}
        aria-valuemax={STEP_KEYS.length}
        aria-label={t('object_type_wizard.progress_aria', {
          defaultValue: 'Krok {{n}} z {{max}}',
          n: step,
          max: STEP_KEYS.length,
        })}
      >
        {STEP_KEYS.map((key, idx) => {
          const n = idx + 1;
          const isActive = step === n;
          const isDone = step > n;
          return (
            <li key={key} className="flex items-center gap-2">
              <button
                type="button"
                onClick={() => setStep(n)}
                className={cn(
                  'inline-flex h-8 items-center gap-2 rounded-xl px-3 text-[12.5px] font-medium transition focus:outline-none focus-visible:ring-2 focus-visible:ring-ring',
                  isActive
                    ? 'bg-zinc-900 text-white'
                    : isDone
                      ? 'bg-zinc-100 text-zinc-700'
                      : 'border border-zinc-200 bg-white text-zinc-500',
                )}
              >
                <span
                  className={cn(
                    'num grid size-5 place-items-center rounded-full text-[10.5px] font-semibold',
                    isActive
                      ? 'bg-white/15'
                      : isDone
                        ? 'bg-emerald-500 text-white'
                        : 'bg-zinc-100 text-zinc-500',
                  )}
                >
                  {isDone ? <Check className="size-2.5" /> : n}
                </span>
                {t(`object_type_wizard.step_${n}_label`, {
                  defaultValue:
                    n === 1
                      ? 'Identyfikacja'
                      : n === 2
                        ? 'Atrybuty'
                        : n === 3
                          ? 'Ustawienia'
                          : 'Podsumowanie',
                })}
              </button>
              {idx < STEP_KEYS.length - 1 ? <span className="block h-px w-6 bg-zinc-200" /> : null}
            </li>
          );
        })}
      </ol>

      <div className="grid gap-6 lg:grid-cols-[1fr_320px]">
        <Card>
          <CardContent className="space-y-5 p-6">
            {step === 1 ? (
              <>
                <div className="text-[11px] font-medium uppercase tracking-wider text-zinc-500">
                  {t('object_type_wizard.step_1_label', { defaultValue: 'Identyfikacja' })}
                </div>
                <div>
                  <div className="mb-2 text-[11.5px] font-medium text-zinc-500">
                    {t('object_type_wizard.field_name', { defaultValue: 'Nazwa' })}
                  </div>
                  <LocaleTabsField
                    values={label}
                    enabledLocales={enabledLocales}
                    primaryLocale={primaryLocale}
                    placeholder={t('object_type_wizard.name_placeholder', {
                      defaultValue: 'np. Subskrypcja',
                    })}
                    onChange={setLabel}
                  />
                </div>
                <div>
                  <div className="mb-1.5 text-[11.5px] font-medium text-zinc-500">
                    {t('object_type_wizard.field_code', { defaultValue: 'Code' })}
                  </div>
                  <Input
                    value={code}
                    onChange={(e) =>
                      setCode(e.target.value.toLowerCase().replace(/[^a-z0-9_]/g, ''))
                    }
                    placeholder="subscription"
                    className="font-mono"
                  />
                </div>
              </>
            ) : null}
            {step === 2 ? (
              <>
                <div className="flex items-center justify-between">
                  <div className="text-[11px] font-medium uppercase tracking-wider text-zinc-500">
                    {t('object_type_wizard.step_2_groups_section', {
                      defaultValue: 'Grupy atrybutów',
                    })}
                  </div>
                  <div className="flex items-center gap-2">
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      className="h-8 gap-1.5 text-[12px] font-medium"
                      onClick={() => setDeclareGroupOpen(true)}
                    >
                      <Library className="size-3.5" />
                      {t('object_type_wizard.step_2_groups_from_library', {
                        defaultValue: 'Z biblioteki',
                      })}
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      className="h-8 gap-1.5 text-[12px] font-medium"
                      onClick={() => setCreateGroupOpen(true)}
                    >
                      <Plus className="size-3.5" />
                      {t('object_type_wizard.step_2_groups_create_new', {
                        defaultValue: 'Stwórz nowy',
                      })}
                    </Button>
                  </div>
                </div>
                <p className="-mt-2 text-[12.5px] text-zinc-500">
                  {t('object_type_wizard.step_2_groups_intro', {
                    defaultValue:
                      'Built-in (Identyfikacja, Audyt, Lokalizacje) zostaną dołączone automatycznie. Tu wybierz dodatkowe.',
                  })}
                </p>
                {pickedGroupRows.length === 0 ? (
                  <div className="rounded-2xl border border-dashed border-zinc-200 px-4 py-6 text-center text-[12.5px] text-zinc-500">
                    {t('object_type_wizard.step_2_groups_empty', {
                      defaultValue:
                        'Brak wybranych grup. Użyj „Z biblioteki" lub „Stwórz nowy" aby dodać.',
                    })}
                  </div>
                ) : (
                  <div className="space-y-1.5">
                    {pickedGroupRows.map((g) => {
                      const labelText = resolveGroupLabel(g.label, i18n.language) || g.code;
                      const displayMode = pickedGroupDisplayModes[g.id] ?? 'tab';
                      return (
                        <div
                          key={g.id}
                          className="flex items-center gap-3 rounded-xl border border-zinc-100 bg-white px-3 py-2"
                        >
                          <span
                            className="grid size-8 shrink-0 place-items-center rounded-md text-[14px]"
                            style={{
                              background: g.color ? `${g.color}1f` : '#f4f4f5',
                              color: g.color ?? '#71717a',
                            }}
                            aria-hidden
                          >
                            {g.icon ?? '📦'}
                          </span>
                          <div className="min-w-0 flex-1">
                            <div className="truncate text-[13px] font-medium tracking-tight">
                              {labelText}
                            </div>
                            <div className="truncate font-mono text-[11px] text-zinc-500">
                              {g.code}
                            </div>
                          </div>
                          <DisplayModeSegmented
                            value={displayMode}
                            onChange={(next) => setGroupDisplayMode(g.id, next)}
                            labelTab={t('object_type_wizard.group_display_mode_tab', {
                              defaultValue: 'Zakładka',
                            })}
                            labelStacked={t('object_type_wizard.group_display_mode_stacked', {
                              defaultValue: 'Inline',
                            })}
                            tooltip={t('object_type_wizard.group_display_mode_hint', {
                              defaultValue:
                                'Zakładka — własny tab. Inline — sekcja w karcie atrybutów.',
                            })}
                          />
                          <button
                            type="button"
                            aria-label={t('object_type_wizard.remove_group', {
                              defaultValue: 'Usuń grupę',
                            })}
                            onClick={() => removeGroup(g.id)}
                            className="grid size-8 place-items-center rounded-lg text-zinc-500 transition hover:bg-rose-50 hover:text-rose-600"
                          >
                            <Trash2 className="size-3.5" />
                          </button>
                        </div>
                      );
                    })}
                  </div>
                )}

                <div className="flex items-center justify-between border-t border-zinc-100 pt-5">
                  <div className="text-[11px] font-medium uppercase tracking-wider text-zinc-500">
                    {t('object_type_wizard.step_2_attrs_section', {
                      defaultValue: 'Atrybuty',
                    })}
                  </div>
                  <div className="flex items-center gap-2">
                    <Button
                      type="button"
                      variant="ghost"
                      size="sm"
                      className="h-8 gap-1.5 text-[12px] font-medium"
                      onClick={() => setAddAttrOpen(true)}
                    >
                      <Library className="size-3.5" />
                      {t('object_type_wizard.step_2_attrs_from_library', {
                        defaultValue: 'Z biblioteki',
                      })}
                    </Button>
                    <Button
                      type="button"
                      size="sm"
                      className="h-8 gap-1.5 text-[12px] font-medium"
                      onClick={() => setCreateAttrOpen(true)}
                    >
                      <Plus className="size-3.5" />
                      {t('object_type_wizard.step_2_attrs_create_new', {
                        defaultValue: 'Stwórz nowy',
                      })}
                    </Button>
                  </div>
                </div>
                <p className="-mt-2 text-[12.5px] text-zinc-500">
                  {t('object_type_wizard.step_2_attrs_intro', {
                    defaultValue:
                      'Pojedyncze atrybuty dołączone bezpośrednio do typu (poza grupami).',
                  })}
                </p>
                {pickedAttributeRows.length === 0 ? (
                  <div className="rounded-2xl border border-dashed border-zinc-200 px-4 py-6 text-center text-[12.5px] text-zinc-500">
                    {t('object_type_wizard.step_2_attrs_empty', {
                      defaultValue:
                        'Brak wybranych atrybutów. Użyj „Z biblioteki" lub „Stwórz nowy" aby dodać.',
                    })}
                  </div>
                ) : (
                  <div className="space-y-1.5">
                    {pickedAttributeRows.map((a) => {
                      const labelText =
                        typeof a.label === 'string'
                          ? a.label
                          : resolveGroupLabel(a.label, i18n.language) || a.code;
                      return (
                        <div
                          key={a.id}
                          className="grid grid-cols-[1fr_120px_40px] items-center gap-3 rounded-xl border border-zinc-100 bg-white px-3 py-2"
                        >
                          <div className="min-w-0">
                            <div className="truncate font-mono text-[13px] font-medium">
                              {a.code}
                            </div>
                            <div className="truncate text-[11px] text-zinc-500">{labelText}</div>
                          </div>
                          <span className="rounded-md bg-zinc-100 px-2 py-0.5 text-center text-[11px] font-medium uppercase text-zinc-700">
                            {a.type}
                          </span>
                          <button
                            type="button"
                            aria-label={t('object_type_wizard.remove_attribute', {
                              defaultValue: 'Usuń atrybut',
                            })}
                            onClick={() => removeAttribute(a.id)}
                            className="grid size-8 place-items-center rounded-lg text-zinc-500 transition hover:bg-rose-50 hover:text-rose-600"
                          >
                            <Trash2 className="size-3.5" />
                          </button>
                        </div>
                      );
                    })}
                  </div>
                )}
              </>
            ) : null}
            {step === 3 ? (
              <>
                <div className="text-[11px] font-medium uppercase tracking-wider text-zinc-500">
                  {t('object_type_wizard.step_3_label', { defaultValue: 'Ustawienia' })}
                </div>
                <SettingToggleRow
                  label={t('object_types.setting_variants_label', {
                    defaultValue: 'Czy mają warianty?',
                  })}
                  description={t('object_types.setting_variants_desc', {
                    defaultValue:
                      'Włącza zakładkę „Warianty" w karcie obiektu (np. Produkt → kolor × rozmiar).',
                  })}
                  checked={hasVariants}
                  onChange={setHasVariants}
                />
                <SettingToggleRow
                  label={t('object_types.setting_multimedia_label', {
                    defaultValue: 'Czy obiekty tego typu mają zdjęcia i pliki?',
                  })}
                  description={t('object_types.setting_multimedia_desc', {
                    defaultValue:
                      'Włącza zakładkę „Multimedia" w karcie obiektu — biblioteka zdjęć i plików.',
                  })}
                  checked={hasMultimedia}
                  onChange={setHasMultimedia}
                />
                <SettingToggleRow
                  label={t('object_types.setting_categorizable_label', {
                    defaultValue: 'Czy można je przypisywać do kategorii?',
                  })}
                  description={t('object_types.setting_categorizable_desc_wizard', {
                    defaultValue:
                      'Włącza zakładkę „Kategorie" w karcie obiektu. Instancje muszą wybrać kategorię główną przy tworzeniu — jej ścieżka root→leaf dodaje atrybuty kumulatywnie.',
                  })}
                  checked={isCategorizable}
                  onChange={setIsCategorizable}
                />
                {/* VIEW-08 (#427) — main menu candidacy. Mirrors show.tsx
                    so operator can promote the new ObjectType in the
                    same step instead of doing a separate PATCH later. */}
                <div className="border-t border-zinc-100 pt-5">
                  <SettingToggleRow
                    label={t('object_types.setting_expose_menu_label', {
                      defaultValue: 'Udostępnij do głównego menu',
                    })}
                    description={t('object_types.setting_expose_menu_desc', {
                      defaultValue:
                        'Po włączeniu ten ObjectType pojawi się jako dostępna pozycja w Ustawieniach → Menu. Tam zdecydujesz, czy ostatecznie pojawi się w głównym menu i w jakiej kolejności.',
                    })}
                    checked={exposeToMainMenu}
                    onChange={setExposeToMainMenu}
                  />
                  {exposeToMainMenu ? (
                    <div className="mt-2 text-[11.5px] text-zinc-500">
                      {t('object_types.setting_expose_menu_link_prefix', {
                        defaultValue: 'Zarządzaj kolejnością i widocznością w',
                      })}{' '}
                      <Link
                        to="/settings/menu"
                        className="text-orange-700 underline underline-offset-2 hover:text-orange-700/80"
                      >
                        {t('object_types.setting_expose_menu_link', {
                          defaultValue: 'Ustawienia → Menu',
                        })}
                      </Link>
                      .
                    </div>
                  ) : null}
                </div>
              </>
            ) : null}
            {step === 4 ? (
              <>
                <div className="text-[11px] font-medium uppercase tracking-wider text-zinc-500">
                  {t('object_type_wizard.step_4_label', { defaultValue: 'Podsumowanie' })}
                </div>
                <p className="text-[13px] text-zinc-600">
                  {t('object_type_wizard.step_4_intro', {
                    defaultValue:
                      'Sprawdź ustawienia i zatwierdź. Po utworzeniu typ pojawi się w sekcji Custom.',
                  })}
                </p>
                <div className="grid grid-cols-1 gap-3 rounded-2xl bg-zinc-50 p-4 text-[13px] sm:grid-cols-2">
                  <div>
                    <span className="text-zinc-500">
                      {t('object_type_wizard.field_name', { defaultValue: 'Nazwa' })}:
                    </span>{' '}
                    <span className="font-medium">{namePrimary || '—'}</span>
                  </div>
                  <div>
                    <span className="text-zinc-500">
                      {t('object_type_wizard.field_code', { defaultValue: 'Code' })}:
                    </span>{' '}
                    <span className="font-mono">{code || '—'}</span>
                  </div>
                  <div className="sm:col-span-2">
                    <span className="text-zinc-500">
                      {t('object_type_wizard.summary_groups_label', {
                        defaultValue: 'Grupy atrybutów',
                      })}
                      :
                    </span>{' '}
                    {pickedGroupIds.size === 0 ? (
                      <span className="text-zinc-500">
                        {t('object_type_wizard.summary_groups_empty', {
                          defaultValue: '— (tylko built-in)',
                        })}
                      </span>
                    ) : (
                      <span className="font-medium">
                        {pickedGroupRows
                          .map((g) => resolveGroupLabel(g.label, i18n.language) || g.code)
                          .join(', ')}
                      </span>
                    )}
                  </div>
                  <div className="sm:col-span-2">
                    <span className="text-zinc-500">
                      {t('object_type_wizard.summary_attrs_label', {
                        defaultValue: 'Atrybuty',
                      })}
                      :
                    </span>{' '}
                    {pickedAttributeIds.size === 0 ? (
                      <span className="text-zinc-500">
                        {t('object_type_wizard.summary_attrs_empty', {
                          defaultValue: '— (brak)',
                        })}
                      </span>
                    ) : (
                      <span className="font-mono text-[12px]">
                        {pickedAttributeRows.map((a) => a.code).join(', ')}
                      </span>
                    )}
                  </div>
                </div>
              </>
            ) : null}

            {error ? (
              <p role="alert" className="text-sm text-rose-600">
                {error}
              </p>
            ) : null}

            {/* Footer is step navigation only; the finish CTA is the navy
                "Utwórz typ" in the topbar. */}
            <div className="flex items-center justify-between border-t border-zinc-100 pt-3">
              <Button variant="ghost" onClick={goPrev} disabled={step === 1}>
                {t('object_type_wizard.prev', { defaultValue: '← Poprzedni' })}
              </Button>
              {step < STEP_KEYS.length ? (
                <Button onClick={goNext}>
                  {t('object_type_wizard.next', { defaultValue: 'Dalej →' })}
                </Button>
              ) : null}
            </div>
          </CardContent>
        </Card>

        <aside className="space-y-3">
          <Card>
            <CardContent className="space-y-3 p-5">
              <div className="text-[11px] font-medium uppercase tracking-wider text-zinc-500">
                {t('object_type_wizard.preview_label', { defaultValue: 'Podgląd' })}
              </div>
              {/* #2578 follow-up — no icon/color badge; the preview shows just
                  the name + code (icon/color are not user-chosen anymore). */}
              <div>
                <div className="text-[14px] font-semibold tracking-tight">
                  {namePrimary ||
                    t('object_type_wizard.preview_default_name', {
                      defaultValue: 'Nowy typ',
                    })}
                </div>
                <div className="font-mono text-[11.5px] text-zinc-500">
                  {code ||
                    t('object_type_wizard.preview_default_code', {
                      defaultValue: 'code…',
                    })}
                </div>
              </div>
            </CardContent>
          </Card>
          <Card>
            <CardContent className="space-y-3 p-5">
              <div className="text-[11px] font-medium uppercase tracking-wider text-zinc-500">
                {t('object_type_wizard.tips_label', { defaultValue: 'Wskazówki' })}
              </div>
              <ul className="space-y-1.5 text-[12px] text-zinc-600">
                <li className="flex items-start gap-2">
                  <Lock className="mt-0.5 size-3 text-zinc-500" />
                  {t('object_type_wizard.tip_snake_case', {
                    defaultValue: 'Code powinien być w snake_case',
                  })}
                </li>
                <li>
                  •{' '}
                  {t('object_type_wizard.tip_name_visibility', {
                    defaultValue: 'Nazwa pojawia się w UI i navbarze',
                  })}
                </li>
                <li>
                  •{' '}
                  {t('object_type_wizard.tip_variants', {
                    defaultValue: 'Variants = osie wariantowości (kolor × rozmiar)',
                  })}
                </li>
              </ul>
            </CardContent>
          </Card>
        </aside>
      </div>

      <DeclareObjectTypeAttributeGroupDialog
        open={declareGroupOpen}
        onOpenChange={setDeclareGroupOpen}
        objectTypeId=""
        objectTypeName={
          namePrimary || t('object_type_wizard.default_name', { defaultValue: 'Bez nazwy' })
        }
        attachedIds={pickedGroupIds}
        onPicked={(ids) => {
          setPickedGroupIds((prev) => {
            const next = new Set(prev);
            for (const id of ids) next.add(id);
            return next;
          });
        }}
        locale={i18n.language}
      />

      <CreateGroupInlineDialog
        open={createGroupOpen}
        onOpenChange={setCreateGroupOpen}
        onCreated={(group) => {
          if (group.id.length > 0) {
            setPickedGroupIds((prev) => {
              const next = new Set(prev);
              next.add(group.id);
              return next;
            });
          }
          void queryClient.invalidateQueries({ queryKey: ['attribute_groups', 'picker'] });
        }}
      />

      <AddAttributesToObjectTypeDialog
        open={addAttrOpen}
        onOpenChange={setAddAttrOpen}
        objectTypeId=""
        objectTypeName={
          namePrimary || t('object_type_wizard.default_name', { defaultValue: 'Bez nazwy' })
        }
        existingIds={pickedAttributeIds}
        onPicked={(ids) => {
          setPickedAttributeIds((prev) => {
            const next = new Set(prev);
            for (const id of ids) next.add(id);
            return next;
          });
        }}
        locale={i18n.language}
      />

      <CreateAttributeForObjectTypeDialog
        open={createAttrOpen}
        onOpenChange={setCreateAttrOpen}
        objectTypeId=""
        objectTypeName={
          namePrimary || t('object_type_wizard.default_name', { defaultValue: 'Bez nazwy' })
        }
        onCreated={(attr) => {
          if (attr.id.length > 0) {
            setPickedAttributeIds((prev) => {
              const next = new Set(prev);
              next.add(attr.id);
              return next;
            });
          }
          void queryClient.invalidateQueries({ queryKey: ['attributes', 'picker'] });
        }}
      />
    </div>
  );
}

import { ArrowLeft, Plus, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router';

import { Button } from '@/components/ui/button';
import { Combobox, type ComboboxOption } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MultiSelect } from '@/components/ui/multi-select';
import { toast } from '@/components/ui/toast';
import { httpErrorDetail, jsonFetch } from '@/lib/http';
import {
  createDefinition,
  extractViolations,
  fetchDefinitions,
  updateDefinition,
  type WorkflowDefinitionResource,
} from '@/lib/workflow/definitions-api';
import {
  type DefinitionDraft,
  draftFromResource,
  draftToPayload,
  emptyDraft,
  localViolations,
  violationsByField,
} from './definition-form';
import { EnabledDefinitionConfirmDialog } from './EnabledDefinitionConfirmDialog';

interface PermissionsResponse {
  member?: Array<{ module: string; permissions: Array<{ code: string }> }>;
}

interface ObjectTypesResponse {
  'hydra:member'?: Array<{ id: string; code?: string; kind?: string }>;
  member?: Array<{ id: string; code?: string; kind?: string }>;
}

/**
 * WFL-P5-03 (#2433) — form-based definition editor (deliberately not a
 * canvas designer): places with pl/en labels and pill color, transitions
 * with from/to selects, a permission picker sourced from the permission
 * catalogue, comment-required and an optional completeness gate. Server
 * 422 violations render inline next to the offending field; editing an
 * ENABLED definition asks for confirmation because it governs live
 * objects the moment it saves.
 */
export function DefinitionEditorPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const editing = id !== undefined;

  const [draft, setDraft] = useState<DefinitionDraft>(emptyDraft);
  const [original, setOriginal] = useState<WorkflowDefinitionResource | null>(null);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [permissionOptions, setPermissionOptions] = useState<ComboboxOption[]>([]);
  const [objectTypeOptions, setObjectTypeOptions] = useState<ComboboxOption[]>([]);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [loading, setLoading] = useState(editing);

  useEffect(() => {
    // The permission catalogue is gated user.admin — degrade to a plain
    // text input when the caller only holds workflow.manage_definitions.
    jsonFetch<PermissionsResponse>('/api/permissions')
      .then((body) => {
        const options = (body.member ?? []).flatMap((group) =>
          group.permissions.map((permission) => ({
            value: permission.code,
            label: permission.code,
          })),
        );
        setPermissionOptions(options);
      })
      .catch(() => setPermissionOptions([]));

    jsonFetch<ObjectTypesResponse>('/api/object_types?itemsPerPage=200')
      .then((body) => {
        const members = body['hydra:member'] ?? body.member ?? [];
        setObjectTypeOptions(
          members.map((type) => ({ value: type.id, label: type.code ?? type.id })),
        );
      })
      .catch(() => setObjectTypeOptions([]));
  }, []);

  useEffect(() => {
    if (!editing) return;
    setLoading(true);
    fetchDefinitions()
      .then((body) => {
        const found = body.items.find((item) => item.id === id);
        if (found === undefined) {
          toast.error(
            t('settings.workflow.not_found', { defaultValue: 'Nie znaleziono definicji.' }),
          );
          void navigate('/workflow/definitions');
          return;
        }
        setOriginal(found);
        setDraft(draftFromResource(found));
      })
      .finally(() => setLoading(false));
  }, [editing, id, navigate, t]);

  const placeOptions: ComboboxOption[] = useMemo(
    () =>
      draft.places
        .filter((place) => place.name.trim() !== '')
        .map((place) => ({ value: place.name.trim(), label: place.name.trim() })),
    [draft.places],
  );

  const fieldError = (field: string): string | undefined => errors[field];

  const save = () => {
    const local = localViolations(draft);
    if (local.length > 0) {
      setErrors(violationsByField(localMessages(local)));
      return;
    }
    if (original?.enabled === true && !confirmOpen) {
      setConfirmOpen(true);
      return;
    }
    setConfirmOpen(false);
    setSaving(true);
    const payload = draftToPayload(draft);
    const request =
      editing && id !== undefined ? updateDefinition(id, payload) : createDefinition(payload);
    request
      .then(() => {
        toast.success(t('settings.workflow.saved', { defaultValue: 'Definicja zapisana.' }));
        void navigate('/workflow/definitions');
      })
      .catch((error: unknown) => {
        const violations = extractViolations(error);
        if (violations.length > 0) {
          setErrors(violationsByField(violations));
          toast.error(
            t('settings.workflow.invalid', {
              defaultValue: 'Definicja zawiera błędy — popraw pola.',
            }),
          );
        } else {
          toast.error(
            httpErrorDetail(error) ??
              t('settings.workflow.save_failed', { defaultValue: 'Zapis nie powiódł się.' }),
          );
        }
      })
      .finally(() => setSaving(false));
  };

  const localMessages = (violations: ReturnType<typeof localViolations>) =>
    violations.map((violation) => ({
      field: violation.field,
      message: t(`settings.workflow.violation.${violation.message}`, {
        defaultValue: violation.message,
      }),
    }));

  if (loading) {
    return (
      <p className="px-1 py-6 text-[13px] text-zinc-500">
        {t('common.loading', { defaultValue: 'Ładowanie…' })}
      </p>
    );
  }

  return (
    <div className="space-y-6" data-testid="workflow-definition-editor">
      <header className="flex items-center gap-3">
        <Button variant="ghost" size="icon" onClick={() => void navigate('/workflow/definitions')}>
          <ArrowLeft className="size-4" />
          <span className="sr-only">{t('common.back', { defaultValue: 'Wstecz' })}</span>
        </Button>
        <h2 className="flex-1 text-[22px] font-semibold tracking-tight text-zinc-900">
          {editing
            ? t('settings.workflow.edit_title', { defaultValue: 'Edytuj definicję' })
            : t('settings.workflow.new_title', { defaultValue: 'Nowa definicja' })}
        </h2>
        <Button
          onClick={save}
          disabled={saving}
          data-testid="definition-save"
          className="bg-cta text-cta-foreground hover:bg-accent-hover"
        >
          {t('common.save', { defaultValue: 'Zapisz' })}
        </Button>
      </header>

      <section className="grid max-w-3xl grid-cols-1 gap-4 sm:grid-cols-2">
        <div>
          <Label htmlFor="definition-name">
            {t('settings.workflow.field_name', { defaultValue: 'Nazwa definicji' })}
          </Label>
          <Input
            id="definition-name"
            value={draft.name}
            onChange={(event) => setDraft({ ...draft, name: event.target.value })}
            data-testid="definition-name"
            aria-invalid={fieldError('name') !== undefined}
          />
          <FieldError message={fieldError('name')} />
        </div>
        <div>
          <Label htmlFor="definition-object-type">
            {t('settings.workflow.field_object_type', {
              defaultValue: 'Typ obiektu (puste = wszystkie)',
            })}
          </Label>
          <Combobox
            options={objectTypeOptions}
            value={draft.objectTypeId === '' ? null : draft.objectTypeId}
            onChange={(value) => setDraft({ ...draft, objectTypeId: value ?? '' })}
            placeholder={t('settings.workflow.object_type_all', { defaultValue: 'Wszystkie typy' })}
          />
        </div>
      </section>

      <section className="space-y-3">
        <div className="flex items-center gap-3">
          <h3 className="flex-1 text-[15px] font-semibold text-zinc-900">
            {t('settings.workflow.places_title', { defaultValue: 'Stany' })}
          </h3>
          <Button
            variant="outline"
            size="sm"
            onClick={() =>
              setDraft({
                ...draft,
                places: [...draft.places, { name: '', labelPl: '', labelEn: '', color: '#71717a' }],
              })
            }
            data-testid="place-add"
          >
            <Plus className="mr-1 size-3.5" />
            {t('settings.workflow.place_add', { defaultValue: 'Dodaj stan' })}
          </Button>
        </div>
        <FieldError message={fieldError('places')} />
        <ul className="space-y-2">
          {draft.places.map((place, index) => (
            <li
              // biome-ignore lint/suspicious/noArrayIndexKey: rows are positional editor slots
              key={index}
              className="flex flex-wrap items-end gap-3 rounded-2xl border border-zinc-200 bg-white p-3"
            >
              <div className="w-44">
                <Label htmlFor={`place-name-${index}`}>
                  {t('settings.workflow.place_name', { defaultValue: 'Nazwa (snake_case)' })}
                </Label>
                <Input
                  id={`place-name-${index}`}
                  value={place.name}
                  onChange={(event) => updatePlace(index, { name: event.target.value })}
                  data-testid={`place-name-${index}`}
                  aria-invalid={fieldError(`places[${index}].name`) !== undefined}
                />
                <FieldError message={fieldError(`places[${index}].name`)} />
              </div>
              <div className="w-40">
                <Label htmlFor={`place-label-pl-${index}`}>
                  {t('settings.workflow.place_label_pl', { defaultValue: 'Etykieta (pl)' })}
                </Label>
                <Input
                  id={`place-label-pl-${index}`}
                  value={place.labelPl}
                  onChange={(event) => updatePlace(index, { labelPl: event.target.value })}
                />
              </div>
              <div className="w-40">
                <Label htmlFor={`place-label-en-${index}`}>
                  {t('settings.workflow.place_label_en', { defaultValue: 'Etykieta (en)' })}
                </Label>
                <Input
                  id={`place-label-en-${index}`}
                  value={place.labelEn}
                  onChange={(event) => updatePlace(index, { labelEn: event.target.value })}
                />
              </div>
              <div>
                <Label htmlFor={`place-color-${index}`}>
                  {t('settings.workflow.place_color', { defaultValue: 'Kolor' })}
                </Label>
                <input
                  id={`place-color-${index}`}
                  type="color"
                  className="block h-9 w-14 cursor-pointer rounded-md border border-zinc-200 bg-white p-1"
                  value={place.color}
                  onChange={(event) => updatePlace(index, { color: event.target.value })}
                />
              </div>
              <Button
                variant="ghost"
                size="icon"
                className="ml-auto"
                disabled={place.name === 'draft'}
                onClick={() =>
                  setDraft({ ...draft, places: draft.places.filter((_, i) => i !== index) })
                }
                aria-label={t('settings.workflow.place_remove', { defaultValue: 'Usuń stan' })}
              >
                <Trash2 className="size-4" />
              </Button>
            </li>
          ))}
        </ul>
      </section>

      <section className="space-y-3">
        <div className="flex items-center gap-3">
          <h3 className="flex-1 text-[15px] font-semibold text-zinc-900">
            {t('settings.workflow.transitions_title', { defaultValue: 'Przejścia' })}
          </h3>
          <Button
            variant="outline"
            size="sm"
            onClick={() =>
              setDraft({
                ...draft,
                transitions: [
                  ...draft.transitions,
                  {
                    name: '',
                    from: [],
                    to: '',
                    permission: '',
                    commentRequired: false,
                    gatePct: '',
                  },
                ],
              })
            }
            data-testid="transition-add"
          >
            <Plus className="mr-1 size-3.5" />
            {t('settings.workflow.transition_add', { defaultValue: 'Dodaj przejście' })}
          </Button>
        </div>
        <ul className="space-y-2">
          {draft.transitions.map((transition, index) => (
            // biome-ignore lint/suspicious/noArrayIndexKey: rows are positional editor slots
            <li key={index} className="space-y-3 rounded-2xl border border-zinc-200 bg-white p-3">
              <div className="flex flex-wrap items-end gap-3">
                <div className="w-52">
                  <Label htmlFor={`transition-name-${index}`}>
                    {t('settings.workflow.transition_name', { defaultValue: 'Nazwa (snake_case)' })}
                  </Label>
                  <Input
                    id={`transition-name-${index}`}
                    value={transition.name}
                    onChange={(event) => updateTransition(index, { name: event.target.value })}
                    data-testid={`transition-name-${index}`}
                    aria-invalid={fieldError(`transitions[${index}].name`) !== undefined}
                  />
                  <FieldError message={fieldError(`transitions[${index}].name`)} />
                </div>
                <div className="w-56" data-testid={`transition-from-${index}`}>
                  <Label>
                    {t('settings.workflow.transition_from', { defaultValue: 'Ze stanów' })}
                  </Label>
                  <MultiSelect
                    options={placeOptions}
                    value={transition.from}
                    onChange={(next) => updateTransition(index, { from: next })}
                  />
                  <FieldError message={fieldError(`transitions[${index}].from`)} />
                </div>
                <div className="w-48" data-testid={`transition-to-${index}`}>
                  <Label>
                    {t('settings.workflow.transition_to', { defaultValue: 'Do stanu' })}
                  </Label>
                  <Combobox
                    options={placeOptions}
                    value={transition.to === '' ? null : transition.to}
                    onChange={(value) => updateTransition(index, { to: value ?? '' })}
                  />
                  <FieldError message={fieldError(`transitions[${index}].to`)} />
                </div>
                <Button
                  variant="ghost"
                  size="icon"
                  className="ml-auto"
                  onClick={() =>
                    setDraft({
                      ...draft,
                      transitions: draft.transitions.filter((_, i) => i !== index),
                    })
                  }
                  aria-label={t('settings.workflow.transition_remove', {
                    defaultValue: 'Usuń przejście',
                  })}
                >
                  <Trash2 className="size-4" />
                </Button>
              </div>
              <div className="flex flex-wrap items-end gap-3">
                <div className="w-72">
                  <Label>
                    {t('settings.workflow.transition_permission', {
                      defaultValue: 'Wymagane uprawnienie (opcjonalne)',
                    })}
                  </Label>
                  {permissionOptions.length > 0 ? (
                    <Combobox
                      options={permissionOptions}
                      value={transition.permission === '' ? null : transition.permission}
                      onChange={(value) => updateTransition(index, { permission: value ?? '' })}
                    />
                  ) : (
                    <Input
                      value={transition.permission}
                      onChange={(event) =>
                        updateTransition(index, { permission: event.target.value })
                      }
                      placeholder="workflow.approve_reject"
                    />
                  )}
                  <FieldError message={fieldError(`transitions[${index}].permission`)} />
                </div>
                <label className="flex h-9 items-center gap-2 text-[13px] text-zinc-700">
                  <input
                    type="checkbox"
                    className="size-4 rounded border-zinc-300"
                    checked={transition.commentRequired}
                    onChange={(event) =>
                      updateTransition(index, { commentRequired: event.target.checked })
                    }
                  />
                  {t('settings.workflow.transition_comment_required', {
                    defaultValue: 'Komentarz wymagany',
                  })}
                </label>
                <div className="w-44">
                  <Label htmlFor={`transition-gate-${index}`}>
                    {t('settings.workflow.transition_gate', {
                      defaultValue: 'Min. kompletność % (puste = brak)',
                    })}
                  </Label>
                  <Input
                    id={`transition-gate-${index}`}
                    inputMode="numeric"
                    value={transition.gatePct}
                    onChange={(event) => updateTransition(index, { gatePct: event.target.value })}
                  />
                  <FieldError message={fieldError(`transitions[${index}].completeness_gate`)} />
                </div>
              </div>
            </li>
          ))}
        </ul>
      </section>

      <EnabledDefinitionConfirmDialog
        open={confirmOpen}
        onOpenChange={setConfirmOpen}
        onConfirm={save}
      />
    </div>
  );

  function updatePlace(index: number, patch: Partial<DefinitionDraft['places'][number]>) {
    setDraft({
      ...draft,
      places: draft.places.map((place, i) => (i === index ? { ...place, ...patch } : place)),
    });
  }

  function updateTransition(index: number, patch: Partial<DefinitionDraft['transitions'][number]>) {
    setDraft({
      ...draft,
      transitions: draft.transitions.map((transition, i) =>
        i === index ? { ...transition, ...patch } : transition,
      ),
    });
  }
}

function FieldError({ message }: { message?: string }) {
  if (message === undefined) return null;
  return (
    <p className="mt-1 text-[12px] text-red-600" role="alert">
      {message}
    </p>
  );
}

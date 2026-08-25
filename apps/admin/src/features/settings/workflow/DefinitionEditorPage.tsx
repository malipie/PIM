import { ArrowLeft } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate, useParams } from 'react-router';

import { Button } from '@/components/ui/button';
import { Combobox, type ComboboxOption } from '@/components/ui/combobox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { toast } from '@/components/ui/toast';
import { httpErrorDetail, jsonFetch } from '@/lib/http';
import {
  createDefinition,
  extractViolations,
  fetchDefinitions,
  setDefinitionEnabled,
  updateDefinition,
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
import { FlowPreview } from './FlowPreview';
import { PlacesSection } from './PlacesSection';
import { ReviewerSection } from './ReviewerSection';
import { TemplatePicker } from './TemplatePicker';
import { TransitionsSection } from './TransitionsSection';

interface PermissionsResponse {
  member?: Array<{ module: string; permissions: Array<{ code: string }> }>;
}

interface ObjectTypesResponse {
  'hydra:member'?: Array<{ id: string; code?: string; kind?: string }>;
  member?: Array<{ id: string; code?: string; kind?: string }>;
}

const ADVANCED_STORAGE_KEY = 'pim.workflow.definitions.advanced';

/**
 * WFL-P5-03 (#2433) — form-based definition editor (deliberately not a
 * canvas designer).
 *
 * #3002 rewrote its language: stages carry the operator's own labels,
 * transitions read as sentences, and permissions show as the people who
 * hold them. Machine names and raw permission codes moved behind the
 * advanced switch — they are our vocabulary, not the customer's. The
 * sections live in their own files so this page stays an orchestrator
 * (and stays under the admin max-lines guard).
 *
 * Server 422 violations still render inline next to the offending field;
 * editing an ENABLED definition asks for confirmation because it governs
 * live objects the moment it saves.
 */
export function DefinitionEditorPage() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { id } = useParams<{ id: string }>();
  const editing = id !== undefined;

  const [draft, setDraft] = useState<DefinitionDraft>(emptyDraft);
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [permissionCodes, setPermissionCodes] = useState<string[]>([]);
  const [objectTypeOptions, setObjectTypeOptions] = useState<ComboboxOption[]>([]);
  const [confirmOpen, setConfirmOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [loading, setLoading] = useState(editing);
  const [advanced, setAdvanced] = useState(readAdvanced);
  // #3004 — a new definition starts with a question, not an empty form.
  const [picking, setPicking] = useState(!editing);
  const [enabled, setEnabled] = useState(false);
  const [togglingEnabled, setTogglingEnabled] = useState(false);
  const [pendingEnable, setPendingEnable] = useState(false);

  useEffect(() => {
    // The permission catalogue is gated user.admin — degrade to a plain
    // text input when the caller only holds workflow.manage_definitions.
    jsonFetch<PermissionsResponse>('/api/permissions')
      .then((body) => {
        setPermissionCodes(
          (body.member ?? []).flatMap((group) =>
            group.permissions.map((permission) => permission.code),
          ),
        );
      })
      .catch(() => setPermissionCodes([]));

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
        setEnabled(found.enabled);
        setDraft(draftFromResource(found));
      })
      .finally(() => setLoading(false));
  }, [editing, id, navigate, t]);

  const fieldError = useCallback((field: string) => errors[field], [errors]);

  const toggleAdvanced = () => {
    setAdvanced((current) => {
      const next = !current;
      try {
        window.localStorage.setItem(ADVANCED_STORAGE_KEY, next ? '1' : '0');
      } catch {
        // Private windows and blocked site data throw — the switch still
        // works for this session, it just will not be remembered.
      }
      return next;
    });
  };

  /**
   * #3004 — activation is its own decision. Saving used to flip the flag
   * on the retired settings page, so an edit silently put a definition in
   * charge of live objects.
   */
  const toggleEnabled = (next: boolean) => {
    if (id === undefined) return;
    if (next && !pendingEnable) {
      setPendingEnable(true);
      setConfirmOpen(true);
      return;
    }
    setPendingEnable(false);
    setConfirmOpen(false);
    setTogglingEnabled(true);
    setDefinitionEnabled(id, next)
      .then(() => {
        setEnabled(next);
        toast.success(
          next
            ? t('settings.workflow.enabled_toast', { defaultValue: 'Definicja włączona.' })
            : t('settings.workflow.disabled_toast', { defaultValue: 'Definicja wyłączona.' }),
        );
      })
      .catch((error: unknown) => {
        toast.error(
          httpErrorDetail(error) ??
            t('settings.workflow.toggle_failed', { defaultValue: 'Nie udało się zmienić stanu.' }),
        );
      })
      .finally(() => setTogglingEnabled(false));
  };

  const save = () => {
    const local = localViolations(draft);
    if (local.length > 0) {
      setErrors(
        violationsByField(
          local.map((violation) => ({
            field: violation.field,
            message: t(`settings.workflow.violation.${violation.message}`, {
              defaultValue: violation.message,
            }),
          })),
        ),
      );
      return;
    }
    if (enabled && !confirmOpen) {
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

  if (loading) {
    return (
      <p className="px-1 py-6 text-[13px] text-zinc-500">
        {t('common.loading', { defaultValue: 'Ładowanie…' })}
      </p>
    );
  }

  if (picking) {
    return (
      <TemplatePicker
        onPick={(next) => {
          setDraft(next);
          setPicking(false);
        }}
        onBlank={() => setPicking(false)}
      />
    );
  }

  return (
    <div className="space-y-6" data-testid="workflow-definition-editor">
      <header className="flex flex-wrap items-center gap-3">
        <Button variant="ghost" size="icon" onClick={() => void navigate('/workflow/definitions')}>
          <ArrowLeft className="size-4" />
          <span className="sr-only">{t('common.back', { defaultValue: 'Wstecz' })}</span>
        </Button>
        <h2 className="flex-1 text-[22px] font-semibold tracking-tight text-zinc-900">
          {editing
            ? t('settings.workflow.edit_title', { defaultValue: 'Edytuj przepływ' })
            : t('settings.workflow.new_title', { defaultValue: 'Nowy przepływ' })}
        </h2>
        {editing ? (
          <label className="flex items-center gap-2 text-[12px] font-medium text-zinc-600">
            <input
              type="checkbox"
              className="size-4 rounded border-zinc-300"
              checked={enabled}
              disabled={togglingEnabled}
              onChange={(event) => toggleEnabled(event.target.checked)}
              data-testid="definition-enabled-toggle"
            />
            {enabled
              ? t('workflow.definitions.active', { defaultValue: 'Aktywny' })
              : t('workflow.definitions.inactive', { defaultValue: 'Wyłączony' })}
          </label>
        ) : null}
        <label className="flex items-center gap-2 text-[12px] text-zinc-500">
          <input
            type="checkbox"
            className="size-4 rounded border-zinc-300"
            checked={advanced}
            onChange={toggleAdvanced}
            data-testid="definition-advanced-toggle"
          />
          {t('workflow.definitions.advanced', { defaultValue: 'Tryb zaawansowany' })}
        </label>
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
            {t('workflow.definitions.field_name', { defaultValue: 'Nazwa przepływu' })}
          </Label>
          <Input
            id="definition-name"
            value={draft.name}
            onChange={(event) => setDraft({ ...draft, name: event.target.value })}
            data-testid="definition-name"
            aria-invalid={fieldError('name') !== undefined}
          />
          {fieldError('name') === undefined ? null : (
            <p className="mt-1 text-[12px] text-brick-600" role="alert">
              {fieldError('name')}
            </p>
          )}
        </div>
        <div>
          <Label htmlFor="definition-object-type">
            {t('workflow.definitions.field_object_type', { defaultValue: 'Dla czego obowiązuje' })}
          </Label>
          <div id="definition-object-type" data-testid="definition-object-type">
            <Combobox
              options={objectTypeOptions}
              value={draft.objectTypeId === '' ? null : draft.objectTypeId}
              onChange={(value) => setDraft({ ...draft, objectTypeId: value ?? '' })}
              placeholder={t('settings.workflow.object_type_all', {
                defaultValue: 'Wszystkie typy',
              })}
            />
          </div>
        </div>
      </section>

      <div className="grid grid-cols-1 gap-6 lg:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
        <div className="space-y-6">
          <PlacesSection
            places={draft.places}
            onChange={(places) => setDraft({ ...draft, places })}
            advanced={advanced}
            error={fieldError}
          />

          <TransitionsSection
            transitions={draft.transitions}
            places={draft.places}
            permissionCodes={permissionCodes}
            onChange={(transitions) => setDraft({ ...draft, transitions })}
            advanced={advanced}
            error={fieldError}
          />

          <ReviewerSection
            value={draft.reviewer}
            onChange={(reviewer) => setDraft({ ...draft, reviewer })}
            error={fieldError('reviewer')}
          />
        </div>

        <FlowPreview draft={draft} enabled={enabled} />
      </div>

      <EnabledDefinitionConfirmDialog
        open={confirmOpen}
        onOpenChange={(open) => {
          setConfirmOpen(open);
          if (!open) setPendingEnable(false);
        }}
        onConfirm={() => (pendingEnable ? toggleEnabled(true) : save())}
      />
    </div>
  );
}

function readAdvanced(): boolean {
  try {
    return window.localStorage.getItem(ADVANCED_STORAGE_KEY) === '1';
  } catch {
    return false;
  }
}

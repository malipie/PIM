import { GitBranch, Pencil, Plus } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { useTranslation } from 'react-i18next';
import { useNavigate } from 'react-router';

import { Button } from '@/components/ui/button';
import { toast } from '@/components/ui/toast';
import { EmptyState } from '@/components/ui-v2/empty-state';
import { StatusPill } from '@/components/ui-v2/status-pill';
import { httpErrorDetail } from '@/lib/http';
import {
  fetchDefinitions,
  setDefinitionEnabled,
  type WorkflowDefinitionResource,
} from '@/lib/workflow/definitions-api';

/**
 * WFL-P5-03 (#2433) — Settings → Workflow: the tenant's custom editorial
 * definitions. List + enable/disable; shape editing happens in the
 * editor route. Only reachable with the `workflow_custom_definitions`
 * feature flag on and `workflow.manage_definitions` held (route gate).
 */
export function DefinitionsListView() {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const [definitions, setDefinitions] = useState<WorkflowDefinitionResource[]>([]);
  const [loading, setLoading] = useState(true);
  const [busyId, setBusyId] = useState<string | null>(null);

  const reload = useCallback(() => {
    setLoading(true);
    fetchDefinitions()
      .then((body) => setDefinitions(body.items))
      .catch(() => setDefinitions([]))
      .finally(() => setLoading(false));
  }, []);

  useEffect(() => {
    reload();
  }, [reload]);

  const toggle = (definition: WorkflowDefinitionResource) => {
    setBusyId(definition.id);
    setDefinitionEnabled(definition.id, !definition.enabled)
      .then(() => {
        toast.success(
          definition.enabled
            ? t('settings.workflow.disabled_toast', { defaultValue: 'Definicja wyłączona.' })
            : t('settings.workflow.enabled_toast', { defaultValue: 'Definicja włączona.' }),
        );
        reload();
      })
      .catch((error: unknown) => {
        toast.error(
          httpErrorDetail(error) ??
            t('settings.workflow.toggle_failed', { defaultValue: 'Nie udało się zmienić stanu.' }),
        );
      })
      .finally(() => setBusyId(null));
  };

  return (
    <div className="space-y-4" data-testid="workflow-definitions-page">
      <header className="flex items-start gap-4">
        <div className="flex-1 space-y-1">
          <h2 className="text-[22px] font-semibold tracking-tight text-zinc-900">
            {t('settings.workflow.title', { defaultValue: 'Workflow — definicje' })}
          </h2>
          <p className="max-w-2xl text-[13px] text-zinc-500">
            {t('settings.workflow.intro', {
              defaultValue:
                'Własny proces edytorski per tenant: stany, przejścia, uprawnienia i bramki kompletności — bez deployu.',
            })}
          </p>
        </div>
        <Button
          onClick={() => void navigate('/workflow/definitions/new')}
          data-testid="definition-create"
        >
          <Plus className="mr-1 size-4" />
          {t('settings.workflow.create_cta', { defaultValue: 'Nowa definicja' })}
        </Button>
      </header>

      {definitions.length === 0 && !loading ? (
        <EmptyState
          icon={<GitBranch className="size-6" />}
          title={t('settings.workflow.empty_title', { defaultValue: 'Brak definicji' })}
          description={t('settings.workflow.empty_body', {
            defaultValue:
              'Dopóki nie włączysz własnej definicji, obiekty przechodzą przez wbudowany proces (szkic → przegląd → publikacja).',
          })}
        />
      ) : (
        <ul className="space-y-3">
          {definitions.map((definition) => (
            <li
              key={definition.id}
              className="flex items-center gap-4 rounded-3xl border border-zinc-200 bg-white px-5 py-4"
              data-testid="definition-row"
            >
              <div className="min-w-0 flex-1">
                <div className="flex items-center gap-2">
                  <span className="truncate text-[15px] font-semibold text-zinc-900">
                    {definition.name}
                  </span>
                  <StatusPill
                    variant={definition.enabled ? 'success' : 'queued'}
                    label={
                      definition.enabled
                        ? t('settings.workflow.state_enabled', { defaultValue: 'Włączona' })
                        : t('settings.workflow.state_disabled', { defaultValue: 'Wyłączona' })
                    }
                  />
                </div>
                <p className="mt-0.5 text-[12.5px] text-zinc-500">
                  {definition.object_type_id === null
                    ? t('settings.workflow.scope_global', {
                        defaultValue: 'Wszystkie typy obiektów',
                      })
                    : t('settings.workflow.scope_object_type', {
                        defaultValue: 'Jeden typ obiektu',
                      })}
                  {' · '}
                  {t('settings.workflow.shape_summary', {
                    defaultValue: '{{places}} stanów · {{transitions}} przejść',
                    places: definition.places.length,
                    transitions: definition.transitions.length,
                  })}
                </p>
              </div>
              <Button
                variant="outline"
                size="sm"
                disabled={busyId === definition.id}
                onClick={() => toggle(definition)}
                data-testid={`definition-toggle-${definition.name}`}
              >
                {definition.enabled
                  ? t('settings.workflow.disable', { defaultValue: 'Wyłącz' })
                  : t('settings.workflow.enable', { defaultValue: 'Włącz' })}
              </Button>
              <Button
                variant="ghost"
                size="sm"
                onClick={() => void navigate(`/workflow/definitions/${definition.id}/edit`)}
                data-testid={`definition-edit-${definition.name}`}
              >
                <Pencil className="mr-1 size-3.5" />
                {t('common.edit', { defaultValue: 'Edytuj' })}
              </Button>
            </li>
          ))}
        </ul>
      )}
    </div>
  );
}

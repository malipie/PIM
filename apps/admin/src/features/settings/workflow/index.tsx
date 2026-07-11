import { DefinitionEditorPage } from './DefinitionEditorPage';
import { DefinitionsListView } from './DefinitionsListView';

/**
 * WFL-P5-03 (#2433) — entry points for `/settings/workflow` (list) and
 * `/settings/workflow/{new,:id/edit}` (form editor). Follows the roles
 * module layout so helpers can grow next to their pages.
 */
export function WorkflowSettingsPage() {
  return <DefinitionsListView />;
}

export function WorkflowDefinitionEditorRoute() {
  return <DefinitionEditorPage />;
}

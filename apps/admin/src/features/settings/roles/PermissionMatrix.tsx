import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/utils';

import {
  permissionActionLabel,
  permissionGroupLabel,
  sortGroups,
  visibleGroups,
} from './permission-catalogue';

export interface PermissionEntry {
  id: string;
  code: string;
  action: string;
  /**
   * #2881 — which of the two permission catalogues this code belongs to.
   * Optional so an older backend simply behaves as before.
   */
  catalogue?: 'prd' | 'legacy';
}

export interface PermissionGroup {
  module: string;
  permissions: PermissionEntry[];
}

interface PermissionMatrixProps {
  groups: PermissionGroup[];
  selectedCodes: Set<string>;
  onToggle: (code: string) => void;
  disabled?: boolean;
}

/**
 * RBAC-P5-006 (#696) — checkbox matrix for the custom-role builder.
 *
 * The PRD specifies a fixed `(module, action)` grid, but the seeded
 * catalogue is sparser — many cells are not valid combinations
 * (`platform.break_glass_recovery` has no `view` action, etc.). To
 * keep the matrix honest with the engine, this component renders
 * exactly the cells the backend exposes via `GET /api/permissions`.
 * Empty actions per row collapse so the matrix stays compact.
 *
 * Rows are ordered by {@link sortGroups} so the visual layout matches
 * PRD §3.2 — adding a new module in the seeder slots into the order
 * without an FE change, falling back to alphabetical for groups not
 * yet in the explicit list.
 */
export function PermissionMatrix({
  groups,
  selectedCodes,
  onToggle,
  disabled = false,
}: PermissionMatrixProps) {
  const { t } = useTranslation();
  const ordered = sortGroups(visibleGroups(groups, selectedCodes));

  // Build the unique action column set across the visible groups so
  // each module row aligns with the same x-axis. Actions are stored
  // by slug; the action labels are looked up per-cell because the same
  // slug can mean slightly different things in different modules (the
  // backend uses the slug as a stable wire-format key).
  const actionSet = new Set<string>();
  for (const group of ordered) {
    for (const permission of group.permissions) {
      actionSet.add(permission.action);
    }
  }
  const actions = Array.from(actionSet);
  actions.sort(actionPriority);

  return (
    <div className="overflow-x-auto rounded-lg border bg-background shadow-sm">
      <table className="w-full text-sm">
        <thead className="bg-muted/40 text-xs uppercase tracking-wide text-muted-foreground">
          <tr>
            <th scope="col" className="sticky left-0 z-10 bg-muted/40 px-4 py-2 text-left">
              {t('settings.roles.editor.col_module')}
            </th>
            {actions.map((action) => (
              <th key={action} scope="col" className="px-3 py-2 text-center font-medium">
                {permissionActionLabel(t, action)}
              </th>
            ))}
          </tr>
        </thead>
        <tbody>
          {ordered.map((group) => (
            <PermissionRow
              key={group.module}
              group={group}
              actions={actions}
              selectedCodes={selectedCodes}
              onToggle={onToggle}
              disabled={disabled}
            />
          ))}
        </tbody>
      </table>
    </div>
  );
}

interface PermissionRowProps {
  group: PermissionGroup;
  actions: string[];
  selectedCodes: Set<string>;
  onToggle: (code: string) => void;
  disabled: boolean;
}

function PermissionRow({ group, actions, selectedCodes, onToggle, disabled }: PermissionRowProps) {
  const { t } = useTranslation();
  const byAction = new Map<string, PermissionEntry>();
  for (const permission of group.permissions) {
    byAction.set(permission.action, permission);
  }

  return (
    <tr className="border-t hover:bg-muted/20">
      <th
        scope="row"
        className="sticky left-0 z-10 bg-background px-4 py-2 text-left text-xs font-medium"
      >
        {permissionGroupLabel(t, group.module)}
        <div className="font-mono text-[10px] font-normal text-muted-foreground">
          {group.module}
        </div>
      </th>
      {actions.map((action) => {
        const permission = byAction.get(action);
        if (!permission) {
          return (
            <td key={action} className="px-3 py-2 text-center text-muted-foreground/40">
              —
            </td>
          );
        }
        const checked = selectedCodes.has(permission.code);
        return (
          <td key={action} className="px-3 py-2 text-center">
            <label
              className={cn(
                'inline-flex cursor-pointer items-center justify-center rounded p-1',
                disabled && 'cursor-not-allowed opacity-60',
              )}
            >
              <input
                type="checkbox"
                className="size-4"
                checked={checked}
                disabled={disabled}
                onChange={() => onToggle(permission.code)}
                aria-label={`${permissionGroupLabel(t, group.module)} · ${permissionActionLabel(t, action)}`}
              />
            </label>
          </td>
        );
      })}
    </tr>
  );
}

// Order common action verbs in a way that reads naturally across the
// matrix (view → write → delete → admin-y verbs). The default fallback
// alphabetises so unfamiliar slugs land deterministically.
const ACTION_PRIORITY = [
  'view',
  'view_own',
  'view_all',
  'view_cross_user',
  'add',
  'add_edit',
  'add_edit_own',
  'add_edit_any',
  'edit',
  'edit_any_state',
  'delete',
  'delete_custom',
  'run',
  'publish_unpublish',
  'approve_pending_changes',
  'approve_reject',
  'approve_schema_ops',
  'approve_pending',
  'bulk_operations',
  'bulk_actions',
  'schema_ops',
  'auto_grant_new_object_types',
  'manage',
  'read',
  'crud',
  'view_revoke',
  'list',
  'break_glass_recovery',
];

function actionPriority(a: string, b: string): number {
  const ai = ACTION_PRIORITY.indexOf(a);
  const bi = ACTION_PRIORITY.indexOf(b);
  if (ai !== -1 && bi !== -1) return ai - bi;
  if (ai !== -1) return -1;
  if (bi !== -1) return 1;
  return a.localeCompare(b);
}

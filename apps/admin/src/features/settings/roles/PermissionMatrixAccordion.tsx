import { Check, ChevronRight } from 'lucide-react';
import { useState } from 'react';
import { useTranslation } from 'react-i18next';

import { cn } from '@/lib/utils';
import type { PermissionGroup } from './PermissionMatrix';
import {
  permissionActionLabel,
  permissionGroupLabel,
  sortGroups,
  visibleGroups,
} from './permission-catalogue';

interface PermissionMatrixAccordionProps {
  groups: PermissionGroup[];
  selectedCodes: Set<string>;
  onToggle: (code: string) => void;
  onToggleGroup: (group: PermissionGroup, allOn: boolean) => void;
  disabled?: boolean;
}

interface ModuleColor {
  dot: string;
}

/**
 * Per-module color identity for the accordion header dot — mirrors the
 * design `RBAC_MODULES[].color` field from
 * `Zrodla/.../settings/data.jsx`. Falls back to zinc for prefixes the
 * backend introduces before the frontend catalogue is updated.
 */
const MODULE_COLOR: Record<string, ModuleColor> = {
  platform: { dot: 'bg-rose-500' },
  tenant: { dot: 'bg-rose-500' },
  products: { dot: 'bg-emerald-500' },
  categories: { dot: 'bg-emerald-500' },
  multimedia: { dot: 'bg-violet-500' },
  modeling: { dot: 'bg-blue-500' },
  publications: { dot: 'bg-cyan-500' },
  imports: { dot: 'bg-amber-500' },
  exports: { dot: 'bg-amber-500' },
  workflow: { dot: 'bg-violet-500' },
  agent: { dot: 'bg-zinc-500' },
  settings: { dot: 'bg-zinc-500' },
  api_tokens: { dot: 'bg-zinc-500' },
  audit: { dot: 'bg-zinc-500' },
};

function moduleColor(slug: string): ModuleColor {
  const prefix = slug.split('.')[0] ?? slug;
  return MODULE_COLOR[prefix] ?? { dot: 'bg-zinc-500' };
}

/**
 * UI re-align (#865) — accordion-style permission matrix per
 * `Zrodla/Front_Claude_Design/PIM-nowoczesny/settings/roles.jsx`
 * §PermissionMatrixEditor.
 *
 * Each module renders as a `rounded-2xl border` card with a clickable
 * header (color dot + module label + `{checked}/{total}` counter +
 * "Wszystko ✓" toggle-all button + chevron). The body — a divided list
 * of individual permission rows with custom checkbox + label + mono
 * permission code — is collapsible per module via local `expanded` state.
 *
 * Replaces the `<PermissionMatrix>` 2D grid (module × action) inside the
 * role editor matrix tab. The 2D grid stays in the codebase for views
 * that still want the spreadsheet view.
 */
export function PermissionMatrixAccordion({
  groups,
  selectedCodes,
  onToggle,
  onToggleGroup,
  disabled = false,
}: PermissionMatrixAccordionProps) {
  const { t } = useTranslation();
  const ordered = sortGroups(visibleGroups(groups, selectedCodes));

  // Default to all expanded — operators usually want full visibility on first
  // open. Per-module collapse is a manual operation from there.
  const [expanded, setExpanded] = useState<Record<string, boolean>>(() => {
    const seed: Record<string, boolean> = {};
    for (const g of ordered) seed[g.module] = true;
    return seed;
  });

  const toggleExpand = (module: string): void => {
    setExpanded((prev) => ({ ...prev, [module]: !prev[module] }));
  };

  return (
    <div className="space-y-2">
      {ordered.map((group) => {
        const color = moduleColor(group.module);
        const inGroup = group.permissions.filter((p) => selectedCodes.has(p.code)).length;
        const total = group.permissions.length;
        const allOn = total > 0 && inGroup === total;
        const isOpen = expanded[group.module] ?? true;

        return (
          <div
            key={group.module}
            className="overflow-hidden rounded-2xl border border-zinc-200 bg-white"
          >
            <button
              type="button"
              onClick={() => toggleExpand(group.module)}
              className="flex w-full items-center gap-3 px-4 py-3 text-left transition hover:bg-zinc-50/50"
            >
              <span className={cn('size-2 rounded-full', color.dot)} aria-hidden />
              <span className="text-[13.5px] font-semibold text-zinc-900">
                {permissionGroupLabel(t, group.module)}
              </span>
              <span className="font-mono text-[11px] text-zinc-500">
                {inGroup}/{total}
              </span>
              <div className="ml-auto flex items-center gap-2">
                <button
                  type="button"
                  disabled={disabled || total === 0}
                  onClick={(e) => {
                    e.stopPropagation();
                    onToggleGroup(group, allOn);
                  }}
                  className={cn(
                    'h-7 rounded-lg border px-2 text-[11px] font-medium transition',
                    allOn
                      ? 'border-zinc-900 bg-zinc-900 text-white'
                      : 'border-zinc-200 text-zinc-600 hover:bg-zinc-100',
                    disabled && 'cursor-not-allowed opacity-60',
                  )}
                >
                  {allOn
                    ? t('settings.roles.editor.group_all_on', { defaultValue: 'Wszystko ✓' })
                    : t('settings.roles.editor.group_select_all', {
                        defaultValue: 'Zaznacz wszystko',
                      })}
                </button>
                <ChevronRight
                  className={cn('size-4 text-zinc-500 transition', isOpen && 'rotate-90')}
                  aria-hidden
                />
              </div>
            </button>

            {isOpen ? (
              <div className="divide-y divide-zinc-100 border-t border-zinc-100">
                {group.permissions.map((permission) => {
                  const checked = selectedCodes.has(permission.code);
                  return (
                    <div
                      key={permission.code}
                      className={cn(
                        'flex items-center gap-3 px-4 py-2.5 transition hover:bg-zinc-50/50',
                        disabled && 'opacity-60',
                      )}
                    >
                      <button
                        type="button"
                        onClick={() => onToggle(permission.code)}
                        disabled={disabled}
                        aria-pressed={checked}
                        aria-label={`${permissionGroupLabel(t, group.module)} · ${permissionActionLabel(t, permission.action)}`}
                        className={cn(
                          'grid size-5 shrink-0 place-items-center rounded border transition',
                          checked
                            ? 'border-zinc-900 bg-zinc-900 text-white'
                            : 'border-zinc-300 bg-white hover:border-zinc-500',
                          disabled && 'cursor-not-allowed',
                        )}
                      >
                        {checked ? <Check className="size-3" strokeWidth={3.5} /> : null}
                      </button>
                      <div className="min-w-0 flex-1">
                        <div className="text-[12.5px] text-zinc-800">
                          {permissionActionLabel(t, permission.action)}
                        </div>
                        <div className="font-mono text-[10.5px] text-zinc-500">
                          {permission.code}
                        </div>
                      </div>
                    </div>
                  );
                })}
              </div>
            ) : null}
          </div>
        );
      })}
    </div>
  );
}

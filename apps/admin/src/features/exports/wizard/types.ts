import type { FilterDsl } from '@/lib/filters/filter-dsl';

/** Export entity types — synced with backend ExportEntityType (EXR-04). */
export type ExportEntityType =
  | 'product'
  | 'custom_module'
  | 'module_schema'
  | 'attributes_groups'
  | 'attribute_groups'
  | 'categories';

export type ExportFormat = 'xlsx' | 'csv' | 'xml';

export type ExportTargetScope = 'all' | 'filter' | 'selected';

/** Result of POST /api/exports/preflight (EXR-07). */
export interface PreflightResult {
  count: number;
  mode: 'sync' | 'async';
  threshold: number;
  soft_cap: number;
  exceeds_cap: boolean;
}

/** Whole-wizard state (EXR-09); steps 2-4 fill the optional fields. */
export interface WizardState {
  step: number;
  entityType: ExportEntityType;
  /** Required when entityType=custom_module — the chosen custom ObjectType. */
  objectTypeId: string | null;
  profileId: string | null;
  format: ExportFormat;
  filterDsl: FilterDsl | null;
  selectedIds: string[] | null;
  targetScope: ExportTargetScope;
  columns: string[];
  locales: string[] | null;
  channels: string[] | null;
  profileName: string;
  /** Last preflight probe for the current configuration (EXR-10). */
  preflight: PreflightResult | null;
  /** EXR-13 — profile being edited (?profile={id}); save = PATCH. */
  editingProfileId: string | null;
  /** EXR-14 — telemetry: central tab vs list-context entry. */
  source: 'central_tab' | 'list_context';
  /** Any user-made change — drives cancel/entity-switch confirmations. */
  dirty: boolean;
}

export type WizardAction =
  | { type: 'SET_ENTITY_TYPE'; entityType: ExportEntityType }
  | { type: 'SET_OBJECT_TYPE_ID'; objectTypeId: string | null }
  | { type: 'GO_TO_STEP'; step: number }
  | { type: 'SET_FORMAT'; format: ExportFormat }
  | { type: 'SET_PROFILE'; profileId: string | null }
  | { type: 'SET_FILTER'; filterDsl: FilterDsl | null; targetScope: ExportTargetScope }
  | { type: 'SET_SELECTED_IDS'; selectedIds: string[] | null }
  | { type: 'SET_COLUMNS'; columns: string[] }
  | { type: 'SET_LOCALES'; locales: string[] | null }
  | { type: 'SET_CHANNELS'; channels: string[] | null }
  | { type: 'SET_PROFILE_NAME'; profileName: string }
  | { type: 'SET_PREFLIGHT'; preflight: PreflightResult | null }
  | {
      type: 'INIT_FROM_LIST';
      entityType: ExportEntityType;
      objectTypeId: string | null;
      selectedIds: string[] | null;
      filterDsl: FilterDsl | null;
      targetScope: ExportTargetScope;
    }
  | {
      type: 'INIT_FROM_PROFILE';
      profileId: string;
      profileName: string;
      entityType: ExportEntityType;
      objectTypeId: string | null;
      format: ExportFormat;
      columns: string[];
      locales: string[] | null;
      channels: string[] | null;
      filterDsl: FilterDsl | null;
      targetScope: ExportTargetScope;
    }
  | {
      type: 'APPLY_PROFILE';
      profileId: string;
      profileName: string;
      format: ExportFormat;
      columns: string[];
      locales: string[] | null;
      channels: string[] | null;
      filterDsl: FilterDsl | null;
      targetScope: ExportTargetScope;
    };

export const WIZARD_STEP_COUNT = 4;

export const INITIAL_WIZARD_STATE: WizardState = {
  step: 0,
  entityType: 'product',
  objectTypeId: null,
  profileId: null,
  format: 'xlsx',
  filterDsl: null,
  selectedIds: null,
  targetScope: 'all',
  columns: [],
  locales: null,
  channels: null,
  profileName: '',
  preflight: null,
  editingProfileId: null,
  source: 'central_tab',
  dirty: false,
};

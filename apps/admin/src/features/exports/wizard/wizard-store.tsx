import { createContext, type Dispatch, type ReactNode, useContext, useReducer } from 'react';

import {
  INITIAL_WIZARD_STATE,
  WIZARD_STEP_COUNT,
  type WizardAction,
  type WizardState,
} from './types';

/**
 * EXR-09 — single source of truth for the 4-step export wizard.
 * Plain context + reducer (repo has no store library; spec default).
 *
 * Switching the entity type resets steps 2-4 (filters/columns are
 * per-entity) — the confirmation dialog lives in StepEntityType, the
 * reducer just performs the reset deterministically.
 */
export function wizardReducer(state: WizardState, action: WizardAction): WizardState {
  switch (action.type) {
    case 'SET_ENTITY_TYPE': {
      if (action.entityType === state.entityType) {
        return state;
      }
      return {
        ...INITIAL_WIZARD_STATE,
        step: state.step,
        entityType: action.entityType,
        dirty: true,
      };
    }
    case 'SET_OBJECT_TYPE_ID':
      return { ...state, objectTypeId: action.objectTypeId, dirty: true };
    case 'GO_TO_STEP': {
      const step = Math.max(0, Math.min(WIZARD_STEP_COUNT - 1, action.step));
      return { ...state, step };
    }
    case 'SET_FORMAT':
      return { ...state, format: action.format, dirty: true };
    case 'SET_PROFILE':
      return { ...state, profileId: action.profileId, dirty: true };
    case 'SET_FILTER':
      return {
        ...state,
        filterDsl: action.filterDsl,
        targetScope: action.targetScope,
        dirty: true,
      };
    case 'SET_SELECTED_IDS':
      return { ...state, selectedIds: action.selectedIds, dirty: true };
    case 'SET_INCLUDE_VARIANTS':
      return { ...state, includeVariants: action.includeVariants, dirty: true };
    case 'SET_COLUMNS':
      return { ...state, columns: action.columns, dirty: true };
    case 'SET_LOCALES':
      return { ...state, locales: action.locales, dirty: true };
    case 'SET_CHANNELS':
      return { ...state, channels: action.channels, dirty: true };
    case 'SET_PROFILE_NAME':
      return { ...state, profileName: action.profileName, dirty: true };
    case 'SET_PREFLIGHT':
      return { ...state, preflight: action.preflight };
    case 'INIT_FROM_LIST':
      return {
        ...INITIAL_WIZARD_STATE,
        source: 'list_context',
        entityType: action.entityType,
        objectTypeId: action.objectTypeId,
        selectedIds: action.selectedIds,
        filterDsl: action.filterDsl,
        targetScope: action.targetScope,
        includeVariants: action.includeVariants,
      };
    case 'INIT_FROM_PROFILE':
      return {
        ...INITIAL_WIZARD_STATE,
        editingProfileId: action.profileId,
        profileId: action.profileId,
        profileName: action.profileName,
        entityType: action.entityType,
        objectTypeId: action.objectTypeId,
        format: action.format,
        columns: action.columns,
        locales: action.locales,
        channels: action.channels,
        filterDsl: action.filterDsl,
        targetScope: action.targetScope,
        includeVariants: action.includeVariants,
      };
    case 'APPLY_PROFILE':
      return {
        ...state,
        profileId: action.profileId,
        profileName: action.profileName,
        format: action.format,
        columns: action.columns,
        locales: action.locales,
        channels: action.channels,
        filterDsl: action.filterDsl,
        targetScope: action.targetScope,
        includeVariants: action.includeVariants,
        dirty: true,
      };
    default:
      return state;
  }
}

interface WizardContextValue {
  state: WizardState;
  dispatch: Dispatch<WizardAction>;
}

const WizardContext = createContext<WizardContextValue | null>(null);

export function WizardProvider({ children }: { children: ReactNode }) {
  const [state, dispatch] = useReducer(wizardReducer, INITIAL_WIZARD_STATE);
  return <WizardContext.Provider value={{ state, dispatch }}>{children}</WizardContext.Provider>;
}

export function useWizard(): WizardContextValue {
  const context = useContext(WizardContext);
  if (!context) {
    throw new Error('useWizard must be used inside <WizardProvider>');
  }
  return context;
}

/** Has the user configured anything beyond step 1? (entity-switch confirm) */
export function hasDownstreamConfig(state: WizardState): boolean {
  return (
    state.filterDsl !== null ||
    state.selectedIds !== null ||
    state.columns.length > 0 ||
    state.profileId !== null ||
    state.profileName !== ''
  );
}

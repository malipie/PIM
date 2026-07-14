import { createContext, type Dispatch, type ReactNode, useContext, useReducer } from 'react';

import {
  type CatalogWizardAction,
  type CatalogWizardState,
  INITIAL_CATALOG_WIZARD_STATE,
  WIZARD_STEP_COUNT,
} from './types';

/**
 * CPDF-P5-02 — single source of truth for the 6-step catalog wizard.
 * Plain context + reducer (repo has no store library; mirrors the exports
 * wizard store).
 */
export function catalogWizardReducer(
  state: CatalogWizardState,
  action: CatalogWizardAction,
): CatalogWizardState {
  switch (action.type) {
    case 'GO_TO_STEP': {
      const step = Math.max(0, Math.min(WIZARD_STEP_COUNT - 1, action.step));
      return { ...state, step };
    }
    case 'SET_TEMPLATE_KIND':
      return { ...state, templateKind: action.templateKind, dirty: true };
    case 'SET_NAME':
      return { ...state, name: action.name, dirty: true };
    case 'SET_LOCALE':
      return { ...state, locale: action.locale, dirty: true };
    case 'SET_FILTER':
      return { ...state, filterDsl: action.filterDsl, dirty: true };
    case 'SET_BRANDING':
      return { ...state, branding: { ...state.branding, ...action.patch }, dirty: true };
    case 'SET_MAPPING':
      return {
        ...state,
        fieldMappings: { ...state.fieldMappings, [action.slot]: action.ref },
        dirty: true,
      };
    default:
      return state;
  }
}

interface CatalogWizardContextValue {
  state: CatalogWizardState;
  dispatch: Dispatch<CatalogWizardAction>;
  /** #2566 — the catalog id being edited, or null when creating. */
  editCatalogId: string | null;
}

const CatalogWizardContext = createContext<CatalogWizardContextValue | null>(null);

export function CatalogWizardProvider({
  children,
  initialState,
  editCatalogId = null,
}: {
  children: ReactNode;
  /** #2566 — prefilled state for the edit flow; defaults to a blank draft. */
  initialState?: CatalogWizardState;
  editCatalogId?: string | null;
}) {
  const [state, dispatch] = useReducer(
    catalogWizardReducer,
    initialState ?? INITIAL_CATALOG_WIZARD_STATE,
  );
  return (
    <CatalogWizardContext.Provider value={{ state, dispatch, editCatalogId }}>
      {children}
    </CatalogWizardContext.Provider>
  );
}

export function useCatalogWizard(): CatalogWizardContextValue {
  const context = useContext(CatalogWizardContext);
  if (!context) {
    throw new Error('useCatalogWizard must be used inside <CatalogWizardProvider>');
  }
  return context;
}

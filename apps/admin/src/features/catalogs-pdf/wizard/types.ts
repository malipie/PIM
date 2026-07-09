import type { FilterDsl } from '@/lib/filters/filter-dsl';

/** Catalog template archetypes — synced with backend CatalogTemplateKind. */
export type CatalogTemplateKind = 'sheet' | 'grid' | 'pricelist';

/** The `sheet` archetype slots (CatalogTemplateCatalog, CPDF-P2-01). */
export type SheetSlot = 'title' | 'sku' | 'image' | 'description' | 'price';

/** Branding block persisted on the catalog profile (free-form on the BE). */
export interface CatalogBranding {
  color: string;
  company_name: string;
  logo: string;
}

/** slot → attribute code (empty string = unmapped, dropped from payload). */
export type FieldMappings = Record<SheetSlot, string>;

/** Whole-wizard state (CPDF-P5-02); mirrors the exports wizard store shape. */
export interface CatalogWizardState {
  step: number;
  templateKind: CatalogTemplateKind;
  name: string;
  locale: string | null;
  filterDsl: FilterDsl | null;
  branding: CatalogBranding;
  fieldMappings: FieldMappings;
  /** Any user-made change — drives the cancel confirmation. */
  dirty: boolean;
}

export type CatalogWizardAction =
  | { type: 'GO_TO_STEP'; step: number }
  | { type: 'SET_TEMPLATE_KIND'; templateKind: CatalogTemplateKind }
  | { type: 'SET_NAME'; name: string }
  | { type: 'SET_LOCALE'; locale: string | null }
  | { type: 'SET_FILTER'; filterDsl: FilterDsl | null }
  | { type: 'SET_BRANDING'; patch: Partial<CatalogBranding> }
  | { type: 'SET_MAPPING'; slot: SheetSlot; ref: string };

export const WIZARD_STEP_COUNT = 6;

/** Sheet slots the mapping step exposes, in render order. */
export const SHEET_SLOTS: readonly SheetSlot[] = ['title', 'sku', 'image', 'description', 'price'];

/** Default slot → attribute code map (CatalogTemplateCatalog defaults). */
export const DEFAULT_FIELD_MAPPINGS: FieldMappings = {
  title: 'name',
  sku: 'sku',
  image: 'main_image',
  description: 'description',
  price: 'price',
};

export const INITIAL_CATALOG_WIZARD_STATE: CatalogWizardState = {
  step: 0,
  templateKind: 'sheet',
  name: '',
  locale: null,
  filterDsl: null,
  branding: { color: '#1f2937', company_name: '', logo: '' },
  fieldMappings: { ...DEFAULT_FIELD_MAPPINGS },
  dirty: false,
};

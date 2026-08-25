/**
 * #3002 — the translation layer between what an operator types and what
 * the workflow engine stores. Pure functions, no React: every rule here
 * is a unit test away from proof.
 *
 * Three jobs:
 *
 *   1. turn a Polish label into the `snake_case` machine name the
 *      validator demands (`^[a-z][a-z0-9_]{0,31}$`),
 *   2. keep CANONICAL transition names canonical — task automation and
 *      notifications are wired to `submit_for_review` / `approve` /
 *      `reject` / …, and a slugified "Zgłoś do przeglądu" would silently
 *      ship a flow without tasks (see `EditorialTransitionEventRecorder`,
 *      whose match falls through to `default => null`),
 *   3. name permissions the way people think about them, instead of
 *      showing raw codes like `workflow.approve_reject`.
 */

const PL_MAP: Record<string, string> = {
  ą: 'a',
  ć: 'c',
  ę: 'e',
  ł: 'l',
  ń: 'n',
  ó: 'o',
  ś: 's',
  ź: 'z',
  ż: 'z',
};

const MAX_PLACE_NAME = 32;
const MAX_TRANSITION_NAME = 64;

/**
 * Label -> machine name. Diacritics are folded, everything that is not
 * `[a-z0-9]` becomes a single underscore, and a leading digit gets an
 * `x` prefix because the validator requires a letter first.
 */
export function slugify(label: string, maxLength: number = MAX_PLACE_NAME): string {
  const folded = label
    .toLowerCase()
    .split('')
    .map((char) => PL_MAP[char] ?? char)
    .join('')
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '');

  let slug = folded
    .replace(/[^a-z0-9]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, maxLength);

  if (slug === '') return '';
  if (/^[0-9]/.test(slug)) slug = `x${slug}`.slice(0, maxLength);
  return slug.replace(/_+$/g, '');
}

/**
 * Slug that does not collide with `taken`. Collisions get `_2`, `_3`, …
 * — two states both labelled "Wstrzymany" must not silently become one.
 */
export function uniqueSlug(label: string, taken: string[], maxLength = MAX_PLACE_NAME): string {
  const base = slugify(label, maxLength);
  if (base === '') return '';
  if (!taken.includes(base)) return base;

  for (let suffix = 2; suffix < 100; suffix += 1) {
    const tail = `_${suffix}`;
    const candidate = `${base.slice(0, maxLength - tail.length)}${tail}`;
    if (!taken.includes(candidate)) return candidate;
  }
  return base;
}

/**
 * Labels whose transition MUST keep its canonical name. Anything else is
 * a custom step: the machine runs it, but no task and no notification
 * comes out of it.
 */
const CANONICAL_TRANSITIONS: Record<string, string> = {
  'zgłoś do przeglądu': 'submit_for_review',
  'submit for review': 'submit_for_review',
  zatwierdź: 'approve',
  approve: 'approve',
  odrzuć: 'reject',
  reject: 'reject',
  opublikuj: 'publish',
  publish: 'publish',
  'cofnij publikację': 'unpublish',
  unpublish: 'unpublish',
  archiwizuj: 'archive',
  archive: 'archive',
  przywróć: 'restore',
  restore: 'restore',
};

/** Every transition name the engine wires tasks and events to. */
export const CANONICAL_TRANSITION_NAMES: readonly string[] = [
  'submit_for_review',
  'publish',
  'approve',
  'reject',
  'unpublish',
  'archive',
  'restore',
];

/**
 * Machine name for a transition label: canonical when the label names a
 * built-in step, slugified otherwise.
 */
export function transitionNameFor(label: string, taken: string[] = []): string {
  const canonical = CANONICAL_TRANSITIONS[label.trim().toLowerCase()];
  if (canonical !== undefined && !taken.includes(canonical)) return canonical;
  return uniqueSlug(label, taken, MAX_TRANSITION_NAME);
}

/** Whether the engine generates tasks and notifications for this name. */
export function isCanonicalTransition(name: string): boolean {
  return CANONICAL_TRANSITION_NAMES.includes(name);
}

/** Built-in places, offered as one-click building blocks. */
export const PLACE_BLOCKS: ReadonlyArray<{
  name: string;
  labelPl: string;
  labelEn: string;
  color: string;
}> = [
  { name: 'draft', labelPl: 'Szkic', labelEn: 'Draft', color: '#71717a' },
  { name: 'review', labelPl: 'W przeglądzie', labelEn: 'In review', color: '#e0a63a' },
  { name: 'approved', labelPl: 'Zatwierdzony', labelEn: 'Approved', color: '#5b6b87' },
  { name: 'published', labelPl: 'Opublikowany', labelEn: 'Published', color: '#449c71' },
  { name: 'archived', labelPl: 'Zarchiwizowany', labelEn: 'Archived', color: '#b4c1d5' },
];

/**
 * Permission code -> how the operator knows that group of people. Codes
 * outside this map still work; they render raw and are marked advanced,
 * so a tenant with custom permissions loses nothing.
 */
export const PERMISSION_LABELS: Record<string, { pl: string; en: string }> = {
  'products.edit': { pl: 'Wprowadzający dane', en: 'Data editor' },
  'workflow.approve_reject': { pl: 'Akceptant', en: 'Approver' },
  'workflow.transition.unpublish': { pl: 'Cofający publikację', en: 'Unpublisher' },
  'publications.publish_unpublish': { pl: 'Publikujący w kanałach', en: 'Channel publisher' },
  'exports.run': { pl: 'Eksporty i integracje', en: 'Exports and integrations' },
  'products.delete': { pl: 'Usuwający produkty', en: 'Product remover' },
  'modeling.attributes.add_edit': { pl: 'Konfigurujący katalog', en: 'Catalog configurator' },
};

/** Friendly name for a permission code, or the raw code when unknown. */
export function permissionLabel(code: string, lang: string): string {
  const entry = PERMISSION_LABELS[code];
  if (entry === undefined) return code;
  return lang.startsWith('en') ? entry.en : entry.pl;
}

/** Whether the code needs the raw-code treatment (advanced mode). */
export function isAdvancedPermission(code: string): boolean {
  return code !== '' && PERMISSION_LABELS[code] === undefined;
}

/**
 * Canonical transition name -> the label people actually use. Mirrors the
 * `workflow.transition.*` i18n keys the product card already renders, so
 * a template does not hand the operator "Submit for review" on a Polish
 * screen — the whole point of #3002 was to stop showing our vocabulary.
 */
const CANONICAL_LABELS: Record<string, { pl: string; en: string }> = {
  submit_for_review: { pl: 'Zgłoś do przeglądu', en: 'Submit for review' },
  publish: { pl: 'Opublikuj', en: 'Publish' },
  approve: { pl: 'Zatwierdź', en: 'Approve' },
  reject: { pl: 'Odrzuć', en: 'Reject' },
  unpublish: { pl: 'Cofnij publikację', en: 'Unpublish' },
  archive: { pl: 'Archiwizuj', en: 'Archive' },
  restore: { pl: 'Przywróć', en: 'Restore' },
};

/**
 * Label for a transition: the canonical wording when the engine knows the
 * name, a humanised machine name otherwise.
 */
export function transitionLabel(name: string, lang: string): string {
  const entry = CANONICAL_LABELS[name];
  if (entry === undefined) return humanizeName(name);
  return lang.startsWith('en') ? entry.en : entry.pl;
}

/**
 * Machine name -> readable label, for names that carry no stored label.
 * Transitions have no `label` field in the API (places do), so the editor
 * shows the i18n label for canonical steps and this humanised form for
 * custom ones: `przekaz_do_publikacji` -> `Przekaz do publikacji`.
 */
export function humanizeName(name: string): string {
  const words = name.replace(/_/g, ' ').trim();
  if (words === '') return '';
  return words.charAt(0).toUpperCase() + words.slice(1);
}

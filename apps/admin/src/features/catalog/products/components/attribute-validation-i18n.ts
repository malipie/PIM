import type { TFunction } from 'i18next';

/**
 * Attribute value-validation messages are emitted in English by the backend
 * TypeValidators (e.g. `Value -4 is below min 0.`), and the API carries only
 * the human string — no machine code. We pattern-match the known message
 * families here and re-render them through i18n so the error toast follows the
 * UI language instead of always being English. Anything unrecognised falls
 * through unchanged.
 */
interface Rule {
  re: RegExp;
  key: string;
  defaultValue: string;
  params?: (match: RegExpMatchArray) => Record<string, string>;
}

const RULES: Rule[] = [
  {
    re: /^(?:Value|Metric value|Price amount) (\S+) (?:is )?below min (\S+)\.?$/,
    key: 'products.detail.validation.value.below_min',
    defaultValue: 'Wartość {{value}} jest poniżej minimum {{min}}.',
    params: (m) => ({ value: m[1] ?? '', min: m[2] ?? '' }),
  },
  {
    re: /^(?:Value|Metric value) (\S+) exceeds max (\S+)\.?$/,
    key: 'products.detail.validation.value.above_max',
    defaultValue: 'Wartość {{value}} przekracza maksimum {{max}}.',
    params: (m) => ({ value: m[1] ?? '', max: m[2] ?? '' }),
  },
  {
    re: /(?:Number value|Metric value|Price amount) must be int or float\.?$/,
    key: 'products.detail.validation.value.numeric',
    defaultValue: 'Wartość musi być liczbą.',
  },
  {
    re: /^(?:Value|Metric value) has more than (\d+) decimal places\.?$/,
    key: 'products.detail.validation.value.precision',
    defaultValue: 'Wartość ma więcej niż {{max}} miejsc po przecinku.',
    params: (m) => ({ max: m[1] ?? '' }),
  },
  {
    re: /^Text(?:area)? exceeds max_length=(\d+) \(got (\d+)\)\.?$/,
    key: 'products.detail.validation.value.max_length',
    defaultValue: 'Tekst przekracza maksymalną długość {{max}} (obecnie {{got}}).',
    params: (m) => ({ max: m[1] ?? '', got: m[2] ?? '' }),
  },
  {
    re: /does not match pattern/,
    key: 'products.detail.validation.value.pattern',
    defaultValue: 'Wartość nie pasuje do wymaganego wzorca.',
  },
  {
    re: /is not a valid email address\.?$/,
    key: 'products.detail.validation.value.email',
    defaultValue: 'To nie jest poprawny adres e-mail.',
  },
  {
    re: /is not a valid option for this attribute\.?$/,
    key: 'products.detail.validation.value.option',
    defaultValue: 'Wybrana wartość nie jest dozwoloną opcją tego atrybutu.',
  },
  {
    re: /is not a valid ISO 8601 string\.?$/,
    key: 'products.detail.validation.value.date',
    defaultValue: 'To nie jest poprawna data (ISO 8601).',
  },
  {
    re: /is not a valid (?:rgb.*|#RRGGBB hex) string\.?$/,
    key: 'products.detail.validation.value.color',
    defaultValue: 'To nie jest poprawny kolor.',
  },
];

/** Localise a single backend attribute value-validation message. */
export function localizeAttributeMessage(raw: string, t: TFunction): string {
  for (const rule of RULES) {
    const match = raw.match(rule.re);
    if (match !== null) {
      return t(rule.key, { defaultValue: rule.defaultValue, ...(rule.params?.(match) ?? {}) });
    }
  }
  return raw;
}

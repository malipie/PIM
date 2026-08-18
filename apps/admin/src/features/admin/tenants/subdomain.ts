/**
 * TNT-P4-06 (#2907) — kształt subdomeny instancji, sprawdzany w formularzu.
 *
 * **Autorytetem jest serwer.** Ten moduł sprawdza wyłącznie KSZTAŁT nazwy,
 * żeby operator dostał odpowiedź natychmiast, bez czekania na żądanie. Nazwy
 * zastrzeżone i zajęte rozstrzyga API (422 / 409) i to jego komunikat trafia
 * do operatora.
 *
 * Świadomie nie kopiuję tu listy nazw zastrzeżonych: żyje ona już w trzech
 * miejscach (PHP, skrypt provisioningu, provisioner) i czwarta kopia
 * rozjechałaby się przy pierwszej zmianie. Lepszy jest brak duplikatu
 * i komunikat z serwera niż lokalna lista, która kiedyś skłamie.
 */

/** Pojedyncza etykieta DNS: 3–32 znaki, bez myślnika na brzegach. */
export const SUBDOMAIN_PATTERN = /^[a-z0-9][a-z0-9-]{1,30}[a-z0-9]$/;

export type SubdomainShapeError = 'empty' | 'too_short' | 'too_long' | 'charset' | 'edges';

/**
 * Zwraca powód, dla którego nazwa nie przejdzie, albo `null`, gdy kształt jest
 * poprawny. Rozróżnia powody, żeby komunikat mówił, co poprawić — „niepoprawna
 * subdomena" nie pomaga nikomu.
 */
export function subdomainShapeError(raw: string): SubdomainShapeError | null {
  const value = raw.trim().toLowerCase();

  if (value === '') return 'empty';
  if (value.length < 3) return 'too_short';
  if (value.length > 32) return 'too_long';
  if (!/^[a-z0-9-]+$/.test(value)) return 'charset';
  if (!SUBDOMAIN_PATTERN.test(value)) return 'edges';

  return null;
}

/**
 * Podpowiedź subdomeny z kodu tenanta: kod dopuszcza podkreślenia, subdomena
 * nie, więc zamieniamy je na myślniki zamiast kazać operatorowi przepisywać.
 */
export function suggestSubdomain(code: string): string {
  return (
    code
      .trim()
      .toLowerCase()
      // Podkreślenia i spacje stają się myślnikiem, a nie znikają: „Acme Corp"
      // ma dać `acme-corp`, nie `acmecorp`.
      .replace(/[\s_]+/g, '-')
      .replace(/[^a-z0-9-]/g, '')
      .replace(/-{2,}/g, '-')
      .replace(/^-+/, '')
      .replace(/-+$/, '')
      .slice(0, 32)
  );
}

/** Pełny adres instancji, pokazywany pod polem na żywo. */
export function instanceUrl(subdomain: string, baseDomain: string): string {
  return `https://${subdomain}.${baseDomain}`;
}

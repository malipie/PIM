# JSONB shapes — kontrakty pól

> HARD-06 (audit 2026-05-12) — formalna dokumentacja shape-ów JSONB w PIM.
> Backend wcześniej jedyne dawał implicit kontrakt przez `*Validator` i
> writer-y; frontend musiał reverse-engineerować shape z gotowych payloadów.
> Każdy bug typu "atrybuty się nie zapisują" (PR #511) był w istocie
> nieporozumieniem co do envelope shape-u. Ten dokument jest authoritative
> source-em.

Schemas używają [JSON Schema 2020-12](https://json-schema.org/specification.html). Plik docelowo zostanie zlinkowany z `CLAUDE.md` jako wymagana lektura przy kontaktach z `attributes_indexed` / `validation_rules` / `completeness`.

---

## 1. `attributes_indexed` — denormalizowany cache atrybutów

**Tabela**: `objects.attributes_indexed JSONB DEFAULT '{}'`
**Index**: `objects_attributes_indexed_gin USING GIN (attributes_indexed)`
**Writer**: [`AttributesIndexedRebuilder::rebuild()`](apps/api/src/Catalog/Application/AttributesIndexedRebuilder.php)
**Readers**: cały admin (przez [`unwrapAttributesIndexed`](apps/admin/src/lib/attributes-indexed.ts) helper) + Meilisearch indexer.

### Shape

Mapa `attribute.code → wartość`. Każda wartość to **envelope** (nie raw value). Envelope może mieć dodatkowe meta pola pod overlay locale/channel w przyszłości.

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "pim:jsonb:attributes_indexed",
  "type": "object",
  "additionalProperties": {
    "type": "object",
    "required": ["value"],
    "properties": {
      "value": {
        "description": "Wartość atrybutu w typie zgodnym z Attribute.type. Dla select to `code` opcji (np. \"red\"); dla multiselect tablica codes (np. [\"new\",\"sale\"]); dla price obiekt {amount, currency}; dla date string ISO YYYY-MM-DD; dla boolean true/false; dla text/wysiwyg string."
      },
      "locale": {
        "type": ["string", "null"],
        "description": "Locale code (pl/en/de/cs) jeśli wartość jest locale-scoped. Brak = global (wszystkie locale)."
      },
      "channel": {
        "type": ["string", "null"],
        "description": "Channel code jeśli wartość jest channel-scoped. Brak = global."
      },
      "provenance": {
        "enum": ["manual", "import", "agent", "integration", null],
        "description": "Skąd przyszła wartość. Reserved `agent` na Fazę 2."
      }
    },
    "additionalProperties": true
  }
}
```

### Przykłady

```json
{
  "name":        { "value": "Buty sportowe Air Max 90" },
  "color":       { "option_code": "red" },
  "tags":        { "option_codes": ["new", "sale"] },
  "price":       { "amount": 299.00, "currency": "PLN" },
  "release_date":{ "value": "2027-03-15" },
  "in_stock":    { "value": true }
}
```

### Reguły dla readerów

1. **Zawsze** czytaj przez `unwrapAttributesIndexed(raw)` (admin) — helper passthroughuje wpisy bez envelope dla bezpieczeństwa migracji.
2. NIE rób `typeof attrs.name === 'string'` — `attrs.name` to **envelope**, nie string.
3. Po unwrapowaniu wartość ma typ zgodny z `Attribute.type`. Per-type rendering w [`AttrRow`](apps/admin/src/features/catalog/products/components/attr-row.tsx).

### Reguły dla writerów

1. **NIGDY nie pisz** `attributes_indexed` ręcznie. Single source of truth: zapisuj w `ObjectValue` przez `ObjectAttributesUpserter` → `AttributesIndexedRebuilder` automatycznie odbuduje cache.
2. Jeśli **musisz** pisać bezpośrednio (np. `DemoCatalogSeeder`), zachowaj envelope: `[$code => ['value' => $rawValue]]`.

---

## 2. `validation_rules` — per-Attribute walidacja

**Tabela**: `attributes.validation_rules JSONB DEFAULT '{}'`
**Reader**: [`TypeValidator`](apps/api/src/Catalog/Application/Validator) (per-type implementacje: `TextValidator`, `NumberValidator`, `SelectValidator`, …).

### Shape (per-type)

Schema unionowa — pole `validation_rules` jest interpretowane przez validator zgodnie z `Attribute.type`. Klucze nieużywane przez dany type są ignorowane (graceful degradation przy migracji).

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "pim:jsonb:validation_rules",
  "type": "object",
  "properties": {
    "max_length":   { "type": "integer", "minimum": 1, "description": "text, wysiwyg" },
    "min_length":   { "type": "integer", "minimum": 0, "description": "text, wysiwyg" },
    "pattern":      { "type": "string", "description": "text, email — regex JS-compatible (email: extra domain allow-list on top of RFC 5322 check)" },
    "color_format": { "enum": ["hex", "rgb"], "description": "color (#1177) — `hex` (#RRGGBB, default) or `rgb` (rgb(r, g, b))" },
    "format":       { "enum": ["ean13", "gtin14", "isbn13", "isbn10", "url", "iso_country"], "description": "identifier (#1179) — digit count + check digit (GTIN mod-10 / ISBN-10 mod-11); text (DP-06 #2036) — `url` (valid absolute URL) or `iso_country` (ISO 3166-1 alpha-2, case-insensitive)" },
    "require_https": { "type": "boolean", "description": "text (DP-06 #2036) — z `format: url` wymusza schemat https" },
    "min":          { "type": ["number", "string"], "description": "number, metric, price (number); date, datetime (ISO 8601 string — floor)" },
    "max":          { "type": ["number", "string"], "description": "number, metric, price (number); date, datetime (ISO 8601 string — ceil)" },
    "min_amount":   { "type": "number", "description": "price — wymóg na amount" },
    "max_amount":   { "type": "number", "description": "price" },
    "currencies":   { "type": "array", "items": { "type": "string", "minLength": 3, "maxLength": 3 }, "description": "price — allowed ISO 4217 codes" },
    "max_count":    { "type": "integer", "minimum": 1, "description": "multiselect, tags — max liczba wybranych opcji" },
    "min_count":    { "type": "integer", "minimum": 0, "description": "multiselect, tags" },
    "allowed_kinds":{ "type": "array", "items": { "type": "string" }, "description": "asset, relation — allowed ObjectType.kind" },
    "min_date":     { "type": "string", "format": "date", "description": "date" },
    "max_date":     { "type": "string", "format": "date", "description": "date" }
  },
  "additionalProperties": false
}
```

### Przykłady

```json
{ "max_length": 255 }                              // name (text)
{ "min": 0, "currencies": ["PLN", "EUR", "USD"] } // price
{ "max_count": 5 }                                 // tags (multiselect)
{ "min_date": "2020-01-01" }                       // release_date (date)
{ "format": "url", "require_https": true }         // datasheet_url (text)
{ "format": "iso_country" }                        // country_of_origin (text)
```

### Notki

- **Pusta mapa `{}` = brak walidacji** poza built-in `Attribute::type` constraints.
- **Klucze nieznane danego typu** są ignorowane przez validator (nie błąd). To pozwala dorzucić nowy klucz dla nowego typu bez breaking migration.
- **`identifier` (#1179) — unikalność per ObjectType na poziomie DB.** Wartość trzymana w `object_values.value->>'value'` jest mirrorowana przez trigger `object_values_sync_identifier_trg` do kolumn `identifier_value` + `identifier_object_type_id` (NULL dla nie-identifier rows). Partial `UNIQUE INDEX object_values_identifier_uniq (tenant_id, identifier_object_type_id, attribute_id, identifier_value) WHERE identifier_value IS NOT NULL` egzekwuje unikalność; app-level pre-check (`IdentifierUniquenessValidator`) daje czytelne 409 przed constraintem. Identifier attrs są wymuszane jako non-localizable + non-scopable (jedna wartość per obiekt). Kolumny są zarządzane wyłącznie przez trigger — Doctrine ich nie mapuje ani nie zapisuje.

---

## 3. `completeness` — denormalizowana kompletność

**Tabela**: `objects.completeness JSONB DEFAULT '{}'` + redundant `objects.completeness_pct SMALLINT DEFAULT 0`
**Writer**: [`AttributesIndexedRebuilder::rebuild()`](apps/api/src/Catalog/Application/AttributesIndexedRebuilder.php) (ten sam co `attributes_indexed`).

### Shape

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "pim:jsonb:completeness",
  "type": "object",
  "required": ["global"],
  "properties": {
    "global": {
      "type": "integer",
      "minimum": 0,
      "maximum": 100,
      "description": "Procent uzupełnionych pól z `ObjectType.completeness_rules.required` (0-100, integer)."
    },
    "per_channel": {
      "type": "object",
      "additionalProperties": { "type": "integer", "minimum": 0, "maximum": 100 },
      "description": "Per-channel pct gdy ObjectType ma channel-scoped attributes (Faza 1 / channel publication)."
    },
    "per_locale": {
      "type": "object",
      "additionalProperties": { "type": "integer", "minimum": 0, "maximum": 100 },
      "description": "Per-locale pct gdy attributes są localizable. Klucz = locale code (pl/en/de/cs)."
    }
  },
  "additionalProperties": false
}
```

### Przykład

```json
{
  "global": 75,
  "per_channel": { "shopify": 80, "baselinker": 60 },
  "per_locale":  { "pl": 100, "en": 50 }
}
```

### Reguły

- `global` **zawsze** obecne — fallback `100` gdy `completeness_rules.required` jest pusta.
- `per_channel` / `per_locale` **opcjonalne** — frontend powinien czytać przez `completeness?.per_channel?.[channel]`.
- `completeness_pct` (SMALLINT) jest mirrorem `global` dla szybkiego sortu/index. Nigdy nie dezsynchronizować — pisać atomicznie razem z JSONB.

---

## 4. `variant_axes` — definicja osi wariantów

**Tabela**: `objects.variant_axes JSONB NULLABLE`
**Writer**: `GenerateVariantsHandler` (po wygenerowaniu wariantów dla mastera).
**Reader**: `VariantsTab` w admin (`apps/admin/src/components/catalog/variants-tab.tsx`).

### Shape

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "pim:jsonb:variant_axes",
  "type": "object",
  "additionalProperties": {
    "type": "array",
    "items": { "type": "string" },
    "description": "Lista option codes z Attribute.options (dla select/multiselect typu)."
  },
  "description": "Klucz = Attribute.code (musi być typu select/multiselect z predefined options). Wartość = lista wybranych opcji do wygenerowania kombinacji."
}
```

### Przykład

```json
{
  "color": ["red", "blue", "black"],
  "size":  ["S", "M", "L", "XL"]
}
```

12 wariantów (3 × 4) zostanie wygenerowanych przy `GenerateVariantsHandler`.

### Reguły

- Klucz `null` na master = brak osi → operator decyduje per ticket variants tab.
- **Tylko** select/multiselect attributes mogą być axes (osie potrzebują predefined values do iteracji).
- Po wygenerowaniu wariantów wartość się **nie aktualizuje** — `variant_axes` opisuje DEFINICJĘ osi, nie istniejące warianty (te są w `objects.parent_id` chain).

---

## 5. `provenance_meta` — meta wartości po stronie ObjectValue

**Tabela**: `object_values.provenance_meta JSONB DEFAULT '{}'`
**Writer**: `ObjectAttributesUpserter` przy save.

### Shape

```json
{
  "$schema": "https://json-schema.org/draft/2020-12/schema",
  "$id": "pim:jsonb:provenance_meta",
  "type": "object",
  "properties": {
    "source": { "type": "string", "description": "Identyfikator źródła (import session uuid, integration name, agent run id)" },
    "imported_at": { "type": "string", "format": "date-time" },
    "user_id": { "type": "string", "format": "uuid" },
    "agent_run_id": { "type": "string", "format": "uuid", "description": "provenance=agent — id AgentRun (epik 0.7, tabela agent_runs)" },
    "model": { "type": "string", "description": "provenance=agent — id modelu Anthropic użytego w runie (np. claude-sonnet-*)" },
    "intent": { "type": "string", "description": "provenance=agent — intencja usera, z której powstała zmiana (skrót)" },
    "channel": { "type": "string", "description": "Integration channel id jeśli provenance=integration" }
  },
  "additionalProperties": true
}
```

### Shape per provenance=agent (AGENT-P0-04 #1947, ADR-0024)

Wartości zapisane przez agenta (po akcepcie batcha `pending_changes`) niosą:

```json
{ "agent_run_id": "<uuid AgentRun>", "model": "claude-sonnet-…", "intent": "ustaw cenę 100 wszystkim bez ceny" }
```

`agent_run_id` linkuje wartość do runu (audyt + tooltip badge'a „agent" w UI, P6-05).

### Reguły

- `additionalProperties: true` — dodatkowe pola wolno dorzucać per provenance type (forward-compat).
- Wartość zawsze powiązana z `ObjectValue.provenance` enum (`manual` / `import` / `agent` / `integration`).
- Frontend wykorzystuje przez `<ProvenanceBadge>` — pokazuje tylko `provenance` + tooltip z `source`.

---

## Reguły ogólne (cross-cutting)

1. **Defensive read po stronie frontendu**: każdy reader JSONB MUSI mieć fallback na missing key + invalid type. Wzór: `unwrapAttributesIndexed`. Nigdy `attrs.name.value` bez null-checka — `attrs.name` może nie istnieć.
2. **Backward compatible writers**: dodanie nowego klucza do envelope = additive (nie wymusza migracji frontendu). Usunięcie/rename = breaking (wymaga koordynacji + migration ticket).
3. **Indeksy GIN są na całym dokumencie JSONB**, nie per-key. Zapytania `WHERE attributes_indexed @> '{"color":{"value":"red"}}'` będą szybkie. Per-key index (functional index) dorzucamy jeśli konkretne zapytanie wymaga (np. fast price lookup → osobny migration ticket).
4. **Validacja shape**: dziś jest implicit (validators po stronie writera). Future (po Fazie 1): JSON Schema validation w Symfony Validator constraints + automated test który asercjuje że produkcyjne payloady matchują schema-y z tego pliku.

---

## Powiązane

- ADR-009 (`Project Plan/01-architektura-pim.md` §13) — generalizacja `ObjectType` jako first-class.
- `agent/lessons.md` "Patterns to Follow" → "`attributes_indexed` ma envelope `{value: ...}`" (PR #511).
- [`apps/admin/src/lib/attributes-indexed.ts`](apps/admin/src/lib/attributes-indexed.ts) — shared reader helper.
- [`apps/api/src/Catalog/Application/AttributesIndexedRebuilder.php`](apps/api/src/Catalog/Application/AttributesIndexedRebuilder.php) — single writer dla cache.

---

## 6. `object_values.value` — kanon per `AttributeType` (ADR-0019)

Cache `attributes_indexed` kopiuje envelope **verbatim** z `ObjectValue.value`
— poniższe shape'y obowiązują w obu miejscach. Authoritative: ADR-0019
(`docs/adr/0019-import-v2-engine-contracts.md`), egzekwowane przez wspólny
rdzeń walidacji (IMP2-1.4 / #1466).

| Typy | Shape |
|---|---|
| text, textarea, wysiwyg, number, date, datetime, boolean, color, email, identifier | `{"value": <scalar>}` |
| select | `{"option_code": "<code>"}` |
| multiselect | `{"option_codes": ["<code>", …]}` |
| price | `{"amount": <number>, "currency": "<ISO>"}` |
| metric | `{"value": <number>, "unit": "<code>"}` |
| asset | `{"asset_id": "<uuid>"}` |
| relation, reference | `{"object_id": "<uuid>"}` |

Legacy odstępstwa (select `{"value": code}` z admina, osie wariantów
`{"value": x}` z GenerateVariants, price `{"value": n}` bez waluty) migruje
jednorazowo #1464; do tego czasu readery mogą mieć tolerancyjny fallback,
po migracji fallbacki znikają.

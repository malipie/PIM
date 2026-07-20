<?php

declare(strict_types=1);

namespace App\Catalog\Application\Filter;

use App\Shared\Infrastructure\Meilisearch\MeiliFilterLiteral;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Throwable;

/**
 * VIEW-09 (#535) — Filter DSL resolver.
 *
 * Flat (single-level) JSONB shape:
 *   {"attr": "brand", "op": "IN", "value": ["Festo", "Bosch"]}
 *
 * Composite (one level of grouping in VIEW-09; nested AND/OR/NOT lands in VIEW-09b):
 *   {"operator": "AND", "conditions": [
 *     {"attr": "description.pl", "op": "IS NOT EMPTY"},
 *     {"attr": "description.en", "op": "IS EMPTY"}
 *   ]}
 *
 * Supported operators in VIEW-09 (basic 6, full 25 → VIEW-10):
 *   `=` | `!=` (alias `≠`) | `IS EMPTY` | `IS NOT EMPTY` | `<` | `IN`
 *
 * Reserved attribute paths:
 *   - `main_image`        — checks `attributes_indexed->>'main_image'` (object_value asset).
 *   - `category`          — checks `EXISTS (SELECT 1 FROM object_categories WHERE object_id=...)`.
 *   - `description`       — locale-agnostic; `EXISTS` over any locale.
 *   - `description.pl`    — locale-scoped (per-locale JSONB path).
 *   - `description.en`    — same.
 *   - `meta_description`  — single-value attribute.
 *   - `completeness_pct`  — column on catalog_objects, NOT JSONB.
 *
 * Custom attribute codes (brand, family, ip_class, voltage…) hit
 * `attributes_indexed->>'{code}'` — GIN-indexed per ADR-006.
 */
final class FilterDslResolver
{
    /**
     * VIEW-10 (#538) — 25 operators per type from PRD §5.5.
     *
     * The canonical form is the lowercase string token below; UI labels
     * (`STARTS WITH`, `≠`, `between`) accept-list resolves to the same
     * canonical form (`starts_with`, `not_equals`, `between`) before
     * validation. This keeps the DSL portable across BE/FE while letting
     * the UI render localised labels.
     */
    public const string OP_EQ = '=';
    public const string OP_NEQ = '!=';
    public const string OP_IS_EMPTY = 'IS EMPTY';
    public const string OP_IS_NOT_EMPTY = 'IS NOT EMPTY';
    public const string OP_LT = '<';
    public const string OP_GT = '>';
    public const string OP_LTE = '<=';
    public const string OP_GTE = '>=';
    public const string OP_IN = 'IN';
    public const string OP_NOT_IN = 'NOT IN';
    public const string OP_STARTS_WITH = 'starts with';
    public const string OP_ENDS_WITH = 'ends with';
    public const string OP_CONTAINS = 'contains';
    public const string OP_NOT_CONTAINS = 'not contains';
    public const string OP_BETWEEN = 'between';
    public const string OP_AFTER = 'after';
    public const string OP_BEFORE = 'before';
    public const string OP_IS_TRUE = '= TRUE';
    public const string OP_IS_FALSE = '= FALSE';

    /**
     * Valid operator → type matrix (PRD §5.5). Lookup is normalised: the
     * caller's operator is uppercased and matched against the canonical
     * tokens below before resolving the type's allow-list.
     *
     * @var array<string, list<string>>
     */
    public const array OPERATORS_BY_TYPE = [
        'text' => [
            self::OP_EQ, self::OP_NEQ, self::OP_IS_EMPTY, self::OP_IS_NOT_EMPTY,
            self::OP_STARTS_WITH, self::OP_ENDS_WITH, self::OP_CONTAINS, self::OP_NOT_CONTAINS,
        ],
        'wysiwyg' => [
            self::OP_EQ, self::OP_NEQ, self::OP_IS_EMPTY, self::OP_IS_NOT_EMPTY,
            self::OP_STARTS_WITH, self::OP_ENDS_WITH, self::OP_CONTAINS, self::OP_NOT_CONTAINS,
        ],
        // #1177 — textarea/email share the text operator set (free-form
        // strings); color is exact-match / set membership (visual filter).
        'textarea' => [
            self::OP_EQ, self::OP_NEQ, self::OP_IS_EMPTY, self::OP_IS_NOT_EMPTY,
            self::OP_STARTS_WITH, self::OP_ENDS_WITH, self::OP_CONTAINS, self::OP_NOT_CONTAINS,
        ],
        'email' => [
            self::OP_EQ, self::OP_NEQ, self::OP_IS_EMPTY, self::OP_IS_NOT_EMPTY,
            self::OP_STARTS_WITH, self::OP_ENDS_WITH, self::OP_CONTAINS, self::OP_NOT_CONTAINS,
        ],
        'color' => [
            self::OP_EQ, self::OP_NEQ, self::OP_IN, self::OP_NOT_IN,
            self::OP_IS_EMPTY, self::OP_IS_NOT_EMPTY,
        ],
        // #1179 — identifier (EAN/GTIN/SKU): exact match / set membership /
        // prefix lookup (e.g. SKU starts with a series code).
        'identifier' => [
            self::OP_EQ, self::OP_NEQ, self::OP_IN, self::OP_NOT_IN,
            self::OP_STARTS_WITH, self::OP_IS_EMPTY, self::OP_IS_NOT_EMPTY,
        ],
        'number' => [
            self::OP_EQ, self::OP_NEQ, self::OP_LT, self::OP_GT, self::OP_LTE, self::OP_GTE,
            self::OP_BETWEEN, self::OP_IS_EMPTY, self::OP_IS_NOT_EMPTY,
        ],
        'metric' => [
            self::OP_EQ, self::OP_NEQ, self::OP_LT, self::OP_GT, self::OP_LTE, self::OP_GTE,
            self::OP_BETWEEN, self::OP_IS_EMPTY, self::OP_IS_NOT_EMPTY,
        ],
        'price' => [
            self::OP_EQ, self::OP_NEQ, self::OP_LT, self::OP_GT, self::OP_LTE, self::OP_GTE,
            self::OP_BETWEEN, self::OP_IS_EMPTY, self::OP_IS_NOT_EMPTY,
        ],
        'date' => [
            self::OP_EQ, self::OP_NEQ, self::OP_AFTER, self::OP_BEFORE, self::OP_BETWEEN,
            self::OP_IS_EMPTY, self::OP_IS_NOT_EMPTY,
        ],
        'datetime' => [
            self::OP_EQ, self::OP_NEQ, self::OP_AFTER, self::OP_BEFORE, self::OP_BETWEEN,
            self::OP_IS_EMPTY, self::OP_IS_NOT_EMPTY,
        ],
        'select' => [
            self::OP_EQ, self::OP_NEQ, self::OP_IN, self::OP_NOT_IN,
            self::OP_IS_EMPTY, self::OP_IS_NOT_EMPTY,
        ],
        'multiselect' => [
            self::OP_CONTAINS, self::OP_NOT_CONTAINS, self::OP_IS_EMPTY, self::OP_IS_NOT_EMPTY,
        ],
        'boolean' => [self::OP_IS_TRUE, self::OP_IS_FALSE],
        'relation' => [
            self::OP_EQ, self::OP_NEQ, self::OP_IN, self::OP_NOT_IN,
            self::OP_IS_EMPTY, self::OP_IS_NOT_EMPTY,
        ],
        'reference' => [self::OP_IS_EMPTY, self::OP_IS_NOT_EMPTY],
        'asset' => [self::OP_IS_EMPTY, self::OP_IS_NOT_EMPTY],
    ];

    /**
     * Aliases used by the UI prototype / legacy callers, normalised to the
     * canonical operator token in {@see normaliseOperator()}.
     */
    private const array OP_ALIASES = [
        '≠' => self::OP_NEQ,
        '≥' => self::OP_GTE,
        '≤' => self::OP_LTE,
        'STARTS_WITH' => self::OP_STARTS_WITH,
        'STARTS WITH' => self::OP_STARTS_WITH,
        'ENDS_WITH' => self::OP_ENDS_WITH,
        'ENDS WITH' => self::OP_ENDS_WITH,
        'CONTAINS' => self::OP_CONTAINS,
        'NOT_CONTAINS' => self::OP_NOT_CONTAINS,
        'NOT CONTAINS' => self::OP_NOT_CONTAINS,
        'BETWEEN' => self::OP_BETWEEN,
        'AFTER' => self::OP_AFTER,
        'BEFORE' => self::OP_BEFORE,
        '=TRUE' => self::OP_IS_TRUE,
        '=FALSE' => self::OP_IS_FALSE,
    ];

    private const array LOGICAL_OPS = ['AND', 'OR'];

    /**
     * Reserved attribute paths that map to columns/joins instead of
     * `attributes_indexed` JSONB lookups.
     *
     * @var array<string, string>
     */
    private const array COLUMN_MAP = [
        'completeness_pct' => 'co.completeness_pct',
        'enabled' => 'co.enabled',
        'status' => 'co.status',
        // 'sku' is the UI alias for the natural key; the physical column on
        // objects is 'code' (EXR-16 fix — the wizard's preflight/export SQL
        // path 500'd on any sku condition).
        'sku' => 'co.code',
    ];

    public function __construct(
        private readonly ?AttributeMetadataResolver $attributeMetadata = null,
        private readonly ?FilterScopeResolver $scopeResolver = null,
    ) {
    }

    /**
     * Normalise UI label / alias to the canonical operator token.
     */
    public static function normaliseOperator(string $op): string
    {
        $trimmed = trim($op);
        if (\in_array($trimmed, [self::OP_EQ, self::OP_NEQ, self::OP_LT, self::OP_GT, self::OP_LTE, self::OP_GTE], true)) {
            return $trimmed;
        }
        $upper = strtoupper($trimmed);
        $lower = strtolower($trimmed);

        if (isset(self::OP_ALIASES[$trimmed])) {
            return self::OP_ALIASES[$trimmed];
        }
        if (isset(self::OP_ALIASES[$upper])) {
            return self::OP_ALIASES[$upper];
        }

        // Operators stored in lowercase form (starts with, ends with, contains).
        $lowerCanonical = [
            self::OP_STARTS_WITH, self::OP_ENDS_WITH, self::OP_CONTAINS, self::OP_NOT_CONTAINS,
            self::OP_BETWEEN, self::OP_AFTER, self::OP_BEFORE,
        ];
        if (\in_array($lower, $lowerCanonical, true)) {
            return $lower;
        }

        // Uppercase canonical (IS EMPTY, IS NOT EMPTY, IN, NOT IN, = TRUE, = FALSE).
        $upperCanonical = [
            self::OP_IS_EMPTY, self::OP_IS_NOT_EMPTY, self::OP_IN, self::OP_NOT_IN,
            self::OP_IS_TRUE, self::OP_IS_FALSE,
        ];
        if (\in_array($upper, $upperCanonical, true)) {
            return $upper;
        }

        return $trimmed;
    }

    /**
     * VIEW-10 — return the operator list for a given attribute type.
     *
     * @return list<string>
     */
    public static function operatorsForType(string $type): array
    {
        return self::OPERATORS_BY_TYPE[$type] ?? [];
    }

    /**
     * VIEW-10 — assert that `$op` is valid for the attribute referenced by
     * `$attrCode`. Skips the check when no metadata resolver is wired (unit
     * tests; production DI always provides one).
     */
    public function validateOperatorForType(string $attrCode, string $op): void
    {
        if (null === $this->attributeMetadata) {
            return;
        }

        $type = $this->attributeMetadata->getAttributeType($attrCode);
        if (null === $type) {
            // Unknown attribute → resolver-level safety net catches the
            // identifier separately. Skip type-narrowing here so the
            // error message reflects the root cause.
            return;
        }

        $canonical = self::normaliseOperator($op);
        $valid = self::operatorsForType($type);
        if (!\in_array($canonical, $valid, true)) {
            throw new BadRequestHttpException(\sprintf(
                'Operator "%s" not supported for attribute "%s" of type "%s". Valid operators: %s.',
                $op,
                $attrCode,
                $type,
                implode(', ', $valid),
            ));
        }
    }

    /**
     * Validate DSL structure. Throws BadRequestHttpException with a
     * clear message on first violation.
     *
     * @param array<string, mixed> $dsl
     */
    public function validate(array $dsl): void
    {
        // #2673 — the optional root `scope` (value context) is validated
        // eagerly so an unknown channel/locale surfaces as a clear 400
        // instead of a silently-null count from toCountSql().
        $this->scopeResolver?->resolve($dsl['scope'] ?? null);

        if (isset($dsl['operator']) && isset($dsl['conditions'])) {
            $this->validateGroup($dsl, depth: 0);

            return;
        }

        if (!isset($dsl['attr']) || !isset($dsl['op'])) {
            throw new BadRequestHttpException('FilterDsl must be either a condition {attr, op, value?} or group {operator, conditions[]}.');
        }

        $this->validateCondition($dsl);
    }

    /**
     * #2673 — true when the DSL root carries a non-empty value-context
     * (`scope: {channel?, locale?}`). Callers on the Meilisearch path use
     * this to switch to the SQL prefilter — scoped values are not in the
     * Meili document (#1148 keeps it global-only).
     *
     * @param array<string, mixed> $dsl
     */
    public static function hasScope(array $dsl): bool
    {
        $scope = $dsl['scope'] ?? null;
        if (!\is_array($scope)) {
            return false;
        }
        $channel = $scope['channel'] ?? null;
        $locale = $scope['locale'] ?? null;

        return (\is_string($channel) && '' !== trim($channel))
            || (\is_string($locale) && '' !== trim($locale));
    }

    /**
     * Convert DSL to a PostgreSQL `WHERE`-fragment SQL string usable
     * inside the bulk count query (`SELECT COUNT(*) FROM catalog_objects co WHERE ... AND ({sql})`).
     *
     * Returns `null` when the DSL targets attributes not yet indexed
     * (graceful degradation; count = 0 surfaced to the user).
     *
     * **NOTE — VIEW-09 scope**: builds *parameter-free* SQL by inlining
     * literal values escaped via `pg_escape_literal` semantics. Future
     * VIEW-10 will switch to PDO-bound parameters for safety; for VIEW-09
     * the DSL flow is admin-only (curated dropdown values) and the
     * controller-side execution is wrapped in try/catch.
     *
     * @param array<string, mixed> $dsl
     */
    public function toCountSql(array $dsl): ?string
    {
        try {
            // #2673 — the root-level `scope` sets the value context for
            // every condition in the document (panel-wide selector).
            $scope = $this->scopeResolver?->resolve($dsl['scope'] ?? null);

            return $this->compile($dsl, depth: 0, scope: $scope);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $dsl
     */
    private function compile(array $dsl, int $depth, ?FilterScopeContext $scope = null): string
    {
        if ($depth > 3) {
            throw new RuntimeException('FilterDsl nesting too deep (>3).');
        }

        if (isset($dsl['operator']) && isset($dsl['conditions'])) {
            /** @var array{operator: string, conditions: list<array<string, mixed>>} $group */
            $group = $dsl;
            $operator = strtoupper($group['operator']);
            if (!\in_array($operator, self::LOGICAL_OPS, true)) {
                throw new RuntimeException('Unsupported logical operator: '.$group['operator']);
            }

            $parts = [];
            foreach ($group['conditions'] as $condition) {
                $parts[] = '('.$this->compile($condition, $depth + 1, $scope).')';
            }
            if ([] === $parts) {
                return '1=1';
            }

            return implode(' '.$operator.' ', $parts);
        }

        return $this->compileCondition($dsl, $scope);
    }

    /**
     * @param array<string, mixed> $cond
     */
    private function compileCondition(array $cond, ?FilterScopeContext $scope = null): string
    {
        $attrRaw = $cond['attr'] ?? null;
        $opRaw = $cond['op'] ?? null;
        if (!\is_string($attrRaw) || !\is_string($opRaw)) {
            throw new RuntimeException('Condition attr/op must be strings.');
        }
        $attr = $attrRaw;
        $op = $opRaw;
        $value = $cond['value'] ?? null;

        if ('' === $attr || '' === $op) {
            throw new RuntimeException('Condition missing attr or op.');
        }

        // #2627 — `attributes_indexed` slots are ENVELOPES ({value}, {amount,
        // currency}, {option_code}, ...), so the SQL path must descend into
        // the typed key and cast numerics; the bare `->>'attr'` text lookup
        // compares the JSON body itself (numeric ops crash Postgres with
        // `text < integer`, text ops silently never match). Type comes from
        // the metadata resolver; without one (unit tests, unknown attr) the
        // legacy expression is kept so behavior degrades, never regresses.
        $type = $this->envelopeType($attr);
        $canonical = self::normaliseOperator($op);

        // #2673 — value context: scope dimensions are trimmed to the
        // attribute's capabilities (a non-localizable attribute ignores the
        // locale, a non-scopable one ignores the channel). COLUMN_MAP codes
        // and explicit dotted locale paths carry no meta and stay on the
        // legacy path — a dot-path is an explicit legacy locale pick and
        // wins over the panel scope.
        $scopedAttributeId = null;
        $scopedChannelId = null;
        $scopedLocale = null;
        if (null !== $scope && !$scope->isEmpty()) {
            $meta = (isset(self::COLUMN_MAP[$attr]) || str_contains($attr, '.'))
                ? null
                : $this->attributeMetadata?->getAttributeMeta($attr);
            if (null !== $meta) {
                $scopedChannelId = $meta->scopable ? $scope->channelId : null;
                $scopedLocale = $meta->localizable ? $scope->locale : null;
                if (null !== $scopedChannelId || null !== $scopedLocale) {
                    $scopedAttributeId = $meta->id;
                }
            }
        }

        if ('multiselect' === $type) {
            return FilterSqlExpressions::compileMultiselectCondition(
                $attr,
                $canonical,
                $value,
                $op,
                $scopedAttributeId,
                $scopedChannelId,
                $scopedLocale,
            );
        }

        $left = null !== $scopedAttributeId
            ? FilterSqlExpressions::scopedLeftExpression($attr, $type, $scopedAttributeId, $scopedChannelId, $scopedLocale)
            : $this->resolveLeftExpression($attr, $type);
        $numeric = \in_array($type, ['price', 'number', 'metric'], true);
        if ($numeric) {
            $left = '('.$left.')::numeric';
        } elseif ('boolean' === $type) {
            $left = '('.$left.')::boolean';
        }

        switch ($canonical) {
            case self::OP_IS_EMPTY:
                return $left.' IS NULL';

            case self::OP_IS_NOT_EMPTY:
                return $left.' IS NOT NULL';

            case self::OP_EQ:
                return $left.' = '.($numeric ? FilterSqlExpressions::scalarLiteral($value) : FilterSqlExpressions::literal($value));

            case self::OP_NEQ:
                return $left.' <> '.($numeric ? FilterSqlExpressions::scalarLiteral($value) : FilterSqlExpressions::literal($value));

            case self::OP_LT:
            case self::OP_BEFORE:
                return $left.' < '.FilterSqlExpressions::scalarLiteral($value);

            case self::OP_GT:
            case self::OP_AFTER:
                return $left.' > '.FilterSqlExpressions::scalarLiteral($value);

            case self::OP_LTE:
                return $left.' <= '.FilterSqlExpressions::scalarLiteral($value);

            case self::OP_GTE:
                return $left.' >= '.FilterSqlExpressions::scalarLiteral($value);

            case self::OP_IN:
                return $left.' IN ('.FilterSqlExpressions::literalList($value).')';

            case self::OP_NOT_IN:
                return $left.' NOT IN ('.FilterSqlExpressions::literalList($value).')';

            case self::OP_STARTS_WITH:
                return $left.' LIKE '.FilterSqlExpressions::likeLiteral($value, prefix: '', suffix: '%');

            case self::OP_ENDS_WITH:
                return $left.' LIKE '.FilterSqlExpressions::likeLiteral($value, prefix: '%', suffix: '');

            case self::OP_CONTAINS:
                return $left.' LIKE '.FilterSqlExpressions::likeLiteral($value, prefix: '%', suffix: '%');

            case self::OP_NOT_CONTAINS:
                return $left.' NOT LIKE '.FilterSqlExpressions::likeLiteral($value, prefix: '%', suffix: '%');

            case self::OP_BETWEEN:
                [$lo, $hi] = FilterSqlExpressions::rangePair($value);

                return $left.' BETWEEN '.FilterSqlExpressions::scalarLiteral($lo).' AND '.FilterSqlExpressions::scalarLiteral($hi);

            case self::OP_IS_TRUE:
                return $left.' = true';

            case self::OP_IS_FALSE:
                return $left.' = false';

            default:
                throw new RuntimeException('Operator not supported: '.$op);
        }
    }

    /**
     * VIEW-10 — compile DSL to a Meilisearch filter expression string.
     *
     * Meilisearch filter syntax (v1.5+): infix `=`, `!=`, `>`, `<`, `>=`,
     * `<=`, `IN [a, b]`, `NOT IN [a, b]`, `BETWEEN`, `EXISTS`, `IS EMPTY`,
     * `IS NULL`, `STARTS WITH`, `CONTAINS`. Lower-cased string literals
     * wrapped in single quotes. Combine with `AND`, `OR`, `NOT` and
     * parentheses.
     *
     * Differences vs the SQL path:
     *   - attribute paths reference the indexed document key directly
     *     (`brand`, `completenessPct`) rather than `co.attributes_indexed->>'brand'`;
     *   - locale-scoped keys flatten to dot-paths (`description.pl`);
     *   - `IS EMPTY` maps to `(NOT EXISTS field OR field IS NULL OR field = "")`.
     *
     * @param array<string, mixed> $dsl
     */
    public function toMeilisearchFilter(array $dsl): string
    {
        // #2673 — scoped values are not in the Meili document (#1148 keeps
        // it global-only); callers must branch to the SQL prefilter path
        // (`hasScope()` + `toCountSql()` → `id IN [...]`) before compiling.
        if (self::hasScope($dsl)) {
            throw new RuntimeException('FilterDsl with a value-context scope cannot compile to a Meilisearch filter — use the SQL prefilter path.');
        }

        return $this->compileMeili($dsl, depth: 0);
    }

    /**
     * @param array<string, mixed> $dsl
     */
    private function compileMeili(array $dsl, int $depth): string
    {
        if ($depth > 3) {
            throw new RuntimeException('FilterDsl nesting too deep (>3).');
        }

        if (isset($dsl['operator']) && isset($dsl['conditions'])) {
            /** @var array{operator: string, conditions: list<array<string, mixed>>} $group */
            $group = $dsl;
            $operator = strtoupper($group['operator']);
            if (!\in_array($operator, self::LOGICAL_OPS, true)) {
                throw new RuntimeException('Unsupported logical operator: '.$group['operator']);
            }

            $parts = [];
            foreach ($group['conditions'] as $condition) {
                $parts[] = '('.$this->compileMeili($condition, $depth + 1).')';
            }
            if ([] === $parts) {
                return '';
            }

            return implode(' '.$operator.' ', $parts);
        }

        return $this->compileMeiliCondition($dsl);
    }

    /**
     * @param array<string, mixed> $cond
     */
    private function compileMeiliCondition(array $cond): string
    {
        $attrRaw = $cond['attr'] ?? null;
        $opRaw = $cond['op'] ?? null;
        if (!\is_string($attrRaw) || !\is_string($opRaw)) {
            throw new RuntimeException('Condition attr/op must be strings.');
        }
        $attr = $this->meiliAttrPath($attrRaw);
        $canonical = self::normaliseOperator($opRaw);
        $value = $cond['value'] ?? null;

        switch ($canonical) {
            case self::OP_IS_EMPTY:
                return "(NOT $attr EXISTS OR $attr IS NULL OR $attr IS EMPTY)";

            case self::OP_IS_NOT_EMPTY:
                return "($attr EXISTS AND $attr IS NOT NULL AND $attr IS NOT EMPTY)";

            case self::OP_EQ:
                return "$attr = ".$this->meiliLiteral($value);

            case self::OP_NEQ:
                return "$attr != ".$this->meiliLiteral($value);

            case self::OP_LT:
            case self::OP_BEFORE:
                return "$attr < ".$this->meiliScalar($value);

            case self::OP_GT:
            case self::OP_AFTER:
                return "$attr > ".$this->meiliScalar($value);

            case self::OP_LTE:
                return "$attr <= ".$this->meiliScalar($value);

            case self::OP_GTE:
                return "$attr >= ".$this->meiliScalar($value);

            case self::OP_IN:
                return "$attr IN [".$this->meiliList($value).']';

            case self::OP_NOT_IN:
                return "$attr NOT IN [".$this->meiliList($value).']';

            case self::OP_STARTS_WITH:
                return "$attr STARTS WITH ".$this->meiliLiteral($value);

            case self::OP_ENDS_WITH:
                // Meilisearch lacks ENDS WITH — emulate via CONTAINS
                // (full-text); behaviour drifts vs SQL but covers the
                // common case of suffix lookup in admin search.
                return "$attr CONTAINS ".$this->meiliLiteral($value);

            case self::OP_CONTAINS:
                return "$attr CONTAINS ".$this->meiliLiteral($value);

            case self::OP_NOT_CONTAINS:
                return "NOT ($attr CONTAINS ".$this->meiliLiteral($value).')';

            case self::OP_BETWEEN:
                [$lo, $hi] = FilterSqlExpressions::rangePair($value);

                return "$attr ".$this->meiliScalar($lo).' TO '.$this->meiliScalar($hi);

            case self::OP_IS_TRUE:
                return "$attr = true";

            case self::OP_IS_FALSE:
                return "$attr = false";

            default:
                throw new RuntimeException('Operator not supported in Meilisearch compiler: '.$opRaw);
        }
    }

    private function meiliAttrPath(string $attr): string
    {
        // #2237 — `sku` is the UI/agent alias for the natural key; the
        // Meili document stores it as `code` (the physical column, mirroring
        // COLUMN_MAP `sku => co.code` on the SQL path). Without this the
        // agent's grounding filter `sku = "…"` hit a non-existent Meili field
        // and the search degraded — misread as a backend outage.
        if ('sku' === $attr) {
            return 'code';
        }

        if (str_contains($attr, '.')) {
            [$base, $locale] = explode('.', $attr, 2);
            FilterSqlExpressions::safeIdent($base);
            FilterSqlExpressions::safeIdent($locale);

            return $base.'.'.$locale;
        }

        return FilterSqlExpressions::safeIdent($attr);
    }

    private function meiliLiteral(mixed $value): string
    {
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_string($value)) {
            return MeiliFilterLiteral::quote($value);
        }
        throw new RuntimeException('Unsupported Meilisearch literal type.');
    }

    private function meiliScalar(mixed $value): string
    {
        if (\is_int($value) || \is_float($value)) {
            return (string) $value;
        }
        if (\is_string($value) && is_numeric($value)) {
            return $value;
        }
        if (\is_string($value)) {
            return $this->meiliLiteral($value);
        }
        throw new RuntimeException('Numeric or date literal required.');
    }

    private function meiliList(mixed $value): string
    {
        if (!\is_array($value) || [] === $value) {
            throw new RuntimeException('IN/NOT IN requires a non-empty array value.');
        }

        return implode(', ', array_map($this->meiliLiteral(...), $value));
    }

    /**
     * Attribute type used to pick the envelope key + cast, or null when the
     * attribute maps to a physical column, is a dotted locale path, or no
     * metadata resolver is wired (unit tests) — null keeps the legacy
     * expression.
     */
    private function envelopeType(string $attr): ?string
    {
        if (isset(self::COLUMN_MAP[$attr]) || str_contains($attr, '.')) {
            return null;
        }

        return $this->attributeMetadata?->getAttributeType($attr);
    }

    private function resolveLeftExpression(string $attr, ?string $type = null): string
    {
        if (isset(self::COLUMN_MAP[$attr])) {
            return self::COLUMN_MAP[$attr];
        }

        return FilterSqlExpressions::leftExpression($attr, $type);
    }

    /**
     * @param array<string, mixed> $group
     */
    private function validateGroup(array $group, int $depth): void
    {
        if ($depth > 3) {
            throw new BadRequestHttpException('FilterDsl nesting too deep (max 3 levels).');
        }
        $operatorRaw = $group['operator'] ?? null;
        if (!\is_string($operatorRaw) || !\in_array(strtoupper($operatorRaw), self::LOGICAL_OPS, true)) {
            throw new BadRequestHttpException('Group operator must be AND or OR.');
        }
        if (!isset($group['conditions']) || !\is_array($group['conditions'])) {
            throw new BadRequestHttpException('Group must contain a conditions array.');
        }
        if (\count($group['conditions']) > 20) {
            throw new BadRequestHttpException('A filter group cannot contain more than 20 conditions.');
        }

        foreach ($group['conditions'] as $cond) {
            if (!\is_array($cond)) {
                throw new BadRequestHttpException('Each condition must be an object.');
            }
            /** @var array<string, mixed> $cond */
            if (isset($cond['operator']) && isset($cond['conditions'])) {
                $this->validateGroup($cond, $depth + 1);
            } else {
                $this->validateCondition($cond);
            }
        }
    }

    /**
     * @param array<string, mixed> $cond
     */
    private function validateCondition(array $cond): void
    {
        $attr = $cond['attr'] ?? null;
        $op = $cond['op'] ?? null;

        if (!\is_string($attr) || '' === trim($attr)) {
            throw new BadRequestHttpException('Condition `attr` must be a non-empty string.');
        }
        if (!\is_string($op) || '' === trim($op)) {
            throw new BadRequestHttpException('Condition `op` must be a non-empty string.');
        }

        $canonical = self::normaliseOperator($op);
        $allKnownOps = array_unique(array_merge(...array_values(self::OPERATORS_BY_TYPE)));
        if (!\in_array($canonical, $allKnownOps, true)) {
            throw new BadRequestHttpException(\sprintf(
                'Operator "%s" not supported. Use one of: %s.',
                $op,
                implode(', ', $allKnownOps),
            ));
        }

        // Validate identifier safety upfront.
        try {
            $this->resolveLeftExpression($attr);
        } catch (RuntimeException $e) {
            throw new BadRequestHttpException($e->getMessage());
        }

        // VIEW-10 — type-narrow check (skipped when no metadata resolver wired).
        $this->validateOperatorForType($attr, $op);

        $emptyOps = [self::OP_IS_EMPTY, self::OP_IS_NOT_EMPTY, self::OP_IS_TRUE, self::OP_IS_FALSE];
        $listOps = [self::OP_IN, self::OP_NOT_IN];
        $rangeOps = [self::OP_BETWEEN];

        if (!\in_array($canonical, $emptyOps, true) && !\array_key_exists('value', $cond)) {
            throw new BadRequestHttpException(\sprintf('Operator "%s" requires a value.', $op));
        }
        if (\in_array($canonical, $listOps, true) && !\is_array($cond['value'] ?? null)) {
            throw new BadRequestHttpException(\sprintf('Operator "%s" requires an array value.', $op));
        }
        if (\in_array($canonical, $rangeOps, true)) {
            $val = $cond['value'] ?? null;
            if (!\is_array($val) || \count($val) !== 2) {
                throw new BadRequestHttpException(\sprintf('Operator "%s" requires a [low, high] tuple.', $op));
            }
        }
    }
}

<?php

declare(strict_types=1);

namespace App\Catalog\Application\Filter;

use JsonException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * VIEW-10 (#538) — bi-directional serializer between URL query params
 * and the JSONB Filter DSL.
 *
 * Two URL flavours are accepted on the read path:
 *   1. **Flat single-level**: `?filter[brand][op]==&filter[brand][value]=Festo`
 *      or the compressed shorthand `?brand=Festo&completeness_pct=lt:50`
 *      (when only a few core ops are used).
 *   2. **Base64 blob**: `?q=<base64-json>` — preserves the full DSL
 *      including 3-level groups (VIEW-09b fallback). Soft limit 4096
 *      chars; longer payloads throw 413.
 *
 * Output is canonicalised: every condition carries the lower-cased
 * operator (per {@see FilterDslResolver::normaliseOperator()}) and is
 * validated through the resolver. Bad shapes surface as 400 Problem
 * Details with the rejected operator named in the message.
 *
 * Shorthand operator codes (used in the compressed URL) map to canonical:
 *   eq, neq, lt, gt, lte, gte, in, notin, contains, ncontains, startsw,
 *   endsw, between, empty, nempty, true, false, after, before.
 */
final class FilterUrlSerializer
{
    public const int MAX_BLOB_BYTES = 4096;

    /**
     * @var array<string, string>
     */
    private const array SHORTHAND_OPS = [
        'eq' => FilterDslResolver::OP_EQ,
        'neq' => FilterDslResolver::OP_NEQ,
        'lt' => FilterDslResolver::OP_LT,
        'gt' => FilterDslResolver::OP_GT,
        'lte' => FilterDslResolver::OP_LTE,
        'gte' => FilterDslResolver::OP_GTE,
        'in' => FilterDslResolver::OP_IN,
        'notin' => FilterDslResolver::OP_NOT_IN,
        'contains' => FilterDslResolver::OP_CONTAINS,
        'ncontains' => FilterDslResolver::OP_NOT_CONTAINS,
        'startsw' => FilterDslResolver::OP_STARTS_WITH,
        'endsw' => FilterDslResolver::OP_ENDS_WITH,
        'between' => FilterDslResolver::OP_BETWEEN,
        'empty' => FilterDslResolver::OP_IS_EMPTY,
        'nempty' => FilterDslResolver::OP_IS_NOT_EMPTY,
        'true' => FilterDslResolver::OP_IS_TRUE,
        'false' => FilterDslResolver::OP_IS_FALSE,
        'after' => FilterDslResolver::OP_AFTER,
        'before' => FilterDslResolver::OP_BEFORE,
    ];

    public function __construct(
        private readonly FilterDslResolver $resolver,
    ) {
    }

    /**
     * Decode a base64-encoded JSON DSL.
     *
     * @return array<string, mixed>
     */
    public function fromBase64(string $blob): array
    {
        if (\strlen($blob) > self::MAX_BLOB_BYTES) {
            $e = new HttpException(413, 'Filter blob exceeds 4096 bytes.');
            // Some HttpException flavours don't propagate the status to
            // the constructed exception code; force it for callers
            // checking $exception->getStatusCode().
            throw $e;
        }
        $raw = base64_decode($blob, true);
        if (false === $raw) {
            throw new BadRequestHttpException('Filter blob is not valid base64.');
        }
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new BadRequestHttpException('Filter blob is not valid JSON: '.$e->getMessage());
        }
        if (!\is_array($decoded)) {
            throw new BadRequestHttpException('Filter blob must decode to a JSON object.');
        }
        /** @var array<string, mixed> $typed */
        $typed = $decoded;
        $this->resolver->validate($typed);

        return $typed;
    }

    /**
     * #2673 — decode WITHOUT validation, or `null` when the payload is not
     * base64 JSON at all. Lets callers with a legacy raw-text fallback
     * (SearchController `?q=`) distinguish "not a blob — treat as text"
     * from "a real DSL that failed validation" (which must 400 loudly, not
     * silently drop the filter).
     *
     * @return ?array<string, mixed>
     */
    public function tryDecode(string $blob): ?array
    {
        if ('' === $blob || \strlen($blob) > self::MAX_BLOB_BYTES) {
            return null;
        }
        $raw = base64_decode($blob, true);
        if (false === $raw) {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }
        if (!\is_array($decoded)) {
            return null;
        }
        /** @var array<string, mixed> $typed */
        $typed = $decoded;

        return $typed;
    }

    /**
     * Encode a DSL array as base64 JSON.
     *
     * @param array<string, mixed> $dsl
     */
    public function toBase64(array $dsl): string
    {
        $json = json_encode($dsl, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return base64_encode($json);
    }

    /**
     * Parse a flat `filter[attr][op]=value` URL params shape into a DSL.
     * `$params` is the array returned by `Request::query->all('filter')`.
     *
     * Each entry must be either:
     *   - `['op' => '...', 'value' => '...']` (canonical or shorthand op),
     *   - `'foo'` (single value, op defaults to `=`),
     *   - `'foo,bar'` (auto-promoted to `IN` if the attribute supports it
     *     — the resolver `validate` step will reject if not).
     *
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed> DSL (single condition or AND group)
     */
    public function fromUrlParams(array $params): array
    {
        $conditions = [];

        foreach ($params as $attr => $raw) {
            if ('' === $attr) {
                throw new BadRequestHttpException('Filter param attribute name must be a non-empty string.');
            }

            $conditions[] = $this->parseEntry($attr, $raw);
        }

        if ([] === $conditions) {
            return [];
        }
        if (1 === \count($conditions)) {
            $first = $conditions[0];
            $this->resolver->validate($first);

            return $first;
        }
        $dsl = ['operator' => 'AND', 'conditions' => $conditions];
        $this->resolver->validate($dsl);

        return $dsl;
    }

    /**
     * Serialize a flat (single-level) DSL to the canonical
     * `filter[attr][op]` URL shape. Nested groups fall back to the
     * caller — call {@see toBase64()} instead for those.
     *
     * @param array<string, mixed> $dsl
     *
     * @return array<string, array{op: string, value?: mixed}>
     */
    public function toUrlParams(array $dsl): array
    {
        $conditions = $this->flattenTopLevel($dsl);
        $out = [];
        foreach ($conditions as $cond) {
            $attrRaw = $cond['attr'] ?? '';
            $opRaw = $cond['op'] ?? '';
            if (!\is_string($attrRaw) || !\is_string($opRaw) || '' === $attrRaw || '' === $opRaw) {
                continue;
            }
            $entry = ['op' => $opRaw];
            if (\array_key_exists('value', $cond)) {
                $entry['value'] = $cond['value'];
            }
            $out[$attrRaw] = $entry;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $dsl
     *
     * @return list<array<string, mixed>>
     */
    private function flattenTopLevel(array $dsl): array
    {
        if (isset($dsl['operator']) && isset($dsl['conditions']) && \is_array($dsl['conditions'])) {
            $out = [];
            foreach ($dsl['conditions'] as $cond) {
                if (!\is_array($cond)) {
                    continue;
                }
                /** @var array<string, mixed> $typed */
                $typed = $cond;
                if (isset($typed['operator'])) {
                    // nested group → caller should use toBase64
                    throw new BadRequestHttpException('Cannot serialize nested groups to URL params; use base64 blob.');
                }
                $out[] = $typed;
            }

            return $out;
        }

        return [$dsl];
    }

    /**
     * @return array<string, mixed>
     */
    private function parseEntry(string $attr, mixed $raw): array
    {
        if (\is_array($raw)) {
            $opRaw = $raw['op'] ?? FilterDslResolver::OP_EQ;
            if (!\is_string($opRaw)) {
                throw new BadRequestHttpException(\sprintf('filter[%s][op] must be a string.', $attr));
            }
            $op = $this->expandShorthandOp($opRaw);
            $cond = ['attr' => $attr, 'op' => $op];
            if (\array_key_exists('value', $raw)) {
                $cond['value'] = $this->normaliseValue($raw['value'], $op);
            }

            return $cond;
        }

        if (\is_string($raw)) {
            if (str_contains($raw, ',')) {
                $values = array_filter(
                    array_map('trim', explode(',', $raw)),
                    static fn (string $v): bool => '' !== $v,
                );

                return ['attr' => $attr, 'op' => FilterDslResolver::OP_IN, 'value' => array_values($values)];
            }

            return ['attr' => $attr, 'op' => FilterDslResolver::OP_EQ, 'value' => $raw];
        }

        if (\is_scalar($raw)) {
            return ['attr' => $attr, 'op' => FilterDslResolver::OP_EQ, 'value' => $raw];
        }

        throw new BadRequestHttpException(\sprintf('Unsupported filter[%s] value shape.', $attr));
    }

    private function expandShorthandOp(string $op): string
    {
        $lower = strtolower(str_replace(' ', '', $op));
        if (isset(self::SHORTHAND_OPS[$lower])) {
            return self::SHORTHAND_OPS[$lower];
        }

        return FilterDslResolver::normaliseOperator($op);
    }

    private function normaliseValue(mixed $raw, string $canonicalOp): mixed
    {
        $listOps = [FilterDslResolver::OP_IN, FilterDslResolver::OP_NOT_IN];
        $rangeOps = [FilterDslResolver::OP_BETWEEN];

        if (\in_array($canonicalOp, $listOps, true)) {
            if (\is_array($raw)) {
                return array_values(array_filter($raw, static fn (mixed $v): bool => \is_scalar($v)));
            }
            if (\is_string($raw)) {
                return array_filter(
                    array_map('trim', explode(',', $raw)),
                    static fn (string $v): bool => '' !== $v,
                );
            }
        }

        if (\in_array($canonicalOp, $rangeOps, true)) {
            if (\is_array($raw)) {
                $list = array_values($raw);
                if (\count($list) === 2) {
                    return $list;
                }
                throw new BadRequestHttpException('between operator requires a [low, high] tuple.');
            }
            if (\is_string($raw) && str_contains($raw, ',')) {
                $parts = array_map('trim', explode(',', $raw, 2));
                if (\count($parts) === 2) {
                    return $parts;
                }
            }
            throw new BadRequestHttpException('between operator requires a [low, high] tuple.');
        }

        return $raw;
    }
}

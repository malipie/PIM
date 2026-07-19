<?php

declare(strict_types=1);

namespace App\Integration\Generic\Application\Sync;

use App\Integration\Generic\Domain\Entity\FieldMapping;

/**
 * Builds an outbound push body from serialized PIM values and the connection's
 * outbound field mappings (APIC-P3-06).
 *
 * Each outbound mapping writes its value into the body at the remote field's
 * dot path (`$.price.amount` → `{"price": {"amount": …}}`), so a 1:1 mapping
 * reconstructs the remote's nested shape. Inbound-only mappings are ignored.
 *
 * Values arrive serialized as strings (the Export cell serializer), but many
 * remote APIs are strictly typed — IdoSell rejects a quoted `"99.99"` where
 * `productRetailPrice` expects a number, and matches `productId` only against a
 * numeric id. {@see coerce()} therefore emits canonical integer/decimal literals
 * as JSON numbers; non-numeric codes and leading-zero strings stay strings.
 */
final readonly class PayloadBuilder
{
    /**
     * @param array<string, string> $values   attributeCode => serialized value
     * @param list<FieldMapping>    $mappings
     *
     * @return array<string, mixed>
     */
    public function build(array $values, array $mappings): array
    {
        $body = [];
        foreach ($mappings as $mapping) {
            if (!$mapping->getDirection()->appliesOutbound()) {
                continue;
            }
            if (!\array_key_exists($mapping->getPimTarget(), $values)) {
                continue;
            }
            self::setPath($body, $mapping->getRemoteFieldPath(), $values[$mapping->getPimTarget()]);
        }

        return $body;
    }

    /**
     * @param array<string, mixed> $body
     */
    private static function setPath(array &$body, string $path, string $value): void
    {
        $segments = self::segments($path);
        if ([] === $segments) {
            return;
        }

        $ref = &$body;
        $last = array_pop($segments);
        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !\is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref[$last] = self::coerce($value);
    }

    /**
     * Emit a JSON-native scalar so strictly-typed remotes accept the value.
     * Only canonical integer/decimal literals convert; a leading zero (`0012`),
     * a non-numeric code (`SEM-23`) or an over-long int (precision loss) stays a
     * string. Booleans are left to the transform-engine hook (§7).
     */
    private static function coerce(string $value): string|int|float
    {
        if (1 === preg_match('/^-?(?:0|[1-9]\d*)$/', $value)) {
            $asInt = (int) $value;

            // Reject values that do not round-trip (beyond PHP_INT_MAX).
            return (string) $asInt === $value ? $asInt : $value;
        }

        if (1 === preg_match('/^-?(?:0|[1-9]\d*)\.\d+$/', $value)) {
            return (float) $value;
        }

        return $value;
    }

    /**
     * @return list<string>
     */
    private static function segments(string $path): array
    {
        // #2642 — shared tokenizer: brackets (`prices['1374']`) build nested
        // objects instead of a literal key the remote would silently drop.
        return RemotePathSegments::parse($path);
    }
}

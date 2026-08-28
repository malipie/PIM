<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Service;

use App\Catalog\Contracts\AttributeType;

/**
 * UI-08.6 (#261) — compatibility matrix for `Attribute.type` migrations.
 *
 * Three states per (source, target) pair:
 *   - SAFE: migration runs without `force=true`. Lossless or with
 *     well-defined semantics (text → select via mapping plan,
 *     select → multiselect, etc.).
 *   - REQUIRES_FORCE: migration is destructive or potentially lossy
 *     (multiselect → select picks first; text → number drops parse
 *     failures). Caller must pass `force=true`.
 *   - BLOCKED: migration is structurally impossible for MVP and remains
 *     unavailable even with force (asset → number, relation → boolean —
 *     follow-up tickets may unlock these once the storage layer
 *     supports per-tenant migration scripts).
 *
 * The matrix is defined in code rather than a config file so PHPStan can
 * verify exhaustivity: every AttributeType case must appear as both a
 * source and a target row.
 */
enum MigrationCompatibility: string
{
    case Safe = 'safe';
    case RequiresForce = 'requires_force';
    case Blocked = 'blocked';
}

final class AttributeTypeMigrationCompatibility
{
    public function evaluate(AttributeType $from, AttributeType $to): MigrationCompatibility
    {
        if ($from === $to) {
            return MigrationCompatibility::Safe;
        }

        return match ([$from, $to]) {
            [AttributeType::Text, AttributeType::Select],
            [AttributeType::Text, AttributeType::Multiselect],
            [AttributeType::Select, AttributeType::Multiselect],
            [AttributeType::Select, AttributeType::Text],
            [AttributeType::Number, AttributeType::Text],
            [AttributeType::Boolean, AttributeType::Text],
            [AttributeType::Date, AttributeType::Text] => MigrationCompatibility::Safe,

            [AttributeType::Multiselect, AttributeType::Select],
            [AttributeType::Multiselect, AttributeType::Text],
            [AttributeType::Text, AttributeType::Number],
            [AttributeType::Text, AttributeType::Boolean],
            [AttributeType::Text, AttributeType::Date],
            [AttributeType::Number, AttributeType::Boolean],
            [AttributeType::Boolean, AttributeType::Number] => MigrationCompatibility::RequiresForce,

            // System types + non-scalar payloads (asset/relation/price/metric)
            // require dedicated migration paths beyond a JSONB rewrite — not
            // safe to expose in MVP.
            default => MigrationCompatibility::Blocked,
        };
    }
}

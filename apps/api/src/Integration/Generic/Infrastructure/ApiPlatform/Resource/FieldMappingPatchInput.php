<?php

declare(strict_types=1);

namespace App\Integration\Generic\Infrastructure\ApiPlatform\Resource;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * PATCH (RFC 7396 merge) input for `/api/field_mappings/{id}` (APIC-P2-08).
 * Every field is nullable — null means "leave unchanged". `connection` is
 * immutable and omitted. Any applied change bumps the mapping version.
 */
final class FieldMappingPatchInput
{
    #[Assert\Length(max: 255)]
    #[Groups(['field_mapping:patch'])]
    public ?string $pimTarget = null;

    #[Assert\Length(max: 512)]
    #[Groups(['field_mapping:patch'])]
    public ?string $remoteFieldPath = null;

    #[Assert\Choice(choices: ['inbound', 'outbound', 'both'])]
    #[Groups(['field_mapping:patch'])]
    public ?string $direction = null;

    #[Groups(['field_mapping:patch'])]
    public ?bool $isMatchKey = null;

    /**
     * Optional endpoint scope (#2634). Null = leave unchanged; empty string =
     * clear the scope (mapping applies to every endpoint again); UUID = scope
     * to that endpoint of the same connection.
     */
    #[Groups(['field_mapping:patch'])]
    public ?string $endpoint = null;
}

<?php

declare(strict_types=1);

namespace App\Integration\Generic\Infrastructure\ApiPlatform\Resource;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST input for `/api/field_mappings` (APIC-P2-08). `connection` is the parent
 * connection's UUID; the {@see \App\Integration\Generic\Infrastructure\ApiPlatform\State\FieldMappingProcessor}
 * resolves it tenant-scoped and stamps the mapping's tenant from it.
 */
final class FieldMappingInput
{
    #[Assert\NotBlank]
    #[Groups(['field_mapping:create'])]
    public string $connection = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    #[Groups(['field_mapping:create'])]
    public string $pimTarget = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 512)]
    #[Groups(['field_mapping:create'])]
    public string $remoteFieldPath = '';

    #[Assert\Choice(choices: ['inbound', 'outbound', 'both'])]
    #[Groups(['field_mapping:create'])]
    public string $direction = 'inbound';

    #[Groups(['field_mapping:create'])]
    public bool $isMatchKey = false;

    /**
     * Optional endpoint scope (#2634): UUID of a RemoteEndpoint belonging to the
     * same connection. Null = the mapping applies to every endpoint.
     */
    #[Groups(['field_mapping:create'])]
    public ?string $endpoint = null;
}

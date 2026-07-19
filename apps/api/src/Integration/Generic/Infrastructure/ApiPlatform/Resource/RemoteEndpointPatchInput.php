<?php

declare(strict_types=1);

namespace App\Integration\Generic\Infrastructure\ApiPlatform\Resource;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * PATCH (RFC 7396 merge) input for `/api/remote_endpoints/{id}` (APIC-P2-05).
 * Every field is nullable — null means "leave unchanged". `connection` is
 * immutable and omitted.
 */
final class RemoteEndpointPatchInput
{
    #[Assert\Choice(choices: ['read_list', 'read_one', 'write_create', 'write_update'])]
    #[Groups(['remote_endpoint:patch'])]
    public ?string $role = null;

    #[Assert\Choice(choices: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])]
    #[Groups(['remote_endpoint:patch'])]
    public ?string $httpMethod = null;

    #[Assert\Length(max: 2048)]
    #[Groups(['remote_endpoint:patch'])]
    public ?string $pathTemplate = null;

    /**
     * @var array<string, string>|null
     */
    #[Groups(['remote_endpoint:patch'])]
    public ?array $queryParams = null;

    /**
     * @var array<string, mixed>|null
     */
    #[Groups(['remote_endpoint:patch'])]
    public ?array $requestBodyTemplate = null;

    /**
     * @var array<string, mixed>|null
     */
    #[Groups(['remote_endpoint:patch'])]
    public ?array $pagination = null;

    #[Assert\Length(max: 512)]
    #[Groups(['remote_endpoint:patch'])]
    public ?string $recordSelector = null;

    #[Assert\Choice(choices: ['json', 'form'])]
    #[Groups(['remote_endpoint:patch'])]
    public ?string $requestFormat = null;

    #[Assert\Choice(choices: ['json'])]
    #[Groups(['remote_endpoint:patch'])]
    public ?string $responseFormat = null;
}

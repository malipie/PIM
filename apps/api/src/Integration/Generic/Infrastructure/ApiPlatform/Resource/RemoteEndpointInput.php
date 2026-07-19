<?php

declare(strict_types=1);

namespace App\Integration\Generic\Infrastructure\ApiPlatform\Resource;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST input for `/api/remote_endpoints` (APIC-P2-05). `connection` is the
 * parent connection's UUID; the {@see \App\Integration\Generic\Infrastructure\ApiPlatform\State\RemoteEndpointProcessor}
 * resolves it tenant-scoped and stamps the endpoint's tenant from it.
 */
final class RemoteEndpointInput
{
    #[Assert\NotBlank]
    #[Groups(['remote_endpoint:create'])]
    public string $connection = '';

    #[Assert\Choice(choices: ['read_list', 'read_one', 'write_create', 'write_update'])]
    #[Groups(['remote_endpoint:create'])]
    public string $role = 'read_list';

    #[Assert\Choice(choices: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'])]
    #[Groups(['remote_endpoint:create'])]
    public string $httpMethod = 'GET';

    #[Assert\NotBlank]
    #[Assert\Length(max: 2048)]
    #[Groups(['remote_endpoint:create'])]
    public string $pathTemplate = '';

    /**
     * @var array<string, string>
     */
    #[Groups(['remote_endpoint:create'])]
    public array $queryParams = [];

    /**
     * @var array<string, mixed>|null
     */
    #[Groups(['remote_endpoint:create'])]
    public ?array $requestBodyTemplate = null;

    /**
     * @var array<string, mixed>
     */
    #[Groups(['remote_endpoint:create'])]
    public array $pagination = ['strategy' => 'none'];

    #[Assert\Length(max: 512)]
    #[Groups(['remote_endpoint:create'])]
    public ?string $recordSelector = null;

    #[Assert\Choice(choices: ['json', 'form'])]
    #[Groups(['remote_endpoint:create'])]
    public string $requestFormat = 'json';

    #[Assert\Choice(choices: ['json'])]
    #[Groups(['remote_endpoint:create'])]
    public string $responseFormat = 'json';
}

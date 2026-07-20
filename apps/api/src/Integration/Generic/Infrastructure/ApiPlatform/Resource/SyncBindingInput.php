<?php

declare(strict_types=1);

namespace App\Integration\Generic\Infrastructure\ApiPlatform\Resource;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * POST input shape for `/api/sync_bindings` (APIC-P3-10). The tenant-stamped
 * {@see \App\Integration\Generic\Domain\Entity\SyncBinding} is built by
 * {@see \App\Integration\Generic\Infrastructure\ApiPlatform\State\SyncBindingProcessor}.
 *
 * `connection` and the read/write endpoints are UUID references resolved
 * tenant-scoped by the processor. `objectTypeId` is a loose reference to a
 * Catalog ObjectType (no FK, per ADR-0022 / APIC-P3-01): only its UUID shape is
 * validated here.
 */
final class SyncBindingInput
{
    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['sync_binding:create'])]
    public string $connection = '';

    #[Assert\NotBlank]
    #[Assert\Uuid]
    #[Groups(['sync_binding:create'])]
    public string $objectTypeId = '';

    #[Assert\Choice(choices: ['inbound', 'outbound', 'bidirectional'])]
    #[Groups(['sync_binding:create'])]
    public string $direction = 'inbound';

    #[Assert\Uuid]
    #[Groups(['sync_binding:create'])]
    public ?string $readEndpoint = null;

    #[Assert\Uuid]
    #[Groups(['sync_binding:create'])]
    public ?string $writeEndpoint = null;

    #[Assert\Length(max: 255)]
    #[Groups(['sync_binding:create'])]
    public ?string $schedule = null;

    #[Assert\Choice(choices: ['lww', 'pim_wins', 'remote_wins'])]
    #[Groups(['sync_binding:create'])]
    public string $conflictPolicy = 'lww';

    #[Assert\Length(max: 255)]
    #[Groups(['sync_binding:create'])]
    public ?string $matchKeyMapping = null;

    #[Groups(['sync_binding:create'])]
    public bool $enabled = true;

    /**
     * #2667 — outbound value-source scope: channel CODE whose values the push
     * reads (validated to resolve within the tenant). null/'' = global values.
     */
    #[Assert\Length(max: 64)]
    #[Groups(['sync_binding:create'])]
    public ?string $sourceChannel = null;

    /**
     * #2667 — outbound value-source scope: locale code (stored SHORT, e.g.
     * `en`; BCP-47 input is normalised). null/'' = global values.
     */
    #[Assert\Length(max: 16)]
    #[Groups(['sync_binding:create'])]
    public ?string $sourceLocale = null;

    /**
     * #2549 — FilterDsl snapshot scoping the OUTBOUND push (null = send all).
     * Applies only to the write flow; the FE builder emits a valid DSL and the
     * Export reader compiles it at run time.
     *
     * @var array<string, mixed>|null
     */
    #[Groups(['sync_binding:create'])]
    public ?array $outboundFilter = null;
}

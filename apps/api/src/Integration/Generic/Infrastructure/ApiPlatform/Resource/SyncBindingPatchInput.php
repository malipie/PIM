<?php

declare(strict_types=1);

namespace App\Integration\Generic\Infrastructure\ApiPlatform\Resource;

use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * PATCH (RFC 7396 merge) input for `/api/sync_bindings/{id}` (APIC-P3-10). Every
 * field is nullable — null means "leave unchanged". The parent `connection` is
 * immutable and omitted. Re-enabling or changing the schedule recomputes the
 * next run in the processor.
 */
final class SyncBindingPatchInput
{
    #[Assert\Uuid]
    #[Groups(['sync_binding:patch'])]
    public ?string $objectTypeId = null;

    #[Assert\Choice(choices: ['inbound', 'outbound', 'bidirectional'])]
    #[Groups(['sync_binding:patch'])]
    public ?string $direction = null;

    #[Assert\Uuid]
    #[Groups(['sync_binding:patch'])]
    public ?string $readEndpoint = null;

    #[Assert\Uuid]
    #[Groups(['sync_binding:patch'])]
    public ?string $writeEndpoint = null;

    #[Assert\Length(max: 255)]
    #[Groups(['sync_binding:patch'])]
    public ?string $schedule = null;

    #[Assert\Choice(choices: ['lww', 'pim_wins', 'remote_wins'])]
    #[Groups(['sync_binding:patch'])]
    public ?string $conflictPolicy = null;

    #[Assert\Length(max: 255)]
    #[Groups(['sync_binding:patch'])]
    public ?string $matchKeyMapping = null;

    #[Groups(['sync_binding:patch'])]
    public ?bool $enabled = null;

    /**
     * #2549 — FilterDsl snapshot scoping the OUTBOUND push. Present-and-empty
     * (`[]`) clears the scope (send all); absent = leave unchanged.
     *
     * @var array<string, mixed>|null
     */
    #[Groups(['sync_binding:patch'])]
    public ?array $outboundFilter = null;
}

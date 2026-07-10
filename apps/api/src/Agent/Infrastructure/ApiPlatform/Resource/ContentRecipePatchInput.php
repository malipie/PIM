<?php

declare(strict_types=1);

namespace App\Agent\Infrastructure\ApiPlatform\Resource;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * AICG-P1-03 (#2329) — PATCH /api/content-recipes/{id} payload (RFC 7396
 * merge-patch): every field optional, null/absent = keep current.
 * `code` is immutable after create (recipes are referenced by id from
 * runs and provenance_meta; renames go through clone).
 */
final class ContentRecipePatchInput
{
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    #[Assert\Length(max: 128)]
    public ?string $targetAttribute = null;

    /** @var list<string>|null */
    #[Assert\All([new Assert\Type('string'), new Assert\NotBlank()])]
    public ?array $sourceAttributes = null;

    /** @var array<string, mixed>|null */
    public ?array $constraints = null;

    #[Assert\Uuid]
    public ?string $objectTypeId = null;

    /** @var array<string, mixed>|null */
    public ?array $appliesTo = null;

    #[Assert\Length(max: 255)]
    public ?string $toneHint = null;

    #[Assert\Uuid]
    public ?string $brandVoiceId = null;
}

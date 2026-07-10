<?php

declare(strict_types=1);

namespace App\Agent\Infrastructure\ApiPlatform\Resource;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * AICG-P1-03 (#2329) — PATCH /api/brand-voice-profiles/{id} payload
 * (RFC 7396): every field optional. `isDefault: true` swaps the tenant
 * default (previous default is cleared in the same transaction);
 * `isDefault: false` clears the flag on this profile.
 */
final class BrandVoiceProfilePatchInput
{
    #[Assert\Length(max: 255)]
    public ?string $name = null;

    public ?string $tone = null;

    /** @var list<array<string, string>>|null */
    public ?array $glossary = null;

    /** @var list<string>|null */
    #[Assert\All([new Assert\Type('string'), new Assert\NotBlank()])]
    public ?array $bannedWords = null;

    /** @var list<array<string, string>>|null */
    public ?array $examples = null;

    public ?bool $isDefault = null;
}

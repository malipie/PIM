<?php

declare(strict_types=1);

namespace App\Agent\Infrastructure\ApiPlatform\Resource;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * AICG-P1-03 (#2329) — POST /api/brand-voice-profiles payload. The
 * {term, use} / {good, bad} pair shapes are guarded by the aggregate
 * and surface as 422 through the processor.
 */
final class BrandVoiceProfileInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $name = '';

    #[Assert\NotBlank]
    public string $tone = '';

    /** @var list<array<string, string>> */
    public array $glossary = [];

    /** @var list<string> */
    #[Assert\All([new Assert\Type('string'), new Assert\NotBlank()])]
    public array $bannedWords = [];

    /** @var list<array<string, string>> */
    public array $examples = [];

    public bool $isDefault = false;
}

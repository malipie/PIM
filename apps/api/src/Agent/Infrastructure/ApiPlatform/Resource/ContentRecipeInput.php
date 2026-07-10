<?php

declare(strict_types=1);

namespace App\Agent\Infrastructure\ApiPlatform\Resource;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * AICG-P1-03 (#2329) — POST /api/content-recipes payload. Structural
 * validation only; the semantic shape of `constraints` (format enum,
 * max_len, seo slots) is guarded by the ContentRecipe aggregate and
 * surfaces as 422 through the processor.
 */
final class ContentRecipeInput
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    #[Assert\Regex(pattern: '/^[a-z0-9_]+$/', message: 'Code may contain only lowercase letters, digits and underscores.')]
    public string $code = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 255)]
    public string $name = '';

    #[Assert\NotBlank]
    #[Assert\Length(max: 128)]
    public string $targetAttribute = '';

    /** @var list<string> */
    #[Assert\All([new Assert\Type('string'), new Assert\NotBlank()])]
    public array $sourceAttributes = [];

    /** @var array<string, mixed> */
    public array $constraints = [];

    #[Assert\Uuid]
    public ?string $objectTypeId = null;

    /** @var array<string, mixed> */
    public array $appliesTo = [];

    #[Assert\Length(max: 255)]
    public ?string $toneHint = null;

    #[Assert\Uuid]
    public ?string $brandVoiceId = null;
}

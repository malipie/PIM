<?php

declare(strict_types=1);

namespace App\Export\Feed\Domain\Descriptor;

/**
 * Per-slot validation rules (ADR-0023 §6.5, XMLF-P2-01). Immutable value
 * object consumed by the generator's feed-health check (XMLF-P2-04) and the
 * mapper coverage/type hints (XMLF-P3-01).
 */
final class SlotValidationRule
{
    /**
     * @param list<string> $requiredOneOf slot targets in this "at least one of" group
     * @param list<string> $enums         allowed values when $format is Enum
     */
    public function __construct(
        public readonly bool $required = false,
        public readonly array $requiredOneOf = [],
        public readonly ?int $maxLength = null,
        public readonly HtmlPolicy $html = HtmlPolicy::Escape,
        public readonly SlotFormat $format = SlotFormat::Text,
        public readonly array $enums = [],
    ) {
    }

    /**
     * @param array<mixed, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $requiredOneOf = [];
        if (isset($data['requiredOneOf']) && \is_array($data['requiredOneOf'])) {
            foreach ($data['requiredOneOf'] as $target) {
                if (\is_string($target)) {
                    $requiredOneOf[] = $target;
                }
            }
        }

        $enums = [];
        if (isset($data['enums']) && \is_array($data['enums'])) {
            foreach ($data['enums'] as $value) {
                if (\is_string($value)) {
                    $enums[] = $value;
                }
            }
        }

        $format = SlotFormat::tryFrom(\is_string($data['fmt'] ?? null) ? $data['fmt'] : 'text') ?? SlotFormat::Text;
        $html = HtmlPolicy::tryFrom(\is_string($data['html'] ?? null) ? $data['html'] : 'escape') ?? HtmlPolicy::Escape;
        $maxLength = isset($data['maxLength']) && \is_int($data['maxLength']) ? $data['maxLength'] : null;

        return new self(
            required: true === ($data['required'] ?? false),
            requiredOneOf: $requiredOneOf,
            maxLength: $maxLength,
            html: $html,
            format: $format,
            enums: $enums,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = ['fmt' => $this->format->value];
        if ($this->required) {
            $out['required'] = true;
        }
        if ([] !== $this->requiredOneOf) {
            $out['requiredOneOf'] = $this->requiredOneOf;
        }
        if (null !== $this->maxLength) {
            $out['maxLength'] = $this->maxLength;
        }
        if (HtmlPolicy::Escape !== $this->html) {
            $out['html'] = $this->html->value;
        }
        if ([] !== $this->enums) {
            $out['enums'] = $this->enums;
        }

        return $out;
    }
}

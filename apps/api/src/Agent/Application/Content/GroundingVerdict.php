<?php

declare(strict_types=1);

namespace App\Agent\Application\Content;

/**
 * AICG-P2-02 (#2332, ADR-0030) — the completeness gate's structured
 * verdict. Deliberately NOT an exception: the executor treats refusals
 * as tool_results the model can read and relay ("too little data to
 * write this copy — fill material/size first"), same semantics as the
 * forbidden-attribute results of GuardedToolExecutor.
 */
final readonly class GroundingVerdict
{
    public const string SUFFICIENT = 'sufficient';
    public const string INSUFFICIENT = 'insufficient_grounding';

    /**
     * @param list<string> $missingCodes
     */
    private function __construct(
        private string $status,
        private array $missingCodes,
        private int $presentFacts,
    ) {
    }

    /**
     * @param list<string> $missingCodes
     */
    public static function sufficient(array $missingCodes, int $presentFacts): self
    {
        return new self(self::SUFFICIENT, $missingCodes, $presentFacts);
    }

    /**
     * @param list<string> $missingCodes
     */
    public static function insufficient(array $missingCodes, int $presentFacts): self
    {
        return new self(self::INSUFFICIENT, $missingCodes, $presentFacts);
    }

    public function isSufficient(): bool
    {
        return self::SUFFICIENT === $this->status;
    }

    public function status(): string
    {
        return $this->status;
    }

    /**
     * @return list<string>
     */
    public function missingCodes(): array
    {
        return $this->missingCodes;
    }

    /**
     * @return array{status: string, missing_source_attributes: list<string>, present_facts: int}
     */
    public function toToolResult(): array
    {
        return [
            'status' => $this->status,
            'missing_source_attributes' => $this->missingCodes,
            'present_facts' => $this->presentFacts,
        ];
    }
}

<?php

declare(strict_types=1);

namespace App\Catalog\Contracts\Command;

/**
 * AICG-P3-01 (#2334, ADR-0030) — outcome of materializing one generated
 * text value as a pending diff. Refusals are data, not exceptions: the
 * content tools relay them as tool_results (the GuardedToolExecutor
 * "forbidden" semantics), so the model can tell the operator what
 * happened instead of crashing the run.
 */
final readonly class ContentValueProposal
{
    public const string MATERIALIZED = 'materialized';
    public const string FORBIDDEN = 'forbidden';
    public const string INVALID = 'invalid';

    /**
     * @param array<string, mixed>|null $before value envelope of the exact
     *                                          scope before the proposal
     * @param array<string, mixed>|null $after  proposed value envelope
     */
    private function __construct(
        public string $status,
        public ?string $message,
        public ?array $before,
        public ?array $after,
        public ?string $scopeLocale,
        public ?string $scopeChannel,
    ) {
    }

    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>      $after
     */
    public static function materialized(?array $before, array $after, ?string $scopeLocale, ?string $scopeChannel): self
    {
        return new self(self::MATERIALIZED, null, $before, $after, $scopeLocale, $scopeChannel);
    }

    public static function forbidden(string $message): self
    {
        return new self(self::FORBIDDEN, $message, null, null, null, null);
    }

    public static function invalid(string $message): self
    {
        return new self(self::INVALID, $message, null, null, null, null);
    }

    public function isMaterialized(): bool
    {
        return self::MATERIALIZED === $this->status;
    }

    /**
     * @return array<string, mixed>
     */
    public function toToolResult(): array
    {
        $payload = ['status' => $this->status];
        if (null !== $this->message) {
            $payload['message'] = $this->message;
        }
        if (self::MATERIALIZED === $this->status) {
            $payload['before'] = $this->before;
            $payload['after'] = $this->after;
            $payload['locale'] = $this->scopeLocale;
            $payload['channel'] = $this->scopeChannel;
        }

        return $payload;
    }
}

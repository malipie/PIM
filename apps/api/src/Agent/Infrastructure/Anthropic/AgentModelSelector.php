<?php

declare(strict_types=1);

namespace App\Agent\Infrastructure\Anthropic;

/**
 * AGENT-P0-06 (#1949) — deterministic model choice per tool kind
 * (ADR-0024 b, PRD 10.1): schema-ops carry a bigger blast radius and
 * run on the Opus-tier model; everything else (read/write/action) runs
 * on the Sonnet-tier default.
 *
 * Model ids live in configuration (AGENT_MODEL_DEFAULT /
 * AGENT_MODEL_SCHEMA env vars), not hardcoded across call sites —
 * a model bump is a config change.
 */
final readonly class AgentModelSelector
{
    public const string KIND_SCHEMA = 'schema';

    public function __construct(
        private string $defaultModel,
        private string $schemaModel,
    ) {
    }

    public function modelForKind(string $kind): string
    {
        return self::KIND_SCHEMA === $kind ? $this->schemaModel : $this->defaultModel;
    }

    public function defaultModel(): string
    {
        return $this->defaultModel;
    }

    public function schemaModel(): string
    {
        return $this->schemaModel;
    }
}

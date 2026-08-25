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

    /**
     * Route the expensive schema tier from the requested operation, not from
     * the user's permission surface. A modeling-capable user asking an
     * ordinary catalog question should stay on the fast default model.
     */
    public function modelForIntent(string $intent): string
    {
        $normalized = mb_strtolower($intent);
        // "change attribute price to 100" mutates an object's value, not the
        // attribute definition. Keep this frequent phrasing on the fast tier.
        if (1 === preg_match('/(?:zmie[nń]|ustaw|change|set|update)\s+(?:warto[sś][cć]\s+)?(?:atrybut|attribute)\s+\S+\s+(?:na|to|=)\s+/u', $normalized)) {
            return $this->defaultModel;
        }

        $schemaVerb = '(?:dodaj|stw[oó]rz|utw[oó]rz|zmie[nń]|edytuj|przemianuj|usu[nń]|skasuj|create|add|update|edit|rename|delete|remove)';
        $schemaNoun = '(?:atrybut|attribute|grup(?:a|ę|y)\s+atrybut|attribute\s+group|typ(?:u|y)?\s+obiektu|object\s+type|schemat|schema)';

        $isSchemaIntent = 1 === preg_match('/'.$schemaVerb.'.{0,60}'.$schemaNoun.'|'.$schemaNoun.'.{0,60}'.$schemaVerb.'/u', $normalized);

        return $isSchemaIntent ? $this->schemaModel : $this->defaultModel;
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

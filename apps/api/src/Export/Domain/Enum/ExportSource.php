<?php

declare(strict_types=1);

namespace App\Export\Domain\Enum;

/**
 * Where the export was triggered from (PRD §5.1).
 *
 * `list_context` — modal opened from the products list toolbar
 *   (Magda/Kasia primary path).
 * `central_tab` — full-page form `/integrations/exports/new`
 *   (Marcin snapshot path).
 * `saved_profile_run` — `POST /api/exports/profiles/{id}/run`
 *   one-click rerun.
 * `agent` — triggered by the agent's trigger_export tool
 *   (AGENT-P3-06); same pipeline, distinct provenance for telemetry.
 */
enum ExportSource: string
{
    case ListContext = 'list_context';
    case CentralTab = 'central_tab';
    case SavedProfileRun = 'saved_profile_run';
    case Agent = 'agent';
}

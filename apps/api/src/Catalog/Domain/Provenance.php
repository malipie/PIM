<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

/**
 * Where a single ObjectValue came from.
 *
 * Per CLAUDE.md "Architecture rules" point 5 — every value carries
 * provenance metadata so admins can answer "who/what set this field?"
 *
 *   - `Manual`     — admin set it via the admin UI form.
 *   - `Import`     — a bulk import job (CSV / XLSX) wrote it.
 *   - `Integration`— an upstream system pushed it via an integration
 *                    adapter (Generic connector / BaseLinker / Shopify).
 *   - `Agent`      — the agent layer committed it after human approval
 *                    (epic 0.7, ADR-0024; AGENT-P0-04 #1947). Writes go
 *                    through the same bulk-path as manual edits, but only
 *                    after a pending_changes batch is accepted.
 *
 * `provenance_meta JSONB` on ObjectValue carries free-form context
 * (importer job id, integration profile, agent run id/model/intent —
 * see docs/api/jsonb-schemas.md section 5) — this enum is the coarse
 * classifier used in filters and provenance badges in the UI
 * (epic 0.6 #61).
 */
enum Provenance: string
{
    case Manual = 'manual';
    case Import = 'import';
    case Integration = 'integration';
    case Agent = 'agent';
}

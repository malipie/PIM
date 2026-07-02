<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog;

use App\Catalog\Domain\Provenance;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ProvenanceTest extends TestCase
{
    #[Test]
    public function fourCasesAreDefinedExactly(): void
    {
        // AGENT-P0-04 (#1947) — epic 0.7 adds the `agent` case alongside
        // the pending_changes approval gate (ADR-0024). Guard against
        // further drift: a fifth source needs a conscious decision.
        self::assertCount(4, Provenance::cases());
    }

    #[Test]
    public function backingValuesRoundTrip(): void
    {
        foreach (Provenance::cases() as $case) {
            self::assertSame(strtolower($case->value), $case->value);
            self::assertSame($case, Provenance::from($case->value));
        }
    }

    #[Test]
    public function agentCaseIsPresent(): void
    {
        // The agent commits values only after a human accepts the
        // pending_changes batch — the enum case is the substrate for
        // provenance=agent badges (P6-05) and agent bulk-path writes
        // (P3-01/P3-02).
        self::assertSame(Provenance::Agent, Provenance::from('agent'));
        self::assertSame('agent', Provenance::Agent->value);
    }
}

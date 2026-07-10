<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent\Application;

use App\Agent\Application\Content\ContentGrounding;
use App\Agent\Application\Content\GroundingGate;
use App\Agent\Application\Content\GroundingVerdict;
use App\Agent\Domain\Entity\ContentRecipe;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AICG-P2-02 (#2332, SEC, ADR-0030) — the anti-hallucination fuse:
 * "generate only when there is something to generate FROM" (plan §3a
 * pkt 5, risk R2). Too few facts → a structured insufficient_grounding
 * verdict the tool returns as tool_result — never an exception, never
 * an attempt to generate.
 *
 * Written failing-first (SEC): these tests predate the gate
 * implementation in the commit history.
 */
final class GroundingGateTest extends TestCase
{
    #[Test]
    public function productWithNoFactsIsInsufficient(): void
    {
        $verdict = new GroundingGate()->evaluate(
            $this->grounding(facts: [], missing: ['material', 'color']),
            $this->recipe(['material', 'color']),
        );

        self::assertFalse($verdict->isSufficient());
        self::assertSame(GroundingVerdict::INSUFFICIENT, $verdict->status());
        self::assertSame(['material', 'color'], $verdict->missingCodes());
    }

    #[Test]
    public function productWithAllFactsIsSufficient(): void
    {
        $verdict = new GroundingGate()->evaluate(
            $this->grounding(facts: ['material' => ['value' => 'alu'], 'color' => ['option_code' => 'red']], missing: []),
            $this->recipe(['material', 'color']),
        );

        self::assertTrue($verdict->isSufficient());
        self::assertSame([], $verdict->missingCodes());
    }

    #[Test]
    public function defaultThresholdIsAtLeastOneFact(): void
    {
        $verdict = new GroundingGate()->evaluate(
            $this->grounding(facts: ['material' => ['value' => 'alu']], missing: ['color', 'size']),
            $this->recipe(['material', 'color', 'size']),
        );

        self::assertTrue($verdict->isSufficient(), 'one hard fact beats an empty prompt — default min_facts = 1');
        self::assertSame(['color', 'size'], $verdict->missingCodes());
    }

    #[Test]
    public function recipeMayRaiseTheMinimumFactCount(): void
    {
        $recipe = $this->recipe(['material', 'color', 'size'], grounding: ['min_facts' => 2]);

        $insufficient = new GroundingGate()->evaluate(
            $this->grounding(facts: ['material' => ['value' => 'alu']], missing: ['color', 'size']),
            $recipe,
        );
        $sufficient = new GroundingGate()->evaluate(
            $this->grounding(
                facts: ['material' => ['value' => 'alu'], 'color' => ['option_code' => 'red']],
                missing: ['size'],
            ),
            $recipe,
        );

        self::assertFalse($insufficient->isSufficient());
        self::assertTrue($sufficient->isSufficient());
    }

    #[Test]
    public function recipeRequiredCodesMustAllBePresent(): void
    {
        $recipe = $this->recipe(['material', 'color', 'size'], grounding: ['required' => ['material', 'size']]);

        $verdict = new GroundingGate()->evaluate(
            $this->grounding(
                facts: ['material' => ['value' => 'alu'], 'color' => ['option_code' => 'red']],
                missing: ['size'],
            ),
            $recipe,
        );

        self::assertFalse($verdict->isSufficient(), 'a missing REQUIRED fact blocks generation even above min_facts');
        self::assertContains('size', $verdict->missingCodes());
    }

    #[Test]
    public function verdictIsAStructuredToolResultNotAnException(): void
    {
        $verdict = new GroundingGate()->evaluate(
            $this->grounding(facts: [], missing: ['material']),
            $this->recipe(['material']),
        );

        $payload = $verdict->toToolResult();
        self::assertSame('insufficient_grounding', $payload['status']);
        self::assertSame(['material'], $payload['missing_source_attributes']);
        self::assertArrayHasKey('present_facts', $payload);
        self::assertSame(0, $payload['present_facts']);
    }

    /**
     * @param array<string, array<string, mixed>> $facts
     * @param list<string>                        $missing
     */
    private function grounding(array $facts, array $missing): ContentGrounding
    {
        return new ContentGrounding(
            facts: $facts,
            usedCodes: array_keys($facts),
            missingCodes: $missing,
        );
    }

    /**
     * @param list<string>         $sourceAttributes
     * @param array<string, mixed> $grounding
     */
    private function recipe(array $sourceAttributes, array $grounding = []): ContentRecipe
    {
        $constraints = ['format' => ContentRecipe::FORMAT_PLAIN];
        if ([] !== $grounding) {
            $constraints['grounding'] = $grounding;
        }

        return new ContentRecipe(
            code: 'gate_recipe',
            name: 'Gate',
            targetAttribute: 'description',
            sourceAttributes: $sourceAttributes,
            constraints: $constraints,
        );
    }
}

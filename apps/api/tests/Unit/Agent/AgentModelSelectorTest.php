<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Infrastructure\Anthropic\AgentModelSelector;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class AgentModelSelectorTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function schemaIntents(): iterable
    {
        yield 'Polish attribute' => ['dodaj atrybut EAN'];
        yield 'Polish object type inflection' => ['stwórz nowy typ obiektu Porady'];
        yield 'English schema' => ['rename the attribute group Technical'];
    }

    #[Test]
    #[DataProvider('schemaIntents')]
    public function selectsSchemaTierOnlyForAnExplicitSchemaMutation(string $intent): void
    {
        self::assertSame('schema', $this->selector()->modelForIntent($intent));
    }

    #[Test]
    public function ordinaryValueMutationStaysOnTheFastTier(): void
    {
        self::assertSame('fast', $this->selector()->modelForIntent('zmień atrybut cena na 100'));
        self::assertSame('fast', $this->selector()->modelForIntent('pokaż brakujące opisy produktów'));
    }

    private function selector(): AgentModelSelector
    {
        return new AgentModelSelector('fast', 'schema');
    }
}

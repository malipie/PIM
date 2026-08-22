<?php

declare(strict_types=1);

namespace App\Tests\Unit\Agent;

use App\Agent\Application\Tool\AgentToolContext;
use App\Agent\Application\Tool\ListAttributesTool;
use App\Agent\Application\Tool\ToolKind;
use App\Catalog\Contracts\Query\AttributeSummary;
use App\Catalog\Contracts\Service\AttributeCatalogReader;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

use const PHP_INT_MAX;

/**
 * #2946 — the agent could not find an attribute the operator named in prose.
 * Asked to "change płeć to Dla dziewczyn" it answered that it has no tool for
 * listing attributes and asked the operator to fetch the code by hand.
 */
final class ListAttributesToolTest extends TestCase
{
    #[Test]
    public function findsAnAttributeByItsPolishLabelNotOnlyByCode(): void
    {
        $tool = new ListAttributesTool($this->reader());

        $result = $tool->execute(['query' => 'płeć'], $this->context());

        $attributes = self::attributesOf($result);
        self::assertCount(1, $attributes);
        self::assertIsArray($attributes[0]);
        self::assertSame('gender', $attributes[0]['code'] ?? null);
        self::assertSame(ToolKind::Read, $tool->kind());
        self::assertSame('object.read', $tool->requiredPermission());
    }

    #[Test]
    public function returnsOptionCodesSoAProseOptionCanBeTranslated(): void
    {
        // The other half of the same problem: the operator says "Dla
        // dziewczyn", the write accepts `female`.
        $tool = new ListAttributesTool($this->reader());

        $result = $tool->execute(['query' => 'gender'], $this->context());

        $first = self::attributesOf($result)[0];
        self::assertIsArray($first);
        $options = $first['options'] ?? null;
        self::assertIsArray($options);
        self::assertIsArray($options[0]);
        self::assertSame('female', $options[0]['code'] ?? null);
        $label = $options[0]['label'] ?? null;
        self::assertIsArray($label);
        self::assertSame('Dla dziewczyn', $label['pl'] ?? null);
    }

    #[Test]
    public function matchIsCaseInsensitiveAndPartial(): void
    {
        $tool = new ListAttributesTool($this->reader());

        self::assertCount(1, self::attributesOf($tool->execute(['query' => 'PŁE'], $this->context())));
        self::assertCount(1, self::attributesOf($tool->execute(['query' => 'GENDer'], $this->context())));
    }

    #[Test]
    public function omittingTheQueryListsEverythingAndOptionsCanBeSuppressed(): void
    {
        $tool = new ListAttributesTool($this->reader());

        $all = $tool->execute([], $this->context());
        self::assertSame(2, $all['total_returned']);
        self::assertNull($all['query']);

        $lean = self::attributesOf($tool->execute(['with_options' => false], $this->context()));
        self::assertIsArray($lean[0]);
        self::assertArrayNotHasKey('options', $lean[0]);
    }

    #[Test]
    public function nonOptionAttributesCarryNoOptionsKey(): void
    {
        $tool = new ListAttributesTool($this->reader());

        $attributes = self::attributesOf($tool->execute(['query' => 'nazwa'], $this->context()));
        self::assertIsArray($attributes[0]);
        self::assertSame('name', $attributes[0]['code'] ?? null);
        self::assertArrayNotHasKey('options', $attributes[0]);
    }

    /**
     * @param array<string, mixed> $result
     *
     * @return array<int, mixed>
     */
    private static function attributesOf(array $result): array
    {
        $attributes = $result['attributes'] ?? null;
        self::assertIsArray($attributes);

        return array_values($attributes);
    }

    private function reader(): AttributeCatalogReader
    {
        return new InMemoryAttributeCatalogReader();
    }

    private function context(): AgentToolContext
    {
        return new AgentToolContext(Uuid::v7(), new Tenant('alpha', 'Alpha'));
    }
}

/**
 * @internal
 */
final class InMemoryAttributeCatalogReader implements AttributeCatalogReader
{
    private Uuid $genderId;

    public function __construct()
    {
        $this->genderId = Uuid::v7();
    }

    public function findAllByTenant(Uuid $tenantId): array
    {
        return [
            new AttributeSummary(
                id: $this->genderId,
                tenantId: $tenantId,
                code: 'gender',
                label: ['pl' => 'Płeć', 'en' => 'Gender'],
                type: 'select',
                isLocalizable: false,
                isRequired: false,
                isSystem: false,
                groupId: null,
                groupCode: 'basic',
                groupLabel: [],
                groupPosition: PHP_INT_MAX,
            ),
            new AttributeSummary(
                id: Uuid::v7(),
                tenantId: $tenantId,
                code: 'name',
                label: ['pl' => 'Nazwa', 'en' => 'Name'],
                type: 'text',
                isLocalizable: true,
                isRequired: false,
                isSystem: false,
                groupId: null,
                groupCode: 'basic',
                groupLabel: [],
                groupPosition: PHP_INT_MAX,
            ),
        ];
    }

    public function findOnTenant(Uuid $attributeId, Uuid $tenantId): ?AttributeSummary
    {
        return null;
    }

    public function optionsFor(Uuid $attributeId, Uuid $tenantId): array
    {
        if ($attributeId->toRfc4122() !== $this->genderId->toRfc4122()) {
            return [];
        }

        return [
            ['code' => 'female', 'label' => ['pl' => 'Dla dziewczyn', 'en' => 'For girls']],
            ['code' => 'male', 'label' => ['pl' => 'Dla chłopców', 'en' => 'For boys']],
        ];
    }
}

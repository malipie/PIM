<?php

declare(strict_types=1);

namespace App\Tests\Unit\Catalog\Application;

use App\Catalog\Application\AttributesIndexedRebuilder;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Provenance;
use App\Catalog\Domain\Service\EffectiveAttributeGroupResolver;
use App\Channel\Contracts\LocaleFallbackResolverInterface;
use App\Channel\Contracts\ScopeEnumeratorInterface;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * AICG-P0-02 (#2326, ADR-0030) — `provenance_meta` extended with the
 * optional content-generation audit fields `source_attributes` +
 * `recipe_id`. This pins the projection contract of
 * {@see AttributesIndexedRebuilder::globalSlot}:
 *
 *   - new fields ride into the `attributes_indexed` slot when present
 *     and well-typed (the badge tooltip reads them, AICG-P5-02),
 *   - legacy rows without them keep their exact pre-AICG shape,
 *   - malformed shapes are dropped, never propagated into the cache
 *     (jsonb-schemas cross-cutting rule #1).
 */
final class AttributesIndexedProvenanceSlotTest extends TestCase
{
    #[Test]
    public function contentGenerationAuditFieldsAreProjectedIntoTheSlot(): void
    {
        $recipeId = Uuid::v7()->toRfc4122();
        $runId = Uuid::v7()->toRfc4122();
        $value = $this->agentValue([
            'agent_run_id' => $runId,
            'model' => 'claude-sonnet-4-6',
            'intent' => 'generate_product_description',
            'source_attributes' => ['material', 'color'],
            'recipe_id' => $recipeId,
        ]);

        $slot = $this->newRebuilder()->globalSlot($value);

        self::assertSame('agent', $slot['provenance']);
        self::assertSame(
            [
                'agent_run_id' => $runId,
                'recipe_id' => $recipeId,
                'source_attributes' => ['material', 'color'],
            ],
            $slot['provenance_meta'],
        );
    }

    #[Test]
    public function legacyAgentMetaWithoutNewFieldsKeepsItsPreAicgShape(): void
    {
        $runId = Uuid::v7()->toRfc4122();
        $value = $this->agentValue([
            'agent_run_id' => $runId,
            'model' => 'claude-sonnet-4-6',
            'intent' => 'set missing prices to 100',
        ]);

        $slot = $this->newRebuilder()->globalSlot($value);

        self::assertSame(['agent_run_id' => $runId], $slot['provenance_meta']);
    }

    #[Test]
    public function malformedNewFieldsAreDroppedNotPropagated(): void
    {
        $runId = Uuid::v7()->toRfc4122();
        $value = $this->agentValue([
            'agent_run_id' => $runId,
            // recipe_id must be a string, source_attributes a list of
            // strings — anything else never reaches the cache.
            'recipe_id' => 123,
            'source_attributes' => 'material,color',
        ]);

        $slot = $this->newRebuilder()->globalSlot($value);

        self::assertSame(['agent_run_id' => $runId], $slot['provenance_meta']);
    }

    #[Test]
    public function nonStringEntriesInSourceAttributesAreFilteredOut(): void
    {
        $runId = Uuid::v7()->toRfc4122();
        $value = $this->agentValue([
            'agent_run_id' => $runId,
            'source_attributes' => ['material', 42, null, 'color'],
        ]);

        $slot = $this->newRebuilder()->globalSlot($value);

        self::assertSame(
            ['agent_run_id' => $runId, 'source_attributes' => ['material', 'color']],
            $slot['provenance_meta'],
        );
    }

    #[Test]
    public function emptySourceAttributesListIsOmittedFromTheSlot(): void
    {
        $runId = Uuid::v7()->toRfc4122();
        $value = $this->agentValue([
            'agent_run_id' => $runId,
            'source_attributes' => [],
        ]);

        $slot = $this->newRebuilder()->globalSlot($value);

        self::assertSame(['agent_run_id' => $runId], $slot['provenance_meta']);
    }

    #[Test]
    public function newFieldsWithoutAgentRunIdDoNotCreateAProvenanceMetaSlot(): void
    {
        // The cache-level provenance_meta is gated on agent_run_id (the
        // badge signal); recipe_id/source_attributes alone must not
        // start projecting for non-agent writes.
        $value = $this->agentValue([
            'source_attributes' => ['material'],
            'recipe_id' => Uuid::v7()->toRfc4122(),
        ]);

        $slot = $this->newRebuilder()->globalSlot($value);

        self::assertArrayNotHasKey('provenance_meta', $slot);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function agentValue(array $meta): ObjectValue
    {
        $tenant = new Tenant('demo-test-'.bin2hex(random_bytes(2)), 'Demo');
        $objectType = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $objectType->assignTenant($tenant);
        $object = new CatalogObject($objectType, 'SKU-PROV-1');

        $attribute = new Attribute('description', ['en' => 'Description'], AttributeType::Textarea);
        $attribute->assignTenant($tenant);

        $value = new ObjectValue($object, $attribute, ['value' => 'generated copy'], Provenance::Agent);
        $value->updateProvenanceMeta($meta);

        return $value;
    }

    private function newRebuilder(): AttributesIndexedRebuilder
    {
        $resolver = $this->createStub(EffectiveAttributeGroupResolver::class);
        $resolver->method('resolve')->willReturn([]);
        $resolver->method('loadGroupAttributes')->willReturn([]);

        $scopes = $this->createStub(ScopeEnumeratorInterface::class);
        $scopes->method('localeShortCodes')->willReturn([]);
        $scopes->method('channelIdsByCode')->willReturn([]);

        return new AttributesIndexedRebuilder(
            $this->createStub(EntityManagerInterface::class),
            $resolver,
            $scopes,
            $this->createStub(LocaleFallbackResolverInterface::class),
        );
    }
}

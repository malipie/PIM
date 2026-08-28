<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\Message\ObjectValuesChangedMessage;
use App\Catalog\Application\PendingChanges\ContentValueMaterializer;
use App\Catalog\Application\PendingChanges\PendingBatchCommitter;
use App\Catalog\Application\Validation\AttributeValueValidator;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Contracts\Command\PendingBatchCommitPort;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeStatus;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectValueRepositoryInterface;
use App\Channel\Contracts\ChannelResolverInterface;
use App\Identity\Contracts\Policy\UserScopedPermissionCheckerInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\ReceivedStamp;
use Symfony\Component\Messenger\Transport\InMemory\InMemoryTransport;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AICG-P3-01 (#2334) — the generated-text write path end to end against
 * real Postgres: materialize -> pending row ONLY (object_values
 * untouched), accept+commit -> the exact SCOPED ObjectValue row with
 * Provenance::Agent and the content provenance_meta
 * (source_attributes + recipe_id, AICG-P0-02 envelope), global reading
 * of the same attribute untouched.
 */
final class ContentValueCommitTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function generatedValueMaterializesAsPendingAndCommitsToTheScopedRow(): void
    {
        $tenant = $this->createTenant();
        [$object, $attribute] = $this->seedProduct();
        $em = $this->em();

        $batchId = Uuid::v7();
        $recipeId = Uuid::v7()->toRfc4122();
        $proposal = $this->materializer()->materializeGeneratedValue(
            $batchId,
            Uuid::v7(),
            $object->getId(),
            'description',
            'Generated English copy.',
            locale: 'en',
            meta: ['intent' => 'generate_product_description', 'recipe_id' => $recipeId],
        );

        self::assertTrue($proposal->isMaterialized());
        // before is the EXACT-scope row: no en row exists yet, so the diff
        // shows null -> generated (row creation), not the global fallback —
        // consistent with what the commit writes and what a rollback would
        // restore. The global reading stays visible untouched below.
        self::assertNull($proposal->before);

        // Materialize only — the catalog is untouched.
        $port = self::getContainer()->get(PendingChangesPort::class);
        $views = $port->listBatch($batchId);
        self::assertCount(1, $views);
        self::assertSame(PendingChangeStatus::Pending, $views[0]->status);
        self::assertSame('en', $views[0]->scopeLocale);
        self::assertNull(
            self::getContainer()->get(ObjectValueRepositoryInterface::class)
                ->findOneByScope($object, $attribute, null, 'en'),
            'no ObjectValue row may exist before approval',
        );

        // Approve -> commit through the real bulk path.
        $runId = Uuid::v7()->toRfc4122();
        $result = $this->committer()->commitAcceptedBatch($batchId, Uuid::v7(), [
            'agent_run_id' => $runId,
            'model' => 'claude-sonnet-test',
            'intent' => 'generate_product_description',
            'source_attributes' => ['material', 'color'],
            'recipe_id' => $recipeId,
        ]);
        $this->drainAsyncTransport();
        self::assertSame(1, $result->committedValues);

        $em->clear();
        $this->activateTenantFilter($this->reloadTenant($tenant));
        $reloadedObject = $em->find(CatalogObject::class, $object->getId());
        $reloadedAttribute = $em->getRepository(Attribute::class)->findOneBy(['code' => 'description']);
        \assert(null !== $reloadedObject && null !== $reloadedAttribute);

        $scoped = self::getContainer()->get(ObjectValueRepositoryInterface::class)
            ->findOneByScope($reloadedObject, $reloadedAttribute, null, 'en');
        self::assertNotNull($scoped, 'the accepted proposal must land on the en row');
        self::assertSame(['value' => 'Generated English copy.'], $scoped->getValue());
        self::assertSame('agent', $scoped->getProvenance()->value);
        $meta = $scoped->getProvenanceMeta();
        self::assertSame($runId, $meta['agent_run_id'] ?? null);
        self::assertSame(['material', 'color'], $meta['source_attributes'] ?? null);
        self::assertSame($recipeId, $meta['recipe_id'] ?? null);

        $global = self::getContainer()->get(ObjectValueRepositoryInterface::class)
            ->findOneByScope($reloadedObject, $reloadedAttribute);
        self::assertNotNull($global);
        self::assertSame(['value' => 'Opis globalny.'], $global->getValue(), 'the global reading must stay untouched');
    }

    #[Test]
    public function aSmallBatchRefreshesTheProjectionWithoutTheWorker(): void
    {
        // #3053 — `object_values` commits synchronously, but the product detail
        // endpoint reads `attributes_indexed`. That projection used to be
        // rebuilt ONLY by the worker (import pattern), so the refetch the UI
        // fires the moment `approve` returns read the value from BEFORE the
        // change: the operator saw an unchanged field and had to leave the
        // product and come back. Deliberately NO drainAsyncTransport() here —
        // the projection has to be correct without the worker ever running.
        $tenant = $this->createTenant();
        [$object] = $this->seedProduct();
        $em = $this->em();

        $batchId = Uuid::v7();
        $this->materializer()->materializeGeneratedValue(
            $batchId,
            Uuid::v7(),
            $object->getId(),
            'description',
            'Świeża treść od agenta.',
            meta: ['intent' => 'generate_product_description'],
        );

        $result = $this->committer()->commitAcceptedBatch($batchId, Uuid::v7(), []);
        self::assertSame(1, $result->committedValues);

        $em->clear();
        $this->activateTenantFilter($this->reloadTenant($tenant));
        $reloaded = $em->find(CatalogObject::class, $object->getId());
        \assert(null !== $reloaded);

        // getAttributesIndexed() is array<string, mixed>; narrow the slot before
        // reading the envelope so PHPStan max sees a real array, not mixed.
        $slot = $reloaded->getAttributesIndexed()['description'] ?? null;
        self::assertIsArray($slot);
        self::assertSame(
            'Świeża treść od agenta.',
            $slot['value'] ?? null,
            'attributes_indexed must be fresh before the response returns — the UI reads the projection, not object_values',
        );

        // The inline rebuild REPLACES the queued one; leaving both would make
        // the worker redo the same write for every approval.
        $transport = self::getContainer()->get('messenger.transport.async');
        if ($transport instanceof InMemoryTransport) {
            $queued = array_filter(
                $transport->getSent(),
                static fn (object $envelope): bool => $envelope->getMessage() instanceof ObjectValuesChangedMessage,
            );
            self::assertSame([], $queued, 'a batch rebuilt inline must not also enqueue ObjectValuesChangedMessage');
        }
    }

    /**
     * @return array{0: CatalogObject, 1: Attribute}
     */
    private function seedProduct(): array
    {
        $em = $this->em();
        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $em->persist($type);

        $attribute = new Attribute('description', ['en' => 'Description'], AttributeType::Textarea);
        $attribute->changeLocalizable(true);
        $em->persist($attribute);

        $object = new CatalogObject($type, 'CVC-1');
        $em->persist($object);
        $em->persist(new ObjectValue($object, $attribute, ['value' => 'Opis globalny.']));
        $em->flush();

        return [$object, $attribute];
    }

    private function materializer(): ContentValueMaterializer
    {
        // Constructed directly (no runtime consumer until AICG-P3-02);
        // RBAC allow-all stub — the refusal matrix is unit-tested.
        $permissions = new class implements UserScopedPermissionCheckerInterface {
            public function canViewAttribute(Uuid $userId, Uuid $attributeId): bool
            {
                return true;
            }

            public function canEditAttribute(Uuid $userId, Uuid $attributeId): bool
            {
                return true;
            }

            public function canEditLocale(Uuid $userId, string $locale): bool
            {
                return true;
            }

            public function canEditChannel(Uuid $userId, string $channel): bool
            {
                return true;
            }
        };

        return new ContentValueMaterializer(
            $this->em(),
            self::getContainer()->get(TenantContext::class),
            self::getContainer()->get(ObjectValueRepositoryInterface::class),
            self::getContainer()->get(AttributeValueValidator::class),
            $permissions,
            self::getContainer()->get(ChannelResolverInterface::class),
            self::getContainer()->get(PendingChangesPort::class),
        );
    }

    private function committer(): PendingBatchCommitPort
    {
        return self::getContainer()->get(PendingBatchCommitter::class);
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function createTenant(): Tenant
    {
        $em = $this->em();
        $tenant = new Tenant('demo-cvc', 'Demo Tenant');
        $em->persist($tenant);
        $em->flush();
        $this->activateTenantFilter($tenant);

        return $tenant;
    }

    private function reloadTenant(Tenant $tenant): Tenant
    {
        $managed = $this->em()->find(Tenant::class, $tenant->getId()->toRfc4122());
        \assert($managed instanceof Tenant);

        return $managed;
    }

    private function activateTenantFilter(Tenant $tenant): void
    {
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();
    }

    /**
     * CI pins MESSENGER_TRANSPORT_DSN to in-memory:// — re-dispatch the
     * queued rebuild messages so the projection runs (same helper as
     * AgentProvenanceProjectionTest).
     */
    private function drainAsyncTransport(): void
    {
        $transport = self::getContainer()->get('messenger.transport.async');
        if (!$transport instanceof InMemoryTransport) {
            return;
        }
        $bus = self::getContainer()->get(MessageBusInterface::class);
        foreach ($transport->getSent() as $envelope) {
            $bus->dispatch($envelope->getMessage(), [new ReceivedStamp('async')]);
        }
    }
}

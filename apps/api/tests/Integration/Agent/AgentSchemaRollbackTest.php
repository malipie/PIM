<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Agent\Application\Approval\AgentApprovalService;
use App\Agent\Application\Tool\AgentToolContext;
use App\Agent\Application\Tool\CreateAttributesFromSchemaTool;
use App\Agent\Domain\AgentRunStatus;
use App\Agent\Domain\AgentRunSurface;
use App\Agent\Domain\Entity\AgentRun;
use App\Agent\Domain\Exception\ApprovalConflictException;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Provenance;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AGENT-P5-04 (#1973, SEC failing-test-first) — schema rollback
 * boundaries: a DATALESS created attribute (and its group) roll back
 * cleanly; an attribute that already carries a value BLOCKS the whole
 * rollback with an operator-facing reason and NOTHING is deleted —
 * "Cofnij" on a schema-op must never destroy data.
 */
final class AgentSchemaRollbackTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function datalessCreatedSchemaRollsBackCleanly(): void
    {
        [$em] = $this->fixture();
        $run = $this->committedSchemaRun($em);

        $rolledBack = $this->approval()->rollback($run->getId());

        self::assertSame(AgentRunStatus::RolledBack, $rolledBack->getStatus());

        $conn = $em->getConnection();
        $attribute = $conn->fetchOne("SELECT COUNT(*) FROM attributes WHERE code = 'weight'");
        self::assertSame(0, (int) (\is_scalar($attribute) ? $attribute : -1), 'a dataless created attribute must be removed');
        $group = $conn->fetchOne("SELECT COUNT(*) FROM attribute_groups WHERE code = 'dimensions'");
        self::assertSame(0, (int) (\is_scalar($group) ? $group : -1), 'the created group must be removed');
    }

    #[Test]
    public function attributeWithDataBlocksTheWholeRollback(): void
    {
        [$em] = $this->fixture();
        $run = $this->committedSchemaRun($em);

        // Kasia fills a value AFTER the schema commit.
        $tenant = $this->managedTenant($em);
        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $em->persist($type);
        $object = new CatalogObject($type, 'SKU-1');
        $em->persist($object);
        $attribute = $em->getRepository(Attribute::class)->findOneBy(['code' => 'weight', 'tenant' => $tenant]);
        \assert($attribute instanceof Attribute);
        $em->persist(new ObjectValue(object: $object, attribute: $attribute, value: ['value' => 12.5], provenance: Provenance::Manual));
        $em->flush();

        try {
            $this->approval()->rollback($run->getId());
            self::fail('rollback of an attribute with data must be blocked');
        } catch (ApprovalConflictException $conflict) {
            self::assertStringContainsString('weight', $conflict->getMessage());
            self::assertStringContainsString('value', $conflict->getMessage());
        }

        // SEC: NOTHING was deleted - not even the dataless group.
        $conn = $em->getConnection();
        $attributeCount = $conn->fetchOne("SELECT COUNT(*) FROM attributes WHERE code = 'weight'");
        self::assertSame(1, (int) (\is_scalar($attributeCount) ? $attributeCount : -1));
        $group = $conn->fetchOne("SELECT COUNT(*) FROM attribute_groups WHERE code = 'dimensions'");
        self::assertSame(1, (int) (\is_scalar($group) ? $group : -1), 'all-or-nothing: the group must survive a blocked rollback');
        $value = $conn->fetchOne('SELECT COUNT(*) FROM object_values');
        self::assertSame(1, (int) (\is_scalar($value) ? $value : -1), 'the manual value must survive');

        $fresh = $em->find(AgentRun::class, $run->getId());
        self::assertInstanceOf(AgentRun::class, $fresh);
        self::assertSame(AgentRunStatus::Done, $fresh->getStatus(), 'a blocked rollback leaves the run done');
    }

    /**
     * @return array{0: EntityManagerInterface}
     */
    private function fixture(): array
    {
        $tenant = new Tenant('alpha', 'Alpha Tenant');
        $em = $this->em();
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        $pl = new \App\Channel\Domain\Entity\Locale('pl_PL', 'Polski');
        $em->persist($pl);
        $em->persist(new \App\Channel\Domain\Entity\TenantLocale($pl, isDefault: true, tenant: $tenant));
        $em->flush();

        return [$em];
    }

    private function committedSchemaRun(EntityManagerInterface $em): AgentRun
    {
        $tool = new CreateAttributesFromSchemaTool(self::getContainer()->get(PendingChangesPort::class));
        $tenant = $this->managedTenant($em);
        $result = $tool->execute([
            'attribute_groups' => [
                ['code' => 'dimensions', 'label' => ['pl' => 'Wymiary']],
            ],
            'attributes' => [
                ['code' => 'weight', 'type' => 'number', 'label' => ['pl' => 'Waga'], 'groups' => ['dimensions']],
            ],
        ], new AgentToolContext(Uuid::v7(), $tenant, []));
        \assert(\is_string($result['pending_change_batch_id']));
        $batchId = Uuid::fromString($result['pending_change_batch_id']);

        $tenant = $this->managedTenant($em);
        $run = new AgentRun(Uuid::v7(), AgentRunSurface::Chat, 'create weight attribute');
        $run->markAwaitingApproval($batchId, 1);
        $em->persist($run);
        $em->flush();

        $approved = $this->approval()->approve($run->getId(), Uuid::v7());
        \assert(AgentRunStatus::Done === $approved->getStatus());

        return $approved;
    }

    private function managedTenant(EntityManagerInterface $em): Tenant
    {
        $context = self::getContainer()->get(TenantContext::class);
        $current = $context->get();
        \assert($current instanceof Tenant);
        $managed = $em->find(Tenant::class, $current->getId()->toRfc4122());
        \assert($managed instanceof Tenant);
        $context->set($managed);

        return $managed;
    }

    private function approval(): AgentApprovalService
    {
        $service = self::getContainer()->get('test.agent.approval');
        \assert($service instanceof AgentApprovalService);

        return $service;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}

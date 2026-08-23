<?php

declare(strict_types=1);

namespace App\Tests\Integration\Agent;

use App\Catalog\Application\PendingChanges\AgentValueNormalizer;
use App\Catalog\Application\PendingChanges\CreateObjectMaterializer;
use App\Catalog\Contracts\Command\PendingBatchCommitPort;
use App\Catalog\Contracts\Command\SetStatusProposalPort;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeOption;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeAttributeRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Identity\Application\PrdPermissionSeeder;
use App\Identity\Application\SeedTenantPrdRolesService;
use App\Identity\Contracts\Policy\UserScopedPermissionCheckerInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use App\Workflow\Contracts\ObjectEditorialWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use const JSON_THROW_ON_ERROR;

/** #2984 — approval-gated create_object and set_status integration. */
final class ObjectMutationToolsTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function createsObjectWithCanonicalInitialValuesOnlyAfterApprovalAndRejectsDuplicate(): void
    {
        [$tenant, $type] = $this->catalogFixture();
        $materializer = new CreateObjectMaterializer(
            self::getContainer()->get(TenantContext::class),
            self::getContainer()->get(ObjectTypeRepositoryInterface::class),
            self::getContainer()->get(CatalogObjectRepositoryInterface::class),
            self::getContainer()->get(AttributeRepositoryInterface::class),
            self::getContainer()->get(ObjectTypeAttributeRepositoryInterface::class),
            $this->allowAttributes(),
            self::getContainer()->get(AgentValueNormalizer::class),
            self::getContainer()->get(PendingChangesPort::class),
        );
        $batchId = Uuid::v7();
        $proposal = $materializer->materializeObjectCreation(
            $batchId,
            Uuid::v7(),
            $type->getCode(),
            'TEST-AGENT-01',
            ['name' => 'Kubek', 'price' => 19.9, 'color' => 'Czerwony'],
        );

        self::assertTrue($proposal->materialized);
        self::assertFalse($this->objectExists('TEST-AGENT-01'), 'materialization must not create the object');
        $rows = self::getContainer()->get(PendingChangesPort::class)->listBatch($batchId);
        self::assertCount(1, $rows);
        self::assertSame(PendingChangeType::Object, $rows[0]->changeType);
        self::assertIsArray($rows[0]->after);
        self::assertIsArray($rows[0]->after['attributes']);
        $initialValues = $rows[0]->after['attributes'];
        self::assertSame(['value' => 'Kubek'], $initialValues['name'] ?? null);
        self::assertSame(['amount' => 19.9, 'currency' => 'EUR'], $initialValues['price'] ?? null);
        self::assertSame(['option_code' => 'red'], $initialValues['color'] ?? null);

        $result = self::getContainer()->get(PendingBatchCommitPort::class)
            ->commitAcceptedBatch($batchId, Uuid::v7());
        self::assertSame(1, $result->objectsTouched);
        self::assertTrue($this->objectExists('TEST-AGENT-01'));
        $stored = $this->storedValues('TEST-AGENT-01');
        self::assertSame(['value' => 'Kubek'], $stored['name']);
        self::assertSame(['amount' => 19.9, 'currency' => 'EUR'], $stored['price']);
        self::assertSame(['option_code' => 'red'], $stored['color']);

        $duplicateBatch = Uuid::v7();
        $duplicate = $materializer->materializeObjectCreation(
            $duplicateBatch,
            Uuid::v7(),
            'product',
            'TEST-AGENT-01',
            ['name' => 'Inny'],
        );
        self::assertFalse($duplicate->materialized);
        self::assertStringContainsString('already exists', $duplicate->rejected[0]['reason']);
        self::assertSame(0, self::getContainer()->get(PendingChangesPort::class)->countBatch($duplicateBatch));
    }

    #[Test]
    public function workflowGuardBlocksProposalAndAllowedTransitionIsRecheckedAndLoggedAtCommit(): void
    {
        [$tenant, $type] = $this->catalogFixture();
        self::getContainer()->get(PrdPermissionSeeder::class)->seed();
        self::getContainer()->get(SeedTenantPrdRolesService::class)->seed($tenant);
        $marketing = $this->userWithRole($tenant, 'marketing', 'marketing@alpha.localhost');
        $admin = $this->userWithRole($tenant, 'admin', 'admin@alpha.localhost');
        $object = new CatalogObject($type, 'WFL-AGENT-01');
        $this->em()->persist($object);
        $this->em()->flush();

        $statuses = self::getContainer()->get(SetStatusProposalPort::class);
        $blockedBatch = Uuid::v7();
        $blocked = $statuses->materializeStatusTransition(
            $blockedBatch,
            $marketing->getId(),
            'product',
            [],
            ObjectEditorialWorkflow::TRANSITION_PUBLISH,
            [$object->getId()->toRfc4122()],
        );
        self::assertSame(0, $blocked->affectedObjects);
        self::assertCount(1, $blocked->blocked);
        self::assertStringContainsString('workflow.approve_reject', $blocked->blocked[0]['reason']);
        self::assertSame(0, self::getContainer()->get(PendingChangesPort::class)->countBatch($blockedBatch));

        $allowedBatch = Uuid::v7();
        $allowed = $statuses->materializeStatusTransition(
            $allowedBatch,
            $admin->getId(),
            'product',
            [],
            ObjectEditorialWorkflow::TRANSITION_PUBLISH,
            [$object->getId()->toRfc4122()],
        );
        self::assertSame(1, $allowed->affectedObjects);
        $diff = self::getContainer()->get(PendingChangesPort::class)->listBatch($allowedBatch);
        self::assertSame(['status' => 'draft'], $diff[0]->before);
        self::assertSame('publish', $diff[0]->after['transition'] ?? null);
        self::assertSame('published', $diff[0]->after['status'] ?? null);

        $result = self::getContainer()->get(PendingBatchCommitPort::class)
            ->commitAcceptedBatch($allowedBatch, $admin->getId());
        self::assertSame(1, $result->objectsTouched);
        self::assertSame('published', $this->em()->getConnection()->fetchOne(
            'SELECT status FROM objects WHERE id = :id',
            ['id' => $object->getId()->toRfc4122()],
        ));
        $transitionCount = $this->em()->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM workflow_transitions WHERE object_id = :id AND transition = :transition',
            ['id' => $object->getId()->toRfc4122(), 'transition' => 'publish'],
        );
        self::assertTrue(\is_int($transitionCount) || \is_string($transitionCount));
        self::assertSame(1, (int) $transitionCount);
    }

    /** @return array{Tenant, ObjectType} */
    private function catalogFixture(): array
    {
        $tenant = new Tenant('alpha', 'Alpha Tenant');
        $this->em()->persist($tenant);
        $this->em()->flush();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);
        $name = new Attribute('name', ['pl' => 'Nazwa'], AttributeType::Text);
        $name->changeRequired(true);
        $price = new Attribute('price', ['pl' => 'Cena'], AttributeType::Price);
        $price->updateValidationRules(['currencies' => ['EUR', 'PLN']]);
        $color = new Attribute('color', ['pl' => 'Kolor'], AttributeType::Select);
        foreach ([$type, $name, $price, $color] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->persist(new ObjectTypeAttribute($type, $name));
        $this->em()->persist(new ObjectTypeAttribute($type, $price));
        $this->em()->persist(new ObjectTypeAttribute($type, $color));
        $this->em()->persist(new AttributeOption($color, 'red', ['pl' => 'Czerwony']));
        $this->em()->flush();

        return [$tenant, $type];
    }

    private function userWithRole(Tenant $tenant, string $roleCode, string $email): User
    {
        $role = self::getContainer()->get(RoleRepositoryInterface::class)->findByCode($roleCode, $tenant);
        \assert(null !== $role);
        $user = new User($tenant, $email, 'irrelevant', ['ROLE_USER']);
        $user->addRole($role);
        $this->em()->persist($user);
        $this->em()->flush();

        return $user;
    }

    private function objectExists(string $code): bool
    {
        $tenant = self::getContainer()->get(TenantContext::class)->get();
        \assert($tenant instanceof Tenant);

        return false !== $this->em()->getConnection()->fetchOne(
            'SELECT 1 FROM objects WHERE tenant_id = :tenant AND code = :code',
            [
                'tenant' => $tenant->getId()->toRfc4122(),
                'code' => $code,
            ],
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function storedValues(string $code): array
    {
        $rows = $this->em()->getConnection()->fetchAllAssociative(
            'SELECT a.code, ov.value::text AS value FROM object_values ov JOIN objects o ON o.id = ov.object_id JOIN attributes a ON a.id = ov.attribute_id WHERE o.code = :code',
            ['code' => $code],
        );
        $values = [];
        foreach ($rows as $row) {
            if (\is_string($row['code'] ?? null) && \is_string($row['value'] ?? null)) {
                $decoded = json_decode($row['value'], true, flags: JSON_THROW_ON_ERROR);
                if (\is_array($decoded)) {
                    /** @var array<string, mixed> $typedDecoded */
                    $typedDecoded = $decoded;
                    $values[$row['code']] = $typedDecoded;
                }
            }
        }

        return $values;
    }

    private function allowAttributes(): UserScopedPermissionCheckerInterface
    {
        return new class implements UserScopedPermissionCheckerInterface {
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
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}

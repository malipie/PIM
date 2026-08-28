<?php

declare(strict_types=1);

namespace App\Tests\Api\Agent;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Contracts\PendingChanges\PendingChangeDraft;
use App\Catalog\Contracts\PendingChanges\PendingChangesPort;
use App\Catalog\Contracts\PendingChanges\PendingChangeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Identity\Application\ByokKeyManager;
use App\Identity\Application\PrdPermissionSeeder;
use App\Identity\Application\RbacSeeder;
use App\Identity\Application\SeedTenantPrdRolesService;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\RbacMatrix;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * AGENT-P4-01 (#1968) — the public agent API surface: 401 without a
 * token, 403 without the BYOK key, run lifecycle over HTTP with the
 * loop running inline on the canned test LLM, decisions
 * (approve/reject/cancel/rollback) and ownership scoping (someone
 * else's run answers 404).
 */
final class AgentRunsApiTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    protected static ?bool $alwaysBootKernel = true;

    private const string TENANT_CODE = 'demo';
    private const string ADMIN_EMAIL = 'admin@demo.localhost';
    private const string SECOND_EMAIL = 'second@demo.localhost';

    protected function setUp(): void
    {
        parent::setUp();

        $em = $this->em();
        self::getContainer()->get(RbacSeeder::class)->seed();
        self::getContainer()->get(PrdPermissionSeeder::class)->seed();
        $em->flush();

        $superAdmin = self::getContainer()->get(RoleRepositoryInterface::class)
            ->findGlobalByCode(RbacMatrix::ROLE_SUPER_ADMIN);
        \assert(null !== $superAdmin);

        $tenant = new Tenant(self::TENANT_CODE, 'Demo Tenant');
        $em->persist($tenant);
        $em->flush();

        self::getContainer()->get(SeedTenantPrdRolesService::class)->seed($tenant);
        $tenantOwner = self::getContainer()->get(RoleRepositoryInterface::class)
            ->findByCode('tenant_owner', $tenant);
        \assert(null !== $tenantOwner);

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        foreach ([self::ADMIN_EMAIL, self::SECOND_EMAIL] as $email) {
            $stub = new User($tenant, $email, '', ['ROLE_USER']);
            $user = new User($tenant, $email, $hasher->hashPassword($stub, 'changeme'), ['ROLE_USER']);
            $user->addRole($superAdmin);
            $user->addRole($tenantOwner);
            $em->persist($user);
        }
        $em->flush();

        self::getContainer()->get(TenantContext::class)->set($tenant);
    }

    #[Test]
    public function anonymousIsRejectedWith401(): void
    {
        $response = static::createClient()->request('GET', '/api/agent/runs');
        self::assertSame(401, $response->getStatusCode());
    }

    #[Test]
    public function missingByokKeyAnswers403(): void
    {
        // No BYOK key seeded — the feature guard refuses per tenant.
        $response = $this->authenticatedClient()->request('POST', '/api/agent/runs', [
            'json' => ['intent' => 'set missing prices to 100'],
        ]);
        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function blankIntentIsA400(): void
    {
        $this->seedByokKey();
        $response = $this->authenticatedClient()->request('POST', '/api/agent/runs', [
            'json' => ['intent' => '   '],
        ]);
        self::assertSame(400, $response->getStatusCode());
    }

    #[Test]
    public function dashboardSurfaceIsAccepted(): void
    {
        // #2246 — the dashboard hero is the third human entry point.
        $this->seedByokKey();
        $response = $this->authenticatedClient()->request('POST', '/api/agent/runs', [
            'json' => ['intent' => 'pokaż raport kompletności produktów', 'surface' => 'dashboard'],
        ]);
        self::assertSame(201, $response->getStatusCode());
        self::assertSame('dashboard', $response->toArray(false)['surface']);
    }

    #[Test]
    public function startRunsTheLoopInlineAndDetailShowsTheTranscript(): void
    {
        $this->seedByokKey();
        $client = $this->authenticatedClient();

        $created = $client->request('POST', '/api/agent/runs', [
            'json' => ['intent' => 'set missing prices to 100', 'surface' => 'chat'],
        ]);
        self::assertSame(201, $created->getStatusCode());
        $payload = $created->toArray(false);
        // Sync transports run the whole loop inline on the canned LLM:
        // a plain-text reply lands the run on awaiting_input.
        self::assertSame('awaiting_input', $payload['status']);
        $runId = $payload['id'];
        self::assertIsString($runId);

        $detail = $client->request('GET', '/api/agent/runs/'.$runId);
        self::assertSame(200, $detail->getStatusCode());
        $detailPayload = $detail->toArray(false);
        self::assertIsArray($detailPayload['messages']);
        self::assertGreaterThanOrEqual(2, \count($detailPayload['messages']), 'intent + canned reply must be in the transcript');
        self::assertGreaterThan(0, $detailPayload['tokens_input']);

        // Next turn: 202, run keeps conversing and returns to awaiting_input.
        $turn = $client->request('POST', \sprintf('/api/agent/runs/%s/messages', $runId), [
            'json' => ['message' => 'products of brand Festo'],
        ]);
        self::assertSame(202, $turn->getStatusCode());

        $after = $client->request('GET', '/api/agent/runs/'.$runId)->toArray(false);
        self::assertSame('awaiting_input', $after['status']);
        self::assertIsArray($after['messages']);
        self::assertGreaterThanOrEqual(4, \count($after['messages']));

        // History lists the caller's runs.
        $history = $client->request('GET', '/api/agent/runs');
        self::assertSame(200, $history->getStatusCode());
        $items = $history->toArray(false)['items'];
        self::assertIsArray($items);
        self::assertCount(1, $items);

        // Cancel the conversation - terminal, idempotent.
        $cancel = $client->request('POST', \sprintf('/api/agent/runs/%s/cancel', $runId));
        self::assertSame(200, $cancel->getStatusCode());
        self::assertSame('cancelled', $cancel->toArray(false)['status']);
    }

    #[Test]
    public function approveCommitsAndRollbackRevertsOverHttp(): void
    {
        $this->seedByokKey();
        $em = $this->em();

        // A run parked at awaiting_approval with a materialized batch
        // (the canned LLM never calls tools, so the batch is seeded
        // directly - the decision endpoints are the unit under test).
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(\App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator::class)->apply();

        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $em->persist($type);
        $em->persist(new Attribute('price', ['en' => 'Price'], AttributeType::Number));
        $object = new CatalogObject($type, 'NOPRICE-1');
        $em->persist($object);
        $em->flush();

        $batchId = Uuid::v7();
        self::getContainer()->get(PendingChangesPort::class)->materialize($batchId, 'agent', [
            new PendingChangeDraft(
                changeType: PendingChangeType::Value,
                targetObjectId: $object->getId(),
                attributeCode: 'price',
                before: null,
                after: ['value' => 100],
            ),
        ]);

        $managedTenant = $em->find(Tenant::class, $tenant->getId()->toRfc4122());
        \assert($managedTenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($managedTenant);

        $owner = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail(self::ADMIN_EMAIL);
        \assert($owner instanceof User);
        $run = new \App\Agent\Domain\Entity\AgentRun($owner->getId(), \App\Agent\Domain\AgentRunSurface::Chat, 'set missing prices to 100');
        $run->markAwaitingApproval($batchId, 1);
        $em->persist($run);
        $em->flush();
        $runId = $run->getId()->toRfc4122();

        $client = $this->authenticatedClient();

        // AGENT-P6-03 (#1976) — the inbox reads the materialized plan.
        $plan = $client->request('GET', \sprintf('/api/agent/runs/%s/plan', $runId));
        self::assertSame(200, $plan->getStatusCode());
        $planPayload = $plan->toArray(false);
        self::assertSame(1, $planPayload['total']);
        $items = $planPayload['items'];
        self::assertIsArray($items);
        self::assertIsArray($items[0]);
        self::assertSame('price', $items[0]['attribute_code']);
        self::assertNull($items[0]['before']);
        self::assertSame(['value' => 100], $items[0]['after']);
        self::assertSame('agent', $items[0]['provenance']);

        $approve = $client->request('POST', \sprintf('/api/agent/runs/%s/approve', $runId));
        self::assertSame(200, $approve->getStatusCode());
        $approvedPayload = $approve->toArray(false);
        self::assertSame('done', $approvedPayload['status']);
        self::assertNotNull($approvedPayload['bulk_operation_id']);

        $values = $em->getConnection()->fetchOne('SELECT COUNT(*) FROM object_values');
        self::assertSame(1, (int) (\is_scalar($values) ? $values : -1), 'approve must commit the value');

        // Idempotent double approve over HTTP.
        $again = $client->request('POST', \sprintf('/api/agent/runs/%s/approve', $runId));
        self::assertSame(200, $again->getStatusCode());
        self::assertSame($approvedPayload['bulk_operation_id'], $again->toArray(false)['bulk_operation_id']);

        $rollback = $client->request('POST', \sprintf('/api/agent/runs/%s/rollback', $runId));
        self::assertSame(200, $rollback->getStatusCode());
        self::assertSame('rolled_back', $rollback->toArray(false)['status']);
        $values = $em->getConnection()->fetchOne('SELECT COUNT(*) FROM object_values');
        self::assertSame(0, (int) (\is_scalar($values) ? $values : -1), 'rollback must revert the committed value');
    }

    #[Test]
    public function approvalInboxIsTenantWideAndOnlyListsPendingProposals(): void
    {
        $this->seedByokKey();
        $em = $this->em();
        $other = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail(self::SECOND_EMAIL);
        $admin = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail(self::ADMIN_EMAIL);
        \assert($other instanceof User);
        \assert($admin instanceof User);

        $pending = new \App\Agent\Domain\Entity\AgentRun(
            $other->getId(),
            \App\Agent\Domain\AgentRunSurface::Chat,
            'proposal from another approver',
        );
        $pending->markAwaitingApproval(Uuid::v7(), 3);
        $conversation = new \App\Agent\Domain\Entity\AgentRun(
            $admin->getId(),
            \App\Agent\Domain\AgentRunSurface::Chat,
            'ordinary conversation',
        );
        $conversation->markAwaitingInput();
        $em->persist($pending);
        $em->persist($conversation);
        $em->flush();

        $response = $this->authenticatedClient()->request('GET', '/api/agent/inbox');
        self::assertSame(200, $response->getStatusCode());
        $body = $response->toArray(false);
        self::assertSame(1, $body['total']);
        self::assertIsArray($body['items']);
        $items = $body['items'];
        self::assertCount(1, $items);
        self::assertIsArray($items[0]);
        self::assertSame($pending->getId()->toRfc4122(), $items[0]['id']);
        self::assertSame('awaiting_approval', $items[0]['status']);
    }

    #[Test]
    public function someoneElsesRunAnswers404(): void
    {
        $this->seedByokKey();
        $em = $this->em();

        $other = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail(self::SECOND_EMAIL);
        \assert($other instanceof User);
        $run = new \App\Agent\Domain\Entity\AgentRun($other->getId(), \App\Agent\Domain\AgentRunSurface::Chat, 'private business');
        $em->persist($run);
        $em->flush();

        $client = $this->authenticatedClient();
        $detail = $client->request('GET', '/api/agent/runs/'.$run->getId()->toRfc4122());
        self::assertSame(404, $detail->getStatusCode());

        $cancel = $client->request('POST', \sprintf('/api/agent/runs/%s/cancel', $run->getId()->toRfc4122()));
        self::assertSame(404, $cancel->getStatusCode());

        $missing = $client->request('GET', '/api/agent/runs/'.Uuid::v7()->toRfc4122());
        self::assertSame(404, $missing->getStatusCode());
    }

    private function seedByokKey(): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(ByokKeyManager::class)->setKey($tenant, 'sk-ant-api03-canned-test-key');
    }

    private function authenticatedClient(string $email = self::ADMIN_EMAIL): Client
    {
        $user = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail($email);
        \assert(null !== $user);

        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);

        $client = static::createClient();
        $client->setDefaultOptions([
            'headers' => ['Authorization' => 'Bearer '.$jwt],
        ]);

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}

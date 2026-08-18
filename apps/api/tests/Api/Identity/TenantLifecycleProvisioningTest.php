<?php

declare(strict_types=1);

namespace App\Tests\Api\Identity;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use ApiPlatform\Symfony\Bundle\Test\Client;
use App\Identity\Application\RbacSeeder;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\RbacMatrix;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Identity\Domain\Repository\UserRepositoryInterface;
use App\Identity\Infrastructure\Provisioning\ProvisioningReconciler;
use App\Shared\Domain\Repository\TenantRepositoryInterface;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

use const JSON_THROW_ON_ERROR;

/**
 * TNT-P4-08 (#2909 / ADR-0036) — zawieszenie, wznowienie i skasowanie tenanta
 * muszą dotknąć STACKU jego instancji, nie tylko wiersza w rejestrze.
 *
 * Przy instancjach per tenant sama zmiana statusu jest deklaracją bez skutku:
 * zawieszony klient, którego kontenery dalej chodzą, jest zawieszony wyłącznie
 * na papierze — a jego instancja nadal zużywa pamięć i odpowiada na ruch.
 *
 * Testowany jest kontrakt na granicy: **co API odkłada do kolejki** i **jak
 * rejestr reaguje na wynik**. Samo uruchamianie kontenerów jest po stronie
 * provisionera (`docker/provisioner/test_provisioner.py`) — tutaj docker nie
 * istnieje i istnieć nie powinien, bo API go nie dotyka.
 */
final class TenantLifecycleProvisioningTest extends ApiTestCase
{
    use Factories;
    use ResetDatabase;

    protected static ?bool $alwaysBootKernel = true;

    private const string PLATFORM_OPERATOR_EMAIL = 'ops@platform.localhost';
    private const string TENANT_A_CODE = 'acme';
    private const string TENANT_B_CODE = 'demo';

    private string $spool = '';
    private string $tenantAId = '';
    private string $tenantBId = '';

    protected function setUp(): void
    {
        // Katalog kolejki MUSI być ustawiony, zanim kontener zbuduje usługę —
        // `%env(PROVISIONER_SPOOL)%` rozwiązuje się przy pierwszym użyciu.
        $this->spool = sys_get_temp_dir().'/pim-spool-test-'.bin2hex(random_bytes(6));
        mkdir($this->spool);
        $_ENV['PROVISIONER_SPOOL'] = $this->spool;
        $_SERVER['PROVISIONER_SPOOL'] = $this->spool;

        parent::setUp();

        self::getContainer()->get('limiter.auth_login')->create('127.0.0.1')->reset();

        $em = $this->em();
        self::getContainer()->get(RbacSeeder::class)->seed();

        $roles = self::getContainer()->get(RoleRepositoryInterface::class);
        $platformOperator = $roles->findGlobalByCode(RbacMatrix::ROLE_PLATFORM_OPERATOR);
        \assert(null !== $platformOperator, 'platform_operator must exist after seeding.');

        $tenantA = new Tenant(self::TENANT_A_CODE, 'Acme Industries');
        $tenantB = new Tenant(self::TENANT_B_CODE, 'Demo Tenant');
        $em->persist($tenantA);
        $em->persist($tenantB);
        $em->flush();
        $this->tenantAId = $tenantA->getId()->toRfc4122();
        $this->tenantBId = $tenantB->getId()->toRfc4122();

        $hasher = self::getContainer()->get(UserPasswordHasherInterface::class);
        $stub = new User($tenantA, self::PLATFORM_OPERATOR_EMAIL, '');
        $operator = new User($tenantA, self::PLATFORM_OPERATOR_EMAIL, $hasher->hashPassword($stub, 'changeme'));
        $operator->addRole($platformOperator);
        $em->persist($operator);
        $em->flush();
    }

    protected function tearDown(): void
    {
        foreach ($this->spoolFiles('*') as $file) {
            unlink($file);
        }
        if ('' !== $this->spool && is_dir($this->spool)) {
            rmdir($this->spool);
        }
        unset($_ENV['PROVISIONER_SPOOL'], $_SERVER['PROVISIONER_SPOOL']);

        parent::tearDown();
    }

    #[Test]
    public function suspendClosesTheTenantImmediatelyAndOrdersTheStackStopped(): void
    {
        $client = $this->operatorClient();
        $client->request('POST', '/api/admin/tenants/'.$this->tenantBId.'/suspend');

        // 202, nie 200: decyzja zapisana, ale instancja dopiero staje.
        self::assertResponseStatusCodeSame(202);

        $job = $this->singleJob();
        self::assertSame('suspend', $job['action']);
        self::assertSame(self::TENANT_B_CODE, $job['code']);

        // Odmowa dostępu obowiązuje OD RAZU — fail-closed. Gdyby zatrzymanie
        // kontenerów padło, chcemy tenanta zamkniętego i błędu w logach, a nie
        // odwrotnie.
        self::assertSame('suspended', $this->statusOf($this->tenantBId));
    }

    /**
     * Kryterium akceptacji: status wraca na `active` DOPIERO po weryfikacji,
     * że instancja odpowiada. Sam fakt zlecenia niczego nie dowodzi.
     */
    #[Test]
    public function reactivateWaitsForTheInstanceInsteadOfFlippingTheRowStraightAway(): void
    {
        $this->suspend($this->tenantBId);

        $client = $this->operatorClient();
        $client->request('POST', '/api/admin/tenants/'.$this->tenantBId.'/reactivate');

        self::assertResponseStatusCodeSame(202);
        $response = $client->getResponse();
        \assert(null !== $response);
        /** @var array<string, mixed> $body */
        $body = $response->toArray();
        self::assertArrayHasKey('provisioning_job_id', $body);

        self::assertSame(
            'suspended',
            $this->statusOf($this->tenantBId),
            'Tenant nie może wrócić na `active`, zanim instancja się zgłosi.',
        );
    }

    #[Test]
    public function reactivateCompletesOnlyWhenTheProvisionerReportsTheInstanceHealthy(): void
    {
        $this->suspend($this->tenantBId);
        $jobId = $this->requestLifecycle('/reactivate');

        $this->writeStatus($jobId, 'done');
        self::getContainer()->get(ProvisioningReconciler::class)->reconcile();

        self::assertSame('active', $this->statusOf($this->tenantBId));
    }

    #[Test]
    public function aReactivationThatFailsLeavesTheTenantSuspended(): void
    {
        $this->suspend($this->tenantBId);
        $jobId = $this->requestLifecycle('/reactivate');

        $this->writeStatus($jobId, 'failed');
        self::getContainer()->get(ProvisioningReconciler::class)->reconcile();

        self::assertSame(
            'suspended',
            $this->statusOf($this->tenantBId),
            '„Aktywny" przy martwej instancji jest gorszy niż widoczna porażka.',
        );
    }

    /**
     * Skasowanie w panelu ZOSTAJE odwracalne przez 30 dni: zlecenie robi zrzut
     * końcowy i wygasza instancję, ale wolumeny giną dopiero przy `purge`
     * z `pim:tenants:purge-deleted`.
     */
    #[Test]
    public function deleteOrdersTheReversibleShutdownNotThePurge(): void
    {
        $client = $this->operatorClient();
        $client->request('DELETE', '/api/admin/tenants/'.$this->tenantBId);

        self::assertResponseStatusCodeSame(202);

        $job = $this->singleJob();
        self::assertSame('delete', $job['action'], 'Panel nigdy nie zleca `purge` — to robi dopiero sweep po 30 dniach.');
        self::assertSame('deleted', $this->statusOf($this->tenantBId));
    }

    /**
     * Zawieszenie jednego klienta nie może dotknąć drugiego — ani jego wiersza
     * w rejestrze, ani jego instancji. Zlecenie adresuje dokładnie jeden kod.
     */
    #[Test]
    public function suspendingOneTenantLeavesTheOtherUntouched(): void
    {
        $this->suspend($this->tenantBId);

        self::assertSame('active', $this->statusOf($this->tenantAId));

        $codes = array_map(
            static fn (array $job): mixed => $job['code'],
            $this->jobs(),
        );
        self::assertSame([self::TENANT_B_CODE], $codes, 'Żadne zlecenie nie może dotyczyć sąsiada.');
    }

    /**
     * Sesje użytkowników sąsiada muszą przeżyć. Zawieszenie działa przez
     * rejestr, więc jego zasięg jest dokładnie tak szeroki, jak zasięg zapytania.
     */
    #[Test]
    public function usersOfTheOtherTenantKeepWorkingWhileTheNeighbourIsSuspended(): void
    {
        // Uwaga na pułapkę: `assertResponseStatusCodeSame()` patrzy na klienta
        // utworzonego NAJPÓŹNIEJ, a `suspend()` tworzy własnego. Odpowiedź
        // sąsiada czytamy więc wprost z jego klienta.
        $client = $this->operatorClient();
        $client->request('GET', '/api/admin/tenants/'.$this->tenantAId);
        self::assertSame(200, $this->statusCodeOf($client));

        $this->suspend($this->tenantBId);

        $client->request('GET', '/api/admin/tenants/'.$this->tenantAId);
        self::assertSame(200, $this->statusCodeOf($client), 'Sąsiad musi działać dalej.');
        self::assertSame('active', $this->statusOf($this->tenantAId));
    }

    private function statusCodeOf(Client $client): int
    {
        $response = $client->getResponse();
        \assert(null !== $response);

        return $response->getStatusCode();
    }

    /**
     * Rozliczenie jest idempotentne. Bez tego powtórzony przebieg cofałby
     * ręczne decyzje operatora — np. ponownie zawieszał tenanta wznowionego
     * po awarii.
     */
    #[Test]
    public function reconcilingTwiceChangesNothingTheSecondTime(): void
    {
        $this->suspend($this->tenantBId);
        $jobId = $this->requestLifecycle('/reactivate');
        $this->writeStatus($jobId, 'done');

        $reconciler = self::getContainer()->get(ProvisioningReconciler::class);
        self::assertSame(1, $reconciler->reconcile());
        self::assertSame(0, $reconciler->reconcile());
        self::assertSame('active', $this->statusOf($this->tenantBId));
    }

    private function suspend(string $tenantId): void
    {
        $this->operatorClient()->request('POST', '/api/admin/tenants/'.$tenantId.'/suspend');
        self::assertResponseStatusCodeSame(202);
    }

    private function requestLifecycle(string $path): string
    {
        $client = $this->operatorClient();
        $client->request('POST', '/api/admin/tenants/'.$this->tenantBId.$path);
        $response = $client->getResponse();
        \assert(null !== $response);
        /** @var array<string, mixed> $body */
        $body = $response->toArray();
        $jobId = $body['provisioning_job_id'] ?? null;
        \assert(\is_string($jobId));

        return $jobId;
    }

    private function writeStatus(string $jobId, string $state): void
    {
        file_put_contents(
            \sprintf('%s/%s.status.json', $this->spool, $jobId),
            json_encode(['state' => $state, 'steps' => []], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function singleJob(): array
    {
        $jobs = $this->jobs();
        self::assertCount(1, $jobs);

        return $jobs[0];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function jobs(): array
    {
        $jobs = [];
        foreach ($this->spoolFiles('*.job.json') as $file) {
            /** @var array<string, mixed>|null $decoded */
            $decoded = json_decode((string) file_get_contents($file), true);
            self::assertIsArray($decoded);
            $jobs[] = $decoded;
        }

        return $jobs;
    }

    /**
     * @return list<string>
     */
    private function spoolFiles(string $pattern): array
    {
        $found = glob($this->spool.'/'.$pattern);

        return false === $found ? [] : $found;
    }

    private function statusOf(string $tenantId): string
    {
        $this->em()->clear();
        $tenant = self::getContainer()->get(TenantRepositoryInterface::class)->findById(Uuid::fromString($tenantId));
        \assert(null !== $tenant);

        return $tenant->getStatus();
    }

    private function operatorClient(): Client
    {
        $user = self::getContainer()->get(UserRepositoryInterface::class)->findByEmail(self::PLATFORM_OPERATOR_EMAIL);
        \assert(null !== $user);
        $jwt = self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
        $client = static::createClient();
        $client->setDefaultOptions(['headers' => ['authorization' => 'Bearer '.$jwt]]);

        return $client;
    }

    private function em(): EntityManagerInterface
    {
        return self::getContainer()->get(EntityManagerInterface::class);
    }
}

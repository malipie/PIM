<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity;

use App\Identity\Infrastructure\Provisioning\ProvisioningQueue;
use App\Shared\Domain\Tenant;
use App\Shared\Domain\TenantSubdomain;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

use const JSON_THROW_ON_ERROR;

/**
 * TNT-P4-05 (#2906) — kolejka zleceń provisioningu.
 */
final class ProvisioningQueueTest extends TestCase
{
    private string $spool;

    protected function setUp(): void
    {
        $this->spool = sys_get_temp_dir().'/pim-spool-'.bin2hex(random_bytes(6));
        mkdir($this->spool);
    }

    protected function tearDown(): void
    {
        foreach ((self::globOrEmpty($this->spool.'/*')) as $file) {
            unlink($file);
        }
        rmdir($this->spool);
    }

    /**
     * `glob()` zwraca `false` przy błędzie, a skrócony operator warunkowy jest
     * w tym projekcie zabroniony. Jedno miejsce zamiast czterech powtórzeń.
     *
     * @return list<string>
     */
    private static function globOrEmpty(string $pattern): array
    {
        $found = glob($pattern);

        return false === $found ? [] : $found;
    }

    private function tenant(): Tenant
    {
        return new Tenant(code: 'acme', name: 'Acme', domain: 'acme', plan: Tenant::PLAN_STARTER);
    }

    #[Test]
    public function enqueuedJobLandsAsACompleteFile(): void
    {
        $queue = new ProvisioningQueue($this->spool);

        $jobId = $queue->enqueueCreate(
            tenant: $this->tenant(),
            subdomain: TenantSubdomain::fromString('acme'),
            ownerEmail: 'owner@acme.pl',
            requestedBy: Uuid::v4(),
        );

        $files = self::globOrEmpty($this->spool.'/*.job.json');
        self::assertCount(1, $files);

        $job = json_decode((string) file_get_contents($files[0]), true);
        self::assertIsArray($job);
        self::assertSame('create', $job['action']);
        self::assertSame('acme', $job['code']);
        self::assertSame('acme', $job['subdomain']);
        self::assertSame($jobId, $job['job_id']);
        self::assertTrue($job['invite_owner']);
    }

    /**
     * Hasło tymczasowe istnieje wyłącznie po to, żeby bootstrap miał czym
     * utworzyć konto. Nikt go nie ogląda, a właściciel dostaje dostęp
     * zaproszeniem ze swojej instancji — dlatego musi być losowe i za każdym
     * razem inne.
     */
    #[Test]
    public function temporaryPasswordIsRandomPerJob(): void
    {
        $queue = new ProvisioningQueue($this->spool);

        $passwords = [];
        foreach (range(1, 3) as $i) {
            $queue->enqueueCreate(
                tenant: $this->tenant(),
                subdomain: TenantSubdomain::fromString('acme'),
                ownerEmail: 'owner@acme.pl',
                requestedBy: Uuid::v4(),
            );
        }

        foreach (self::globOrEmpty($this->spool.'/*.job.json') as $file) {
            $job = json_decode((string) file_get_contents($file), true);
            self::assertIsArray($job);
            self::assertIsString($job['owner_password']);
            self::assertGreaterThanOrEqual(12, \strlen($job['owner_password']));
            $passwords[] = $job['owner_password'];
        }

        self::assertCount(3, array_unique($passwords), 'Hasła tymczasowe muszą być różne dla każdego zlecenia.');
    }

    /**
     * Plik częściowo zapisany zostałby przejęty przez provisionera jako
     * zlecenie nieczytelne, dlatego zapis jest atomowy: nazwa `*.job.json`
     * pojawia się dopiero w komplecie, a plik tymczasowy nigdy jej nie nosi.
     */
    #[Test]
    public function noTemporaryFileSurvivesUnderTheJobName(): void
    {
        $queue = new ProvisioningQueue($this->spool);
        $queue->enqueueCreate(
            tenant: $this->tenant(),
            subdomain: TenantSubdomain::fromString('acme'),
            ownerEmail: 'owner@acme.pl',
            requestedBy: Uuid::v4(),
        );

        self::assertSame([], self::globOrEmpty($this->spool.'/*.tmp'));
    }

    #[Test]
    public function unavailableSpoolIsReportedInsteadOfSilentlyDroppingTheJob(): void
    {
        $queue = new ProvisioningQueue($this->spool.'-nie-istnieje');

        self::assertFalse($queue->isAvailable());

        $this->expectException(RuntimeException::class);
        $queue->enqueueCreate(
            tenant: $this->tenant(),
            subdomain: TenantSubdomain::fromString('acme'),
            ownerEmail: 'owner@acme.pl',
            requestedBy: Uuid::v4(),
        );
    }

    #[Test]
    public function lifecycleActionsAreLimitedToTheKnownSet(): void
    {
        $queue = new ProvisioningQueue($this->spool);

        foreach (['suspend', 'reactivate', 'delete'] as $action) {
            self::assertNotSame('', $queue->enqueueLifecycle($this->tenant(), $action, Uuid::v4()));
        }

        $this->expectException(RuntimeException::class);
        $queue->enqueueLifecycle($this->tenant(), 'exec', Uuid::v4());
    }

    /**
     * Identyfikator zlecenia wchodzi do ścieżki pliku. Bez sprawdzenia
     * kształtu `../` w parametrze czytałby dowolny plik z dysku.
     */
    #[Test]
    public function statusRefusesIdentifiersThatCouldEscapeTheSpool(): void
    {
        $queue = new ProvisioningQueue($this->spool);

        foreach (['../../etc/passwd', '..', 'nie-uuid', ''] as $evil) {
            self::assertNull($queue->status($evil), \sprintf('Identyfikator %s nie powinien nic zwrócić.', $evil));
        }
    }

    #[Test]
    public function statusIsNullUntilTheProvisionerWritesOne(): void
    {
        $queue = new ProvisioningQueue($this->spool);

        self::assertNull($queue->status(Uuid::v4()->toRfc4122()));
    }

    #[Test]
    public function statusIsReadBackOnceTheProvisionerWritesIt(): void
    {
        $queue = new ProvisioningQueue($this->spool);
        $jobId = Uuid::v4()->toRfc4122();

        file_put_contents(
            \sprintf('%s/%s.status.json', $this->spool, $jobId),
            json_encode(['state' => 'running', 'steps' => []], JSON_THROW_ON_ERROR),
        );

        $status = $queue->status($jobId);
        self::assertNotNull($status);
        self::assertSame('running', $status['state']);
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Integration\Audit;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Audit\AuditWriteFailedException;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * #3045 — a write whose audit row cannot be stored must FAIL, not look
 * successful.
 *
 * DH Auditor catches every exception its own INSERT throws, on purpose, so one
 * broken provider cannot stop the others
 * ({@see \DH\Auditor\EventSubscriber\AuditEventSubscriber}). On Postgres that
 * is not survivable: the failed INSERT aborts the transaction, the COMMIT that
 * follows is downgraded to a silent ROLLBACK, and the caller gets a success it
 * did not earn. Measured on a live instance before the fix: POST
 * /api/import-profiles answered 201 with a full resource and a Location
 * header while the table stayed empty.
 *
 * The missing table is simulated the only way that reproduces the real
 * incident: by dropping it. DDL is transactional on Postgres, so ResetDatabase
 * hands the next test an intact schema.
 */
final class AuditWriteFailureTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function aWriteWhoseAuditRowCannotBeStoredFailsInsteadOfLookingSuccessful(): void
    {
        $em = $this->em();
        $tenant = new Tenant('alpha', 'Alpha');
        $em->persist($tenant);
        $em->flush();

        $this->connection()->executeStatement('DROP TABLE attributes_audit');

        $attribute = new Attribute('audit_probe', ['pl' => 'Sonda', 'en' => 'Probe'], AttributeType::Text);
        $em->persist($attribute);

        // expectException, nie try/catch: flush() nie deklaruje tego wyjatku,
        // wiec PHPStan uznaje catch za martwy.
        $this->expectException(AuditWriteFailedException::class);
        $this->expectExceptionMessageMatches('/attributes_audit/');

        $em->flush();
    }

    #[Test]
    public function theHealthyPathStillWritesBothRows(): void
    {
        // The guard costs one probe per flush; this pins that it does not
        // reject a perfectly good write.
        $em = $this->em();
        $tenant = new Tenant('beta', 'Beta');
        $em->persist($tenant);
        $em->flush();

        $attribute = new Attribute('audit_ok', ['pl' => 'Dobry', 'en' => 'Fine'], AttributeType::Text);
        $em->persist($attribute);
        $em->flush();

        $stored = $this->connection()->fetchOne(
            'SELECT count(*) FROM attributes WHERE code = :c',
            ['c' => 'audit_ok'],
        );
        self::assertSame(1, (int) (\is_scalar($stored) ? $stored : -1));

        $audited = $this->connection()->fetchOne(
            'SELECT count(*) FROM attributes_audit WHERE type = :t',
            ['t' => 'insert'],
        );
        self::assertGreaterThan(0, (int) (\is_scalar($audited) ? $audited : 0), 'the audit row must be there on the happy path');
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function connection(): Connection
    {
        return $this->em()->getConnection();
    }
}

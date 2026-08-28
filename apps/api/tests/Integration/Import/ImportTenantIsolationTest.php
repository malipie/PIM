<?php

declare(strict_types=1);

namespace App\Tests\Integration\Import;

use App\Backup\Domain\Entity\Backup;
use App\Backup\Domain\Enum\BackupTriggerAction;
use App\Backup\Domain\Repository\BackupRepositoryInterface;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\RelationCardinality;
use App\Import\Application\Service\RelationImportStep;
use App\Import\Domain\Entity\ImportLog;
use App\Import\Domain\Entity\ImportProfile;
use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Entity\ImportUndoLog;
use App\Import\Domain\Enum\ImportLogLevel;
use App\Import\Domain\Enum\ImportUndoOperation;
use App\Import\Domain\Repository\ImportProfileRepositoryInterface;
use App\Import\Domain\Repository\ImportSessionRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Uid\Uuid;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * IMP-01 tenant isolation smoke — DoD requirement: 2 tenants × cross-read = 0
 * via the Doctrine TenantFilter on every domain entity in this epic.
 *
 * The test seeds one ImportSession + one ImportProfile + one Backup per
 * tenant, then flips {@see TenantContext} between the two and asserts
 * each repository call returns only the active tenant's row.
 */
final class ImportTenantIsolationTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function importSessionsAreIsolatedByTenantFilter(): void
    {
        [$alpha, $beta] = $this->seedTwoTenantsWithSessions();

        $repo = self::getContainer()->get(ImportSessionRepositoryInterface::class);

        $this->activateTenantFilter($alpha);
        $alphaRows = $repo->findByTenantAndUser($alpha, Uuid::fromString('01943fd0-0000-7000-0000-000000000001'));
        self::assertCount(1, $alphaRows);
        self::assertSame('alpha-festo.xlsx', $alphaRows[0]->getFileName());

        $this->activateTenantFilter($beta);
        $betaRows = $repo->findByTenantAndUser($beta, Uuid::fromString('01943fd0-0000-7000-0000-000000000002'));
        self::assertCount(1, $betaRows);
        self::assertSame('beta-festo.xlsx', $betaRows[0]->getFileName());

        // Cross-read attempt: still in beta context, ask for alpha's tenant.
        // Repo returns 0 because the TenantFilter strips alpha's row from
        // the result set under beta's active filter.
        $crossRead = $repo->findByTenantAndUser(
            $alpha,
            Uuid::fromString('01943fd0-0000-7000-0000-000000000001'),
        );
        self::assertCount(0, $crossRead, 'TenantFilter must not leak alpha rows into beta context.');
    }

    #[Test]
    public function importProfilesAreIsolatedByTenantFilter(): void
    {
        [$alpha, $beta] = $this->seedTwoTenantsWithProfiles();

        $repo = self::getContainer()->get(ImportProfileRepositoryInterface::class);

        $this->activateTenantFilter($alpha);
        $alphaProfiles = $repo->findByTenantAndUser($alpha, Uuid::fromString('01943fd0-0000-7000-0000-000000000001'));
        self::assertCount(1, $alphaProfiles);
        self::assertSame('Alpha Festo', $alphaProfiles[0]->getName());

        $this->activateTenantFilter($beta);
        $crossRead = $repo->findByTenantAndUser(
            $alpha,
            Uuid::fromString('01943fd0-0000-7000-0000-000000000001'),
        );
        self::assertCount(0, $crossRead);
    }

    #[Test]
    public function backupsAreIsolatedByTenantFilter(): void
    {
        $alpha = $this->createTenant('alpha');
        $beta = $this->createTenant('beta');

        $em = $this->em();
        $alphaUserId = Uuid::v7();
        $betaUserId = Uuid::v7();

        $alphaBackup = new Backup($alphaUserId, BackupTriggerAction::PreImport);
        $alphaBackup->assignTenant($alpha);
        $em->persist($alphaBackup);

        $betaBackup = new Backup($betaUserId, BackupTriggerAction::Manual);
        $betaBackup->assignTenant($beta);
        $em->persist($betaBackup);

        $em->flush();
        $em->clear();

        $repo = self::getContainer()->get(BackupRepositoryInterface::class);

        $this->activateTenantFilter($alpha);
        $alphaCount = $repo->countSince($alpha, new DateTimeImmutable('-1 hour'));
        self::assertSame(1, $alphaCount, 'Alpha context must see only its own backup row.');

        $em->clear();
        $this->activateTenantFilter($beta);
        $betaCount = $repo->countSince($alpha, new DateTimeImmutable('-1 hour'));
        self::assertSame(0, $betaCount, 'TenantFilter must hide alpha backups from beta context.');
    }

    #[Test]
    public function importLogsAreIsolatedByTenantFilter(): void
    {
        // IMP2-2.5 (#1481) — import_logs now carries its own tenant_id, so the
        // TenantFilter scopes its queries directly (not only via the session FK).
        $alpha = $this->createTenant('alpha');
        $beta = $this->createTenant('beta');
        $em = $this->em();

        $this->tenantContext()->set($alpha);
        $type = $this->productObjectType($em);

        $alphaSession = new ImportSession(
            userId: Uuid::v7(),
            targetObjectType: $type,
            fileName: 'alpha.csv',
            fileSizeBytes: 10,
        );
        $alphaSession->assignTenant($alpha);
        $em->persist($alphaSession);
        $alphaLog = new ImportLog($alphaSession, 1, ImportLogLevel::Error, 'alpha row failed');
        $alphaLog->assignTenant($alpha);
        $em->persist($alphaLog);

        $betaSession = new ImportSession(
            userId: Uuid::v7(),
            targetObjectType: $type,
            fileName: 'beta.csv',
            fileSizeBytes: 10,
        );
        $betaSession->assignTenant($beta);
        $em->persist($betaSession);
        $betaLog = new ImportLog($betaSession, 1, ImportLogLevel::Warning, 'beta row warned');
        $betaLog->assignTenant($beta);
        $em->persist($betaLog);

        $em->flush();
        $em->clear();

        $repo = $em->getRepository(ImportLog::class);

        $this->activateTenantFilter($alpha);
        $alphaLogs = $repo->findAll();
        self::assertCount(1, $alphaLogs, 'alpha context sees only its own log row');
        self::assertSame('alpha row failed', $alphaLogs[0]->getMessage());

        $em->clear();
        $this->activateTenantFilter($beta);
        $betaLogs = $repo->findAll();
        self::assertCount(1, $betaLogs, 'TenantFilter must not leak alpha logs into beta context');
        self::assertSame('beta row warned', $betaLogs[0]->getMessage());
    }

    #[Test]
    public function undoLogIsIsolatedByTenant(): void
    {
        // IMP2-2.4 (#1480) — import_undo_log carries its own tenant_id (stamped
        // by TenantAssignmentListener) and is RLS-protected, so the TenantFilter
        // scopes its queries directly: an alpha context must never see beta's
        // before-state rows (cross-read = 0).
        $alpha = $this->createTenant('alpha');
        $beta = $this->createTenant('beta');
        $em = $this->em();

        $this->tenantContext()->set($alpha);
        $type = $this->productObjectType($em);

        $alphaSession = new ImportSession(
            userId: Uuid::v7(),
            targetObjectType: $type,
            fileName: 'alpha.csv',
            fileSizeBytes: 10,
        );
        $alphaSession->assignTenant($alpha);
        $em->persist($alphaSession);
        $alphaUndo = new ImportUndoLog(
            $alphaSession,
            Uuid::v7(),
            ImportUndoOperation::ValueOverwritten,
            ['value' => ['value' => 'alpha-before'], 'provenance' => 'manual'],
            'name',
        );
        $alphaUndo->assignTenant($alpha);
        $em->persist($alphaUndo);

        $betaSession = new ImportSession(
            userId: Uuid::v7(),
            targetObjectType: $type,
            fileName: 'beta.csv',
            fileSizeBytes: 10,
        );
        $betaSession->assignTenant($beta);
        $em->persist($betaSession);
        $betaUndo = new ImportUndoLog(
            $betaSession,
            Uuid::v7(),
            ImportUndoOperation::ValueOverwritten,
            ['value' => ['value' => 'beta-before'], 'provenance' => 'manual'],
            'name',
        );
        $betaUndo->assignTenant($beta);
        $em->persist($betaUndo);

        $em->flush();
        $em->clear();

        $repo = $em->getRepository(ImportUndoLog::class);

        $this->activateTenantFilter($alpha);
        $alphaRows = $repo->findAll();
        self::assertCount(1, $alphaRows, 'alpha context sees only its own undo-log row');
        self::assertSame(['value' => 'alpha-before'], $alphaRows[0]->getPayload()['value']);

        $em->clear();
        $this->activateTenantFilter($beta);
        $betaRows = $repo->findAll();
        self::assertCount(1, $betaRows, 'TenantFilter must not leak alpha undo-log rows into beta context');
        self::assertSame(['value' => 'beta-before'], $betaRows[0]->getPayload()['value']);
    }

    #[Test]
    public function relationTargetInAnotherTenantNeverLinks(): void
    {
        // IMP2-1.8 (AC) — a relation target whose code exists only in tenant
        // beta must NOT link when resolved for tenant alpha: 0 object_relations
        // + one row error. findByCodeInObjectTypes is tenant-scoped, so the
        // cross-tenant code resolves to null — never a leaked link.
        $alpha = $this->createTenant('alpha');
        $beta = $this->createTenant('beta');
        $em = $this->em();

        $this->tenantContext()->set($alpha);
        $type = $this->productObjectType($em);
        $typeId = $type->getId()->toRfc4122();

        $source = new CatalogObject($type, 'SRC-A');
        $em->persist($source);
        $attr = new Attribute('rel', ['en' => 'Rel'], AttributeType::Relation);
        $attr->setRelationTargetObjectTypeIds([$typeId]);
        $attr->setRelationCardinality(RelationCardinality::Many);
        $em->persist($attr);
        $em->flush();

        // beta owns a product that shares the target CODE but not the tenant.
        $this->tenantContext()->set($beta);
        $betaTarget = new CatalogObject($type, 'CROSS-TGT');
        $em->persist($betaTarget);
        $em->flush();
        $em->clear();

        // Resolve the buffered relation in ALPHA's context.
        $step = self::getContainer()->get(RelationImportStep::class);
        $step->reset();
        $step->recordRelation('SRC-A', 'rel', ['CROSS-TGT'], 7);
        $errors = $step->resolve(ObjectKind::Product, $alpha);

        self::assertCount(1, $errors, 'cross-tenant target must produce exactly one row error');
        self::assertSame(7, $errors[0]->rowNumber);
        self::assertSame(ImportLogLevel::Error, $errors[0]->level);
        self::assertStringContainsString('CROSS-TGT', $errors[0]->message);

        $linkCount = $em->getConnection()->fetchOne('SELECT COUNT(*) FROM object_relations');
        self::assertSame(0, (int) (\is_scalar($linkCount) ? $linkCount : 1), 'no object_relations row may be written');
    }

    /**
     * @return array{0: Tenant, 1: Tenant}
     */
    private function seedTwoTenantsWithSessions(): array
    {
        $alpha = $this->createTenant('alpha');
        $beta = $this->createTenant('beta');

        $em = $this->em();

        // ObjectType creation needs an active tenant so the assignment listener
        // can stamp it. Using alpha here is fine — both sessions share it via
        // `target_object_type_id`, the tenant_id on `import_sessions` rows is
        // independent.
        $this->tenantContext()->set($alpha);
        $type = $this->productObjectType($em);

        $alphaSession = new ImportSession(
            userId: Uuid::fromString('01943fd0-0000-7000-0000-000000000001'),
            targetObjectType: $type,
            fileName: 'alpha-festo.xlsx',
            fileSizeBytes: 1024,
        );
        $alphaSession->assignTenant($alpha);
        $em->persist($alphaSession);

        $betaSession = new ImportSession(
            userId: Uuid::fromString('01943fd0-0000-7000-0000-000000000002'),
            targetObjectType: $type,
            fileName: 'beta-festo.xlsx',
            fileSizeBytes: 2048,
        );
        $betaSession->assignTenant($beta);
        $em->persist($betaSession);

        $em->flush();
        $em->clear();

        return [$alpha, $beta];
    }

    /**
     * @return array{0: Tenant, 1: Tenant}
     */
    private function seedTwoTenantsWithProfiles(): array
    {
        $alpha = $this->createTenant('alpha');
        $beta = $this->createTenant('beta');

        $em = $this->em();
        $this->tenantContext()->set($alpha);
        $type = $this->productObjectType($em);

        $alphaProfile = new ImportProfile(
            userId: Uuid::fromString('01943fd0-0000-7000-0000-000000000001'),
            name: 'Alpha Festo',
            targetObjectType: $type,
        );
        $alphaProfile->assignTenant($alpha);
        $em->persist($alphaProfile);

        $betaProfile = new ImportProfile(
            userId: Uuid::fromString('01943fd0-0000-7000-0000-000000000002'),
            name: 'Beta Festo',
            targetObjectType: $type,
        );
        $betaProfile->assignTenant($beta);
        $em->persist($betaProfile);

        $em->flush();
        $em->clear();

        return [$alpha, $beta];
    }

    private function productObjectType(EntityManagerInterface $em): ObjectType
    {
        $type = $em->getRepository(ObjectType::class)->findOneBy(['kind' => ObjectKind::Product]);
        if ($type instanceof ObjectType) {
            return $type;
        }

        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $em->persist($type);
        $em->flush();

        return $type;
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    /**
     * KernelTestCase has no HTTP request lifecycle, so the
     * RequestTenantSubscriber that normally wires the active tenant into
     * the Doctrine filter never fires. Tests asserting filter behaviour
     * call this helper after every {@see TenantContext::set()} switch.
     */
    private function activateTenantFilter(Tenant $tenant): void
    {
        $this->tenantContext()->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }

    private function createTenant(string $code): Tenant
    {
        $tenant = new Tenant($code, ucfirst($code).' Tenant');
        $em = $this->em();
        $em->persist($tenant);
        $em->flush();

        return $tenant;
    }
}

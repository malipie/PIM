<?php

declare(strict_types=1);

namespace App\Tests\Api\Import;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Import\Application\Handler\ImportRunHandler;
use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Enum\ImportSessionStatus;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * #1729 — the import features from this initiative (newline multi-value #1719,
 * option auto-create #1718, attribute auto-create #1728) must work for ANY
 * ObjectType, not just product. This drives a full run into a CUSTOM
 * ObjectType and asserts all three at once.
 */
final class CrossObjectTypeImportTest extends CatalogApiTestCase
{
    #[Test]
    public function customObjectTypeImportSplitsNewlineMintsOptionsAndAttributes(): void
    {
        $tenant = $this->tenant();
        $em = $this->em();
        self::getContainer()->get(TenantContext::class)->set($tenant);

        // A custom ObjectType with one pre-existing multiselect attribute.
        $custom = new ObjectType('gadget', ObjectKind::Custom, ['pl' => 'Gadżet']);
        $custom->assignTenant($tenant);
        $tags = new Attribute('tagi', ['pl' => 'Tagi'], AttributeType::Multiselect);
        $em->persist($custom);
        $em->persist($tags);
        $em->persist(new ObjectTypeAttribute($custom, $tags, false, 1));
        $em->flush();

        $session = new ImportSession(
            userId: Uuid::v7(),
            targetObjectType: $custom,
            fileName: 'gadget.csv',
            fileSizeBytes: 64,
        );
        $session->assignTenant($tenant);
        // tagi → existing multiselect (newline split + option auto-create);
        // cecha → no attribute yet (attribute auto-create → text).
        $session->setColumnMapping(['sku' => 'sku', 'tagi' => 'tagi', 'cecha' => 'cecha']);
        $session->setCreateMissingOptions(true);
        $em->persist($session);
        $em->flush();
        $sessionId = $session->getId();

        self::getContainer()->get('imports.storage')->write(
            \sprintf('%s/%s/gadget.csv', $tenant->getId()->toRfc4122(), $sessionId->toRfc4122()),
            // The multi-value `tagi` cell packs its values with embedded
            // newlines inside quotes — exactly how IdoSell/IAI exports them.
            "sku;tagi;cecha\nGAD-1;\"alfa\nbeta\ngamma\";ręczny\n",
        );

        self::getContainer()->get(TenantFilterConfigurator::class)->apply();
        self::getContainer()->get(ImportRunHandler::class)->run($session);
        $em->clear();

        self::getContainer()->get(TenantContext::class)->set($this->tenant());
        $reloaded = $em->find(ImportSession::class, $sessionId);
        \assert($reloaded instanceof ImportSession);
        self::assertSame(ImportSessionStatus::Success, $reloaded->getStatus());
        self::assertSame(1, $reloaded->getSuccessCount());
        self::assertSame(0, $reloaded->getErrorCount());

        $conn = $em->getConnection();
        // Custom object created under the custom ObjectType.
        $kind = $conn->fetchOne(
            "SELECT ot.kind FROM objects o JOIN object_types ot ON ot.id = o.object_type_id WHERE o.code = 'GAD-1'",
        );
        self::assertSame('custom', $kind);

        // #1719 + #1718 — newline-split multiselect minted 3 options.
        $options = $conn->fetchOne(
            "SELECT COUNT(*) FROM attribute_options o JOIN attributes a ON a.id = o.attribute_id WHERE a.code = 'tagi'",
        );
        self::assertSame(3, (int) (\is_scalar($options) ? $options : 0), 'newline multiselect minted alfa/beta/gamma options');

        // #1728 — the missing `cecha` attribute was minted (text) and attached.
        $cechaType = $conn->fetchOne("SELECT type FROM attributes WHERE code = 'cecha'");
        self::assertSame('text', $cechaType);
        $attached = $conn->fetchOne(
            'SELECT COUNT(*) FROM object_type_attributes ota '
            .'JOIN attributes a ON a.id = ota.attribute_id '
            .'JOIN object_types ot ON ot.id = ota.object_type_id '
            ."WHERE ot.kind = 'custom' AND a.code = 'cecha'",
        );
        self::assertSame(1, (int) (\is_scalar($attached) ? $attached : 0), 'minted attribute attached to the custom ObjectType');
    }

    private function tenant(): Tenant
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        return $tenant;
    }
}

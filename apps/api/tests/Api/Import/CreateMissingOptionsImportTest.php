<?php

declare(strict_types=1);

namespace App\Tests\Api\Import;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeOption;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
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
 * #1718 — the gated `createMissingOptions` flag mints select/multiselect
 * options for unknown import values instead of failing the row.
 *
 * The run handler is driven directly (the same in-process pattern the batch
 * throttle test uses) so the assertions never touch the JWT/auth layer. The
 * `color` select attribute already owns one option, so the membership
 * validator is active and an unknown label is genuinely rejected unless the
 * flag mints it.
 */
final class CreateMissingOptionsImportTest extends CatalogApiTestCase
{
    #[Test]
    public function flagOnMintsMissingOptionAndWritesValue(): void
    {
        $sessionId = $this->runImport(createMissingOptions: true, sku: 'CMO-1');

        $em = $this->em();
        self::getContainer()->get(TenantContext::class)->set($this->tenant());
        $session = $em->find(ImportSession::class, $sessionId);
        \assert($session instanceof ImportSession);
        self::assertSame(ImportSessionStatus::Success, $session->getStatus());
        self::assertSame(1, $session->getSuccessCount());
        self::assertSame(0, $session->getErrorCount(), 'unknown option is minted, not an error');

        $conn = $em->getConnection();
        $mintedCode = $conn->fetchOne(
            "SELECT o.code FROM attribute_options o JOIN attributes a ON a.id = o.attribute_id WHERE a.code = 'color' AND o.code = 'bezowy'",
        );
        self::assertSame('bezowy', $mintedCode, 'a new option code was slugged from the Polish label');

        $label = $conn->fetchOne("SELECT label FROM attribute_options WHERE code = 'bezowy'");
        self::assertIsString($label);
        self::assertStringContainsString('Beżowy', $label, 'minted option keeps the original label');

        $value = $conn->fetchOne(
            'SELECT ov.value::text FROM object_values ov '
            .'JOIN objects obj ON obj.id = ov.object_id '
            .'JOIN attributes a ON a.id = ov.attribute_id '
            ."WHERE obj.code = 'CMO-1' AND a.code = 'color'",
        );
        self::assertIsString($value);
        self::assertStringContainsString('bezowy', $value, 'the written value references the minted option code');
    }

    #[Test]
    public function flagOffDoesNotMint(): void
    {
        $this->runImport(createMissingOptions: false, sku: 'CMO-2');

        $em = $this->em();
        self::getContainer()->get(TenantContext::class)->set($this->tenant());
        $optionCount = $em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM attribute_options o JOIN attributes a ON a.id = o.attribute_id WHERE a.code = 'color'",
        );
        self::assertSame(1, (int) (\is_scalar($optionCount) ? $optionCount : 0), 'no option minted when the flag is off (only the seeded one remains)');
    }

    private function runImport(bool $createMissingOptions, string $sku): Uuid
    {
        $tenant = $this->tenant();
        $em = $this->em();
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $product = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($product instanceof ObjectType);

        $color = new Attribute('color', ['en' => 'Color'], AttributeType::Select);
        $skuAttr = new Attribute('sku', ['en' => 'SKU'], AttributeType::Text);
        $nameAttr = new Attribute('name', ['en' => 'Name'], AttributeType::Text);
        $em->persist($skuAttr);
        $em->persist($nameAttr);
        $em->persist($color);
        // One pre-existing option keeps the membership validator active.
        $em->persist(new AttributeOption($color, 'red', ['pl' => 'Czerwony'], 0));
        $em->persist(new ObjectTypeAttribute($product, $skuAttr, false, 1));
        $em->persist(new ObjectTypeAttribute($product, $nameAttr, false, 2));
        $em->persist(new ObjectTypeAttribute($product, $color, false, 3));

        $session = new ImportSession(
            userId: Uuid::v7(),
            targetObjectType: $product,
            fileName: 'cmo.csv',
            fileSizeBytes: 64,
        );
        $session->assignTenant($tenant);
        $session->setColumnMapping(['sku' => 'sku', 'name' => 'name', 'color' => 'color']);
        $session->setCreateMissingOptions($createMissingOptions);
        $em->persist($session);
        $em->flush();
        $sessionId = $session->getId();

        self::getContainer()->get('imports.storage')->write(
            \sprintf('%s/%s/cmo.csv', $tenant->getId()->toRfc4122(), $sessionId->toRfc4122()),
            \sprintf("sku;name;color\n%s;Botki;Beżowy\n", $sku),
        );

        self::getContainer()->get(TenantFilterConfigurator::class)->apply();
        self::getContainer()->get(ImportRunHandler::class)->run($session);
        $em->clear();

        return $sessionId;
    }

    private function tenant(): Tenant
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        return $tenant;
    }
}

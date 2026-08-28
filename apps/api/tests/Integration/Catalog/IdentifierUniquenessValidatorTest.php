<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\BuiltInObjectTypeSeeder;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectValue;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Catalog\Domain\Validator\IdentifierUniquenessValidator;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class IdentifierUniquenessValidatorTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();
        self::bootKernel();

        $this->tenant = new Tenant('demo', 'Demo');
        $this->em()->persist($this->tenant);
        $this->em()->flush();
        self::getContainer()->get(TenantContext::class)->set($this->tenant);
        self::getContainer()->get(BuiltInObjectTypeSeeder::class)->seed($this->tenant);
    }

    #[Test]
    public function persistedIdentifierConflictsOnlyWithAnotherObject(): void
    {
        $em = $this->em();
        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $this->tenant);
        self::assertNotNull($type);

        $identifier = new Attribute('sku', ['en' => 'SKU'], AttributeType::Identifier);
        $existing = new CatalogObject($type, 'existing');
        $candidate = new CatalogObject($type, 'candidate');
        $em->persist($identifier);
        $em->persist($existing);
        $em->persist($candidate);
        $em->flush();
        $objectValue = new ObjectValue($existing, $identifier, ['value' => 'SKU-001']);
        $em->persist($objectValue);
        $em->flush();

        // Foundry builds the test schema from ORM metadata, so PostgreSQL
        // migration triggers are absent. Mirror the production trigger's two
        // denormalised columns explicitly before exercising the query port.
        $em->getConnection()->executeStatement(
            'UPDATE object_values SET identifier_value = :value, identifier_object_type_id = :type WHERE id = :id',
            [
                'value' => 'SKU-001',
                'type' => $type->getId()->toRfc4122(),
                'id' => $objectValue->getId()->toRfc4122(),
            ],
        );

        $validator = self::getContainer()->get(IdentifierUniquenessValidator::class);
        self::assertTrue($validator->isDuplicate($candidate, $identifier, 'SKU-001'));
        self::assertFalse($validator->isDuplicate($existing, $identifier, 'SKU-001'));
        self::assertFalse($validator->isDuplicate($candidate, $identifier, 'SKU-002'));
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}

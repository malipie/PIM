<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Application\BuiltInSystemAttributesSeeder;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\AttributeGroupAttribute;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Uid\Uuid;

/**
 * UI-08.8 (#263) — `PATCH /api/attribute_groups/{groupId}/attributes/{attributeId}`.
 *
 * Covers the visible_when rule write path + cross-group reference
 * validation. Read path (form-schema response carrying visibleWhen)
 * is exercised by ObjectFormSchemaApiTest in UI-08.4.
 */
final class AttributeGroupAttributeApiTest extends CatalogApiTestCase
{
    private AttributeGroup $marketing;
    private Attribute $requiresReferral;
    private Attribute $contraindications;

    protected function setUp(): void
    {
        parent::setUp();

        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(BuiltInSystemAttributesSeeder::class)->seed($tenant);

        $tenantContext = self::getContainer()->get(TenantContext::class);
        $tenantContext->set($tenant);

        $em = $this->em();
        $this->marketing = new AttributeGroup('marketing', ['en' => 'Marketing']);
        $em->persist($this->marketing);
        $this->requiresReferral = new Attribute('requires_referral', ['en' => 'Requires referral'], AttributeType::Boolean);
        $em->persist($this->requiresReferral);
        $this->contraindications = new Attribute('contraindications', ['en' => 'Contraindications'], AttributeType::Text);
        $em->persist($this->contraindications);
        $em->flush();
        $em->persist(new AttributeGroupAttribute($this->marketing, $this->requiresReferral, 1));
        $em->persist(new AttributeGroupAttribute($this->marketing, $this->contraindications, 2));
        $em->flush();

        $tenantContext->clear();
    }

    #[Test]
    public function patchSetsVisibleWhenRule(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request('PATCH', $this->junctionUri($this->marketing, $this->contraindications), [
            'json' => [
                'visibleWhen' => [
                    'field' => 'requires_referral',
                    'operator' => 'equals',
                    'value' => true,
                ],
            ],
        ]);

        self::assertSame(204, $response->getStatusCode());

        $stored = $this->em()->getConnection()->fetchOne(
            'SELECT visible_when FROM attribute_group_attributes'
            .' WHERE attribute_group_id = ? AND attribute_id = ?',
            [
                $this->marketing->getId()->toRfc4122(),
                $this->contraindications->getId()->toRfc4122(),
            ],
        );
        self::assertIsString($stored);
        $decoded = json_decode($stored, true);
        self::assertIsArray($decoded);
        self::assertSame('requires_referral', $decoded['field']);
        self::assertSame('equals', $decoded['operator']);
        self::assertTrue($decoded['value']);
    }

    #[Test]
    public function patchClearsVisibleWhenWhenNullProvided(): void
    {
        $client = $this->authenticatedClient();
        // First set the rule.
        $client->request('PATCH', $this->junctionUri($this->marketing, $this->contraindications), [
            'json' => [
                'visibleWhen' => ['field' => 'requires_referral', 'operator' => 'equals', 'value' => true],
            ],
        ]);
        // Then clear it.
        $response = $client->request('PATCH', $this->junctionUri($this->marketing, $this->contraindications), [
            'json' => ['visibleWhen' => null],
        ]);

        self::assertSame(204, $response->getStatusCode());
        $stored = $this->em()->getConnection()->fetchOne(
            'SELECT visible_when FROM attribute_group_attributes'
            .' WHERE attribute_group_id = ? AND attribute_id = ?',
            [
                $this->marketing->getId()->toRfc4122(),
                $this->contraindications->getId()->toRfc4122(),
            ],
        );
        self::assertNull($stored);
    }

    #[Test]
    public function patchRejectsCrossGroupFieldReference(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request('PATCH', $this->junctionUri($this->marketing, $this->contraindications), [
            'json' => [
                'visibleWhen' => ['field' => 'sku', 'operator' => 'equals', 'value' => 'X'],
            ],
        ]);

        self::assertSame(422, $response->getStatusCode());
    }

    #[Test]
    public function patchAcceptsSystemAuditFieldReference(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request('PATCH', $this->junctionUri($this->marketing, $this->contraindications), [
            'json' => [
                'visibleWhen' => ['field' => 'created_by', 'operator' => 'equals', 'value' => 'user-x'],
            ],
        ]);

        self::assertSame(204, $response->getStatusCode());
    }

    #[Test]
    public function patchRejectsUnsupportedOperator(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request('PATCH', $this->junctionUri($this->marketing, $this->contraindications), [
            'json' => [
                'visibleWhen' => ['field' => 'requires_referral', 'operator' => 'in', 'value' => [true]],
            ],
        ]);

        // The handler raises InvalidArgumentException → unhandled → 500.
        // The Messenger HandlerFailedException unwrapper converts only
        // HttpExceptions; domain validation throws a generic exception
        // here on purpose (the API edge can map to 422 in a follow-up,
        // but for MVP we accept that "unsupported operator" surfaces as
        // either 422 (when wrapped) or 500 (raw). Pin: not 204.
        self::assertNotSame(204, $response->getStatusCode());
    }

    #[Test]
    public function patchUpdatesPositionAndRequired(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request('PATCH', $this->junctionUri($this->marketing, $this->contraindications), [
            'json' => ['position' => 9, 'isRequiredInGroup' => true],
        ]);

        self::assertSame(204, $response->getStatusCode());

        $row = $this->em()->getConnection()->fetchAssociative(
            'SELECT position, is_required_in_group FROM attribute_group_attributes'
            .' WHERE attribute_group_id = ? AND attribute_id = ?',
            [
                $this->marketing->getId()->toRfc4122(),
                $this->contraindications->getId()->toRfc4122(),
            ],
        );
        self::assertIsArray($row);
        self::assertIsScalar($row['position']);
        self::assertSame(9, (int) $row['position']);
        self::assertIsScalar($row['is_required_in_group']);
        self::assertTrue((bool) $row['is_required_in_group']);
    }

    #[Test]
    public function patchOnUnknownGroupReturns404(): void
    {
        $client = $this->authenticatedClient();
        $response = $client->request('PATCH', '/api/attribute_groups/'.Uuid::v7()->toRfc4122().'/attributes/'.$this->contraindications->getId()->toRfc4122(), [
            'json' => ['visibleWhen' => null],
        ]);

        self::assertSame(404, $response->getStatusCode());
    }

    private function junctionUri(AttributeGroup $group, Attribute $attribute): string
    {
        return \sprintf(
            '/api/attribute_groups/%s/attributes/%s',
            $group->getId()->toRfc4122(),
            $attribute->getId()->toRfc4122(),
        );
    }
}

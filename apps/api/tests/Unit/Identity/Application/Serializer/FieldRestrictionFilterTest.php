<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application\Serializer;

use App\Identity\Application\Policy\AttributePermissionPolicy;
use App\Identity\Application\Serializer\FieldRestrictionFilter;
use App\Identity\Application\Serializer\RestrictedField;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\AttributePermission;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * RBAC-P3-012 (#675) — FieldRestrictionFilter unit coverage of the
 * response-shape composition (per PRD §3.5).
 */
final class FieldRestrictionFilterTest extends TestCase
{
    #[Test]
    public function restrictedAttributesAreRemovedFromResponse(): void
    {
        $editableId = Uuid::v7();
        $restrictedId = Uuid::v7();

        $policy = $this->policyMap([
            $editableId->toRfc4122() => AttributePermission::Edit,
            $restrictedId->toRfc4122() => AttributePermission::Restricted,
        ]);

        $filter = new FieldRestrictionFilter($policy);
        $result = $filter->restrict($this->user(), [
            $editableId->toRfc4122() => 'Czujnik',
            $restrictedId->toRfc4122() => 999.99,
        ]);

        self::assertCount(1, $result);
        self::assertArrayHasKey($editableId->toRfc4122(), $result);
        self::assertArrayNotHasKey($restrictedId->toRfc4122(), $result);
    }

    #[Test]
    public function viewPermissionProducesViewOnlyShape(): void
    {
        $id = Uuid::v7();
        $policy = $this->policyMap([$id->toRfc4122() => AttributePermission::View]);
        $filter = new FieldRestrictionFilter($policy);

        $result = $filter->restrict($this->user(), [$id->toRfc4122() => 1234.56]);

        $field = $result[$id->toRfc4122()];
        self::assertInstanceOf(RestrictedField::class, $field);
        self::assertSame(1234.56, $field->value);
        self::assertFalse($field->editable);
        self::assertSame(RestrictedField::REASON_VIEW_ONLY, $field->reason);
    }

    #[Test]
    public function editPermissionProducesEditableShapeWithoutReason(): void
    {
        $id = Uuid::v7();
        $policy = $this->policyMap([$id->toRfc4122() => AttributePermission::Edit]);
        $filter = new FieldRestrictionFilter($policy);

        $result = $filter->restrict($this->user(), [$id->toRfc4122() => 'value']);

        $field = $result[$id->toRfc4122()];
        self::assertTrue($field->editable);
        self::assertNull($field->reason);
        self::assertSame('value', $field->value);
    }

    #[Test]
    public function integrationVisibleFalseDemotesEditToViewOnly(): void
    {
        $id = Uuid::v7();
        $policy = $this->policyMap([$id->toRfc4122() => AttributePermission::Edit]);
        $filter = new FieldRestrictionFilter($policy);

        $result = $filter->restrict(
            $this->user(),
            [$id->toRfc4122() => 'value'],
            [$id->toRfc4122() => false],
        );

        $field = $result[$id->toRfc4122()];
        self::assertFalse($field->editable);
        self::assertSame(RestrictedField::REASON_INTEGRATION_HIDDEN, $field->reason);
    }

    #[Test]
    public function integrationVisibleFalseDoesNotPromoteRestrictedToVisible(): void
    {
        $id = Uuid::v7();
        $policy = $this->policyMap([$id->toRfc4122() => AttributePermission::Restricted]);
        $filter = new FieldRestrictionFilter($policy);

        $result = $filter->restrict(
            $this->user(),
            [$id->toRfc4122() => 'value'],
            [$id->toRfc4122() => false],
        );

        // Restricted from the policy stays removed even when the flag is
        // set — the flag only demotes edit/view, never promotes restricted.
        self::assertSame([], $result);
    }

    #[Test]
    public function missingIntegrationFlagDefaultsToTrue(): void
    {
        $id = Uuid::v7();
        $policy = $this->policyMap([$id->toRfc4122() => AttributePermission::Edit]);
        $filter = new FieldRestrictionFilter($policy);

        // Flag map is empty → defaults to true → edit stays editable.
        $result = $filter->restrict($this->user(), [$id->toRfc4122() => 'value']);

        self::assertTrue($result[$id->toRfc4122()]->editable);
        self::assertNull($result[$id->toRfc4122()]->reason);
    }

    #[Test]
    public function emptyMapReturnsEmpty(): void
    {
        $policy = $this->createMock(AttributePermissionPolicy::class);
        $policy->expects(self::never())->method('resolvePermission');
        $policy->method('resolvePermissions')->willReturn([]);

        $filter = new FieldRestrictionFilter($policy);

        self::assertSame([], $filter->restrict($this->user(), []));
    }

    #[Test]
    public function restrictedFieldToArrayShapeMatchesPrdSection35(): void
    {
        $field = RestrictedField::editable('foo');
        self::assertSame(['value' => 'foo', 'editable' => true], $field->toArray());

        $view = RestrictedField::viewOnly(42);
        self::assertSame(['value' => 42, 'editable' => false, 'reason' => 'view_only'], $view->toArray());

        $integration = RestrictedField::viewOnly('secret', RestrictedField::REASON_INTEGRATION_HIDDEN);
        self::assertSame(
            ['value' => 'secret', 'editable' => false, 'reason' => 'integration_visible'],
            $integration->toArray(),
        );
    }

    private function user(): User
    {
        return new User(
            new Tenant('alpha', 'Alpha'),
            'tester@alpha.localhost',
            'placeholder',
            ['ROLE_USER'],
            Uuid::v7(),
        );
    }

    /**
     * @param array<string, AttributePermission> $byAttribute
     */
    private function policyMap(array $byAttribute): AttributePermissionPolicy
    {
        $policy = $this->createStub(AttributePermissionPolicy::class);
        // #2794 — the filter resolves the whole field map in one call.
        $policy->method('resolvePermissions')
            ->willReturnCallback(static function (User $_user, array $attributeIds) use ($byAttribute): array {
                $resolved = [];
                /** @var Uuid $attributeId */
                foreach ($attributeIds as $attributeId) {
                    $key = $attributeId->toRfc4122();
                    $resolved[$key] = $byAttribute[$key] ?? AttributePermission::Restricted;
                }

                return $resolved;
            });

        return $policy;
    }
}

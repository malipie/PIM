<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Security;

use App\ApiConfigurator\Domain\Entity\ApiKey;
use App\ApiConfigurator\Domain\Entity\ApiProfile;
use App\ApiConfigurator\Domain\Enum\OutputFormat;
use App\Asset\Domain\Entity\Asset;
use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Channel\Domain\Entity\Channel;
use App\Identity\Domain\Entity\Permission;
use App\Identity\Domain\Entity\Role;
use App\Identity\Domain\Entity\User;
use App\Identity\Infrastructure\Security\ApiKeyVoter;
use App\Identity\Infrastructure\Security\ApiProfileVoter;
use App\Identity\Infrastructure\Security\AssetVoter;
use App\Identity\Infrastructure\Security\AttributeGroupVoter;
use App\Identity\Infrastructure\Security\AttributeVoter;
use App\Identity\Infrastructure\Security\CatalogObjectVoter;
use App\Identity\Infrastructure\Security\ChannelVoter;
use App\Identity\Infrastructure\Security\ObjectTypeVoter;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * Concrete-voter coverage for #41 — shape of each voter against the
 * resource × action permission grid plus tenant-isolation guard.
 *
 * Heavy lifting (token resolve, RBAC walk, tenant compare) lives in
 * AbstractRbacVoter — these cases just prove each subclass plumbs the
 * right resource string + subject class into the abstract base.
 */
final class RbacVotersTest extends TestCase
{
    #[Test]
    public function catalogObjectVoterGrantsReadWhenPermissionPresent(): void
    {
        $tenant = new Tenant('alpha', 'Alpha');
        $objectType = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $object = new CatalogObject($objectType, 'SKU-1');
        $object->assignTenant($tenant);

        $user = $this->userWithPermission($tenant, 'object', 'read');

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            new CatalogObjectVoter()->vote($this->token($user), $object, ['READ']),
        );
    }

    #[Test]
    public function catalogObjectVoterDeniesCrossTenantRead(): void
    {
        $alpha = new Tenant('alpha', 'Alpha');
        $beta = new Tenant('beta', 'Beta');
        $objectType = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $object = new CatalogObject($objectType, 'SKU-1');
        $object->assignTenant($beta);

        $user = $this->userWithPermission($alpha, 'object', 'read');

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            new CatalogObjectVoter()->vote($this->token($user), $object, ['READ']),
        );
    }

    #[Test]
    public function catalogObjectVoterGrantsClassLevelCreate(): void
    {
        $tenant = new Tenant('alpha', 'Alpha');
        $user = $this->userWithPermission($tenant, 'object', 'write');

        // Class-level subject (Post / GetCollection) — string FQCN, no instance.
        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            new CatalogObjectVoter()->vote($this->token($user), CatalogObject::class, ['CREATE']),
        );
    }

    #[Test]
    public function catalogObjectVoterDeniesWhenPermissionMissing(): void
    {
        $tenant = new Tenant('alpha', 'Alpha');
        // No `object.delete` permission seeded.
        $user = $this->userWithPermission($tenant, 'object', 'read');

        $object = new CatalogObject(
            new ObjectType('product', ObjectKind::Product, ['en' => 'Product']),
            'SKU-1',
        );
        $object->assignTenant($tenant);

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            new CatalogObjectVoter()->vote($this->token($user), $object, ['DELETE']),
        );
    }

    #[Test]
    public function objectTypeVoterRoutesToObjectTypeResource(): void
    {
        $tenant = new Tenant('alpha', 'Alpha');
        $type = new ObjectType('product', ObjectKind::Product, ['en' => 'Product']);
        $type->assignTenant($tenant);

        $user = $this->userWithPermission($tenant, 'object_type', 'read');

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            new ObjectTypeVoter()->vote($this->token($user), $type, ['READ']),
        );
    }

    #[Test]
    public function attributeVoterRoutesToAttributeResource(): void
    {
        $tenant = new Tenant('alpha', 'Alpha');
        $attribute = new Attribute('name', ['en' => 'Name'], AttributeType::Text);
        $attribute->assignTenant($tenant);

        $user = $this->userWithPermission($tenant, 'attribute', 'write');

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            new AttributeVoter()->vote($this->token($user), $attribute, ['UPDATE']),
        );
    }

    #[Test]
    public function attributeGroupVoterRoutesToAttributeGroupResource(): void
    {
        $tenant = new Tenant('alpha', 'Alpha');
        $group = new AttributeGroup('seo', ['en' => 'SEO'], 0);
        $group->assignTenant($tenant);

        $user = $this->userWithPermission($tenant, 'attribute_group', 'delete');

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            new AttributeGroupVoter()->vote($this->token($user), $group, ['DELETE']),
        );
    }

    // ADR-014 / MOD-02 (#894): Association voter was removed alongside the
    // dormant `object_associations` table. Relation links now run through
    // `ObjectRelation` (which inherits permissions from the parent objects
    // via CatalogObjectVoter + AttributeVoter).

    #[Test]
    public function channelVoterRoutesToChannelResource(): void
    {
        $tenant = new Tenant('alpha', 'Alpha');
        $channel = new Channel('ecommerce_pl', 'Polish webstore');
        $channel->assignTenant($tenant);

        $user = $this->userWithPermission($tenant, 'channel', 'read');

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            new ChannelVoter()->vote($this->token($user), $channel, ['READ']),
        );
    }

    #[Test]
    public function assetVoterRoutesToAssetResource(): void
    {
        $tenant = new Tenant('alpha', 'Alpha');
        $asset = new Asset('cover-1', 'cover.jpg', 'image/jpeg', 1024, 'alpha/cover-1/original.jpg');
        $asset->assignTenant($tenant);

        $user = $this->userWithPermission($tenant, 'asset', 'read');

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            new AssetVoter()->vote($this->token($user), $asset, ['READ']),
        );
    }

    #[Test]
    public function apiProfileVoterRoutesToApiProfileResource(): void
    {
        $tenant = new Tenant('alpha', 'Alpha');
        $profile = new ApiProfile('storefront', 'Storefront', OutputFormat::JSON_LD);
        $profile->assignTenant($tenant);

        $user = $this->userWithPermission($tenant, 'api_profile', 'read');

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            new ApiProfileVoter()->vote($this->token($user), $profile, ['READ']),
        );
    }

    #[Test]
    public function apiKeyVoterReusesApiProfileResource(): void
    {
        $tenant = new Tenant('alpha', 'Alpha');
        $key = new ApiKey('hashed', 'pim_test_a4f2', 'demo');
        $key->assignTenant($tenant);

        $user = $this->userWithPermission($tenant, 'api_profile', 'write');

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            new ApiKeyVoter()->vote($this->token($user), $key, ['CREATE']),
        );
    }

    #[Test]
    public function votersAbstainOnUnsupportedSubject(): void
    {
        $tenant = new Tenant('alpha', 'Alpha');
        $user = $this->userWithPermission($tenant, 'object', 'read');

        // Channel voter shouldn't speak about CatalogObject.
        $object = new CatalogObject(new ObjectType('product', ObjectKind::Product, ['en' => 'P']), 'X');
        $object->assignTenant($tenant);

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            new ChannelVoter()->vote($this->token($user), $object, ['READ']),
        );
    }

    /**
     * @param non-empty-string $resource
     * @param non-empty-string $action
     */
    private function userWithPermission(Tenant $tenant, string $resource, string $action): User
    {
        $user = new User($tenant, 'admin@'.$tenant->getCode().'.test', '', ['ROLE_USER']);
        $role = new Role('test_role_'.$resource.'_'.$action, 'Test Role');
        $permission = new Permission($resource, $action);
        $role->grantPermission($permission);
        $user->addRole($role);
        unset($action);

        return $user;
    }

    private function token(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main', $user->getRoles());
    }

    /**
     * Regression net if Symfony Security ever swaps `Voter::ACCESS_GRANTED`
     * (the base constant the abstract voter inherits from). All concrete
     * voters above route through the same base, so a single static check
     * here protects every voter at once.
     */
    public static function setUpBeforeClass(): void
    {
        self::assertSame(1, Voter::ACCESS_GRANTED);
    }
}

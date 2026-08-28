<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Security\Prd;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Identity\Application\PermissionResolverInterface;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\PermissionSet;
use App\Identity\Infrastructure\Security\Prd\AttributeVoter;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;

/**
 * RBAC-P3-004 (#667) — AttributeVoter unit coverage.
 */
final class AttributeVoterTest extends TestCase
{
    #[Test]
    public function grantsView(): void
    {
        $tenant = new Tenant('alpha', 'Alpha');
        $attribute = $this->attribute($tenant);
        $user = $this->user($tenant);

        $voter = new AttributeVoter($this->resolverWith($user, ['modeling.view']));

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->token($user), $attribute, ['view']),
        );
    }

    #[Test]
    public function grantsAddEditOnAttributesPermission(): void
    {
        $tenant = new Tenant('alpha', 'Alpha');
        $attribute = $this->attribute($tenant);
        $user = $this->user($tenant);

        $voter = new AttributeVoter($this->resolverWith($user, ['modeling.attributes.add_edit']));

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->token($user), $attribute, ['add_edit']),
        );
    }

    #[Test]
    public function grantsDeleteUnderTheSameCode(): void
    {
        $tenant = new Tenant('alpha', 'Alpha');
        $attribute = $this->attribute($tenant);
        $user = $this->user($tenant);

        $voter = new AttributeVoter($this->resolverWith($user, ['modeling.attributes.add_edit']));

        self::assertSame(
            VoterInterface::ACCESS_GRANTED,
            $voter->vote($this->token($user), $attribute, ['delete']),
        );
    }

    #[Test]
    public function deniesAddEditWithoutPermission(): void
    {
        $tenant = new Tenant('alpha', 'Alpha');
        $attribute = $this->attribute($tenant);
        $user = $this->user($tenant);

        $voter = new AttributeVoter($this->resolverWith($user, ['modeling.view']));

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->token($user), $attribute, ['add_edit']),
        );
    }

    #[Test]
    public function deniesAcrossTenants(): void
    {
        $alpha = new Tenant('alpha', 'Alpha');
        $beta = new Tenant('beta', 'Beta');
        $attribute = $this->attribute($beta);
        $user = $this->user($alpha);

        $voter = new AttributeVoter($this->resolverWith($user, ['modeling.attributes.add_edit']));

        self::assertSame(
            VoterInterface::ACCESS_DENIED,
            $voter->vote($this->token($user), $attribute, ['add_edit']),
        );
    }

    #[Test]
    public function abstainsOnUnknownAttribute(): void
    {
        $tenant = new Tenant('alpha', 'Alpha');
        $attribute = $this->attribute($tenant);
        $user = $this->user($tenant);

        $voter = new AttributeVoter($this->resolverWith($user, ['modeling.view']));

        self::assertSame(
            VoterInterface::ACCESS_ABSTAIN,
            $voter->vote($this->token($user), $attribute, ['UNHANDLED']),
        );
    }

    private function attribute(Tenant $tenant): Attribute
    {
        $attribute = new Attribute('color', ['en' => 'Color'], AttributeType::Text);
        $attribute->assignTenant($tenant);

        return $attribute;
    }

    private function user(Tenant $tenant): User
    {
        return new User($tenant, 'tester@'.$tenant->getCode().'.localhost', 'placeholder');
    }

    /**
     * @param list<string> $codes
     */
    private function resolverWith(User $user, array $codes): PermissionResolverInterface
    {
        $resolver = $this->createMock(PermissionResolverInterface::class);
        $resolver->method('resolve')->with($user)->willReturn(new PermissionSet($codes));

        return $resolver;
    }

    private function token(User $user): UsernamePasswordToken
    {
        return new UsernamePasswordToken($user, 'main');
    }
}

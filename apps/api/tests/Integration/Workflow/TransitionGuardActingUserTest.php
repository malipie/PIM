<?php

declare(strict_types=1);

namespace App\Tests\Integration\Workflow;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\ObjectKind;
use App\Identity\Application\PrdPermissionSeeder;
use App\Identity\Application\SeedTenantPrdRolesService;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\RoleRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Doctrine\Filter\TenantFilterConfigurator;
use App\Workflow\Application\ActingUserContext;
use App\Workflow\Contracts\ObjectEditorialWorkflow;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Workflow\Registry;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

/**
 * WFL-P1-01 (#2415, SEC) — the guard consults ActingUserContext when
 * there is no security token: the exact path the async bulk worker
 * (WFL-P1-05) takes, acting WITHIN the initiating user's permissions
 * (agent-loop model, ADR-0024 b). Also pins guard parity for any
 * non-HTTP `can()` caller — including the PATCH handler's
 * getEnabledTransitions() — independent of endpoint RBAC layers.
 */
final class TransitionGuardActingUserTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function guardBlocksActingUserWithoutPermissionAndAllowsSystem(): void
    {
        $em = $this->em();
        self::getContainer()->get(PrdPermissionSeeder::class)->seed();
        $em->flush();

        $tenant = new Tenant('alpha', 'Alpha Tenant');
        $em->persist($tenant);
        $em->flush();
        self::getContainer()->get(SeedTenantPrdRolesService::class)->seed($tenant);

        self::getContainer()->get(TenantContext::class)->set($tenant);
        self::getContainer()->get(TenantFilterConfigurator::class)->apply();

        $marketingRole = self::getContainer()->get(RoleRepositoryInterface::class)->findByCode('marketing', $tenant);
        \assert(null !== $marketingRole);
        $marketing = new User($tenant, 'marketing@alpha.localhost', 'irrelevant', ['ROLE_USER']);
        $marketing->addRole($marketingRole);
        $em->persist($marketing);
        $em->flush();

        $type = new ObjectType('product', ObjectKind::Product, ['pl' => 'Produkt']);
        $type->assignTenant($tenant);
        $em->persist($type);
        $object = new CatalogObject($type, 'WFL-GUARD-ACTING');
        $object->assignTenant($tenant);
        $em->persist($object);
        $em->flush();

        $workflow = self::getContainer()->get(Registry::class)->get($object, ObjectEditorialWorkflow::NAME);
        $actingUser = self::getContainer()->get(ActingUserContext::class);

        // Acting as marketing (worker path): submit allowed, publish blocked.
        $actingUser->set($marketing->getId());
        self::assertTrue($workflow->can($object, ObjectEditorialWorkflow::TRANSITION_SUBMIT_FOR_REVIEW));
        self::assertFalse($workflow->can($object, ObjectEditorialWorkflow::TRANSITION_PUBLISH));

        $blockers = [];
        foreach ($workflow->buildTransitionBlockerList($object, ObjectEditorialWorkflow::TRANSITION_PUBLISH) as $blocker) {
            $blockers[] = $blocker->getCode();
        }
        self::assertSame(['workflow.approve_reject'], $blockers, 'blocker must carry the missing permission code');

        // No actor at all = trusted system path (CLI/fixtures) — allowed.
        $actingUser->set(null);
        self::assertTrue($workflow->can($object, ObjectEditorialWorkflow::TRANSITION_PUBLISH));
    }

    private function em(): EntityManagerInterface
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);

        return $em;
    }
}

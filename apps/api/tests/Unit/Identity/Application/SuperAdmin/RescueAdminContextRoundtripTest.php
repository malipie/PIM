<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application\SuperAdmin;

use App\Identity\Application\SuperAdmin\RlsBypass;
use App\Identity\Application\SuperAdmin\SuperAdminContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\FilterCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * RBAC-P3-014 (#677) — additional smoke for the cross-tenant /
 * finally-restore round-trip when the closure mutates state in
 * between. Complements the focused unit suite in
 * {@see SuperAdminContextTest}.
 */
final class RescueAdminContextRoundtripTest extends TestCase
{
    #[Test]
    public function activeSuperAdminIdIsVisibleInsideClosureAndClearedAfter(): void
    {
        $filters = $this->createMock(FilterCollection::class);
        $filters->method('isEnabled')->willReturnOnConsecutiveCalls(true, false);
        $filters->expects(self::once())->method('disable');
        $filters->expects(self::once())->method('enable');

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('getFilters')->willReturn($filters);

        $context = new SuperAdminContext($em, $this->createStub(RlsBypass::class));
        $superAdminId = Uuid::v7();

        $insideActive = null;
        $insideId = null;

        $context->runCrossTenant($superAdminId, static function () use (&$insideActive, &$insideId, $context): void {
            $insideActive = $context->isActive();
            $insideId = $context->activeSuperAdminId();
        });

        self::assertTrue($insideActive);
        self::assertSame($superAdminId->toRfc4122(), $insideId?->toRfc4122());
        self::assertFalse($context->isActive());
        self::assertNull($context->activeSuperAdminId());
    }
}

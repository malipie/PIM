<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application\SuperAdmin;

use App\Identity\Application\SuperAdmin\RlsBypass;
use App\Identity\Application\SuperAdmin\SuperAdminContext;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query\FilterCollection;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Uid\Uuid;

/**
 * RBAC-P3-014 (#677) — SuperAdminContext unit coverage of the
 * filter-toggle + finally-restore semantics.
 */
final class SuperAdminContextTest extends TestCase
{
    #[Test]
    public function startsInactive(): void
    {
        $context = new SuperAdminContext(
            $this->createStub(EntityManagerInterface::class),
            $this->createStub(RlsBypass::class),
        );

        self::assertFalse($context->isActive());
        self::assertNull($context->activeSuperAdminId());
    }

    #[Test]
    public function activationDisablesEnabledTenantFilter(): void
    {
        $filters = $this->createMock(FilterCollection::class);
        $filters->expects(self::once())
            ->method('isEnabled')
            ->with(SuperAdminContext::FILTER_NAME)
            ->willReturn(true);
        $filters->expects(self::once())
            ->method('disable')
            ->with(SuperAdminContext::FILTER_NAME);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getFilters')->willReturn($filters);

        $context = new SuperAdminContext($em, $this->createStub(RlsBypass::class));
        $previous = $context->useCrossTenantMode(Uuid::v7());

        self::assertTrue($previous);
        self::assertTrue($context->isActive());
    }

    #[Test]
    public function activationLeavesDisabledFilterUntouched(): void
    {
        $filters = $this->createMock(FilterCollection::class);
        $filters->expects(self::once())
            ->method('isEnabled')
            ->with(SuperAdminContext::FILTER_NAME)
            ->willReturn(false);
        $filters->expects(self::never())->method('disable');

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getFilters')->willReturn($filters);

        $context = new SuperAdminContext($em, $this->createStub(RlsBypass::class));
        $previous = $context->useCrossTenantMode(Uuid::v7());

        self::assertFalse($previous);
        self::assertTrue($context->isActive());
    }

    #[Test]
    public function restoreReEnablesFilterWhenPreviouslyEnabled(): void
    {
        $filters = $this->createMock(FilterCollection::class);
        $filters->method('isEnabled')->willReturnOnConsecutiveCalls(true, false);
        $filters->expects(self::once())->method('disable');
        $filters->expects(self::once())->method('enable')->with(SuperAdminContext::FILTER_NAME);

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getFilters')->willReturn($filters);

        $context = new SuperAdminContext($em, $this->createStub(RlsBypass::class));
        $previous = $context->useCrossTenantMode(Uuid::v7());
        $context->restoreTenantScope($previous);

        self::assertFalse($context->isActive());
    }

    #[Test]
    public function restoreSkipsEnableWhenFilterWasNotActiveBefore(): void
    {
        $filters = $this->createMock(FilterCollection::class);
        $filters->method('isEnabled')->willReturn(false);
        $filters->expects(self::never())->method('enable');

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getFilters')->willReturn($filters);

        $context = new SuperAdminContext($em, $this->createStub(RlsBypass::class));
        $context->restoreTenantScope($context->useCrossTenantMode(Uuid::v7()));

        self::assertFalse($context->isActive());
    }

    #[Test]
    public function runCrossTenantRestoresScopeAfterCallback(): void
    {
        $filters = $this->createMock(FilterCollection::class);
        $filters->method('isEnabled')->willReturnOnConsecutiveCalls(true, false);
        $filters->expects(self::once())->method('disable');
        $filters->expects(self::once())->method('enable');

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getFilters')->willReturn($filters);

        $context = new SuperAdminContext($em, $this->createStub(RlsBypass::class));
        $result = $context->runCrossTenant(Uuid::v7(), static fn (): string => 'done');

        self::assertSame('done', $result);
        self::assertFalse($context->isActive());
    }

    #[Test]
    public function runCrossTenantRestoresScopeOnException(): void
    {
        $filters = $this->createMock(FilterCollection::class);
        $filters->method('isEnabled')->willReturnOnConsecutiveCalls(true, false);
        $filters->expects(self::once())->method('disable');
        $filters->expects(self::once())->method('enable');

        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getFilters')->willReturn($filters);

        $context = new SuperAdminContext($em, $this->createStub(RlsBypass::class));

        try {
            $context->runCrossTenant(Uuid::v7(), static function (): never {
                throw new RuntimeException('boom');
            });
        } catch (RuntimeException) {
            // expected
        }

        self::assertFalse($context->isActive());
    }

    /**
     * #2876 — the Doctrine filter is only half of tenant isolation. Postgres
     * FORCE RLS is the other half, and it does not care about PHP: it reads
     * `app.is_super_admin`. Provisioning a tenant writes the NEW tenant's
     * rows while the request has the CALLER's tenant pinned, so without this
     * the insert died with SQLSTATE 42501 — after the tenant row was already
     * committed. Created, no owner invitation, an error on screen.
     */
    #[Test]
    public function crossTenantModeLiftsTheRlsPolicyToo(): void
    {
        $filters = $this->createStub(FilterCollection::class);
        $filters->method('isEnabled')->willReturn(true);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getFilters')->willReturn($filters);

        $rls = $this->createMock(RlsBypass::class);
        $rls->expects(self::once())->method('enableSuperAdminBypass');
        $rls->expects(self::once())->method('disableSuperAdminBypass');

        new SuperAdminContext($em, $rls)->runCrossTenant(Uuid::v7(), static fn (): bool => true);
    }

    /**
     * Handing the privilege back is not optional. If the callback throws and
     * the bypass stays on, the rest of the request can write any tenant's
     * rows — a wider hole than the bug it was lifted for.
     */
    #[Test]
    public function theRlsBypassIsReleasedEvenWhenTheCallbackThrows(): void
    {
        $filters = $this->createStub(FilterCollection::class);
        $filters->method('isEnabled')->willReturn(true);
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getFilters')->willReturn($filters);

        $rls = $this->createMock(RlsBypass::class);
        $rls->expects(self::once())->method('disableSuperAdminBypass');

        $context = new SuperAdminContext($em, $rls);

        $this->expectException(RuntimeException::class);
        $context->runCrossTenant(Uuid::v7(), static function (): void {
            throw new RuntimeException('boom');
        });
    }
}

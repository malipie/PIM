<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Infrastructure\Http;

use App\Identity\Application\PermissionResolverInterface;
use App\Identity\Contracts\Attribute\NoPermissionRequired;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Exception\PermissionDeniedException;
use App\Identity\Domain\Rbac\PermissionSet;
use App\Identity\Infrastructure\Http\EndpointGuardListener;
use App\Shared\Domain\Tenant;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * Unit coverage for the RBAC-P3-001 runtime guard listener.
 *
 * Each test wires a tiny fixture controller (defined at the bottom of this
 * file) with the desired attribute combination, then dispatches a
 * ControllerArgumentsEvent and asserts the listener's effect: it lets the
 * request through, throws PermissionDeniedException, or throws LogicException
 * (dev fallback for missing attributes).
 */
final class EndpointGuardListenerTest extends TestCase
{
    #[Test]
    public function controllerWithoutAnyAttributeOnApiRouteThrowsInDevWhenStrict(): void
    {
        $listener = $this->listener(environment: 'dev', strictMode: true);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/has neither #\[RequiresPermission\] nor #\[NoPermissionRequired\]/');

        $listener->onControllerArguments($this->buildEvent(
            controller: [new EndpointGuardFixture(), 'unguardedAction'],
            path: '/api/some/route',
        ));
    }

    #[Test]
    public function controllerWithoutAnyAttributeOnlyLogsWhenStrictDisabled(): void
    {
        // Phase 6 retrofit window — strictMode defaults to false so the
        // ~130 baselined unannotated controllers do not break tests.
        $listener = $this->listener(environment: 'dev', strictMode: false);

        // Contract: the listener logs and returns; the absence of a thrown
        // exception IS the assertion.
        $this->expectNotToPerformAssertions();
        $listener->onControllerArguments($this->buildEvent(
            controller: [new EndpointGuardFixture(), 'unguardedAction'],
            path: '/api/some/route',
        ));
    }

    #[Test]
    public function controllerWithoutAttributeOnNonApiRouteIsSkipped(): void
    {
        $listener = $this->listener(environment: 'dev');

        // Non-API path: the guard short-circuits before any permission
        // check; returning without a throw IS the assertion.
        $this->expectNotToPerformAssertions();
        $listener->onControllerArguments($this->buildEvent(
            controller: [new EndpointGuardFixture(), 'unguardedAction'],
            path: '/_profiler',
        ));
    }

    #[Test]
    public function controllerWithoutAttributeInProdLogsAndAllows(): void
    {
        $listener = $this->listener(environment: 'prod');

        // In prod a missing attribute logs-and-allows (never the dev
        // LogicException); no throw IS the assertion.
        $this->expectNotToPerformAssertions();
        $listener->onControllerArguments($this->buildEvent(
            controller: [new EndpointGuardFixture(), 'unguardedAction'],
            path: '/api/some/route',
        ));
    }

    #[Test]
    public function noPermissionRequiredAttributeSkipsAllChecks(): void
    {
        $listener = $this->listener(
            security: $this->securityWithoutUser(),
            environment: 'dev',
        );

        // #[NoPermissionRequired] short-circuits the guard even with no
        // authenticated user; no throw IS the assertion.
        $this->expectNotToPerformAssertions();
        $listener->onControllerArguments($this->buildEvent(
            controller: [new EndpointGuardFixture(), 'publicAction'],
            path: '/api/auth/login',
        ));
    }

    #[Test]
    public function requiresPermissionWithoutAuthenticatedUserThrows(): void
    {
        $listener = $this->listener(
            security: $this->securityWithoutUser(),
            environment: 'dev',
        );

        $this->expectException(PermissionDeniedException::class);

        $listener->onControllerArguments($this->buildEvent(
            controller: [new EndpointGuardFixture(), 'guardedAction'],
            path: '/api/products/123',
        ));
    }

    #[Test]
    public function requiresPermissionWithUnauthorizedUserThrowsWithCorrectCode(): void
    {
        $user = $this->user();
        $listener = $this->listener(
            security: $this->securityWithUser($user),
            resolver: $this->resolverFor($user, codes: ['products.view']),
            environment: 'dev',
        );

        try {
            $listener->onControllerArguments($this->buildEvent(
                controller: [new EndpointGuardFixture(), 'guardedAction'],
                path: '/api/products/123',
            ));
            self::fail('Expected PermissionDeniedException');
        } catch (PermissionDeniedException $e) {
            self::assertSame('products.edit', $e->permissionCode);
        }
    }

    #[Test]
    public function requiresPermissionWithAuthorizedUserAllows(): void
    {
        $user = $this->user();
        $listener = $this->listener(
            security: $this->securityWithUser($user),
            resolver: $this->resolverFor($user, codes: ['products.edit']),
            environment: 'dev',
        );

        // The user holds products.edit (the code guardedAction requires), so
        // the guard must let the request through. The resolver stub throws if
        // it receives another principal; PermissionDeniedException or that
        // guard exception would fail this no-throw contract.
        $this->expectNotToPerformAssertions();
        $listener->onControllerArguments($this->buildEvent(
            controller: [new EndpointGuardFixture(), 'guardedAction'],
            path: '/api/products/123',
        ));
    }

    #[Test]
    public function closureControllerIsSkippedGracefully(): void
    {
        $listener = $this->listener(environment: 'dev');

        // A closure controller has no class to introspect for attributes;
        // the guard skips it without throwing — that IS the assertion.
        $this->expectNotToPerformAssertions();
        $listener->onControllerArguments($this->buildEvent(
            controller: static fn (): string => 'noop',
            path: '/api/products/123',
        ));
    }

    #[Test]
    public function subjectArgumentMissingThrowsLogicException(): void
    {
        $user = $this->user();
        $listener = $this->listener(
            security: $this->securityWithUser($user),
            resolver: $this->resolverFor($user, codes: ['products.edit']),
            environment: 'dev',
        );

        $this->expectException(LogicException::class);
        $this->expectExceptionMessageMatches('/declares subject "missing" but the controller has no argument/');

        $listener->onControllerArguments($this->buildEvent(
            controller: [new EndpointGuardFixture(), 'subjectActionMissingArgument'],
            path: '/api/products/123',
        ));
    }

    #[Test]
    public function subRequestIsSkipped(): void
    {
        $listener = $this->listener(environment: 'dev');

        /** @var callable(): mixed $callable */
        $callable = [new EndpointGuardFixture(), 'unguardedAction'];
        $event = new ControllerArgumentsEvent(
            $this->kernel(),
            $callable,
            [],
            new Request(server: ['REQUEST_URI' => '/api/products']),
            HttpKernelInterface::SUB_REQUEST,
        );

        // Sub-requests are skipped (the main request was already guarded);
        // returning without a throw IS the assertion.
        $this->expectNotToPerformAssertions();
        $listener->onControllerArguments($event);
    }

    private function listener(
        ?Security $security = null,
        ?PermissionResolverInterface $resolver = null,
        string $environment = 'prod',
        bool $strictMode = false,
    ): EndpointGuardListener {
        return new EndpointGuardListener(
            security: $security ?? $this->securityWithoutUser(),
            resolver: $resolver ?? $this->createStub(PermissionResolverInterface::class),
            logger: new NullLogger(),
            environment: $environment,
            strictMode: $strictMode,
        );
    }

    private function securityWithoutUser(): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn(null);

        return $security;
    }

    private function securityWithUser(User $user): Security
    {
        $security = $this->createStub(Security::class);
        $security->method('getUser')->willReturn($user);

        return $security;
    }

    /**
     * @param list<string> $codes
     */
    private function resolverFor(User $user, array $codes): PermissionResolverInterface
    {
        $resolver = $this->createStub(PermissionResolverInterface::class);
        $resolver->method('resolve')->willReturnCallback(
            static fn (User $candidate): PermissionSet => $candidate === $user
                ? new PermissionSet($codes)
                : throw new LogicException('Endpoint guard resolved permissions for the wrong user.'),
        );

        return $resolver;
    }

    private function user(): User
    {
        $tenant = new Tenant('demo', 'Demo');

        return new User($tenant, 'tester@demo.localhost', 'placeholder-hash');
    }

    /**
     * @param callable(): mixed|array{object, string} $controller
     */
    private function buildEvent(mixed $controller, string $path): ControllerArgumentsEvent
    {
        /** @var callable(): mixed $callable */
        $callable = $controller;

        return new ControllerArgumentsEvent(
            $this->kernel(),
            $callable,
            [],
            new Request(server: ['REQUEST_URI' => $path]),
            HttpKernelInterface::MAIN_REQUEST,
        );
    }

    private function kernel(): HttpKernelInterface
    {
        return $this->createStub(HttpKernelInterface::class);
    }
}

/**
 * Fixture controller — kept in the test file to scope the attribute-bearing
 * classes to test runs only. Symfony's controller discovery does not pick
 * up classes outside `src/` so these are invisible to the router.
 */
final class EndpointGuardFixture
{
    public function unguardedAction(): void
    {
    }

    #[NoPermissionRequired(reason: 'unit-test fixture')]
    public function publicAction(): void
    {
    }

    #[RequiresPermission(module: 'products', action: 'edit')]
    public function guardedAction(): void
    {
    }

    #[RequiresPermission(module: 'products', action: 'edit', subject: 'missing')]
    public function subjectActionMissingArgument(): void
    {
    }
}

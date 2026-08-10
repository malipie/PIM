<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\Messenger;

use App\Shared\Application\TenantAgnosticMessage;
use App\Shared\Application\TenantAwareMessage;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Repository\TenantRepositoryInterface;
use App\Shared\Domain\Tenant;
use App\Shared\Infrastructure\Messenger\Stamp\TenantStamp;
use App\Shared\Infrastructure\Messenger\TenantContextRebindingMiddleware;
use Closure;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use stdClass;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Middleware\MiddlewareInterface;
use Symfony\Component\Messenger\Middleware\StackInterface;
use Symfony\Component\Messenger\Stamp\ConsumedByWorkerStamp;
use Symfony\Component\Uid\Uuid;

/**
 * Unit coverage for the audit-driven async tenant rebinding middleware
 * (HIGH-002 / 2026-04-29).
 */
final class TenantContextRebindingMiddlewareTest extends TestCase
{
    #[Test]
    public function syncDispatchPassesThroughWithoutBindingTenant(): void
    {
        $context = new TenantContext();
        $repo = $this->makeRepo();
        $middleware = new TenantContextRebindingMiddleware($repo, $context);

        $envelope = new Envelope(new stdClass());
        $stack = $this->makeStack();

        $middleware->handle($envelope, $stack);

        self::assertNull($context->get(), 'Sync dispatch must not bind a tenant.');
        self::assertSame(0, $repo->lookups, 'Sync dispatch must not hit the repo.');
    }

    #[Test]
    public function asyncMessageWithStampBindsTenantAndClearsAfter(): void
    {
        $context = new TenantContext();
        $tenant = new Tenant('demo', 'Demo');
        $repo = $this->makeRepo($tenant);
        $middleware = new TenantContextRebindingMiddleware($repo, $context);

        $envelope = new Envelope(new stdClass())
            ->with(new ConsumedByWorkerStamp())
            ->with(new TenantStamp($tenant->getId()));

        $stack = $this->makeStack(static function () use ($context, $tenant): void {
            self::assertSame(
                $tenant->getId()->toRfc4122(),
                $context->get()?->getId()->toRfc4122(),
                'Tenant must be bound when the inner handler runs.',
            );
        });

        $middleware->handle($envelope, $stack);

        self::assertNull($context->get(), 'Context must be cleared after the handler returns.');
        self::assertSame(1, $repo->lookups);
    }

    #[Test]
    public function asyncMessageWithoutStampFallsBackToTenantAwareInterface(): void
    {
        $context = new TenantContext();
        $tenant = new Tenant('demo', 'Demo');
        $repo = $this->makeRepo($tenant);
        $middleware = new TenantContextRebindingMiddleware($repo, $context);

        $message = new class($tenant->getId()) implements TenantAwareMessage {
            public function __construct(private readonly Uuid $tenantId)
            {
            }

            public function tenantId(): Uuid
            {
                return $this->tenantId;
            }
        };

        $envelope = new Envelope($message)->with(new ConsumedByWorkerStamp());

        $stack = $this->makeStack(static function () use ($context): void {
            self::assertNotNull($context->get(), 'Tenant must be bound from message field.');
        });

        $middleware->handle($envelope, $stack);

        self::assertNull($context->get());
    }

    #[Test]
    public function asyncMessageWithNoTenantSourceThrows(): void
    {
        $context = new TenantContext();
        $middleware = new TenantContextRebindingMiddleware($this->makeRepo(), $context);

        $envelope = new Envelope(new stdClass())->with(new ConsumedByWorkerStamp());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('carries no tenant context');

        $middleware->handle($envelope, $this->makeStack());
    }

    /**
     * #2803 — the maintenance scheduler emits a payload that has no tenant
     * to declare. Before the marker existed the middleware rejected every
     * firing, so five daily jobs never ran once in production and the
     * worker killed itself after each fifth failure.
     */
    #[Test]
    public function asyncMessageMarkedTenantAgnosticPassesThroughWithoutTenant(): void
    {
        $context = new TenantContext();
        $middleware = new TenantContextRebindingMiddleware($this->makeRepo(), $context);

        $message = new class implements TenantAgnosticMessage {};
        $envelope = new Envelope($message)->with(new ConsumedByWorkerStamp());

        $seenInsideHandler = 'unset';
        $middleware->handle($envelope, $this->makeStack(static function () use ($context, &$seenInsideHandler): void {
            $seenInsideHandler = $context->get();
        }));

        self::assertNull($seenInsideHandler, 'tenant-agnostic work must run with an empty context, not a stale one');
        self::assertNull($context->get());
    }

    /**
     * The marker must not become a way to smuggle tenant-scoped work past
     * the guard: an unmarked message with no tenant still fails loudly.
     */
    #[Test]
    public function markerDoesNotWeakenTheGuardForOtherMessages(): void
    {
        $context = new TenantContext();
        $middleware = new TenantContextRebindingMiddleware($this->makeRepo(), $context);

        $envelope = new Envelope(new stdClass())->with(new ConsumedByWorkerStamp());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('carries no tenant context');

        $middleware->handle($envelope, $this->makeStack());
    }

    /**
     * A tenant-agnostic handler must not inherit whatever tenant the
     * previous message on the same worker boot left behind.
     */
    #[Test]
    public function tenantAgnosticMessageDoesNotInheritPreviousTenant(): void
    {
        $tenant = new Tenant('alpha', 'Alpha');
        $context = new TenantContext();
        $context->set($tenant);

        $middleware = new TenantContextRebindingMiddleware($this->makeRepo($tenant), $context);

        $message = new class implements TenantAgnosticMessage {};
        $envelope = new Envelope($message)->with(new ConsumedByWorkerStamp());

        $seenInsideHandler = 'unset';
        $middleware->handle($envelope, $this->makeStack(static function () use ($context, &$seenInsideHandler): void {
            $seenInsideHandler = $context->get();
        }));

        self::assertNull(
            $seenInsideHandler,
            'a stale tenant would leave the Doctrine filter armed and scope a global sweep to one tenant',
        );
        self::assertNull($context->get());
    }

    #[Test]
    public function asyncMessageWithUnknownTenantThrows(): void
    {
        $context = new TenantContext();
        $repo = $this->makeRepo();
        $middleware = new TenantContextRebindingMiddleware($repo, $context);

        $envelope = new Envelope(new stdClass())
            ->with(new ConsumedByWorkerStamp())
            ->with(new TenantStamp(Uuid::v7()));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no longer exists');

        $middleware->handle($envelope, $this->makeStack());
    }

    #[Test]
    public function contextIsClearedEvenWhenInnerHandlerThrows(): void
    {
        $context = new TenantContext();
        $tenant = new Tenant('demo', 'Demo');
        $repo = $this->makeRepo($tenant);
        $middleware = new TenantContextRebindingMiddleware($repo, $context);

        $envelope = new Envelope(new stdClass())
            ->with(new ConsumedByWorkerStamp())
            ->with(new TenantStamp($tenant->getId()));

        $stack = $this->makeStack(static function (): void {
            throw new RuntimeException('handler boom');
        });

        try {
            $middleware->handle($envelope, $stack);
            self::fail('Inner handler exception should have propagated.');
        } catch (RuntimeException $e) {
            self::assertSame('handler boom', $e->getMessage());
        }

        self::assertNull($context->get(), 'Context must be cleared even when handler throws.');
    }

    private function makeRepo(?Tenant $tenant = null): InMemoryTenantRepoStub
    {
        return new InMemoryTenantRepoStub($tenant);
    }

    /**
     * @param (Closure(): void)|null $onNext
     */
    private function makeStack(?Closure $onNext = null): StackInterface
    {
        $next = new class implements MiddlewareInterface {
            /** @var (Closure(): void)|null */
            public ?Closure $onHandle = null;

            public function handle(Envelope $envelope, StackInterface $stack): Envelope
            {
                if (null !== $this->onHandle) {
                    ($this->onHandle)();
                }

                return $envelope;
            }
        };
        $next->onHandle = $onNext;

        return new class($next) implements StackInterface {
            public function __construct(private readonly MiddlewareInterface $next)
            {
            }

            public function next(): MiddlewareInterface
            {
                return $this->next;
            }
        };
    }
}

/**
 * @internal
 */
final class InMemoryTenantRepoStub implements TenantRepositoryInterface
{
    public int $lookups = 0;

    public function __construct(private ?Tenant $tenant = null)
    {
    }

    public function findById(Uuid $id): ?Tenant
    {
        ++$this->lookups;
        if (null === $this->tenant) {
            return null;
        }

        return $this->tenant->getId()->toRfc4122() === $id->toRfc4122() ? $this->tenant : null;
    }

    public function findByCode(string $code): ?Tenant
    {
        return null;
    }

    public function save(Tenant $tenant): void
    {
    }

    public function remove(Tenant $tenant): void
    {
    }

    public function findAllOrderedByCode(): array
    {
        return null === $this->tenant ? [] : [$this->tenant];
    }
}

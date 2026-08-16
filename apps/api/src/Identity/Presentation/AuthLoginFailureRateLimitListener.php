<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;

/**
 * #2881 — the consuming half of the `/api/auth/login` limiter.
 *
 * {@see AuthLoginRateLimitListener} only peeks at the bucket on the way
 * in; this is where the budget is actually spent, and only on a login
 * that failed. Anti-bruteforce counts wrong passwords, not people: the
 * previous "every attempt ticks" rule locked out an administrator who
 * switched between three of their own accounts from one address.
 *
 * Scoped to the `login` firewall by name. `LoginFailureEvent` is also
 * dispatched by the `api` firewall — an expired JWT, a bad API key, a
 * revoked token — and none of those are password guessing against this
 * endpoint. Counting them here would let an integration with a stale key
 * exhaust the humans' login budget.
 */
#[AsEventListener(event: LoginFailureEvent::class)]
final readonly class AuthLoginFailureRateLimitListener
{
    /**
     * Firewall name from `security.yaml`; the one whose pattern is
     * `^/api/auth/login$`.
     */
    private const string FIREWALL = 'login';

    public function __construct(
        private RateLimiterFactoryInterface $authLoginLimiter,
    ) {
    }

    public function __invoke(LoginFailureEvent $event): void
    {
        if (self::FIREWALL !== $event->getFirewallName()) {
            return;
        }

        $this->authLoginLimiter
            ->create($event->getRequest()->getClientIp() ?? 'unknown')
            ->consume();
    }
}

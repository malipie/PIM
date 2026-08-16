<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Rate limiter on `/api/auth/login` (#48 / 0.4.8).
 *
 * Five **failed** attempts per IP per 15-minute fixed window; the next
 * request from that IP gets a 429 with a `Retry-After` header.
 *
 * #2881 — until now every attempt consumed the budget, successes
 * included. That is not anti-bruteforce, it is a cap on logging in: an
 * administrator moving between three of their own accounts from one
 * address locked themselves out for a quarter of an hour, and so did
 * anyone verifying a deployment from the same office IP. Production logs
 * showed the shape plainly — five 200s, then a 429. Brute force is made
 * of *failures*, so failures are what this counts;
 * {@see AuthLoginFailureRateLimitListener} does the consuming.
 *
 * A success does not consume the budget, and it does not clear it
 * either: failures already recorded keep counting down their window, so
 * landing one guess mid-run does not re-arm the limiter for an attacker.
 * Only `pim:security:unblock-ip` (#97) and the window expiring reset it.
 *
 * This listener therefore only *peeks*. `consume(0)` reports the bucket
 * without saving it, but its accepted flag is not usable for a
 * zero-token request — Symfony hardcodes `true` on that branch — so the
 * decision reads the remaining tokens instead.
 *
 * IP fingerprinting is the only signal available on a pre-auth POST
 * (no JWT yet, no session). It's a coarse signal — corporate NATs
 * + privacy proxies hash to one IP — but it's the standard defence
 * for brute-force prevention. The dedicated hardening ticket (0.11.2)
 * adds CIDR allowlisting + per-email lockouts on top.
 *
 * The listener fires on `kernel.request` with priority high enough
 * to run before Lexik's `JsonLogin` authenticator. We do not swallow
 * the limiter's logic into the authenticator itself because Lexik
 * is vendor code and the limiter is a cross-cutting concern that
 * future endpoints (`/api/auth/refresh`, `/api/agent/run`) will
 * register similar listeners against.
 */
#[AsEventListener(event: RequestEvent::class, priority: 32)]
final readonly class AuthLoginRateLimitListener
{
    public function __construct(
        private RateLimiterFactoryInterface $authLoginLimiter,
    ) {
    }

    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ('POST' !== $request->getMethod()) {
            return;
        }

        if ('/api/auth/login' !== $request->getPathInfo()) {
            return;
        }

        $limiter = $this->authLoginLimiter->create($request->getClientIp() ?? 'unknown');
        $peeked = $limiter->consume(0);
        if ($peeked->getRemainingTokens() > 0) {
            return;
        }

        $retryAfter = $peeked->getRetryAfter();
        $secondsUntilReset = max(1, $retryAfter->getTimestamp() - time());

        throw new TooManyRequestsHttpException(
            $secondsUntilReset,
            'Too many login attempts. Try again later.',
            null,
            0,
            ['Retry-After' => (string) $secondsUntilReset],
        );
    }
}

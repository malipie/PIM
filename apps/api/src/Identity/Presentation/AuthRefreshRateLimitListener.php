<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * Rate limiter on `/api/auth/refresh` (#97 / 0.11.2).
 *
 * 30 FAILED refreshes per IP per 1-hour sliding window.
 *
 * #2881 — the estimate this was built on ("~5 legitimate calls per hour
 * per tab") does not survive contact with the admin: every page load runs
 * `authProvider.check()`, which refreshes. An operator working through the
 * panel exhausts thirty in minutes, and then the SPA bounces them to the
 * login screen, where logging in works and the next navigation bounces
 * them again — the shape reported from production, which reads as "my
 * role was taken away".
 *
 * A successful refresh is a browser doing its job, so it no longer spends
 * the budget. What the limiter is actually for — a stolen-cookie replay
 * loop polling `/refresh` — produces *failures*, and those still count:
 * {@see AuthRefreshFailureRateLimitListener} consumes on a 4xx.
 *
 * A success does not clear the bucket either, so recorded failures keep
 * counting down their window.
 *
 * Mirrors {@see AuthLoginRateLimitListener} — runs at priority 32 so
 * the limiter checks before the Lexik refresh-cookie consumer.
 */
#[AsEventListener(event: RequestEvent::class, priority: 32)]
final readonly class AuthRefreshRateLimitListener
{
    public function __construct(
        private RateLimiterFactoryInterface $authRefreshLimiter,
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

        if ('/api/auth/refresh' !== $request->getPathInfo()) {
            return;
        }

        $limiter = $this->authRefreshLimiter->create($request->getClientIp() ?? 'unknown');
        // consume(0) reports the bucket without spending it. The accepted
        // flag is unusable for a zero-token request — Symfony hardcodes
        // `true` on that branch — so read the remaining tokens.
        $peeked = $limiter->consume(0);
        if ($peeked->getRemainingTokens() > 0) {
            return;
        }

        $retryAfter = $peeked->getRetryAfter();
        $secondsUntilReset = max(1, $retryAfter->getTimestamp() - time());

        throw new TooManyRequestsHttpException(
            $secondsUntilReset,
            'Too many refresh-token attempts. Try again later.',
            null,
            0,
            ['Retry-After' => (string) $secondsUntilReset],
        );
    }
}

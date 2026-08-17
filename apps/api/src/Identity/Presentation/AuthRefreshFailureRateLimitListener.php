<?php

declare(strict_types=1);

namespace App\Identity\Presentation;

use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

/**
 * #2881 — the consuming half of the `/api/auth/refresh` limiter.
 *
 * {@see AuthRefreshRateLimitListener} only peeks on the way in; the budget
 * is spent here, and only on a refresh that failed. A refresh succeeds
 * because the browser holds a valid rotating cookie — that is the SPA
 * working, not an attack, and it happens on every page load.
 *
 * A refresh fails when the cookie is missing, expired, already used or
 * revoked. That is the signal the limiter was built for: Lexik's rotation
 * revokes the whole family on reuse, so a replay loop produces a stream of
 * failures and runs the bucket down exactly as intended.
 *
 * Unlike the login pair there is no `LoginFailureEvent` to hang this on —
 * `/api/auth/refresh` is Lexik's own controller, not an authenticator — so
 * the outcome is read off the response instead. 429 is excluded: refusing
 * a request the limiter itself refused would make the window renew itself
 * for as long as a client keeps retrying.
 */
#[AsEventListener(event: ResponseEvent::class)]
final readonly class AuthRefreshFailureRateLimitListener
{
    public function __construct(
        private RateLimiterFactoryInterface $authRefreshLimiter,
    ) {
    }

    public function __invoke(ResponseEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if ('POST' !== $request->getMethod() || '/api/auth/refresh' !== $request->getPathInfo()) {
            return;
        }

        $status = $event->getResponse()->getStatusCode();
        if ($status < Response::HTTP_BAD_REQUEST || Response::HTTP_TOO_MANY_REQUESTS === $status) {
            return;
        }

        $this->authRefreshLimiter
            ->create($request->getClientIp() ?? 'unknown')
            ->consume();
    }
}

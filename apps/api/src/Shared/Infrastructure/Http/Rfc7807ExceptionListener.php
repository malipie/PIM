<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

use App\Shared\Infrastructure\Audit\AuditWriteFailedException;
use League\Flysystem\FilesystemException;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * W2-7 / AUD-042 — single RFC 7807 error contract for the custom
 * controllers under `/api/*`.
 *
 * API Platform native resources already emit clean RFC 7807
 * (`application/problem+json`, members `type`/`title`/`status`/`detail`,
 * validation errors carrying `violations`). The ~157 custom `#[Route]`
 * controllers do NOT flow through an API Platform resource, so their
 * `HttpExceptionInterface` throws fall through to Symfony's stock error
 * handling: a FlattenException JSON shape with `type` pointing at RFC 2616,
 * the exception FQCN in `class` (information leak) and a full `trace` in
 * debug — and, with no `Accept` header, an HTML error page (routing
 * structure leak). Integrators were handed two different error contracts
 * on one API.
 *
 * This subscriber normalises that second contract to match API Platform:
 * for `/api/*` requests that API Platform does NOT own, it maps the
 * thrown {@see HttpExceptionInterface} onto an `application/problem+json`
 * RFC 7807 document with no `class`/`trace`, regardless of the `Accept`
 * header (so a missing `Accept` never yields HTML).
 *
 * It deliberately does nothing for:
 *   - requests API Platform handles (its `ExceptionListener` already
 *     produces RFC 7807 — see the `_api_resource_class` / `_api_respond` /
 *     `_graphql` gate, mirroring API Platform's own check);
 *   - validation exceptions carrying a violation list (left to API
 *     Platform so the `violations` array survives);
 *   - non-`/api` paths (the admin SPA and Mercure live on other origins;
 *     HTML profiler pages stay useful in dev);
 *   - authentication / access-denied flows, which the security component
 *     answers with its own response before/around this event.
 *
 * Runs at a high priority — below {@see PermissionDeniedProblemListener}
 * (128) so its richer permission payload still wins, and well above API
 * Platform's `ExceptionListener` (-96) / Symfony's `ErrorListener` (-128)
 * so it claims custom-route errors before HTML/FlattenException rendering.
 */
final class Rfc7807ExceptionListener implements EventSubscriberInterface
{
    public function __construct(private readonly bool $debug = false)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [KernelEvents::EXCEPTION => ['onException', 64]];
    }

    public function onException(ExceptionEvent $event): void
    {
        // A higher-priority listener already produced a response — defer to
        // it. In particular {@see PermissionDeniedProblemListener} (priority
        // 128) emits a richer 403 carrying `permission_required`; since
        // `PermissionDeniedException extends AccessDeniedHttpException` it
        // would otherwise be caught below and flattened. Setting a response
        // on an ExceptionEvent does not stop propagation, so this guard is
        // what keeps the specialised payload intact.
        if (null !== $event->getResponse()) {
            return;
        }

        $throwable = $event->getThrowable();
        $request = $event->getRequest();

        // #2221 (chaos dry-run #2137) — an object-storage outage (MinIO/S3
        // unreachable mid-write) escapes Flysystem as a FilesystemException,
        // which is NOT an HttpExceptionInterface: pre-fix it fell through to
        // the stock HTML 500 page even on `/api/*`. Storage loss is an
        // infrastructure outage, not a domain error, so every storage-backed
        // custom route maps it to a 503 problem+json here. The `detail` is a
        // FIXED string — Flysystem messages embed storage keys/paths
        // (information leak, AUD-042); logging already happened in Symfony's
        // ErrorListener::logKernelException at higher priority.
        // #3045 — an audit write that failed took the whole transaction with
        // it (Postgres aborts, COMMIT silently downgrades to ROLLBACK). The
        // request MUST NOT look successful: nothing was saved. `detail` stays
        // a fixed string per AUD-042 — the audit table name is an internal
        // detail and already reached the log through
        // ErrorListener::logKernelException at higher priority.
        if ($throwable instanceof AuditWriteFailedException && $this->isCustomApiRequest($request)) {
            $event->setResponse(new JsonResponse(
                data: [
                    'type' => $this->typeForStatus(Response::HTTP_INTERNAL_SERVER_ERROR),
                    'title' => Response::$statusTexts[Response::HTTP_INTERNAL_SERVER_ERROR],
                    'status' => Response::HTTP_INTERNAL_SERVER_ERROR,
                    'detail' => 'The change could not be recorded in the audit trail and was rolled back — nothing was saved. Report this: the instance needs a schema check.',
                ],
                status: Response::HTTP_INTERNAL_SERVER_ERROR,
                headers: ['content-type' => 'application/problem+json'],
            ));

            return;
        }

        if ($throwable instanceof FilesystemException && $this->isCustomApiRequest($request)) {
            $event->setResponse(new JsonResponse(
                data: [
                    'type' => $this->typeForStatus(Response::HTTP_SERVICE_UNAVAILABLE),
                    'title' => Response::$statusTexts[Response::HTTP_SERVICE_UNAVAILABLE],
                    'status' => Response::HTTP_SERVICE_UNAVAILABLE,
                    'detail' => 'Object storage is temporarily unavailable. Try again later.',
                ],
                status: Response::HTTP_SERVICE_UNAVAILABLE,
                headers: ['content-type' => 'application/problem+json'],
            ));

            return;
        }

        // Only HTTP exceptions carry a status + safe public message. Domain
        // exceptions (500s) keep Symfony's handling so they are logged and
        // rendered as opaque 500s without a leaked message.
        if (!$throwable instanceof HttpExceptionInterface) {
            return;
        }

        if (!$this->isCustomApiRequest($request)) {
            return;
        }

        $status = $throwable->getStatusCode();

        $payload = [
            'type' => $this->typeForStatus($status),
            'title' => Response::$statusTexts[$status] ?? 'An error occurred',
            'status' => $status,
            'detail' => $this->detailFor($throwable, $status),
        ];

        // `class` and `trace` are never emitted — even in debug — because the
        // exception FQCN is an information leak (AUD-042). Symfony's profiler
        // and logs remain the place to inspect the stack in dev.

        $response = new JsonResponse(
            data: $payload,
            status: $status,
            headers: ['content-type' => 'application/problem+json'],
        );

        // Preserve transport headers the HttpException advertises
        // (e.g. `Retry-After` on 429, `Allow` on 405).
        $response->headers->add($throwable->getHeaders());
        $response->headers->set('content-type', 'application/problem+json');

        $event->setResponse($response);
    }

    /**
     * True only for `/api/*` requests that API Platform does NOT manage.
     *
     * Mirrors API Platform's own `ExceptionListener` gate: a request is
     * API-Platform-managed when it carries `_api_resource_class` (set by
     * API Platform routing), `_api_respond`, or `_graphql`. Those keep
     * their native RFC 7807 pipeline; everything else under `/api/` is a
     * custom controller this listener normalises.
     */
    private function isCustomApiRequest(Request $request): bool
    {
        $path = $request->getPathInfo();
        if (!str_starts_with($path, '/api/') && '/api' !== $path) {
            return false;
        }

        if (null !== $request->attributes->get('_api_resource_class')) {
            return false;
        }

        if ($request->attributes->getBoolean('_api_respond')) {
            return false;
        }

        if ($request->attributes->getBoolean('_graphql')) {
            return false;
        }

        return true;
    }

    /**
     * RFC 7807 `type` URI. Mirrors the API Platform shape (`/errors/{status}`)
     * so integrators see one scheme across the whole API instead of the
     * old RFC 2616 sentinel.
     */
    private function typeForStatus(int $status): string
    {
        return \sprintf('/errors/%d', $status);
    }

    /**
     * Human-readable `detail`. The custom controllers throw
     * `HttpException` with an operator-authored message (e.g.
     * "code is required."); that message is safe to surface and is the
     * actionable part for an integrator. For 5xx we fall back to the
     * generic status text so internal failure strings never leak; in debug
     * the real message is kept to aid local debugging.
     */
    private function detailFor(HttpExceptionInterface $throwable, int $status): string
    {
        // `HttpExceptionInterface extends \Throwable`, so `getMessage()` is
        // always available.
        $message = $throwable->getMessage();

        if ($status >= 500 && !$this->debug) {
            return Response::$statusTexts[$status] ?? 'An error occurred';
        }

        return '' !== $message
            ? $message
            : (Response::$statusTexts[$status] ?? 'An error occurred');
    }
}

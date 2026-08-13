<?php

declare(strict_types=1);

namespace App\Identity\Infrastructure\Http;

use App\Identity\Application\PermissionResolverInterface;
use App\Identity\Contracts\Attribute\NoPermissionRequired;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Exception\PermissionDeniedException;
use Closure;
use LogicException;
use Psr\Log\LoggerInterface;
use ReflectionAttribute;
use ReflectionMethod;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\ControllerArgumentsEvent;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * RBAC-P3-001 (#664) — runtime enforcement of `#[RequiresPermission]`.
 *
 * Subscribes to `kernel.controller_arguments` (not `kernel.controller`) so
 * argument value resolvers have already mapped route params / entity
 * resolvers / `ValueResolver`s into typed PHP arguments by the time we
 * read them. That lets the listener locate the `subject` argument by name
 * and hand it to a Voter via `Security::isGranted()` exactly as the
 * controller method itself would receive it.
 *
 * Flow per main request:
 *   1. resolve `[$obj, $method]` (skip closures / non-method controllers)
 *   2. read `#[RequiresPermission]` + `#[NoPermissionRequired]` attrs
 *   3. if neither is present and the path is under `/api/*`:
 *        - dev/test env: throw LogicException (PHPStan-rule escapee)
 *        - prod env: log a warning, allow through (PR-blocking PHPStan
 *          rule is the primary gate; runtime is defence-in-depth)
 *   4. if `#[NoPermissionRequired]` is present: allow
 *   5. for each `#[RequiresPermission]`:
 *        - resolve the User (token must hold one — anonymous = 403)
 *        - check the permission code via PermissionResolver
 *        - if a `subject` argument name is declared: locate the resolved
 *          argument by name and delegate to `Security::isGranted($action, $subject)`
 *          so per-resource Voters can apply tenant boundary / ownership /
 *          workflow-state / per-attribute checks (Phase 3 #665..#674)
 *
 * Sub-requests (ESI fragments, error-page rendering) are intentionally
 * skipped — the main request has already gated the user, and a child
 * fragment may legitimately render with no controller of its own.
 */
final class EndpointGuardListener implements EventSubscriberInterface
{
    public function __construct(
        private readonly Security $security,
        private readonly PermissionResolverInterface $resolver,
        private readonly LoggerInterface $logger,
        private readonly string $environment,
        /**
         * Strict mode controls what happens when an `/api/*` controller has
         * neither `#[RequiresPermission]` nor `#[NoPermissionRequired]`.
         *
         * - `false` (default): log a warning and permit. Required during the
         *   Phase 6 retrofit window — the PHPStan rule from RBAC-P1-010 has
         *   ~130 baselined controllers waiting on ticket #720-#722 to gain
         *   their attribute. Flipping strict on before that retrofit would
         *   break every test exercising a baselined endpoint.
         * - `true`: throw `LogicException` in dev/test, log+permit in prod.
         *   Operator flips this to `true` once the PHPStan baseline reaches
         *   zero entries, locking the contract at runtime.
         */
        private readonly bool $strictMode = false,
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 0: the SecurityListener (priority 8) has already populated
        // the token; argument resolvers (priority 0 on kernel.controller_arguments)
        // have run by the time this listener fires.
        return [KernelEvents::CONTROLLER_ARGUMENTS => ['onControllerArguments', 0]];
    }

    public function onControllerArguments(ControllerArgumentsEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $reflection = $this->resolveReflectionMethod($event->getController());
        if (null === $reflection) {
            // Closures / non-method callables cannot carry PHP attributes —
            // they bypass the static PHPStan rule too, and nothing safe can
            // be inferred at runtime. Phase 6 ticket retrofits these to
            // proper controllers; for now we let them through.
            return;
        }

        $requiresAttributes = $reflection->getAttributes(RequiresPermission::class);
        $noPermAttributes = $reflection->getAttributes(NoPermissionRequired::class);

        if ([] !== $noPermAttributes) {
            return;
        }

        if ([] === $requiresAttributes) {
            $path = $event->getRequest()->getPathInfo();
            if (!str_starts_with($path, '/api/')) {
                return;
            }

            $location = $reflection->getDeclaringClass()->getName().'::'.$reflection->getName();

            if ($this->strictMode && \in_array($this->environment, ['dev', 'test'], true)) {
                throw new LogicException(\sprintf(
                    'Controller %s has neither #[RequiresPermission] nor #[NoPermissionRequired]. '
                    .'Every /api endpoint must declare one (RBAC-P1-010 PHPStan rule should have caught this).',
                    $location,
                ));
            }

            $this->logger->warning('Endpoint missing RBAC attribute', [
                'controller' => $location,
                'path' => $path,
            ]);

            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            // Anonymous or non-domain principal hitting a guarded endpoint.
            // The firewall normally rejects this earlier with 401; surfacing
            // 403 here keeps the contract symmetric with authenticated-but-
            // unprivileged users so SPA logic does not need to special-case.
            $codes = array_map(
                static fn (ReflectionAttribute $attr): string => $attr->newInstance()->permissionCode(),
                $requiresAttributes,
            );
            throw new PermissionDeniedException(implode(',', $codes));
        }

        $permissions = $this->resolver->resolve($user);
        /** @var list<mixed> $arguments */
        $arguments = array_values($event->getArguments());
        $argumentMap = $this->buildArgumentMap($reflection, $arguments);

        foreach ($requiresAttributes as $attribute) {
            /** @var RequiresPermission $requirement */
            $requirement = $attribute->newInstance();
            $permissionCode = $requirement->permissionCode();

            // #2838 — `anyOf` lets one requirement accept alternative
            // grants; without it the only way to express "either of these"
            // would be two attributes, which the loop above ANDs.
            $satisfied = false;
            foreach ($requirement->acceptedCodes() as $accepted) {
                if ($permissions->has($accepted)) {
                    $satisfied = true;
                    break;
                }
            }
            if (!$satisfied) {
                throw new PermissionDeniedException($permissionCode);
            }

            if (null === $requirement->subject) {
                continue;
            }

            if (!\array_key_exists($requirement->subject, $argumentMap)) {
                throw new LogicException(\sprintf(
                    '#[RequiresPermission] on %s::%s declares subject "%s" but the controller has no argument with that name.',
                    $reflection->getDeclaringClass()->getName(),
                    $reflection->getName(),
                    $requirement->subject,
                ));
            }

            $subject = $argumentMap[$requirement->subject];
            if (!$this->security->isGranted($requirement->action, $subject)) {
                throw new PermissionDeniedException($permissionCode);
            }
        }
    }

    /**
     * Resolves `[$obj, $method]` controllers — the form Symfony emits for
     * every `AbstractController` subclass. Closures / arrays whose first
     * element is a class name (rare) cannot carry method attributes and
     * are returned as null.
     */
    private function resolveReflectionMethod(mixed $controller): ?ReflectionMethod
    {
        if (\is_array($controller) && 2 === \count($controller) && \is_object($controller[0]) && \is_string($controller[1])) {
            return new ReflectionMethod($controller[0], $controller[1]);
        }

        if (\is_object($controller) && !$controller instanceof Closure && method_exists($controller, '__invoke')) {
            return new ReflectionMethod($controller, '__invoke');
        }

        return null;
    }

    /**
     * Walks ReflectionMethod parameters in declaration order, zipping them
     * with the resolved positional arguments from the event. Result is a
     * `name => value` map used to locate `#[RequiresPermission(subject: '...')]`.
     *
     * @param list<mixed> $arguments
     *
     * @return array<string, mixed>
     */
    private function buildArgumentMap(ReflectionMethod $reflection, array $arguments): array
    {
        $map = [];
        foreach ($reflection->getParameters() as $index => $parameter) {
            if (!\array_key_exists($index, $arguments)) {
                continue;
            }
            $map[$parameter->getName()] = $arguments[$index];
        }

        return $map;
    }
}

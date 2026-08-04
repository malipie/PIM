<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Application\Sso\SsoConfigCipher;
use App\Identity\Application\SsoProviderResponseBuilder;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Domain\Entity\SsoProvider;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Repository\SsoProviderRepositoryInterface;
use InvalidArgumentException;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

/**
 * RBAC-P5-014 (#704) — `/api/sso/providers` CRUD for the Settings →
 * SSO config tab.
 *
 *   - GET    /api/sso/providers      — list providers on caller's tenant
 *   - POST   /api/sso/providers      — create (one per kind per tenant)
 *   - PATCH  /api/sso/providers/{id} — update name / config / enabled
 *   - DELETE /api/sso/providers/{id} — remove
 *
 * Secret handling: `client_secret`, `idp_certificate`, `private_key` and
 * `sp_private_key` are ENCRYPTED at rest since #2725 — {@see SsoConfigCipher}
 * wraps those leaves of the JSONB config before it is persisted, so a database
 * dump no longer hands over the tenant's IdP credentials. The
 * {@see SsoProviderResponseBuilder} additionally masks them with `'****'` on
 * read so the admin UI never sees them after creation; a config PATCH that
 * echoes the mask back keeps the stored value (see
 * mergeConfigPreservingMaskedSecrets below).
 *
 * Test-connection endpoint is intentionally NOT in this controller —
 * it lives on the SSO bundle's callback path (`/api/auth/sso/.../login`)
 * because it needs the full OAuth/SAML state machine. The UI links to
 * those existing routes instead of duplicating the flow.
 *
 * Permission gate: `user.admin` until Phase 6 retrofit (#720+) lands the
 * PRD §3.2 `settings.tenant.manage` code on the gate.
 */
final readonly class SsoProvidersController
{
    private const int MAX_NAME_LENGTH = 80;

    private const array ALLOWED_KINDS = [
        SsoProvider::KIND_GOOGLE_WORKSPACE,
        SsoProvider::KIND_MICROSOFT_365,
        SsoProvider::KIND_SAML,
    ];

    public function __construct(
        private Security $security,
        private SsoProviderRepositoryInterface $providers,
        private SsoProviderResponseBuilder $builder,
        private SsoConfigCipher $configCipher,
    ) {
    }

    #[Route(path: '/api/sso/providers', methods: ['GET'], name: 'api_sso_providers_list')]
    #[RequiresPermission(module: 'user', action: 'admin')]
    public function list(): JsonResponse
    {
        $caller = $this->security->getUser();
        if (!$caller instanceof User) {
            return $this->problem(Response::HTTP_UNAUTHORIZED, 'Unauthorized', 'No authenticated user.');
        }

        $rows = $this->providers->findByTenant($caller->getTenant()->getId());
        $member = $this->builder->buildList($rows);

        return new JsonResponse([
            'member' => $member,
            'totalItems' => \count($member),
            'meta' => [
                'page' => 1,
                'per_page' => \count($member),
                'total_pages' => 1,
            ],
        ]);
    }

    #[Route(path: '/api/sso/providers', methods: ['POST'], name: 'api_sso_providers_create')]
    #[RequiresPermission(module: 'user', action: 'admin')]
    public function create(Request $request): JsonResponse
    {
        $caller = $this->security->getUser();
        if (!$caller instanceof User) {
            return $this->problem(Response::HTTP_UNAUTHORIZED, 'Unauthorized', 'No authenticated user.');
        }

        $payload = $this->decode($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        $kind = $payload['kind'] ?? null;
        if (!\is_string($kind) || !\in_array($kind, self::ALLOWED_KINDS, true)) {
            return $this->problem(
                Response::HTTP_BAD_REQUEST,
                'Bad Request',
                \sprintf('`kind` must be one of: %s.', implode(', ', self::ALLOWED_KINDS)),
            );
        }

        $existing = $this->providers->findByTenantAndKind($caller->getTenant()->getId(), $kind);
        if (null !== $existing) {
            return $this->problem(
                Response::HTTP_CONFLICT,
                'Provider already exists',
                \sprintf('Tenant already has an SSO provider for `%s`. Edit the existing one instead.', $kind),
                ['code' => 'duplicate_kind'],
            );
        }

        $name = $this->extractName($payload, $kind);
        if ($name instanceof JsonResponse) {
            return $name;
        }

        $config = $payload['config'] ?? [];
        if (!\is_array($config)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', '`config` must be an object.');
        }
        $stringConfig = self::ensureStringKeyed($config);

        $restriction = self::missingDirectoryRestriction($kind, $stringConfig);
        if (null !== $restriction) {
            return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'Unprocessable Entity', $restriction);
        }

        $enabled = isset($payload['enabled']) ? (bool) $payload['enabled'] : false;

        $provider = new SsoProvider(
            tenantId: $caller->getTenant()->getId(),
            kind: $kind,
            name: $name,
            config: $this->configCipher->protect($stringConfig),
            enabled: $enabled,
        );
        $this->providers->save($provider);

        return new JsonResponse($this->builder->buildOne($provider), Response::HTTP_CREATED);
    }

    #[Route(path: '/api/sso/providers/{id}', methods: ['PATCH'], name: 'api_sso_providers_update', requirements: ['id' => '[0-9a-f-]{36}'])]
    #[RequiresPermission(module: 'user', action: 'admin')]
    public function update(string $id, Request $request): JsonResponse
    {
        $caller = $this->security->getUser();
        if (!$caller instanceof User) {
            return $this->problem(Response::HTTP_UNAUTHORIZED, 'Unauthorized', 'No authenticated user.');
        }

        $provider = $this->loadAccessibleProvider($caller, $id);
        if ($provider instanceof JsonResponse) {
            return $provider;
        }

        $payload = $this->decode($request);
        if ($payload instanceof JsonResponse) {
            return $payload;
        }

        if (\array_key_exists('name', $payload)) {
            $name = $this->extractName($payload, $provider->getKind());
            if ($name instanceof JsonResponse) {
                return $name;
            }
            $provider->rename($name);
        }

        if (\array_key_exists('config', $payload)) {
            if (!\is_array($payload['config'])) {
                return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', '`config` must be an object.');
            }
            // Merge: if a key sent as `'****'` (the masked placeholder from
            // the read projection) we keep the existing value rather than
            // overwriting the real secret with the mask. The FE always
            // round-trips the read shape on edit, so this is how secrets
            // survive an "edit name" submission without re-entering them.
            // The merge compares against the STORED shape (masked-placeholder
            // detection only needs to know a key exists), then the result is
            // re-protected: values kept from the current config are already
            // enveloped and stay untouched, freshly submitted ones get wrapped.
            $merged = self::mergeConfigPreservingMaskedSecrets(
                $provider->getConfig(),
                self::ensureStringKeyed($payload['config']),
            );
            $restriction = self::missingDirectoryRestriction($provider->getKind(), $merged);
            if (null !== $restriction) {
                return $this->problem(Response::HTTP_UNPROCESSABLE_ENTITY, 'Unprocessable Entity', $restriction);
            }
            $provider->updateConfig($this->configCipher->protect($merged));
        }

        if (\array_key_exists('enabled', $payload)) {
            if ((bool) $payload['enabled']) {
                $provider->enable();
            } else {
                $provider->disable();
            }
        }

        $this->providers->save($provider);

        return new JsonResponse($this->builder->buildOne($provider));
    }

    #[Route(path: '/api/sso/providers/{id}', methods: ['DELETE'], name: 'api_sso_providers_delete', requirements: ['id' => '[0-9a-f-]{36}'])]
    #[RequiresPermission(module: 'user', action: 'admin')]
    public function delete(string $id): JsonResponse
    {
        $caller = $this->security->getUser();
        if (!$caller instanceof User) {
            return $this->problem(Response::HTTP_UNAUTHORIZED, 'Unauthorized', 'No authenticated user.');
        }

        $provider = $this->loadAccessibleProvider($caller, $id);
        if ($provider instanceof JsonResponse) {
            return $provider;
        }

        $this->providers->remove($provider);

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    private function loadAccessibleProvider(User $caller, string $id): SsoProvider|JsonResponse
    {
        try {
            $uuid = Uuid::fromString($id);
        } catch (InvalidArgumentException) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'Not Found', 'SSO provider not found.');
        }

        $provider = $this->providers->findById($uuid);
        if (null === $provider) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'Not Found', 'SSO provider not found.');
        }

        if (!$provider->getTenantId()->equals($caller->getTenant()->getId())) {
            return $this->problem(Response::HTTP_NOT_FOUND, 'Not Found', 'SSO provider not found.');
        }

        return $provider;
    }

    /**
     * @return array<string, mixed>|JsonResponse
     */
    private function decode(Request $request): array|JsonResponse
    {
        /** @var array<string, mixed>|null $payload */
        $payload = json_decode($request->getContent(), true);
        if (!\is_array($payload)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', 'Request body must be JSON.');
        }

        return $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function extractName(array $payload, string $kind): string|JsonResponse
    {
        $name = $payload['name'] ?? null;
        if (null === $name || (\is_string($name) && '' === trim($name))) {
            // Auto-name from kind when omitted on POST — matches the wizard pattern.
            return self::defaultNameForKind($kind);
        }
        if (!\is_string($name)) {
            return $this->problem(Response::HTTP_BAD_REQUEST, 'Bad Request', '`name` must be a string.');
        }
        $name = trim($name);
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            return $this->problem(
                Response::HTTP_BAD_REQUEST,
                'Bad Request',
                \sprintf('`name` must be %d characters or fewer.', self::MAX_NAME_LENGTH),
            );
        }

        return $name;
    }

    private static function defaultNameForKind(string $kind): string
    {
        return match ($kind) {
            SsoProvider::KIND_GOOGLE_WORKSPACE => 'Google Workspace',
            SsoProvider::KIND_MICROSOFT_365 => 'Microsoft 365',
            SsoProvider::KIND_SAML => 'SAML 2.0',
            default => 'SSO Provider',
        };
    }

    /**
     * #2728 — a provider without a directory restriction accepts ANY account
     * the IdP will authenticate, and {@see \App\Identity\Application\Sso\SsoUserResolver}
     * auto-provisions each one as `viewer` — read access to the tenant's whole
     * catalogue. Google needs the Workspace domain; Microsoft needs a concrete
     * Azure directory (`common` is the multi-tenant endpoint, i.e. everyone).
     * SAML carries its restriction in the IdP certificate + entity id, so it
     * has no equivalent key.
     *
     * Returns the problem detail when the restriction is missing, null when the
     * config is acceptable.
     *
     * @param array<string, mixed> $config
     */
    private static function missingDirectoryRestriction(string $kind, array $config): ?string
    {
        $value = static function (string $key) use ($config): ?string {
            $raw = $config[$key] ?? null;

            return \is_string($raw) && '' !== trim($raw) ? trim($raw) : null;
        };

        return match ($kind) {
            SsoProvider::KIND_GOOGLE_WORKSPACE => null === $value('hosted_domain')
                ? '`config.hosted_domain` is required for Google Workspace SSO — without it any Google account (including personal Gmail) could sign in and be auto-provisioned.'
                : null,
            SsoProvider::KIND_MICROSOFT_365 => (null === $value('tenant_id') || 'common' === $value('tenant_id'))
                ? '`config.tenant_id` must name a concrete Azure directory for Microsoft 365 SSO — `common` accepts any Microsoft account, including personal ones.'
                : null,
            default => null,
        };
    }

    /**
     * @param array<string, mixed> $current
     * @param array<string, mixed> $next
     *
     * @return array<string, mixed>
     */
    private static function mergeConfigPreservingMaskedSecrets(array $current, array $next): array
    {
        $merged = $next;
        foreach ($next as $key => $value) {
            // #2725 — one definition of "this key holds a credential", shared
            // with SsoConfigCipher so masking and encryption cannot drift.
            $isSecret = SsoConfigCipher::isSecretKey($key);
            // If a secret-shaped field was sent as the masked placeholder,
            // keep the existing value instead of overwriting it.
            if ($isSecret && '****' === $value && isset($current[$key])) {
                $merged[$key] = $current[$key];
            }
        }

        return $merged;
    }

    /**
     * Narrow `mixed`-keyed arrays from `json_decode` into the
     * `array<string, mixed>` the SsoProvider entity expects. Numeric
     * keys are coerced to their string form so the JSON round-trip
     * stays stable.
     *
     * @param array<mixed, mixed> $raw
     *
     * @return array<string, mixed>
     */
    private static function ensureStringKeyed(array $raw): array
    {
        $out = [];
        foreach ($raw as $key => $value) {
            $out[(string) $key] = $value;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $extras
     */
    private function problem(int $status, string $title, string $detail, array $extras = []): JsonResponse
    {
        return new JsonResponse(
            array_merge(
                [
                    'type' => 'about:blank',
                    'title' => $title,
                    'status' => $status,
                    'detail' => $detail,
                ],
                $extras,
            ),
            $status,
            ['Content-Type' => 'application/problem+json; charset=utf-8'],
        );
    }
}

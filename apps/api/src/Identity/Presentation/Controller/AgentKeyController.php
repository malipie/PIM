<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

use App\Identity\Application\ByokKeyManager;
use App\Identity\Contracts\Attribute\RequiresPermission;
use App\Identity\Domain\Repository\TenantAgentConfigRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use DateTimeInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * AGENT-P6-06 (#1979) — HTTP surface for the tenant's BYOK Anthropic
 * key (Piotr, PRD §4.2/§10.2): set/rotate (the same PUT - a new key
 * replaces the old, re-encrypted), soft-disable, and a status readout
 * that NEVER returns plaintext - only the display prefix and
 * timestamps. Settings-admin gated; the agent feature itself
 * additionally requires the global AGENT_ENABLED flag (P0-08).
 */
final readonly class AgentKeyController
{
    /**
     * Selectable model overrides exposed in Settings → AI. Absent /
     * null means the platform default (Sonnet for read/write, Opus for
     * schema-ops). Haiku is the cheapest — the testing pick.
     */
    private const array SELECTABLE_MODELS = [
        'claude-haiku-4-5',
        'claude-sonnet-4-6',
        'claude-opus-4-8',
    ];

    public function __construct(
        private ByokKeyManager $keys,
        private TenantAgentConfigRepositoryInterface $configs,
        private TenantContext $tenantContext,
        private bool $agentFeatureEnabled,
    ) {
    }

    #[Route('/api/settings/agent-key', name: 'pim_agent_key_status', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'settings', action: 'tenant.manage')]
    public function status(): JsonResponse
    {
        $tenant = $this->tenant();
        $config = $this->configs->findForTenant($tenant);

        return new JsonResponse([
            'agent_feature_enabled' => $this->agentFeatureEnabled,
            'configured' => null !== $config,
            'enabled' => $config?->isEnabled() ?? false,
            'key_prefix' => $config?->getKeyPrefix(),
            'enabled_at' => $config?->getEnabledAt()->format(DateTimeInterface::ATOM),
            'disabled_at' => $config?->getDisabledAt()?->format(DateTimeInterface::ATOM),
            'last_used_at' => $config?->getLastUsedAt()?->format(DateTimeInterface::ATOM),
            'proactive_scan_enabled' => $config?->isProactiveScanEnabled() ?? false,
            'model' => $config?->getModel(),
            'prompt_caching_enabled' => $config?->isPromptCachingEnabled() ?? true,
            'selectable_models' => self::SELECTABLE_MODELS,
        ]);
    }

    #[Route('/api/settings/agent-key', name: 'pim_agent_key_set', methods: ['PUT'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'settings', action: 'tenant.manage')]
    public function set(Request $request): JsonResponse
    {
        $tenant = $this->tenant();

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];
        $apiKey = $body['api_key'] ?? null;
        if (!\is_string($apiKey) || '' === trim($apiKey)) {
            throw new BadRequestHttpException('api_key must be a non-empty string.');
        }
        if (!str_starts_with(trim($apiKey), 'sk-ant-')) {
            throw new BadRequestHttpException('api_key does not look like an Anthropic key (expected the sk-ant- prefix).');
        }

        $config = $this->keys->setKey($tenant, trim($apiKey));

        return new JsonResponse([
            'configured' => true,
            'enabled' => $config->isEnabled(),
            'key_prefix' => $config->getKeyPrefix(),
        ]);
    }

    /**
     * Partial update of the per-tenant agent settings — any subset of
     * `proactive_scan_enabled` (P8-01), `model`, and
     * `prompt_caching_enabled`. Each provided field is validated; the
     * rest are left untouched. All require a configured key.
     */
    #[Route('/api/settings/agent-key', name: 'pim_agent_key_patch', methods: ['PATCH'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'settings', action: 'tenant.manage')]
    public function patch(Request $request): JsonResponse
    {
        $tenant = $this->tenant();

        /** @var array<string, mixed> $body */
        $body = json_decode($request->getContent(), true) ?? [];
        $response = [];

        if (\array_key_exists('proactive_scan_enabled', $body)) {
            $proactive = $body['proactive_scan_enabled'];
            if (!\is_bool($proactive)) {
                throw new BadRequestHttpException('proactive_scan_enabled must be a boolean.');
            }
            $this->keys->setProactiveScan($tenant, $proactive);
            $response['proactive_scan_enabled'] = $proactive;
        }

        if (\array_key_exists('model', $body)) {
            $model = $body['model'];
            if (null !== $model && !\in_array($model, self::SELECTABLE_MODELS, true)) {
                throw new BadRequestHttpException(\sprintf('model must be null (auto) or one of: %s.', implode(', ', self::SELECTABLE_MODELS)));
            }
            $this->keys->setModel($tenant, $model);
            $response['model'] = $model;
        }

        if (\array_key_exists('prompt_caching_enabled', $body)) {
            $caching = $body['prompt_caching_enabled'];
            if (!\is_bool($caching)) {
                throw new BadRequestHttpException('prompt_caching_enabled must be a boolean.');
            }
            $this->keys->setPromptCaching($tenant, $caching);
            $response['prompt_caching_enabled'] = $caching;
        }

        if ([] === $response) {
            throw new BadRequestHttpException('No recognized settings in the request body.');
        }

        return new JsonResponse($response);
    }

    #[Route('/api/settings/agent-key', name: 'pim_agent_key_disable', methods: ['DELETE'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    #[RequiresPermission(module: 'settings', action: 'tenant.manage')]
    public function disable(): JsonResponse
    {
        $tenant = $this->tenant();
        $this->keys->disable($tenant);

        return new JsonResponse(['configured' => true, 'enabled' => false]);
    }

    private function tenant(): Tenant
    {
        $tenant = $this->tenantContext->get();
        if (!$tenant instanceof Tenant) {
            throw new NotFoundHttpException('No tenant resolved for this request.');
        }

        return $tenant;
    }
}

<?php

declare(strict_types=1);

namespace App\ApiConfigurator\Presentation\Controller;

use App\ApiConfigurator\Application\WebhookDeliveryClient;
use App\ApiConfigurator\Application\WebhookSecretGenerator;
use App\ApiConfigurator\Domain\Repository\ApiProfileRepositoryInterface;
use App\Identity\Contracts\Attribute\RequiresPermission;
use DateTimeImmutable;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Uid\Uuid;

use const DATE_ATOM;

/**
 * Test + rotate endpoints for a profile's outbound webhook (#93).
 *
 * `POST /api/api_profiles/{id}/test_webhook` fires a sample payload
 * at the configured URL and reports the destination's response. The
 * admin uses this to validate signing + URL reachability without
 * waiting for a real domain event to fly past.
 *
 * `POST /api/api_profiles/{id}/rotate_webhook_secret` mints a fresh
 * HMAC secret. The new value is returned exactly once — old
 * subscribers must update; rotation = revocation.
 */
final class TestWebhookController
{
    public function __construct(
        private readonly ApiProfileRepositoryInterface $profiles,
        private readonly WebhookDeliveryClient $client,
        private readonly WebhookSecretGenerator $secrets,
    ) {
    }

    #[Route(
        '/api/api_profiles/{id}/test_webhook',
        name: 'pim_api_profiles_test_webhook',
        requirements: ['id' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
        methods: ['POST'],
    )]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'api_profile', action: 'admin', anyOf: [
        'api_profile.admin',
        'settings.integrations.manage',
    ])]
    public function test(string $id): JsonResponse
    {
        $profile = $this->profiles->findById(Uuid::fromString($id));
        if (null === $profile) {
            throw new NotFoundHttpException(\sprintf('ApiProfile "%s" was not found.', $id));
        }
        $url = $profile->getWebhookUrl();
        $secret = $profile->getWebhookSecret();
        if (null === $url || '' === $url) {
            throw new UnprocessableEntityHttpException('Profile has no webhookUrl configured.');
        }
        if (null === $secret) {
            throw new UnprocessableEntityHttpException('Profile has no webhook secret — rotate first.');
        }

        $samplePayload = [
            'event' => 'test.ping',
            'occurredOn' => new DateTimeImmutable()->format(DATE_ATOM),
            'profileCode' => $profile->getCode(),
            'data' => ['note' => 'PIM test ping — your endpoint is reachable.'],
        ];

        $result = $this->client->deliver($url, $secret, $samplePayload);

        return new JsonResponse([
            'url' => $url,
            'statusCode' => $result['statusCode'],
            'durationMs' => $result['durationMs'],
            'success' => $result['statusCode'] >= 200 && $result['statusCode'] < 300,
        ]);
    }

    #[Route(
        '/api/api_profiles/{id}/rotate_webhook_secret',
        name: 'pim_api_profiles_rotate_webhook_secret',
        requirements: ['id' => '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}'],
        methods: ['POST'],
    )]
    #[IsGranted('ROLE_USER')]
    #[RequiresPermission(module: 'api_profile', action: 'admin', anyOf: [
        'api_profile.admin',
        'settings.integrations.manage',
    ])]
    public function rotate(string $id): JsonResponse
    {
        $profile = $this->profiles->findById(Uuid::fromString($id));
        if (null === $profile) {
            throw new NotFoundHttpException(\sprintf('ApiProfile "%s" was not found.', $id));
        }

        $secret = $this->secrets->generate();
        $profile->setWebhookSecret($secret);
        $this->profiles->save($profile);

        return new JsonResponse([
            'webhookSecret' => $secret,
            'note' => 'Capture this secret — it will not be shown again. Old subscribers must update.',
        ]);
    }
}

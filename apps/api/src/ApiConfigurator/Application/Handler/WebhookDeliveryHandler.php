<?php

declare(strict_types=1);

namespace App\ApiConfigurator\Application\Handler;

use App\ApiConfigurator\Application\WebhookDeliveryClient;
use App\ApiConfigurator\Domain\Enum\WebhookDeliveryStatus;
use App\ApiConfigurator\Domain\Message\WebhookDeliveryMessage;
use App\ApiConfigurator\Domain\Repository\ApiProfileRepositoryInterface;
use App\ApiConfigurator\Domain\Repository\WebhookDeliveryRepositoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Attempts one webhook delivery and records the outcome (APIC-P4-05).
 *
 * Loads the {@see \App\ApiConfigurator\Domain\Entity\WebhookDelivery} + the
 * profile's current signing secret, POSTs the stored payload via
 * {@see WebhookDeliveryClient}, and marks the row delivered (2xx) or failed.
 * A failed attempt is recorded then re-thrown so the message's transport retry
 * strategy backs off and eventually dead-letters — a missing profile/secret is
 * a permanent failure (recorded, NOT re-thrown, so it doesn't churn retries).
 */
#[AsMessageHandler]
final readonly class WebhookDeliveryHandler
{
    public function __construct(
        private WebhookDeliveryRepositoryInterface $deliveries,
        private ApiProfileRepositoryInterface $profiles,
        private WebhookDeliveryClient $client,
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function __invoke(WebhookDeliveryMessage $message): void
    {
        $delivery = $this->deliveries->findById($message->deliveryId);
        if (null === $delivery) {
            return;
        }

        // #2732 — redelivery guard: a worker killed after a successful POST but
        // before ack (or a manual messenger:failed:retry on a delivered row)
        // must not POST the same event to the receiver a second time.
        if (WebhookDeliveryStatus::Delivered === $delivery->getStatus()) {
            $this->logger->info('Webhook delivery skipped — already delivered.', [
                'delivery' => $message->deliveryId->toRfc4122(),
            ]);

            return;
        }

        $profile = $this->profiles->findById($delivery->getProfileId());
        $secret = $profile?->getWebhookSecret();
        if (null === $secret) {
            // Permanent: the profile or its secret is gone. Record and stop —
            // re-throwing would only burn retries on an unrecoverable state.
            $delivery->markFailed(null, 0, 'Profile or webhook secret no longer available.');
            $this->deliveries->save($delivery);

            return;
        }

        $result = $this->client->deliver($delivery->getTargetUrl(), $secret, $delivery->getPayload());
        $status = $result['statusCode'];

        if ($status >= 200 && $status < 300) {
            $delivery->markDelivered($status, $result['durationMs']);
            $this->deliveries->save($delivery);

            return;
        }

        $error = 0 === $status
            ? 'Transport failure (no HTTP response).'
            : \sprintf('Remote returned HTTP %d.', $status);
        $delivery->markFailed(0 === $status ? null : $status, $result['durationMs'], $error);
        $this->deliveries->save($delivery);

        // Re-throw so Messenger applies the retry/backoff strategy and, once
        // exhausted, dead-letters the envelope to the `failed` transport.
        throw new RuntimeException($error);
    }
}

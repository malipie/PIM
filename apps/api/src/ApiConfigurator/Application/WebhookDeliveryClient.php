<?php

declare(strict_types=1);

namespace App\ApiConfigurator\Application;

use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\Clock;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * POSTs a webhook body to a profile's `webhookUrl` with an HMAC
 * signature header. Failures are caught + logged so a missing
 * subscriber never bubbles up into the event publisher path —
 * webhooks are best-effort, retries land in #93 follow-up via
 * Symfony Messenger transport.
 *
 * #2741 — the signature covers `timestamp.body`, not the body alone, and the
 * timestamp travels in its own header (the Stripe/Shopify convention). Signing
 * only the body made every captured delivery replayable forever: the signature
 * stayed valid, so a receiver had no way to reject a re-sent request. With the
 * timestamp inside the signed string a receiver can bound acceptance to a
 * freshness window without the attacker being able to shift it.
 */
final readonly class WebhookDeliveryClient
{
    public function __construct(
        private HttpClientInterface $http,
        private LoggerInterface $logger = new NullLogger(),
        private ClockInterface $clock = new Clock(),
    ) {
    }

    /**
     * Builds the string the HMAC is computed over. Exposed so receivers'
     * documentation and the tests share one definition of the contract.
     */
    public static function signedPayload(int $timestamp, string $body): string
    {
        return $timestamp.'.'.$body;
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return array{statusCode: int, durationMs: int}
     */
    public function deliver(string $url, string $secret, array $payload): array
    {
        $body = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $timestamp = $this->clock->now()->getTimestamp();
        $signature = hash_hmac('sha256', self::signedPayload($timestamp, $body), $secret);

        $start = (int) (microtime(true) * 1000);
        try {
            $response = $this->http->request('POST', $url, [
                'headers' => [
                    'content-type' => 'application/json',
                    'x-pim-timestamp' => (string) $timestamp,
                    'x-pim-signature' => 'sha256='.$signature,
                    'x-pim-event' => $payload['event'] ?? 'unknown',
                ],
                'body' => $body,
                'timeout' => 5,
                'max_duration' => 8,
            ]);
            $statusCode = $response->getStatusCode();
        } catch (TransportExceptionInterface $e) {
            $this->logger->warning('Webhook delivery failed', [
                'url' => $url,
                'error' => $e->getMessage(),
            ]);
            $statusCode = 0;
        }

        $durationMs = (int) (microtime(true) * 1000) - $start;

        return ['statusCode' => $statusCode, 'durationMs' => $durationMs];
    }
}

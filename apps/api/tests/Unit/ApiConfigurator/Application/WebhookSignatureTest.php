<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiConfigurator\Application;

use App\ApiConfigurator\Application\WebhookDeliveryClient;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * #2741 — the HMAC must cover `timestamp.body`, not the body alone, and the
 * timestamp must travel in its own header. Signing only the body left every
 * captured delivery replayable forever.
 */
#[CoversClass(WebhookDeliveryClient::class)]
final class WebhookSignatureTest extends TestCase
{
    private const string SECRET = 'whsec_test';

    #[Test]
    public function signatureCoversTimestampAndBody(): void
    {
        $clock = new MockClock('2026-08-04T12:00:00+00:00');
        $timestamp = $clock->now()->getTimestamp();

        $captured = null;
        $client = new WebhookDeliveryClient(
            new MockHttpClient(static function (string $method, string $url, array $options) use (&$captured): MockResponse {
                $captured = $options;

                return new MockResponse('', ['http_code' => 200]);
            }),
            new NullLogger(),
            $clock,
        );

        $client->deliver('https://hook.test/in', self::SECRET, ['event' => 'object.created.product', 'id' => 'abc']);

        self::assertIsArray($captured);
        self::assertIsArray($captured['headers']);
        $headers = self::headerMap($captured['headers']);

        self::assertSame((string) $timestamp, $headers['x-pim-timestamp'], 'the timestamp must travel in its own header');

        $body = $captured['body'];
        self::assertIsString($body);
        $expected = hash_hmac('sha256', WebhookDeliveryClient::signedPayload($timestamp, $body), self::SECRET);
        self::assertSame('sha256='.$expected, $headers['x-pim-signature']);

        // The old body-only signature must NOT validate any more — that is the
        // whole point: a captured request cannot be replayed under a new time.
        $bodyOnly = hash_hmac('sha256', $body, self::SECRET);
        self::assertNotSame('sha256='.$bodyOnly, $headers['x-pim-signature']);
    }

    #[Test]
    public function aReplayedRequestFailsVerificationUnderTheDocumentedRecipe(): void
    {
        // A receiver following the documented recipe recomputes the HMAC from
        // the header timestamp; substituting a fresher timestamp (the replay)
        // no longer matches the captured signature.
        $timestamp = 1785801600;
        $body = '{"event":"object.created.product"}';
        $signature = hash_hmac('sha256', WebhookDeliveryClient::signedPayload($timestamp, $body), self::SECRET);

        $replayTimestamp = $timestamp + 3600;
        $replayCheck = hash_hmac('sha256', WebhookDeliveryClient::signedPayload($replayTimestamp, $body), self::SECRET);

        self::assertNotSame($signature, $replayCheck);
        self::assertTrue(hash_equals($signature, hash_hmac('sha256', WebhookDeliveryClient::signedPayload($timestamp, $body), self::SECRET)));
    }

    /**
     * @param array<int|string, mixed> $headers
     *
     * @return array<string, string>
     */
    private static function headerMap(array $headers): array
    {
        $map = [];
        foreach ($headers as $key => $value) {
            if (\is_string($key)) {
                $map[strtolower($key)] = \is_scalar($value) ? (string) $value : '';

                continue;
            }
            $line = \is_string($value) ? $value : '';
            [$name, $raw] = array_pad(explode(':', $line, 2), 2, '');
            $map[strtolower(trim($name))] = trim($raw);
        }

        return $map;
    }
}

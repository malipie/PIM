<?php

declare(strict_types=1);

namespace App\Tests\Unit\ApiConfigurator\Application\Handler;

use App\ApiConfigurator\Application\Handler\WebhookDeliveryHandler;
use App\ApiConfigurator\Application\WebhookDeliveryClient;
use App\ApiConfigurator\Domain\Entity\ApiProfile;
use App\ApiConfigurator\Domain\Entity\WebhookDelivery;
use App\ApiConfigurator\Domain\Enum\WebhookDeliveryStatus;
use App\ApiConfigurator\Domain\Message\WebhookDeliveryMessage;
use App\ApiConfigurator\Domain\Repository\ApiProfileRepositoryInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;

#[CoversClass(WebhookDeliveryHandler::class)]
final class WebhookDeliveryHandlerTest extends TestCase
{
    /**
     * @return array{WebhookDeliveryHandler, InMemoryWebhookDeliveryRepository, WebhookDelivery}
     */
    private function handlerFor(int $httpCode, ?string $secret = 'shhh'): array
    {
        $deliveries = new InMemoryWebhookDeliveryRepository();

        $profile = $this->createStub(ApiProfile::class);
        $profile->method('getWebhookSecret')->willReturn($secret);
        $profiles = $this->createStub(ApiProfileRepositoryInterface::class);
        $profiles->method('findById')->willReturn($profile);

        // http_code 0 → simulate a transport failure (the client catches it and
        // reports statusCode 0); otherwise a normal mocked HTTP status.
        $response = 0 === $httpCode
            ? new MockResponse('', ['error' => 'Connection refused'])
            : new MockResponse('', ['http_code' => $httpCode]);
        $client = new WebhookDeliveryClient(new MockHttpClient($response));

        $handler = new WebhookDeliveryHandler($deliveries, $profiles, $client);

        $delivery = new WebhookDelivery(Uuid::v7(), 'object.created.product', 'https://hook.test/in', ['event' => 'x']);
        $deliveries->save($delivery);

        return [$handler, $deliveries, $delivery];
    }

    public function testRecordsDeliveredOn2xx(): void
    {
        [$handler, , $delivery] = $this->handlerFor(200);

        ($handler)(new WebhookDeliveryMessage($delivery->getId(), Uuid::v7()));

        self::assertSame(WebhookDeliveryStatus::Delivered, $delivery->getStatus());
        self::assertSame(1, $delivery->getAttempts());
        self::assertSame(200, $delivery->getHttpStatus());
        self::assertNull($delivery->getLastError());
    }

    public function testRecordsFailureAndRethrowsOnNon2xx(): void
    {
        [$handler, , $delivery] = $this->handlerFor(500);

        try {
            ($handler)(new WebhookDeliveryMessage($delivery->getId(), Uuid::v7()));
            self::fail('Expected the handler to re-throw so Messenger retries.');
        } catch (RuntimeException) {
            // expected — drives the transport retry/backoff.
        }

        self::assertSame(WebhookDeliveryStatus::Failed, $delivery->getStatus());
        self::assertSame(1, $delivery->getAttempts());
        self::assertSame(500, $delivery->getHttpStatus());
        self::assertNotNull($delivery->getLastError());
    }

    public function testTransportFailureRethrowsWithNullHttpStatus(): void
    {
        // MockResponse with http_code 0 surfaces as a transport failure in the client.
        [$handler, , $delivery] = $this->handlerFor(0);

        $this->expectException(RuntimeException::class);

        try {
            ($handler)(new WebhookDeliveryMessage($delivery->getId(), Uuid::v7()));
        } finally {
            self::assertSame(WebhookDeliveryStatus::Failed, $delivery->getStatus());
            self::assertNull($delivery->getHttpStatus());
        }
    }

    public function testMissingSecretIsPermanentFailureNoRethrow(): void
    {
        [$handler, , $delivery] = $this->handlerFor(200, secret: null);

        // Must NOT throw — a gone profile/secret is unrecoverable, retrying is waste.
        ($handler)(new WebhookDeliveryMessage($delivery->getId(), Uuid::v7()));

        self::assertSame(WebhookDeliveryStatus::Failed, $delivery->getStatus());
        self::assertSame(1, $delivery->getAttempts());
    }

    public function testUnknownDeliveryIsNoOp(): void
    {
        [$handler] = $this->handlerFor(200);

        // A delivery id that was never persisted (e.g. purged) is a silent no-op.
        ($handler)(new WebhookDeliveryMessage(Uuid::v7(), Uuid::v7()));

        $this->expectNotToPerformAssertions();
    }

    public function testRedeliveryOfDeliveredRowDoesNotPostAgain(): void
    {
        // #2732 — a worker killed after a successful POST but before ack (or a
        // manual messenger:failed:retry on a delivered row) redelivers the
        // message; the receiver must not get a duplicate event.
        [$handler, , $delivery] = $this->handlerFor(200);

        ($handler)(new WebhookDeliveryMessage($delivery->getId(), Uuid::v7()));
        self::assertSame(WebhookDeliveryStatus::Delivered, $delivery->getStatus());

        ($handler)(new WebhookDeliveryMessage($delivery->getId(), Uuid::v7()));

        self::assertSame(1, $delivery->getAttempts(), 'the second invocation must not attempt a POST');
        self::assertSame(WebhookDeliveryStatus::Delivered, $delivery->getStatus());
    }
}

<?php

declare(strict_types=1);

namespace App\Tests\Api\Shared;

use ApiPlatform\Symfony\Bundle\Test\ApiTestCase;
use App\Shared\Infrastructure\Observability\CorrelationIdContext;
use Symfony\Component\Uid\Uuid;

final class CorrelationIdApiTest extends ApiTestCase
{
    protected static ?bool $alwaysBootKernel = true;

    public function testSafeRequestIdIsReturnedByRealHttpKernel(): void
    {
        $response = static::createClient()->request('GET', '/api', [
            'headers' => [CorrelationIdContext::HEADER_NAME => 'pilot-import-42'],
        ]);

        self::assertResponseIsSuccessful();
        self::assertSame('pilot-import-42', $response->getHeaders(false)['x-request-id'][0] ?? null);
    }

    public function testUnsafeRequestIdIsReplacedByRealHttpKernel(): void
    {
        $response = static::createClient()->request('GET', '/api', [
            'headers' => [CorrelationIdContext::HEADER_NAME => 'unsafe request id'],
        ]);

        self::assertResponseIsSuccessful();
        $returned = $response->getHeaders(false)['x-request-id'][0] ?? null;
        self::assertIsString($returned);
        self::assertNotSame('unsafe request id', $returned);
        self::assertTrue(Uuid::isValid($returned));
    }
}

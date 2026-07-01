<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Feed;

use App\Export\Feed\Application\Delivery\FeedDeliveryConfig;
use App\Export\Feed\Application\Delivery\FeedTokenService;
use App\Export\Feed\Domain\Entity\FeedProfile;
use App\Export\Feed\Domain\Enum\FeedTemplateKind;
use App\Shared\Application\Crypto\EncryptedSecret;
use App\Shared\Application\Crypto\EncryptionServiceInterface;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * XMLF-P3-04 — feed URL token lifecycle + delivery config (gzip + encrypted
 * HTTP Basic).
 */
final class FeedTokenAndDeliveryTest extends TestCase
{
    private function feed(): FeedProfile
    {
        return new FeedProfile('c', 'n', FeedTemplateKind::Custom, Uuid::v7(), ['root' => ['element' => 'p'], 'item' => ['element' => 'i', 'slots' => []]]);
    }

    private function encryption(): EncryptionServiceInterface
    {
        return new class implements EncryptionServiceInterface {
            public function encrypt(string $plaintext): EncryptedSecret
            {
                return new EncryptedSecret(base64_encode($plaintext), 1);
            }

            public function decrypt(EncryptedSecret $secret): string
            {
                return (string) base64_decode($secret->ciphertext, true);
            }

            public function needsRotation(EncryptedSecret $secret): bool
            {
                return false;
            }
        };
    }

    #[Test]
    public function mintStoresHmacAndMatchesOnlyTheRightToken(): void
    {
        $service = new FeedTokenService('server-secret');
        $feed = $this->feed();

        $token = $service->mint($feed);
        self::assertGreaterThanOrEqual(32, \strlen($token));
        self::assertNotSame($token, $feed->getTokenHash()); // stored value is the HMAC, not the token
        self::assertTrue($service->matches($feed, $token));
        self::assertFalse($service->matches($feed, $token.'x'));
    }

    #[Test]
    public function rotateChangesTheHashAndRevokeClearsIt(): void
    {
        $service = new FeedTokenService('server-secret');
        $feed = $this->feed();

        $first = $service->mint($feed);
        $hash1 = $feed->getTokenHash();
        $service->rotate($feed);
        self::assertNotSame($hash1, $feed->getTokenHash());
        self::assertFalse($service->matches($feed, $first));

        $service->revoke($feed);
        self::assertNull($feed->getTokenHash());
    }

    #[Test]
    public function deliveryEncryptsBasicPasswordAndDecryptsBack(): void
    {
        $config = new FeedDeliveryConfig($this->encryption());

        $delivery = $config->build(gzip: true, authType: 'basic', username: 'crawler', password: 's3cret');
        self::assertTrue($config->gzipEnabled($delivery));
        $auth = $delivery['auth'];
        self::assertIsArray($auth);
        self::assertSame('crawler', $auth['username']);
        self::assertArrayHasKey('encrypted_password', $auth);
        self::assertNotContains('s3cret', $auth);

        $creds = $config->basicCredentials($delivery);
        self::assertNotNull($creds);
        self::assertSame('crawler', $creds['username']);
        self::assertSame('s3cret', $creds['password']);
    }

    #[Test]
    public function noneAuthHasNoCredentials(): void
    {
        $config = new FeedDeliveryConfig($this->encryption());
        $delivery = $config->build(gzip: false, authType: 'none');

        self::assertFalse($config->gzipEnabled($delivery));
        self::assertNull($config->basicCredentials($delivery));
    }

    #[Test]
    public function basicWithoutCredentialsIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new FeedDeliveryConfig($this->encryption())->build(gzip: true, authType: 'basic');
    }
}

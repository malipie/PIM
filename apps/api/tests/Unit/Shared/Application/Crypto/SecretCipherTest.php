<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Crypto;

use App\Shared\Application\Crypto\SecretCipher;
use App\Shared\Infrastructure\Crypto\AesGcmEncryptionService;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * #2726 / #2725 — single-column secret envelope. The contract that matters to
 * callers: a protected value never contains the plaintext, it round-trips, and
 * a legacy plaintext row keeps working so existing enrolments / SSO providers
 * do not break on deploy and migrate on their next write.
 */
#[CoversClass(SecretCipher::class)]
final class SecretCipherTest extends TestCase
{
    private function cipher(): SecretCipher
    {
        if (!\function_exists('sodium_crypto_aead_aes256gcm_is_available')
            || !sodium_crypto_aead_aes256gcm_is_available()) {
            self::markTestSkipped('AES-256-GCM is unavailable on this host (no AES-NI).');
        }

        return new SecretCipher(new AesGcmEncryptionService([1 => base64_encode(str_repeat("\x2a", 32))]));
    }

    #[Test]
    public function protectedValueHidesThePlaintextAndRoundTrips(): void
    {
        $cipher = $this->cipher();
        $plaintext = 'OG223SOUL7LFTEPPY4FQ665GGQI4PQ5Q';

        $protected = $cipher->protect($plaintext);

        self::assertStringStartsWith('enc:v1:', $protected);
        self::assertStringNotContainsString($plaintext, $protected);
        self::assertTrue($cipher->isProtected($protected));
        self::assertSame($plaintext, $cipher->reveal($protected));
    }

    #[Test]
    public function eachProtectCallProducesADistinctEnvelope(): void
    {
        // A fresh nonce per call — identical secrets must not be correlatable
        // across rows by comparing ciphertexts.
        $cipher = $this->cipher();

        self::assertNotSame($cipher->protect('same-secret'), $cipher->protect('same-secret'));
    }

    #[Test]
    public function legacyPlaintextIsReturnedUnchanged(): void
    {
        $cipher = $this->cipher();

        self::assertSame('LEGACYBASE32SECRET', $cipher->reveal('LEGACYBASE32SECRET'));
        self::assertNull($cipher->reveal(null));
        self::assertFalse($cipher->isProtected('LEGACYBASE32SECRET'));
        self::assertFalse($cipher->isProtected(null));
    }

    #[Test]
    public function emptyStringStaysEmptySoPresenceChecksKeepWorking(): void
    {
        self::assertSame('', $this->cipher()->protect(''));
    }
}

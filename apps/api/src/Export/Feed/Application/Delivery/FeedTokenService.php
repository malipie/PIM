<?php

declare(strict_types=1);

namespace App\Export\Feed\Application\Delivery;

use App\Export\Feed\Domain\Entity\FeedProfile;

/**
 * Feed URL token lifecycle (ADR-0023 §6.9, XMLF-P3-04). Each feed's public URL
 * carries an unguessable token (>=128-bit); we persist only its keyed HMAC on
 * the profile (never the plaintext), so the public endpoint (XMLF-P3-05) can
 * resolve the feed by a single indexed lookup on the hash. The token is shown
 * once at mint/rotate; it is NOT the tenant API key (separate lifecycle,
 * read-only, revocable per feed).
 */
final class FeedTokenService
{
    /** 24 random bytes → 32-char base64url token (192-bit, > the 128-bit floor). */
    private const int TOKEN_BYTES = 24;

    public function __construct(private readonly string $secret)
    {
    }

    /**
     * Mint a new token, store its HMAC on the feed, and return the plaintext
     * (shown to the user once).
     */
    public function mint(FeedProfile $feed): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
        $feed->setTokenHash($this->hash($token));

        return $token;
    }

    public function rotate(FeedProfile $feed): string
    {
        return $this->mint($feed);
    }

    public function revoke(FeedProfile $feed): void
    {
        $feed->setTokenHash(null);
    }

    /**
     * Deterministic keyed hash used both to store the token and to resolve a
     * feed from an incoming URL token.
     */
    public function hash(string $token): string
    {
        return hash_hmac('sha256', $token, $this->secret);
    }

    public function matches(FeedProfile $feed, string $token): bool
    {
        $stored = $feed->getTokenHash();

        return null !== $stored && hash_equals($stored, $this->hash($token));
    }
}

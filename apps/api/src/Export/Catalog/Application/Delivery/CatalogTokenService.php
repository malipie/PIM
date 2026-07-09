<?php

declare(strict_types=1);

namespace App\Export\Catalog\Application\Delivery;

use App\Export\Catalog\Domain\Entity\CatalogProfile;

/**
 * Catalog URL token lifecycle (ADR-0027 §6.5, CPDF-P3-02 [SEC]). Each catalog's
 * public PDF URL carries an unguessable token (>=128-bit); we persist only its
 * keyed HMAC on the profile (never the plaintext), so the public endpoint
 * (CPDF-P3-02) can resolve the catalog by a single indexed lookup on the hash.
 * The token is shown once at mint/rotate; it is NOT the tenant API key (separate
 * lifecycle, read-only, revocable per catalog). Mirrors {@see \App\Export\Feed\Application\Delivery\FeedTokenService}.
 */
final class CatalogTokenService
{
    /** 24 random bytes → 32-char base64url token (192-bit, > the 128-bit floor). */
    private const int TOKEN_BYTES = 24;

    public function __construct(private readonly string $secret)
    {
    }

    /**
     * Mint a new token, store its HMAC on the catalog, and return the plaintext
     * (shown to the user once).
     */
    public function mint(CatalogProfile $catalog): string
    {
        $token = rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
        $catalog->setTokenHash($this->hash($token));

        return $token;
    }

    public function rotate(CatalogProfile $catalog): string
    {
        return $this->mint($catalog);
    }

    public function revoke(CatalogProfile $catalog): void
    {
        $catalog->setTokenHash(null);
    }

    /**
     * Deterministic keyed hash used both to store the token and to resolve a
     * catalog from an incoming URL token.
     */
    public function hash(string $token): string
    {
        return hash_hmac('sha256', $token, $this->secret);
    }

    public function matches(CatalogProfile $catalog, string $token): bool
    {
        $stored = $catalog->getTokenHash();

        return null !== $stored && hash_equals($stored, $this->hash($token));
    }
}

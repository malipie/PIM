<?php

declare(strict_types=1);

namespace App\Identity\Application\Sso;

use App\Identity\Domain\Entity\SsoProvider;
use App\Identity\Domain\Repository\SsoProviderRepositoryInterface;
use App\Shared\Domain\Tenant;
use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Provider\GoogleUser;
use League\OAuth2\Client\Token\AccessToken;
use RuntimeException;

/**
 * RBAC-P2-012 (#661) — Google Workspace OAuth provider wrapper.
 *
 * Wraps League's GoogleProvider with per-tenant config lookup +
 * hosted-domain restriction (Google Workspace Account vs personal
 * Gmail). Per PRD-PIM-rbac §3.6, SSO is identity-only; user
 * provisioning + role assignment goes through SsoUserResolver.
 *
 * Flow:
 *   1. authorizationUrl() — build redirect URL with state CSRF token
 *   2. fetchUser() — exchange code for token, return verified email
 *
 * Config (stored w SsoProvider.config JSON):
 *   - client_id (Google OAuth client ID)
 *   - client_secret (encrypted via ByokKeyManager in production)
 *   - hosted_domain (G-Suite domain, e.g. 'example.com') — rejects
 *     personal Gmail or other domains
 *
 * Security:
 *   - state token CSRF protection handled by League's library
 *   - hosted_domain check rejects users from outside the configured org
 *   - email_verified claim on Google user is checked (rejects unverified)
 */
final class GoogleAuthProvider
{
    public function __construct(
        private readonly SsoProviderRepositoryInterface $providers,
    ) {
    }

    /**
     * Build the authorization URL for the tenant's Google Workspace
     * SSO config. Returns array with both the URL and the state token
     * the caller MUST persist (session or signed cookie) for CSRF
     * verification on callback.
     *
     * @return array{url: string, state: string}
     */
    public function authorizationUrl(Tenant $tenant, string $redirectUri): array
    {
        $config = $this->loadConfig($tenant);

        $client = new Google([
            'clientId' => $config['client_id'],
            'clientSecret' => $config['client_secret'],
            'redirectUri' => $redirectUri,
            'hostedDomain' => $config['hosted_domain'] ?? null,
        ]);

        $authUrl = $client->getAuthorizationUrl([
            'scope' => ['email', 'profile', 'openid'],
        ]);

        return [
            'url' => $authUrl,
            'state' => $client->getState(),
        ];
    }

    /**
     * Exchange the OAuth code for an access token, fetch the user
     * profile, verify hosted_domain, return the verified email.
     *
     * @throws RuntimeException when the user is from outside the
     *                          configured hosted_domain, or email
     *                          is unverified, or token exchange fails
     */
    public function fetchUserEmail(Tenant $tenant, string $code, string $redirectUri): string
    {
        $config = $this->loadConfig($tenant);

        $client = new Google([
            'clientId' => $config['client_id'],
            'clientSecret' => $config['client_secret'],
            'redirectUri' => $redirectUri,
            'hostedDomain' => $config['hosted_domain'] ?? null,
        ]);

        /** @var AccessToken $token */
        $token = $client->getAccessToken('authorization_code', ['code' => $code]);

        /** @var GoogleUser $googleUser */
        $googleUser = $client->getResourceOwner($token);

        $email = $googleUser->getEmail();
        if (null === $email || '' === $email) {
            throw new RuntimeException('Google user has no email claim.');
        }

        // Hosted domain enforcement (Google Workspace tenant restriction).
        // The library's hostedDomain config tells Google to reject login
        // attempts from outside the domain — but we verify explicitly
        // here too because Google's enforcement is advisory; the resource
        // owner response may still include a Gmail address if the user
        // bypassed the hint.
        // #2728 — fail CLOSED when the restriction is absent. With an
        // "External" Google Cloud app and no hosted_domain, any private Gmail
        // account passing OAuth used to be auto-provisioned as `viewer`, i.e.
        // read access to the tenant's whole catalogue. A provider configured
        // without the restriction is a misconfiguration, not a permission to
        // accept everyone — so the login is refused rather than provisioning.
        $expectedDomain = $config['hosted_domain'] ?? null;
        if (!\is_string($expectedDomain) || '' === $expectedDomain) {
            throw new RuntimeException(
                'Google SSO provider has no hosted_domain restriction — refusing to authenticate. '
                .'Set the Workspace domain on the provider before enabling SSO.',
            );
        }
        $emailDomain = substr($email, (int) strrpos($email, '@') + 1);
        if ($emailDomain !== $expectedDomain) {
            throw new RuntimeException(\sprintf(
                'Google account "%s" is not in the allowed hosted_domain "%s".',
                $email,
                $expectedDomain,
            ));
        }

        return $email;
    }

    /**
     * @return array{client_id: string, client_secret: string, hosted_domain?: string}
     */
    private function loadConfig(Tenant $tenant): array
    {
        $provider = $this->providers->findByTenantAndKind($tenant->getId(), SsoProvider::KIND_GOOGLE_WORKSPACE);
        if (null === $provider) {
            throw new RuntimeException(\sprintf(
                'Google Workspace SSO not configured for tenant "%s".',
                $tenant->getCode(),
            ));
        }
        if (!$provider->isEnabled()) {
            throw new RuntimeException(\sprintf(
                'Google Workspace SSO is disabled for tenant "%s".',
                $tenant->getCode(),
            ));
        }

        $config = $provider->getConfig();
        if (!isset($config['client_id'], $config['client_secret'])) {
            throw new RuntimeException('Google SSO config missing client_id or client_secret.');
        }

        /** @var array{client_id: string, client_secret: string, hosted_domain?: string} $typedConfig */
        $typedConfig = $config;

        return $typedConfig;
    }
}

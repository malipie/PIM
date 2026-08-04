<?php

declare(strict_types=1);

namespace App\Identity\Application\Sso;

use App\Identity\Domain\Entity\SsoProvider;
use App\Identity\Domain\Repository\SsoProviderRepositoryInterface;
use App\Shared\Domain\Tenant;
use League\OAuth2\Client\Token\AccessToken;
use RuntimeException;
use Stevenmaguire\OAuth2\Client\Provider\Microsoft;

/**
 * RBAC-P2-013 (#662) — Microsoft 365 OAuth provider wrapper.
 *
 * Wraps stevenmaguire/oauth2-microsoft with per-tenant config. Same
 * substrate as GoogleAuthProvider (#661): authorizationUrl() + state
 * CSRF + fetchUserEmail() with Azure-tenant restriction.
 *
 * Config (stored w SsoProvider.config JSON):
 *   - client_id (Azure app registration)
 *   - client_secret (encrypted via ByokKeyManager w production)
 *   - tenant_id (optional Azure tenant restriction — 'common' for
 *     multi-tenant org, specific UUID for single-tenant lock)
 *
 * Note: Microsoft Graph email claim na profile depends on consent
 * scopes — base 'openid email profile' gives mail or userPrincipalName.
 */
final class MicrosoftAuthProvider
{
    public function __construct(
        private readonly SsoProviderRepositoryInterface $providers,
    ) {
    }

    /**
     * @return array{url: string, state: string}
     */
    public function authorizationUrl(Tenant $tenant, string $redirectUri): array
    {
        $config = $this->loadConfig($tenant);

        $client = new Microsoft([
            'clientId' => $config['client_id'],
            'clientSecret' => $config['client_secret'],
            'redirectUri' => $redirectUri,
        ]);

        $authUrl = $client->getAuthorizationUrl([
            'scope' => ['openid', 'email', 'profile', 'User.Read'],
        ]);

        return [
            'url' => $authUrl,
            'state' => $client->getState(),
        ];
    }

    /**
     * @throws RuntimeException when token exchange fails or email missing
     */
    public function fetchUserEmail(Tenant $tenant, string $code, string $redirectUri): string
    {
        $config = $this->loadConfig($tenant);

        $client = new Microsoft([
            'clientId' => $config['client_id'],
            'clientSecret' => $config['client_secret'],
            'redirectUri' => $redirectUri,
        ]);

        /** @var AccessToken $token */
        $token = $client->getAccessToken('authorization_code', ['code' => $code]);

        /** @var \League\OAuth2\Client\Provider\ResourceOwnerInterface $msUser */
        $msUser = $client->getResourceOwner($token);

        /** @var array<string, mixed> $rawData */
        $rawData = $msUser->toArray();

        // Microsoft Graph returns email in 'mail' field, falls back to
        // 'userPrincipalName' (Azure AD username, looks like an email).
        $email = '';
        if (isset($rawData['mail']) && \is_string($rawData['mail']) && '' !== $rawData['mail']) {
            $email = $rawData['mail'];
        } elseif (isset($rawData['userPrincipalName']) && \is_string($rawData['userPrincipalName'])) {
            $email = $rawData['userPrincipalName'];
        }

        if ('' === $email) {
            throw new RuntimeException('Microsoft user has no email claim (mail or userPrincipalName).');
        }

        // #2728 — the directory restriction must be explicit. `common` is the
        // multi-tenant endpoint: it accepts ANY Microsoft account (including
        // personal ones), and the resolver would auto-provision each as a
        // `viewer` with read access to the tenant's catalogue. Refuse rather
        // than silently accept the whole world.
        $directory = $config['tenant_id'] ?? null;
        if (!\is_string($directory) || '' === $directory || 'common' === $directory) {
            throw new RuntimeException(
                'Microsoft SSO provider has no directory (tenant_id) restriction — refusing to authenticate. '
                .'Set the Azure directory id on the provider before enabling SSO.',
            );
        }

        return $email;
    }

    /**
     * @return array{client_id: string, client_secret: string, tenant_id?: string}
     */
    private function loadConfig(Tenant $tenant): array
    {
        $provider = $this->providers->findByTenantAndKind($tenant->getId(), SsoProvider::KIND_MICROSOFT_365);
        if (null === $provider) {
            throw new RuntimeException(\sprintf(
                'Microsoft 365 SSO not configured for tenant "%s".',
                $tenant->getCode(),
            ));
        }
        if (!$provider->isEnabled()) {
            throw new RuntimeException(\sprintf(
                'Microsoft 365 SSO is disabled for tenant "%s".',
                $tenant->getCode(),
            ));
        }

        $config = $provider->getConfig();
        if (!isset($config['client_id'], $config['client_secret'])) {
            throw new RuntimeException('Microsoft SSO config missing client_id or client_secret.');
        }

        /** @var array{client_id: string, client_secret: string, tenant_id?: string} $typedConfig */
        $typedConfig = $config;

        return $typedConfig;
    }
}

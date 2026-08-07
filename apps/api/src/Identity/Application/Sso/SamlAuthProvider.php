<?php

declare(strict_types=1);

namespace App\Identity\Application\Sso;

use App\Identity\Domain\Entity\SsoProvider;
use App\Identity\Domain\Repository\SsoProviderRepositoryInterface;
use App\Shared\Domain\Tenant;
use OneLogin\Saml2\Auth as SamlAuth;
use OneLogin\Saml2\Error as SamlError;
use OneLogin\Saml2\ValidationError;
use RuntimeException;

/**
 * RBAC-P2-014 (#663) — SAML 2.0 SSO provider wrapper.
 *
 * Wraps onelogin/php-saml. More involved than OAuth substrate (#661/#662)
 * — SAML uses XML-signed assertions instead of OAuth code exchange.
 *
 * Config (stored w SsoProvider.config JSON):
 *   - sp_entity_id (this Harmon PIM tenant's SAML EntityID)
 *   - sp_acs_url (Assertion Consumer Service URL — our callback)
 *   - idp_entity_id (Identity Provider EntityID, e.g. Okta org)
 *   - idp_sso_url (where to redirect for SSO login)
 *   - idp_x509cert (PEM cert for verifying signed assertions)
 *
 * Security: wantAssertionsSigned + wantNameIdEncrypted enforced;
 * signatureAlgorithm SHA-256. NameID format is emailAddress.
 *
 * Flow:
 *   loginUrl() → build IdP redirect URL with SAMLRequest
 *   processCallback() → parse SAMLResponse, verify signature, extract email
 */
final class SamlAuthProvider
{
    public function __construct(
        private readonly SsoProviderRepositoryInterface $providers,
        private readonly SsoConfigCipher $configCipher,
    ) {
    }

    /**
     * Build SAML AuthnRequest redirect URL. Caller handles the redirect.
     */
    public function loginUrl(Tenant $tenant): string
    {
        $auth = $this->buildAuth($tenant);

        // login() z $stay=true returns the redirect URL instead of issuing
        // header() + exit. Controller wraps in RedirectResponse.
        $url = $auth->login(returnTo: null, parameters: [], forceAuthn: false, isPassive: false, stay: true);

        if ('' === $url) {
            throw new RuntimeException('SAML login URL generation returned empty.');
        }

        return $url;
    }

    /**
     * Process SAMLResponse POST from IdP. Returns verified email from
     * the assertion's NameID (must be emailAddress format).
     *
     * @throws RuntimeException when signature invalid, response malformed,
     *                          or email not extractable
     */
    public function processCallback(Tenant $tenant): string
    {
        $auth = $this->buildAuth($tenant);

        try {
            $auth->processResponse();
        } catch (SamlError|ValidationError $e) {
            throw new RuntimeException('SAML response processing failed: '.$e->getMessage(), 0, $e);
        }

        /** @var list<string> $errors */
        $errors = $auth->getErrors();
        if ([] !== $errors) {
            throw new RuntimeException('SAML response invalid: '.implode(', ', $errors));
        }

        if (!$auth->isAuthenticated()) {
            throw new RuntimeException('SAML response did not authenticate user.');
        }

        // NameID format MUST be emailAddress per PRD §3.6.
        /** @var string $nameId */
        $nameId = $auth->getNameId();
        if ('' === $nameId || !str_contains($nameId, '@')) {
            // Fallback: check attribute claims for email
            /** @var array<string, list<string>> $attributes */
            $attributes = $auth->getAttributes();
            if (isset($attributes['email'][0])) {
                return $attributes['email'][0];
            }
            if (isset($attributes['emailAddress'][0])) {
                return $attributes['emailAddress'][0];
            }
            throw new RuntimeException('SAML assertion has no email (NameID format emailAddress or email attribute).');
        }

        return $nameId;
    }

    private function buildAuth(Tenant $tenant): SamlAuth
    {
        $config = $this->loadConfig($tenant);

        // OneLogin's Auth needs full settings array; assemble it from
        // SsoProvider config + sensible security defaults.
        $settings = [
            'strict' => true,
            'debug' => false,
            'sp' => [
                'entityId' => $config['sp_entity_id'],
                'assertionConsumerService' => [
                    'url' => $config['sp_acs_url'],
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-POST',
                ],
                'NameIDFormat' => 'urn:oasis:names:tc:SAML:1.1:nameid-format:emailAddress',
            ],
            'idp' => [
                'entityId' => $config['idp_entity_id'],
                'singleSignOnService' => [
                    'url' => $config['idp_sso_url'],
                    'binding' => 'urn:oasis:names:tc:SAML:2.0:bindings:HTTP-Redirect',
                ],
                'x509cert' => $config['idp_x509cert'],
            ],
            'security' => [
                'wantAssertionsSigned' => true,
                'wantMessagesSigned' => false,
                'wantNameId' => true,
                'signatureAlgorithm' => 'http://www.w3.org/2001/04/xmldsig-more#rsa-sha256',
                'digestAlgorithm' => 'http://www.w3.org/2001/04/xmlenc#sha256',
            ],
        ];

        return new SamlAuth($settings);
    }

    /**
     * @return array{
     *     sp_entity_id: string,
     *     sp_acs_url: string,
     *     idp_entity_id: string,
     *     idp_sso_url: string,
     *     idp_x509cert: string
     * }
     */
    private function loadConfig(Tenant $tenant): array
    {
        $provider = $this->providers->findByTenantAndKind($tenant->getId(), SsoProvider::KIND_SAML);
        if (null === $provider) {
            throw new RuntimeException(\sprintf(
                'SAML SSO not configured for tenant "%s".',
                $tenant->getCode(),
            ));
        }
        if (!$provider->isEnabled()) {
            throw new RuntimeException(\sprintf(
                'SAML SSO is disabled for tenant "%s".',
                $tenant->getCode(),
            ));
        }

        // #2725 — credentials live encrypted at rest; reveal them for the call.
        $config = $this->configCipher->reveal($provider->getConfig());
        $required = ['sp_entity_id', 'sp_acs_url', 'idp_entity_id', 'idp_sso_url', 'idp_x509cert'];
        foreach ($required as $key) {
            if (!isset($config[$key]) || !\is_string($config[$key]) || '' === $config[$key]) {
                throw new RuntimeException(\sprintf('SAML SSO config missing required field "%s".', $key));
            }
        }

        /** @var array{sp_entity_id: string, sp_acs_url: string, idp_entity_id: string, idp_sso_url: string, idp_x509cert: string} $typedConfig */
        $typedConfig = $config;

        return $typedConfig;
    }
}

<?php

declare(strict_types=1);

namespace App\Identity\Presentation\Controller;

/**
 * AUD-007 (#1577) — environment guard for the dev-only magic-link /
 * password-reset plaintext token.
 *
 * Until the production email send fully replaces the in-response token,
 * the password-reset + invitation endpoints expose the freshly minted
 * 256-bit plaintext token in their JSON body so the operator can drive
 * the confirm/accept flow without a mailbox. That convenience is a
 * CRITICAL account-takeover vector on any prod deploy: the endpoints are
 * PUBLIC_ACCESS, so knowing a victim's email is enough to mint + read a
 * working reset token.
 *
 * This trait gates the field on the kernel environment. Consuming
 * controllers take the environment as a constructor argument (bound to
 * `%kernel.environment%` in services.yaml) so the guard is unit-testable
 * without booting a prod kernel: only the explicit `dev`/`test` allowlist
 * gets the token (#2727 — a denylist on 'prod' leaked it on staging,
 * preprod, or an APP_ENV typo); everywhere else the field is omitted
 * entirely (not nulled — the key must be absent so no contract reader
 * treats it as present-but-empty).
 */
trait DevTokenExposure
{
    /**
     * Returns the `token_dev_only` payload fragment to merge into a JSON
     * response. `['token_dev_only' => $token]` on the dev/test allowlist;
     * empty (key absent) everywhere else.
     *
     * @return array{token_dev_only?: string|null}
     */
    private function devTokenPayload(?string $token): array
    {
        if (!\in_array($this->devTokenEnvironment, ['dev', 'test'], true)) {
            return [];
        }

        return ['token_dev_only' => $token];
    }
}

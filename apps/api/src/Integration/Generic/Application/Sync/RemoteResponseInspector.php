<?php

declare(strict_types=1);

namespace App\Integration\Generic\Application\Sync;

use JsonException;

use const JSON_THROW_ON_ERROR;

/**
 * Detects an application-level error in a remote 2xx response body (APIC, #1886).
 *
 * Many REST APIs answer `HTTP 200` and report per-record failures **in the
 * body** — IdoSell returns `{"errors":{"faultCode":N,"faultString":"…"}}`,
 * others use a top-level `error`/`errors`. Trusting only the HTTP status makes
 * the sync count every record as success even when the remote rejected it.
 * This inspector surfaces a generic error envelope so {@see OutboundSyncRunner}
 * can reclassify the record as failed and log the reason.
 *
 * Conservative: an unparseable/empty body, or an empty `errors` envelope
 * (`faultCode:0, faultString:""` / `errors:[]`), is treated as success.
 */
final readonly class RemoteResponseInspector
{
    public static function errorIn(string $body): ?string
    {
        $body = trim($body);
        if ('' === $body) {
            return null;
        }

        try {
            $decoded = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        if (!\is_array($decoded)) {
            return null;
        }

        // RPC-style status envelope (BaseLinker): HTTP 200 with
        // `{"status":"ERROR","error_code":…,"error_message":…}` (#2634).
        if ('ERROR' === ($decoded['status'] ?? null)) {
            $message = $decoded['error_message'] ?? null;
            if (\is_string($message) && '' !== trim($message)) {
                return trim($message);
            }
            $code = $decoded['error_code'] ?? null;
            if ((\is_string($code) && '' !== trim($code)) || \is_int($code)) {
                return \sprintf('error_code %s', (string) $code);
            }

            return 'remote returned status ERROR';
        }

        // Top-level `error` string (common REST shape).
        $error = $decoded['error'] ?? null;
        if (\is_string($error) && '' !== trim($error)) {
            return trim($error);
        }

        $errors = $decoded['errors'] ?? null;
        if (!\is_array($errors)) {
            return null;
        }

        // IdoSell-style fault envelope.
        $faultString = $errors['faultString'] ?? null;
        if (\is_string($faultString) && '' !== trim($faultString)) {
            return trim($faultString);
        }
        $faultCode = $errors['faultCode'] ?? null;
        if ((\is_int($faultCode) && 0 !== $faultCode)
            || (\is_string($faultCode) && '' !== $faultCode && '0' !== $faultCode)) {
            return \sprintf('faultCode %s', (string) $faultCode);
        }

        // A non-empty list of errors (no fault envelope).
        if (array_is_list($errors) && [] !== $errors) {
            return \sprintf('remote returned %d error(s)', \count($errors));
        }

        return null;
    }
}

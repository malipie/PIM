<?php

declare(strict_types=1);

namespace App\Export\Feed\Infrastructure\Logging;

use Psr\Log\LoggerInterface;
use Psr\Log\LoggerTrait;
use Stringable;

/**
 * PSR-3 decorator that redacts feed URL tokens before any record is written
 * (XMLF-P3-06 [SEC], ADR-0023 §6.9 — "never the full token in an access log").
 *
 * The pull URL carries the credential in the path, so the framework itself
 * leaks it without help: RouterListener info-logs `route_parameters.token` +
 * `request_uri` for every matched request, and ErrorListener warn-logs
 * "No route found for GET …/pull/…/<token>.xml" for near-miss paths. Both
 * flow through the single `logger` service (no monolog in this app), so one
 * decorator closes every sink. Redaction keeps a 6-char prefix for log
 * correlation — enough to tell tokens apart, useless as a credential.
 */
final class FeedTokenRedactingLogger implements LoggerInterface
{
    use LoggerTrait;

    /** Full pull URLs anywhere in a string (message, request_uri, referer…). */
    private const string URL_PATTERN = '#(/api/feeds/pull/[0-9a-fA-F-]{36}/)([A-Za-z0-9_-]{10,})(\.xml)#';

    /** Bare token values, e.g. RouterListener's `route_parameters['token']`. */
    private const string TOKEN_VALUE_PATTERN = '#^[A-Za-z0-9_-]{16,}$#';

    public function __construct(
        private readonly LoggerInterface $inner,
    ) {
    }

    /**
     * @param mixed[] $context
     */
    public function log($level, Stringable|string $message, array $context = []): void
    {
        $text = (string) $message;
        if (str_contains($text, '/api/feeds/pull/')) {
            $text = $this->redactUrls($text);
        }

        array_walk_recursive($context, function (mixed &$value, string|int $key): void {
            if (!\is_string($value)) {
                return;
            }
            if (str_contains($value, '/api/feeds/pull/')) {
                $value = $this->redactUrls($value);
            }
            if ('token' === $key && 1 === preg_match(self::TOKEN_VALUE_PATTERN, $value)) {
                $value = $this->redactToken($value);
            }
        });

        $this->inner->log($level, $text, $context);
    }

    private function redactUrls(string $text): string
    {
        $redacted = preg_replace_callback(
            self::URL_PATTERN,
            fn (array $m): string => $m[1].$this->redactToken($m[2]).$m[3],
            $text,
        );

        return $redacted ?? $text;
    }

    private function redactToken(string $token): string
    {
        return substr($token, 0, 6).'…redacted';
    }
}

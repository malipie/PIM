<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export\Feed;

use App\Export\Feed\Infrastructure\Logging\FeedTokenRedactingLogger;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Stringable;

use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * XMLF-P3-06 [SEC] — the full feed URL token never reaches a log sink.
 *
 * The framework logs the credential-bearing pull path on its own
 * (RouterListener `route_parameters` + `request_uri`, ErrorListener
 * "No route found for …"), so the decorator must scrub message strings,
 * nested context strings and bare `token` route parameters alike.
 */
final class FeedTokenRedactingLoggerTest extends TestCase
{
    /**
     * Deliberately low-entropy (repeated word) so the secret scanner does not
     * flag a test fixture as a leaked credential; still matches the redactor's
     * token shape (`[A-Za-z0-9_-]{16,}`) like a real 32-char URL token.
     */
    private const string TOKEN = 'feedtokenfeedtokenfeedtoken12345';
    private const string TENANT = '019f044f-2759-7ad8-84e3-1a9ad5c28380';

    private RecordingLogger $inner;

    private FeedTokenRedactingLogger $logger;

    protected function setUp(): void
    {
        $this->inner = new RecordingLogger();
        $this->logger = new FeedTokenRedactingLogger($this->inner);
    }

    #[Test]
    public function redactsThePullUrlInsideTheMessage(): void
    {
        $this->logger->warning(sprintf(
            'Uncaught PHP Exception: "No route found for GET https://pim.localhost/api/feeds/pull/%s/%s.xml"',
            self::TENANT,
            self::TOKEN,
        ));

        $message = $this->inner->records[0]['message'];
        self::assertStringNotContainsString(self::TOKEN, $message);
        self::assertStringContainsString('feedto…redacted.xml', $message);
        self::assertStringContainsString(self::TENANT, $message, 'tenantId is not a secret and stays for correlation');
    }

    #[Test]
    public function redactsRouteParametersAndRequestUriInContext(): void
    {
        $this->logger->info('Matched route "{route}".', [
            'route' => 'pim_feeds_pull',
            'route_parameters' => [
                '_route' => 'pim_feeds_pull',
                'tenantId' => self::TENANT,
                'token' => self::TOKEN,
            ],
            'request_uri' => sprintf('https://pim.localhost/api/feeds/pull/%s/%s.xml', self::TENANT, self::TOKEN),
            'method' => 'GET',
        ]);

        $flat = json_encode($this->inner->records[0]['context'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        self::assertStringNotContainsString(self::TOKEN, $flat);
        self::assertStringContainsString('feedto…redacted', $flat);
    }

    #[Test]
    public function leavesUnrelatedRecordsUntouched(): void
    {
        $this->logger->error('Plain failure.', ['feed_id' => self::TENANT, 'token_hash' => 'sha256:abcd', 'attempt' => 3]);

        self::assertSame('Plain failure.', $this->inner->records[0]['message']);
        self::assertSame(
            ['feed_id' => self::TENANT, 'token_hash' => 'sha256:abcd', 'attempt' => 3],
            $this->inner->records[0]['context'],
        );
    }

    #[Test]
    public function shortLookalikeTokenValuesAreNotRedacted(): void
    {
        // A short value under a `token` key (e.g. a CSRF token id) is not a
        // feed credential; only ≥16-char URL-token-shaped values are scrubbed.
        $this->logger->debug('csrf', ['token' => 'abc123']);

        self::assertSame(['token' => 'abc123'], $this->inner->records[0]['context']);
    }
}

/**
 * Captures records after redaction so assertions see what a real sink would.
 */
final class RecordingLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string, context: mixed[]}> */
    public array $records = [];

    /**
     * @param mixed[] $context
     */
    public function log($level, Stringable|string $message, array $context = []): void
    {
        $this->records[] = ['level' => $level, 'message' => (string) $message, 'context' => $context];
    }
}

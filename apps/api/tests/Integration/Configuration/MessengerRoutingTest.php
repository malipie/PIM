<?php

declare(strict_types=1);

namespace App\Tests\Integration\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionClass;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Handler\HandlersLocatorInterface;
use Symfony\Component\Messenger\Transport\Sender\SendersLocatorInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * Locks in the explicit Messenger transport routing posture for
 * async-routed messages (audit MEDIUM-007).
 *
 * Async-routed message classes (those that target a non-`sync`
 * transport) MUST have an entry in `config/packages/messenger.yaml`
 * routing block — implicit/default behavior diverges silently between
 * environments once an async transport ships in production.
 *
 * Synchronous messages (Application Commands, Domain Events handled
 * by in-process subscribers) intentionally stay UNROUTED — Symfony's
 * default in-process dispatch is the established and tested behavior;
 * routing them through the `sync` transport adds a Send → Receive hop
 * that under bulk seed paths shows up as ErrorChunk accumulation
 * before GC reclaims them.
 */
final class MessengerRoutingTest extends KernelTestCase
{
    /**
     * Sources of truth — must match the `routing:` block in
     * `messenger.yaml`. Each async-routed message class is enumerated
     * here so a future deletion / typo flips this test red.
     *
     * @return iterable<string, array{class-string, string}>
     */
    public static function routedMessageProvider(): iterable
    {
        yield 'catalog: object values changed (background reindex)' => [
            \App\Catalog\Application\Message\ObjectValuesChangedMessage::class,
            'async',
        ];

        yield 'catalog: object categories changed (#1314 placement reconcile)' => [
            \App\Catalog\Contracts\Event\ObjectCategoriesChanged::class,
            'async',
        ];

        yield 'channel: reconcile placements for category (#1314 back-fill)' => [
            \App\Channel\Application\Message\ReconcileChannelPlacementsForCategory::class,
            'async',
        ];

        yield 'export: run export (async to import_export queue)' => [
            \App\Export\Domain\Message\RunExportMessage::class,
            'import',
        ];

        yield 'export: run feed regeneration (XMLF-P4-02, reuses import transport)' => [
            \App\Export\Feed\Domain\Message\RunFeedMessage::class,
            'import',
        ];

        yield 'integration: inbound sync (APIC-P3-04, reuses import transport)' => [
            \App\Integration\Generic\Domain\Message\InboundSyncMessage::class,
            'import',
        ];

        yield 'integration: outbound sync (APIC-P3-06, reuses import transport)' => [
            \App\Integration\Generic\Domain\Message\OutboundSyncMessage::class,
            'import',
        ];
    }

    /**
     * @param class-string $messageClass
     */
    #[Test]
    #[DataProvider('routedMessageProvider')]
    public function eachAsyncRoutedMessageClassPointsAtItsExplicitTransport(string $messageClass, string $expectedTransport): void
    {
        $locator = self::getContainer()->get('messenger.senders_locator');
        self::assertInstanceOf(SendersLocatorInterface::class, $locator);

        $reflection = new ReflectionClass($messageClass);
        $message = $reflection->newInstanceWithoutConstructor();
        $envelope = new Envelope($message);

        $senderAliases = [];
        foreach ($locator->getSenders($envelope) as $alias => $_sender) {
            $senderAliases[] = $alias;
        }

        self::assertNotEmpty(
            $senderAliases,
            \sprintf(
                '%s has no Messenger sender configured — async-routed messages must be routed explicitly (audit MEDIUM-007).',
                $messageClass,
            ),
        );
        self::assertContains(
            $expectedTransport,
            $senderAliases,
            \sprintf(
                '%s should be routed to "%s" but is routed to "%s".',
                $messageClass,
                $expectedTransport,
                implode('|', $senderAliases),
            ),
        );
    }

    /**
     * Defence in depth — root cause of the reopened #1885 (dev worker silently
     * dropping outbound syncs).
     *
     * A routed async message with NO registered handler is acked by the bus as
     * "handled successfully" (`allow_no_handlers: true`, required so handler-less
     * domain events don't raise NoHandlerForMessageException). On a worker that
     * means the work is dropped with no error in the log. Every message routed to
     * a worker transport above therefore MUST resolve to at least one handler —
     * this locks that in at the code level (the runtime cause, a stale non-debug
     * compiled container, is handled by the worker entrypoint rebuilding it).
     *
     * @param class-string $messageClass
     */
    #[Test]
    #[DataProvider('routedMessageProvider')]
    public function eachAsyncRoutedMessageHasARegisteredHandler(string $messageClass, string $expectedTransport): void
    {
        $locator = self::getContainer()->get('messenger.bus.default.messenger.handlers_locator');
        self::assertInstanceOf(HandlersLocatorInterface::class, $locator);

        $reflection = new ReflectionClass($messageClass);
        $message = $reflection->newInstanceWithoutConstructor();
        $handlers = iterator_to_array($locator->getHandlers(new Envelope($message)));

        self::assertNotEmpty(
            $handlers,
            \sprintf(
                '%s is routed to the "%s" transport but resolves to no handler — the bus '.
                'would ack it as "handled successfully" (allow_no_handlers) and the worker '.
                'would silently drop the work.',
                $messageClass,
                $expectedTransport,
            ),
        );
    }

    /**
     * IMP2-2.9 (#1485, ADR-0019 D11) — both worker transports that can hit
     * per-tenant bulk-lock contention (`async` reindex/agent jobs, `import`
     * runs) must use the long-backoff retry policy: 5 retries, 30s → 60 →
     * 120 → 240 → capped 300s ≈ 16 min, so a contended job rides out a
     * realistic import window before dead-lettering. A short/default policy
     * would burn the retries in ~7s and dead-letter a still-running import.
     *
     * @return iterable<string, array{string}>
     */
    public static function lockAwareTransportProvider(): iterable
    {
        yield 'async (reindex / agent jobs)' => ['async'];
        yield 'import (dedicated import queue)' => ['import'];
    }

    #[Test]
    #[DataProvider('lockAwareTransportProvider')]
    public function lockAwareTransportUsesLongBackoffRetryStrategy(string $transport): void
    {
        self::bootKernel();
        $projectDir = self::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDir);

        /** @var array{framework?: array{messenger?: array{transports?: array<string, mixed>}}} $config */
        $config = Yaml::parseFile($projectDir.'/config/packages/messenger.yaml');
        $transports = $config['framework']['messenger']['transports'] ?? [];
        self::assertArrayHasKey($transport, $transports, "messenger.yaml must define the '{$transport}' transport.");

        $definition = $transports[$transport];
        self::assertIsArray($definition, "Transport '{$transport}' must be the verbose {dsn, retry_strategy} form, not a flat DSN string.");
        self::assertArrayHasKey('retry_strategy', $definition, "Transport '{$transport}' must declare a retry_strategy (bulk-lock contention rides out the import window).");

        $retry = $definition['retry_strategy'];
        self::assertIsArray($retry, "Transport '{$transport}' retry_strategy must be a mapping.");
        self::assertSame(5, $retry['max_retries'] ?? null, "Transport '{$transport}' retry_strategy.max_retries must be 5.");
        self::assertSame(30000, $retry['delay'] ?? null, "Transport '{$transport}' retry_strategy.delay must be 30000ms.");
        self::assertSame(2, $retry['multiplier'] ?? null, "Transport '{$transport}' retry_strategy.multiplier must be 2.");
        self::assertSame(300000, $retry['max_delay'] ?? null, "Transport '{$transport}' retry_strategy.max_delay must be 300000ms.");
    }
}

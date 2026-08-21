<?php

declare(strict_types=1);

namespace App\Tests\Unit\Export;

use App\Export\Domain\Entity\ExportSession;
use App\Export\Domain\Enum\ExportEncoding;
use App\Export\Domain\Enum\ExportFormat;
use App\Export\Domain\Enum\ExportSource;
use App\Export\Domain\Enum\ExportStatus;
use App\Export\Domain\Enum\ExportTargetScope;
use App\Shared\Domain\Tenant;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ExportSessionTest extends TestCase
{
    #[Test]
    public function constructorDefaultsAreSane(): void
    {
        $session = $this->makeSession();

        self::assertInstanceOf(Uuid::class, $session->getId());
        self::assertSame(ExportStatus::Pending, $session->getStatus());
        self::assertSame(ExportFormat::Xlsx, $session->getFormat());
        self::assertSame(ExportSource::ListContext, $session->getSource());
        self::assertSame(ExportTargetScope::Selected, $session->getTargetScope());
        self::assertSame(0, $session->getSuccessCount());
        self::assertSame(0, $session->getTargetCount());
        self::assertNull($session->getCompletedAt());
        self::assertNull($session->getFilePath());
        self::assertNull($session->getErrorMessage());
        self::assertTrue($session->includesVariants());
    }

    #[Test]
    public function pendingTransitionsToRunning(): void
    {
        $session = $this->makeSession();
        $session->markRunning();

        self::assertSame(ExportStatus::Running, $session->getStatus());
    }

    #[Test]
    public function runningTransitionsToDoneAndPopulatesFileMetadata(): void
    {
        $session = $this->makeSession();
        $session->markRunning();
        $session->markDone(42, 'exports/tenant-id/session-id.xlsx', 12345);

        self::assertSame(ExportStatus::Done, $session->getStatus());
        self::assertSame(42, $session->getSuccessCount());
        self::assertSame('exports/tenant-id/session-id.xlsx', $session->getFilePath());
        self::assertSame(12345, $session->getFileSizeBytes());
        self::assertNotNull($session->getCompletedAt());
        self::assertNotNull($session->getDurationMs());
        self::assertGreaterThanOrEqual(0, $session->getDurationMs());
    }

    #[Test]
    public function pendingCanGoDirectlyToDoneForSyncPath(): void
    {
        // Sync export (<100 rows) writes the whole session in one tx — there is
        // no intermediate `running` state when the worker is the request handler.
        $session = $this->makeSession();
        $session->markDone(7, 'exports/t/s.csv', 256);

        self::assertSame(ExportStatus::Done, $session->getStatus());
        self::assertSame(7, $session->getSuccessCount());
    }

    #[Test]
    public function pendingCanFailDirectly(): void
    {
        $session = $this->makeSession();
        $session->markError('builder blew up');

        self::assertSame(ExportStatus::Error, $session->getStatus());
        self::assertSame('builder blew up', $session->getErrorMessage());
        self::assertNotNull($session->getCompletedAt());
    }

    #[Test]
    public function terminalStatusBlocksFurtherTransitions(): void
    {
        $session = $this->makeSession();
        $session->markDone(1, 'p', 1);

        $this->expectException(LogicException::class);
        $session->markRunning();
    }

    #[Test]
    public function downloadableOnlyWhenDone(): void
    {
        $session = $this->makeSession();
        self::assertFalse($session->getStatus()->isDownloadable());

        $session->markRunning();
        self::assertFalse($session->getStatus()->isDownloadable());

        $session->markDone(1, 'p', 1);
        self::assertTrue($session->getStatus()->isDownloadable());
    }

    #[Test]
    public function isSelfOwnedByReturnsTrueOnlyForOwner(): void
    {
        $userId = Uuid::v7();
        $other = Uuid::v7();
        $session = $this->makeSession($userId);

        self::assertTrue($session->isSelfOwnedBy($userId));
        self::assertFalse($session->isSelfOwnedBy($other));
    }

    #[Test]
    public function assignTenantOnceOnly(): void
    {
        $session = $this->makeSession();
        $tenant = new Tenant('Acme', 'acme');
        $session->assignTenant($tenant);

        self::assertSame($tenant, $session->getTenant());

        $this->expectException(LogicException::class);
        $session->assignTenant(new Tenant('Other', 'other'));
    }

    #[Test]
    public function csvEncodingCarriedThrough(): void
    {
        $session = new ExportSession(
            userId: Uuid::v7(),
            source: ExportSource::CentralTab,
            format: ExportFormat::Csv,
            targetScope: ExportTargetScope::All,
            selectedColumns: ['sku', 'name'],
            encoding: ExportEncoding::Utf8Bom,
        );

        self::assertSame(ExportFormat::Csv, $session->getFormat());
        self::assertSame(ExportEncoding::Utf8Bom, $session->getEncoding());
        $encoding = $session->getEncoding();
        self::assertNotNull($encoding);
        self::assertSame("\xEF\xBB\xBF", $encoding->bomBytes());
    }

    #[Test]
    public function targetCountRejectsNegative(): void
    {
        $session = $this->makeSession();

        $this->expectException(LogicException::class);
        $session->setTargetCount(-1);
    }

    #[Test]
    public function aDoneSessionWhoseFileNeverArrivedStopsClaimingSuccess(): void
    {
        // #2945 — the run counted every row and then failed to hand the file
        // to storage. It used to stay `done`: a green row with a real count
        // and a download button that answered "Pobieranie nie powiodło się".
        $session = $this->makeSession();
        $session->markRunning();
        $session->markDone(10056, '/tmp/pim-export-async-abc.xlsx', 2044150);
        $completedAt = $session->getCompletedAt();
        $durationMs = $session->getDurationMs();

        $session->markFileUnavailable('Plik eksportu nie trafił do magazynu — uruchom eksport ponownie.');

        self::assertSame(ExportStatus::Error, $session->getStatus());
        self::assertStringContainsString('uruchom eksport ponownie', (string) $session->getErrorMessage());
        // A path into a worker's /tmp is unservable by any other container.
        self::assertNull($session->getFilePath());
        // The run really did happen: its count and timing are not rewritten.
        self::assertSame(10056, $session->getSuccessCount());
        self::assertSame($completedAt, $session->getCompletedAt());
        self::assertSame($durationMs, $session->getDurationMs());
    }

    #[Test]
    public function fileUnavailableAlsoCoversARunThatNeverReachedDone(): void
    {
        $session = $this->makeSession();
        $session->markRunning();

        $session->markFileUnavailable('storage down');

        self::assertSame(ExportStatus::Error, $session->getStatus());
        self::assertNull($session->getFilePath());
        self::assertNotNull($session->getCompletedAt());
    }

    private function makeSession(?Uuid $userId = null): ExportSession
    {
        return new ExportSession(
            userId: $userId ?? Uuid::v7(),
            source: ExportSource::ListContext,
            format: ExportFormat::Xlsx,
            targetScope: ExportTargetScope::Selected,
            selectedColumns: ['sku', 'name', 'description.pl'],
        );
    }
}

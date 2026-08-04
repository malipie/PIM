<?php

declare(strict_types=1);

namespace App\Tests\Api\Import;

use App\Asset\Contracts\AssetIngestorInterface;
use App\Asset\Domain\Repository\AssetRepositoryInterface;
use App\Catalog\Contracts\Service\ProductAssetLinker;
use App\Catalog\Domain\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectType;
use App\Catalog\Domain\Entity\ObjectTypeAttribute;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\AttributeRepositoryInterface;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectValueRepositoryInterface;
use App\Import\Application\Handler\ImageDownloadHandler;
use App\Import\Application\Service\ImportProgressPublisher;
use App\Import\Application\Service\Media\SsrfGuard;
use App\Import\Domain\Entity\ImportSession;
use App\Import\Domain\Enum\ImportSessionStatus;
use App\Import\Domain\Message\ImageDownloadJob;
use App\Import\Domain\Message\ImageDownloadMessage;
use App\Import\Domain\Repository\ImportSessionRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use App\Tests\Api\Catalog\CatalogApiTestCase;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use RuntimeException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Uid\Uuid;
use ZipArchive;

use const JSON_THROW_ON_ERROR;

/**
 * IMP2-1.12 (#1475) — the image-download handler: SSRF guard, HTTP caps,
 * content-hash dedup, asset link, envelope write, counters. The handler is
 * built with a MockHttpClient and public-IP-literal URLs so no real network or
 * DNS is touched (deterministic in CI).
 */
final class ImageDownloadHandlerTest extends CatalogApiTestCase
{
    // 1×1 transparent PNG.
    private const string PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAAC0lEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==';
    // 1×1 red PNG — distinct bytes (distinct content hash → a second ingest).
    private const string PNG_RED = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function setUp(): void
    {
        parent::setUp();
        // product_assets is a raw-SQL junction (no ORM entity), so the
        // metadata-built test schema omits it; create it for the link path.
        $this->em()->getConnection()->executeStatement(
            'CREATE TABLE IF NOT EXISTS product_assets (asset_id UUID NOT NULL, product_id UUID NOT NULL, position INT DEFAULT NULL, created_at TIMESTAMP(0) WITHOUT TIME ZONE NOT NULL, PRIMARY KEY (asset_id, product_id))',
        );
    }

    #[Test]
    public function downloadsLinksAndWritesEnvelope(): void
    {
        $png = base64_decode(self::PNG, true);
        \assert(false !== $png);
        [$sessionId, $objectId] = $this->seed();

        $handler = $this->handler(new MockHttpClient([
            new MockResponse($png, ['http_code' => 200]),
        ]));
        $handler($this->message($sessionId, $objectId, ['https://93.184.216.34/photo.png']));

        $em = $this->em();
        $em->clear();
        $session = $em->find(ImportSession::class, $sessionId);
        \assert($session instanceof ImportSession);
        self::assertSame(1, $session->getImagesDownloaded());
        self::assertSame(0, $session->getImagesFailed());
        self::assertSame(ImportSessionStatus::Success, $session->getStatus(), 'last media batch finalizes the run');

        $assetId = $em->getConnection()->fetchOne('SELECT id FROM assets ORDER BY created_at DESC LIMIT 1');
        self::assertIsString($assetId);
        // E1 (#1727) — the imported image lands in the object's library folder
        // (`product-<objectId>`), matching the manual product upload convention.
        $folder = $em->getConnection()->fetchOne('SELECT folder_code FROM assets WHERE id = :id', ['id' => $assetId]);
        self::assertSame('product-'.$objectId, $folder, 'imported image is filed under the object folder');
        $links = $em->getConnection()->fetchOne('SELECT COUNT(*) FROM product_assets WHERE product_id = :p', ['p' => $objectId]);
        self::assertSame(1, (int) (\is_scalar($links) ? $links : 0), 'asset linked to the product');
        $envelope = $em->getConnection()->fetchOne(
            "SELECT ov.value FROM object_values ov JOIN attributes a ON a.id=ov.attribute_id WHERE a.code='photo' AND ov.object_id = :p",
            ['p' => $objectId],
        );
        \assert(\is_string($envelope));
        self::assertSame(['asset_id' => $assetId], json_decode($envelope, true, 512, JSON_THROW_ON_ERROR));
    }

    #[Test]
    public function multipleDistinctImagesAllIngestUnderAStableTenant(): void
    {
        // Regression: the first ingest flushes + clears the shared EM, which
        // detached the TenantContext tenant, so the SECOND distinct image
        // failed with "new entity found through Asset#tenant". Two distinct
        // PNGs (distinct content hash → two real ingests) must both land.
        $png = base64_decode(self::PNG, true);
        $red = base64_decode(self::PNG_RED, true);
        \assert(false !== $png && false !== $red);
        [$sessionId, $objectId] = $this->seed();

        $handler = $this->handler(new MockHttpClient([
            new MockResponse($png, ['http_code' => 200]),
            new MockResponse($red, ['http_code' => 200]),
        ]));
        $handler($this->message($sessionId, $objectId, [
            'https://93.184.216.34/one.png',
            'https://93.184.216.34/two.png',
        ]));

        $em = $this->em();
        $em->clear();
        $session = $em->find(ImportSession::class, $sessionId);
        \assert($session instanceof ImportSession);
        self::assertSame(2, $session->getImagesDownloaded(), 'both distinct images ingested');
        self::assertSame(0, $session->getImagesFailed());
        $links = $em->getConnection()->fetchOne('SELECT COUNT(*) FROM product_assets WHERE product_id = :p', ['p' => $objectId]);
        self::assertSame(2, (int) (\is_scalar($links) ? $links : 0), 'both assets linked to the product');
    }

    #[Test]
    public function http404IsLoggedAsImageNotFoundWithoutFailingSession(): void
    {
        [$sessionId, $objectId] = $this->seed();
        $handler = $this->handler(new MockHttpClient([new MockResponse('nope', ['http_code' => 404])]));
        $handler($this->message($sessionId, $objectId, ['https://93.184.216.34/missing.png']));

        $em = $this->em();
        $em->clear();
        $session = $em->find(ImportSession::class, $sessionId);
        \assert($session instanceof ImportSession);
        self::assertSame(0, $session->getImagesDownloaded());
        self::assertSame(1, $session->getImagesFailed());
        self::assertFalse($session->getStatus()->isTerminal() && ImportSessionStatus::Failed === $session->getStatus(), 'media failure never fails the session');
        $logs = $em->getConnection()->fetchOne("SELECT COUNT(*) FROM import_logs WHERE error_type='image_not_found' AND import_session_id = :s", ['s' => $sessionId->toRfc4122()]);
        self::assertSame(1, (int) (\is_scalar($logs) ? $logs : 0));
    }

    #[Test]
    public function privateHostIsRejectedWithoutRequest(): void
    {
        [$sessionId, $objectId] = $this->seed();
        // A throwing client proves no HTTP request is attempted for a blocked host.
        $handler = $this->handler(new MockHttpClient(static function (): MockResponse {
            throw new RuntimeException('SSRF: request must not be made');
        }));
        $handler($this->message($sessionId, $objectId, ['http://169.254.169.254/latest/meta-data/']));

        $em = $this->em();
        $em->clear();
        $session = $em->find(ImportSession::class, $sessionId);
        \assert($session instanceof ImportSession);
        self::assertSame(1, $session->getImagesFailed());
        $logs = $em->getConnection()->fetchOne("SELECT COUNT(*) FROM import_logs WHERE error_type='image_not_found' AND import_session_id = :s", ['s' => $sessionId->toRfc4122()]);
        self::assertSame(1, (int) (\is_scalar($logs) ? $logs : 0));
    }

    #[Test]
    public function nonImageBytesAreImageFormatUnsupported(): void
    {
        [$sessionId, $objectId] = $this->seed();
        $handler = $this->handler(new MockHttpClient([new MockResponse('<html>not an image</html>', ['http_code' => 200])]));
        $handler($this->message($sessionId, $objectId, ['https://93.184.216.34/page.html']));

        $em = $this->em();
        $em->clear();
        $session = $em->find(ImportSession::class, $sessionId);
        \assert($session instanceof ImportSession);
        self::assertSame(0, $session->getImagesDownloaded());
        self::assertSame(1, $session->getImagesFailed());
        $logs = $em->getConnection()->fetchOne("SELECT COUNT(*) FROM import_logs WHERE error_type='image_format_unsupported' AND import_session_id = :s", ['s' => $sessionId->toRfc4122()]);
        self::assertSame(1, (int) (\is_scalar($logs) ? $logs : 0));
    }

    #[Test]
    public function repeatedUrlInBatchDownloadsOnce(): void
    {
        $png = base64_decode(self::PNG, true);
        \assert(false !== $png);
        [$sessionId, $objectId] = $this->seed();
        // Only ONE response queued: a second HTTP request would exhaust the mock
        // and throw — proving the in-batch URL cache hit.
        $handler = $this->handler(new MockHttpClient([new MockResponse($png, ['http_code' => 200])]));
        $handler($this->message($sessionId, $objectId, [
            'https://93.184.216.34/same.png',
            'https://93.184.216.34/same.png',
        ]));

        $em = $this->em();
        $em->clear();
        $session = $em->find(ImportSession::class, $sessionId);
        \assert($session instanceof ImportSession);
        self::assertSame(1, $session->getImagesDownloaded(), 'repeated URL fetched once');
        $assetCount = $em->getConnection()->fetchOne('SELECT COUNT(*) FROM product_assets WHERE product_id = :p', ['p' => $objectId]);
        self::assertSame(1, (int) (\is_scalar($assetCount) ? $assetCount : 0));
    }

    #[Test]
    public function redeliveredBatchDoesNotDoubleCountOrDuplicateLogs(): void
    {
        // #2731 — a retry after a partially applied batch re-runs the whole
        // handler. Content-hash dedup already protects the assets, but the
        // counters were additive and the logs re-inserted, so one redelivery
        // doubled `images_failed` and the operator-facing warnings.
        [$sessionId, $objectId] = $this->seed();
        $batchId = Uuid::v7();
        $message = new ImageDownloadMessage($sessionId, $this->tenantId(), [
            new ImageDownloadJob($objectId, 'photo', null, null, [], ['http://10.0.0.1/private.png'], 2, 'MED-1'),
        ], null, $batchId);

        // Private host → SSRF-rejected without a request, so no HTTP mock needed.
        $this->handler(new MockHttpClient([]))($message);
        $this->handler(new MockHttpClient([]))($message);

        $em = $this->em();
        $em->clear();
        $session = $em->find(ImportSession::class, $sessionId);
        \assert($session instanceof ImportSession);
        self::assertSame(1, $session->getImagesFailed(), 'the redelivery must not add the failure twice');

        $logs = $em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM import_logs WHERE error_type='image_not_found' AND import_session_id = :s",
            ['s' => $sessionId->toRfc4122()],
        );
        self::assertSame(1, (int) (\is_scalar($logs) ? $logs : 0), 'the redelivery must not duplicate the warning log');
    }

    #[Test]
    public function crossTenantExistingUuidIsDroppedWithWarning(): void
    {
        [$sessionId, $objectId] = $this->seed();
        $foreignUuid = Uuid::v7()->toRfc4122(); // never created in this tenant
        $handler = $this->handler(new MockHttpClient([]));
        $job = new ImageDownloadJob($objectId, 'photo', null, null, [$foreignUuid], [], 2, 'MED-1');
        $handler(new ImageDownloadMessage($sessionId, $this->tenantId(), [$job]));

        $em = $this->em();
        $em->clear();
        $logs = $em->getConnection()->fetchOne("SELECT COUNT(*) FROM import_logs WHERE error_type='image_not_found' AND import_session_id = :s", ['s' => $sessionId->toRfc4122()]);
        self::assertSame(1, (int) (\is_scalar($logs) ? $logs : 0), 'unknown/cross-tenant asset id warned');
        $envelope = $em->getConnection()->fetchOne(
            "SELECT COUNT(*) FROM object_values ov JOIN attributes a ON a.id=ov.attribute_id WHERE a.code='photo' AND ov.object_id = :p",
            ['p' => $objectId],
        );
        self::assertSame(0, (int) (\is_scalar($envelope) ? $envelope : 1), 'no envelope written for a dangling id');
    }

    #[Test]
    public function importWithUrlColumnWiresMediaPhaseAndNeverWritesRawUrl(): void
    {
        // End-to-end through the import endpoint: a URL in an Asset column is
        // (a) never written raw to JSONB (assetPayload + the chunk asset
        // prefetch skip it) and (b) routed to the media phase, which runs
        // inline on the sync transport. A private-IP URL is used so the SSRF
        // guard rejects it instantly — proving the full wiring with no network
        // and no flake. (The successful-download path is covered by the
        // handler integration tests above with a MockHttpClient.)
        $this->seedSkuAndPhoto();

        $client = $this->authenticatedClient();
        $path = tempnam(sys_get_temp_dir(), 'pim-url-').'.csv';
        file_put_contents($path, "sku;photo\nURLPROD-1;http://10.0.0.1/x.png\n");
        try {
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'photo' => 'photo'], JSON_THROW_ON_ERROR),
                        'mode' => 'UPSERT',
                    ],
                    'files' => ['file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'url.csv', 'text/csv', null, true)],
                ],
            ]);
            self::assertResponseIsSuccessful();
            $body = json_decode((string) $client->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
            \assert(\is_array($body));
            $sessionId = $body['id'];
            \assert(\is_string($sessionId));

            $em = $this->em();
            $em->clear();
            $session = $em->find(ImportSession::class, Uuid::fromString($sessionId));
            \assert($session instanceof ImportSession);
            // The media phase ran (SSRF-rejected → failed counter), proving the
            // dispatch wiring fired.
            self::assertSame(1, $session->getImagesFailed(), 'media phase dispatched + attempted the URL');
            self::assertFalse($session->isAwaitingMedia(), 'no media batch left pending');

            // The raw URL must NEVER be written to object_values.
            $photoValues = $em->getConnection()->fetchOne(
                "SELECT COUNT(*) FROM object_values ov JOIN attributes a ON a.id=ov.attribute_id JOIN objects o ON o.id=ov.object_id WHERE a.code='photo' AND o.code='URLPROD-1'",
            );
            self::assertSame(0, (int) (\is_scalar($photoValues) ? $photoValues : 1), 'a rejected URL leaves no asset value (and never a raw URL)');

            $logs = $em->getConnection()->fetchOne(
                "SELECT COUNT(*) FROM import_logs WHERE error_type='image_not_found' AND import_session_id = :s",
                ['s' => $sessionId],
            );
            self::assertSame(1, (int) (\is_scalar($logs) ? $logs : 0));
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function bareFilenameTokenIsWarnedNotSilentlyDropped(): void
    {
        // IMP2-1.12 (1c) — a non-UUID, non-URL token (relative path; ZIP/transform
        // territory) surfaces as an image_not_found row warning, and writes no
        // asset value.
        $this->seedSkuAndPhoto();
        $client = $this->authenticatedClient();
        $path = tempnam(sys_get_temp_dir(), 'pim-bare-').'.csv';
        file_put_contents($path, "sku;photo\nBARE-1;product-front.png\n");
        try {
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'photo' => 'photo'], JSON_THROW_ON_ERROR),
                        'mode' => 'UPSERT',
                    ],
                    'files' => ['file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($path, 'bare.csv', 'text/csv', null, true)],
                ],
            ]);
            self::assertResponseIsSuccessful();
            $body = json_decode((string) $client->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
            \assert(\is_array($body));
            $sessionId = $body['id'];
            \assert(\is_string($sessionId));

            $em = $this->em();
            $em->clear();
            $logs = $em->getConnection()->fetchOne(
                "SELECT COUNT(*) FROM import_logs WHERE error_type='image_not_found' AND import_session_id = :s",
                ['s' => $sessionId],
            );
            self::assertSame(1, (int) (\is_scalar($logs) ? $logs : 0), 'bare filename surfaced as a warning');
            $photoValues = $em->getConnection()->fetchOne(
                "SELECT COUNT(*) FROM object_values ov JOIN attributes a ON a.id=ov.attribute_id JOIN objects o ON o.id=ov.object_id WHERE a.code='photo' AND o.code='BARE-1'",
            );
            self::assertSame(0, (int) (\is_scalar($photoValues) ? $photoValues : 1));
        } finally {
            @unlink($path);
        }
    }

    #[Test]
    public function multipartZipImportExtractsLinksAndSetsZipName(): void
    {
        // End-to-end through the endpoint: multipart `file` + `zip_file` +
        // image_source=zip → ZIP staged, filename cell extracted + linked
        // (sync transport runs the media handler inline).
        $png = base64_decode(self::PNG, true);
        \assert(false !== $png);
        $this->seedSkuAndPhoto();

        $client = $this->authenticatedClient();
        $csvPath = tempnam(sys_get_temp_dir(), 'pim-zcsv-').'.csv';
        file_put_contents($csvPath, "sku;photo\nZIPPROD-1;front.jpg\n");
        $zipPath = tempnam(sys_get_temp_dir(), 'pim-zfix-');
        \assert(false !== $zipPath);
        $zip = new ZipArchive();
        if (true !== $zip->open($zipPath, ZipArchive::OVERWRITE)) {
            throw new RuntimeException('Failed to create test ZIP fixture.');
        }
        $zip->addFromString('catalog/Front.JPG', $png);
        $zip->close();

        try {
            $client->request('POST', '/api/import-sessions', [
                'extra' => [
                    'parameters' => [
                        'target_object_type_id' => $this->objectTypeIdFor(ObjectKind::Product),
                        'mapping' => json_encode(['sku' => 'sku', 'photo' => 'photo'], JSON_THROW_ON_ERROR),
                        'mode' => 'UPSERT',
                        'image_source' => 'zip',
                    ],
                    'files' => [
                        'file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($csvPath, 'data.csv', 'text/csv', null, true),
                        'zip_file' => new \Symfony\Component\HttpFoundation\File\UploadedFile($zipPath, 'images.zip', 'application/zip', null, true),
                    ],
                ],
            ]);
            self::assertResponseIsSuccessful();
            $body = json_decode((string) $client->getResponse()?->getContent(), true, 512, JSON_THROW_ON_ERROR);
            \assert(\is_array($body));
            $sessionId = $body['id'];
            \assert(\is_string($sessionId));

            $em = $this->em();
            $em->clear();
            $session = $em->find(ImportSession::class, Uuid::fromString($sessionId));
            \assert($session instanceof ImportSession);
            self::assertSame('images.zip', $session->getZipFileName(), 'zip metadata recorded on the session');
            self::assertNotNull($session->getZipFileSizeBytes());
            self::assertSame(1, $session->getImagesDownloaded(), 'ZIP entry extracted + ingested inline');

            $envelope = $em->getConnection()->fetchOne(
                "SELECT ov.value FROM object_values ov JOIN attributes a ON a.id=ov.attribute_id JOIN objects o ON o.id=ov.object_id WHERE a.code='photo' AND o.code='ZIPPROD-1'",
            );
            self::assertIsString($envelope);
            self::assertStringContainsString('asset_id', $envelope);
            self::assertStringNotContainsString('front.jpg', $envelope, 'filename never lands raw in JSONB');
            $links = $em->getConnection()->fetchOne("SELECT COUNT(*) FROM product_assets pa JOIN objects o ON o.id=pa.product_id WHERE o.code='ZIPPROD-1'");
            self::assertSame(1, (int) (\is_scalar($links) ? $links : 0));
        } finally {
            @unlink($csvPath);
            @unlink($zipPath);
        }
    }

    private function seedSkuAndPhoto(): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $productOt = self::getContainer()->get(\App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($productOt instanceof ObjectType);

        $sku = new Attribute('sku', ['en' => 'SKU'], AttributeType::Text);
        $photo = new Attribute('photo', ['en' => 'Photo'], AttributeType::Asset);
        $em->persist($sku);
        $em->persist($photo);
        $em->persist(new ObjectTypeAttribute($productOt, $sku, false, 1));
        $em->persist(new ObjectTypeAttribute($productOt, $photo, false, 2));
        $em->flush();
    }

    /**
     * @return array{0: Uuid, 1: string} sessionId, objectId(rfc4122)
     */
    private function seed(): array
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);
        $productOt = self::getContainer()->get(\App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert($productOt instanceof ObjectType);

        $photo = new Attribute('photo', ['en' => 'Photo'], AttributeType::Asset);
        $em->persist($photo);
        $em->persist(new ObjectTypeAttribute($productOt, $photo, false, 3));
        $object = new CatalogObject($productOt, 'MED-1');
        $em->persist($object);

        $session = new ImportSession(
            userId: Uuid::v7(),
            targetObjectType: $productOt,
            fileName: 'media.csv',
            fileSizeBytes: 32,
        );
        $session->assignTenant($tenant);
        $session->markRunning();
        $session->incrementPendingImageBatches();
        $session->markRowPhaseComplete();
        $em->persist($session);
        $em->flush();

        return [$session->getId(), $object->getId()->toRfc4122()];
    }

    /**
     * @param list<string> $urls
     */
    private function message(Uuid $sessionId, string $objectId, array $urls): ImageDownloadMessage
    {
        return new ImageDownloadMessage($sessionId, $this->tenantId(), [
            new ImageDownloadJob($objectId, 'photo', null, null, [], $urls, 2, 'MED-1'),
        ]);
    }

    private function tenantId(): Uuid
    {
        $tenant = $this->em()->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        return $tenant->getId();
    }

    private function handler(MockHttpClient $httpClient): ImageDownloadHandler
    {
        $c = self::getContainer();

        return new ImageDownloadHandler(
            $c->get(EntityManagerInterface::class),
            $c->get(ImportSessionRepositoryInterface::class),
            $httpClient,
            $c->get(SsrfGuard::class),
            $c->get(AssetIngestorInterface::class),
            $c->get(ProductAssetLinker::class),
            $c->get(AssetRepositoryInterface::class),
            $c->get(CatalogObjectRepositoryInterface::class),
            $c->get(AttributeRepositoryInterface::class),
            $c->get(ObjectValueRepositoryInterface::class),
            $c->get(TenantContext::class),
            $c->get(ImportProgressPublisher::class),
            new NullLogger(),
            $c->get('imports.storage'),
        );
    }

    #[Test]
    public function extractsFromZipLinksAndWritesEnvelope(): void
    {
        $png = base64_decode(self::PNG, true);
        \assert(false !== $png);
        [$sessionId, $objectId] = $this->seed();

        // Stage a ZIP (with one image) in the imports storage + point the
        // message at it; the handler streams it down and extracts by filename.
        $zipBytes = $this->makeZipBytes(['catalog/Front.JPG' => $png]);
        $tenantId = $this->tenantId()->toRfc4122();
        $zipPath = $tenantId.'/'.$sessionId->toRfc4122().'/images.zip';
        self::getContainer()->get('imports.storage')->write($zipPath, $zipBytes);

        $job = new ImageDownloadJob($objectId, 'photo', null, null, [], [], 2, 'MED-1', ['front.jpg']);
        $handler = $this->handler(new MockHttpClient([]));
        $handler(new ImageDownloadMessage($sessionId, $this->tenantId(), [$job], $zipPath));

        $em = $this->em();
        $em->clear();
        $session = $em->find(ImportSession::class, $sessionId);
        \assert($session instanceof ImportSession);
        self::assertSame(1, $session->getImagesDownloaded(), 'ZIP entry counts as a downloaded image');
        self::assertSame(ImportSessionStatus::Success, $session->getStatus());

        $envelope = $em->getConnection()->fetchOne(
            "SELECT ov.value FROM object_values ov JOIN attributes a ON a.id=ov.attribute_id WHERE a.code='photo' AND ov.object_id = :p",
            ['p' => $objectId],
        );
        self::assertIsString($envelope);
        $decoded = json_decode($envelope, true, 512, JSON_THROW_ON_ERROR);
        \assert(\is_array($decoded));
        self::assertArrayHasKey('asset_id', $decoded);
        $links = $em->getConnection()->fetchOne('SELECT COUNT(*) FROM product_assets WHERE product_id = :p', ['p' => $objectId]);
        self::assertSame(1, (int) (\is_scalar($links) ? $links : 0));

        // The spent ZIP is removed from storage on finalize.
        self::assertFalse(self::getContainer()->get('imports.storage')->fileExists($zipPath), 'spent ZIP deleted');
    }

    #[Test]
    public function missingZipEntryIsImageNotFound(): void
    {
        $png = base64_decode(self::PNG, true);
        \assert(false !== $png);
        [$sessionId, $objectId] = $this->seed();
        $zipBytes = $this->makeZipBytes(['other.jpg' => $png]);
        $zipPath = $this->tenantId()->toRfc4122().'/'.$sessionId->toRfc4122().'/images.zip';
        self::getContainer()->get('imports.storage')->write($zipPath, $zipBytes);

        $job = new ImageDownloadJob($objectId, 'photo', null, null, [], [], 2, 'MED-1', ['absent.jpg']);
        $handler = $this->handler(new MockHttpClient([]));
        $handler(new ImageDownloadMessage($sessionId, $this->tenantId(), [$job], $zipPath));

        $em = $this->em();
        $em->clear();
        $session = $em->find(ImportSession::class, $sessionId);
        \assert($session instanceof ImportSession);
        self::assertSame(1, $session->getImagesFailed());
        $logs = $em->getConnection()->fetchOne("SELECT COUNT(*) FROM import_logs WHERE error_type='image_not_found' AND import_session_id = :s", ['s' => $sessionId->toRfc4122()]);
        self::assertSame(1, (int) (\is_scalar($logs) ? $logs : 0));
    }

    /**
     * @param array<string, string> $entries
     */
    private function makeZipBytes(array $entries): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pim-ztest-');
        \assert(false !== $path);
        $zip = new ZipArchive();
        if (true !== $zip->open($path, ZipArchive::OVERWRITE)) {
            throw new RuntimeException('Failed to create test ZIP fixture.');
        }
        foreach ($entries as $name => $contents) {
            $zip->addFromString($name, $contents);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }
}

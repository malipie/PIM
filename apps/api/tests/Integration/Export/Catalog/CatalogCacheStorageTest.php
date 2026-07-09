<?php

declare(strict_types=1);

namespace App\Tests\Integration\Export\Catalog;

use App\Export\Catalog\Infrastructure\Delivery\FlysystemCatalogCacheStorage;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * CPDF-P1-04 — round-trips a catalog PDF through the real MinIO-backed cache
 * (no mock storage): put → exists → read → delete. The seam has no consumer
 * until CPDF-P3-01/P3-02, so the impl is instantiated directly against the real
 * `exports.storage` operator rather than pulled from the (compiled-away) alias.
 */
final class CatalogCacheStorageTest extends KernelTestCase
{
    private const string KEY = 'catalogs/__test__/cache-roundtrip.pdf';

    #[Test]
    public function putReadExistsDeleteRoundTrip(): void
    {
        self::bootKernel();
        $operator = self::getContainer()->get('exports.storage');
        $storage = new FlysystemCatalogCacheStorage($operator);

        $local = tempnam(sys_get_temp_dir(), 'cpdf');
        \assert(false !== $local);
        file_put_contents($local, "%PDF-1.7\nround-trip\n%%EOF");

        try {
            $storage->put(self::KEY, $local);
            self::assertTrue($storage->exists(self::KEY));

            $stream = $storage->read(self::KEY);
            $bytes = stream_get_contents($stream);
            if (\is_resource($stream)) {
                fclose($stream);
            }
            self::assertIsString($bytes);
            self::assertStringStartsWith('%PDF-', $bytes);
            self::assertStringContainsString('round-trip', $bytes);

            $storage->delete(self::KEY);
            self::assertFalse($storage->exists(self::KEY));
        } finally {
            if (is_file($local)) {
                @unlink($local);
            }
            if ($storage->exists(self::KEY)) {
                $storage->delete(self::KEY);
            }
        }
    }
}

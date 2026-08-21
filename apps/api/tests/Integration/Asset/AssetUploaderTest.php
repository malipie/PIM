<?php

declare(strict_types=1);

namespace App\Tests\Integration\Asset;

use App\Asset\Application\AssetUploader;
use App\Asset\Domain\Entity\AssetVariant;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use Doctrine\ORM\EntityManagerInterface;
use League\Flysystem\FilesystemOperator;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Zenstruck\Foundry\Test\Factories;
use Zenstruck\Foundry\Test\ResetDatabase;

final class AssetUploaderTest extends KernelTestCase
{
    use Factories;
    use ResetDatabase;

    #[Test]
    public function uploadStoresBytesAndPersistsAssetWithOriginalVariant(): void
    {
        $tenant = $this->createTenant('demo');
        $this->tenantContext()->set($tenant);

        $tmpPath = $this->writeTempFile('hello asset');

        $asset = $this->uploader()->upload(new File($tmpPath), 'demo-asset-1');

        self::assertSame('demo-asset-1', $asset->getCode());
        self::assertStringStartsWith($tenant->getId()->toRfc4122(), $asset->getStoragePath());
        self::assertSame($tenant, $asset->getTenant());

        // Bytes are reachable through the storage
        self::assertSame('hello asset', $this->storage()->read($asset->getStoragePath()));

        // One `original` variant created at upload time
        self::assertCount(1, $asset->getVariants());
        $variant = $asset->getVariants()->first();
        self::assertNotFalse($variant);
        self::assertSame(AssetVariant::CODE_ORIGINAL, $variant->getVariantCode());
        self::assertSame($asset->getStoragePath(), $variant->getStoragePath());

        @unlink($tmpPath);
    }

    #[Test]
    public function browserUploadKeepsTheOperatorsFilenameAndAProperExtension(): void
    {
        // #2944 — `UploadedFile::getFilename()` is PHP's *temporary* name
        // (`phpA1b2C3`), not the operator's. Storing it made the media
        // library a wall of `phpntte2rm1nkbqe` and wrote every object to
        // `original.bin`; imports looked fine because they pass a real name.
        $tenant = $this->createTenant('demo');
        $this->tenantContext()->set($tenant);

        $tmpPath = $this->writeTempFile(self::onePixelGif());

        $asset = $this->uploader()->upload(new UploadedFile(
            path: $tmpPath,
            originalName: 'Zdjęcie produktu.GIF',
            mimeType: 'image/gif',
            test: true,
        ));

        self::assertSame('Zdjęcie produktu.GIF', $asset->getOriginalFilename());
        // The client name is display-only; the path takes a sniffed,
        // character-reduced extension instead.
        self::assertStringEndsWith('/original.gif', $asset->getStoragePath());
        self::assertStringNotContainsString('Zdjęcie', $asset->getStoragePath());
        // The default code is slugged from the operator's name, not php's.
        self::assertStringStartsWith('zdjecie-produktu', $asset->getCode());

        @unlink($tmpPath);
    }

    #[Test]
    public function aTraversalAttemptInTheClientNameNeverReachesThePath(): void
    {
        $tenant = $this->createTenant('demo');
        $this->tenantContext()->set($tenant);

        $tmpPath = $this->writeTempFile(self::onePixelGif());

        $asset = $this->uploader()->upload(new UploadedFile(
            path: $tmpPath,
            originalName: '../../etc/passwd.gif',
            mimeType: 'image/gif',
            test: true,
        ));

        self::assertSame('passwd.gif', $asset->getOriginalFilename());
        self::assertStringNotContainsString('..', $asset->getStoragePath());
        self::assertStringEndsWith('/original.gif', $asset->getStoragePath());

        @unlink($tmpPath);
    }

    /** Smallest valid GIF — enough for `guessExtension()` to sniff image/gif. */
    private static function onePixelGif(): string
    {
        $bytes = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7', true);
        self::assertNotFalse($bytes);

        return $bytes;
    }

    private function uploader(): AssetUploader
    {
        return self::getContainer()->get(AssetUploader::class);
    }

    private function storage(): FilesystemOperator
    {
        return self::getContainer()->get('assets.storage');
    }

    private function tenantContext(): TenantContext
    {
        return self::getContainer()->get(TenantContext::class);
    }

    private function createTenant(string $code): Tenant
    {
        $em = self::getContainer()->get('doctrine')->getManager();
        \assert($em instanceof EntityManagerInterface);
        $tenant = new Tenant($code, ucfirst($code).' Tenant');
        $em->persist($tenant);
        $em->flush();

        // An image upload dispatches AssetThumbnailsRequested, whose handler
        // syncs the asset into the catalogue and therefore needs the built-in
        // Asset ObjectType. `sync://` runs it inline, so the seed has to
        // happen before the first upload rather than lazily.
        self::getContainer()->get(\App\Catalog\Application\BuiltInObjectTypeSeeder::class)->seed($tenant);

        return $tenant;
    }

    private function writeTempFile(string $contents): string
    {
        $path = tempnam(sys_get_temp_dir(), 'pim-asset-test-');
        \assert(false !== $path);
        file_put_contents($path, $contents);

        return $path;
    }
}

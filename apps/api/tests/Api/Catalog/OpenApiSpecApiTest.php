<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use PHPUnit\Framework\Attributes\Test;

/**
 * OpenAPI surface coverage for #46 (0.4.6).
 *
 * Pins the contract every integrator reads from `/api/docs`:
 *   - top-level info (title + version) reflects api_platform.yaml,
 *   - both security schemes (JWT bearer + ApiKey reserved for #94)
 *     are listed in `components.securitySchemes`,
 *   - each ApiResource shortName lands as a tag,
 *   - the three sugar paths declare both GET collection + POST.
 */
final class OpenApiSpecApiTest extends CatalogApiTestCase
{
    #[Test]
    public function specInfoBlockReflectsApiPlatformConfig(): void
    {
        $body = $this->fetchOpenApi();
        $info = $body['info'] ?? [];
        \assert(\is_array($info));

        self::assertSame('PIM API', $info['title'] ?? null);
        // Read the expected version from the same container parameter
        // api_platform.yaml feeds, instead of a literal: the release bump in
        // #2789 (0.1.0 -> 1.8.7) left this assertion behind and turned the
        // api-catalog shard red on main for three days.
        self::assertSame(
            self::getContainer()->getParameter('api_platform.version'),
            $info['version'] ?? null,
        );
    }

    #[Test]
    public function bothSecuritySchemesAreAdvertised(): void
    {
        $body = $this->fetchOpenApi();
        $components = $body['components'] ?? [];
        \assert(\is_array($components));
        $schemes = $components['securitySchemes'] ?? [];
        \assert(\is_array($schemes));

        self::assertArrayHasKey('JWT', $schemes);
        self::assertArrayHasKey('ApiKey', $schemes);
    }

    #[Test]
    public function sugarPathsExposeReadAndWriteOperations(): void
    {
        $body = $this->fetchOpenApi();
        $paths = $body['paths'] ?? [];
        \assert(\is_array($paths));

        foreach (['/api/products' => true, '/api/categories' => true, '/api/assets' => false] as $path => $expectsPost) {
            $collection = $paths[$path] ?? null;
            \assert(\is_array($collection));
            self::assertArrayHasKey('get', $collection, $path.' must support GET collection.');

            if ($expectsPost) {
                self::assertArrayHasKey('post', $collection, $path.' must support POST.');
            } else {
                self::assertArrayNotHasKey('post', $collection, $path.' is read-only.');
            }
        }
    }

    #[Test]
    public function tagsReflectShortNames(): void
    {
        $body = $this->fetchOpenApi();
        $tags = $body['tags'] ?? [];
        \assert(\is_array($tags));

        $tagNames = [];
        foreach ($tags as $tag) {
            \assert(\is_array($tag));
            $name = $tag['name'] ?? null;
            if (\is_string($name)) {
                $tagNames[] = $name;
            }
        }

        self::assertContains('CatalogObject', $tagNames);
        self::assertContains('Channel', $tagNames);
        self::assertContains('AssetStorage', $tagNames);
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOpenApi(): array
    {
        $client = $this->authenticatedClient();
        $response = $client->request('GET', '/api/docs', [
            'headers' => ['accept' => 'application/vnd.openapi+json'],
        ]);

        $normalised = [];
        foreach ($response->toArray() as $key => $value) {
            $normalised[(string) $key] = $value;
        }

        return $normalised;
    }
}

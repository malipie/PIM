<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Domain\ObjectKind;
use PHPUnit\Framework\Attributes\Test;

use const JSON_THROW_ON_ERROR;

/**
 * DP-07 (#2037, ADR-0025) — ObjectType-level cross-field rules end to end:
 * the PATCH pipeline that stores them (shape + reference guards) and the
 * write path that enforces them (422 BEFORE anything is saved).
 */
final class CrossFieldValidationApiTest extends CatalogApiTestCase
{
    /**
     * @return array{client: \ApiPlatform\Symfony\Bundle\Test\Client, productOt: string}
     */
    private function seedWeights(): array
    {
        $client = $this->authenticatedClient();
        $productOt = $this->objectTypeIdFor(ObjectKind::Product);

        foreach (['weight_net', 'weight_gross'] as $code) {
            $response = $client->request('POST', '/api/attributes', [
                'headers' => ['content-type' => 'application/ld+json', 'accept' => 'application/ld+json'],
                'body' => json_encode([
                    'code' => $code,
                    'type' => 'number',
                    'label' => ['pl' => $code, 'en' => $code],
                ], JSON_THROW_ON_ERROR),
            ]);
            self::assertResponseStatusCodeSame(201);
            $attrId = $response->toArray()['id'];
            \assert(\is_string($attrId));
            $client->request('POST', '/api/object_types/'.$productOt.'/attributes/'.$attrId);
            self::assertResponseStatusCodeSame(204);
        }

        return ['client' => $client, 'productOt' => $productOt];
    }

    #[Test]
    public function patchStoresRulesAndEchoesThem(): void
    {
        ['client' => $client, 'productOt' => $productOt] = $this->seedWeights();

        $rules = [['type' => 'compare', 'left' => 'weight_net', 'op' => 'lte', 'right' => 'weight_gross']];
        $response = $client->request('PATCH', '/api/object_types/'.$productOt, [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['validationRules' => $rules], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(200);
        self::assertSame($rules, $response->toArray()['validationRules']);
    }

    #[Test]
    public function patchRejectsUnknownAttributeCode(): void
    {
        ['client' => $client, 'productOt' => $productOt] = $this->seedWeights();

        $client->request('PATCH', '/api/object_types/'.$productOt, [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['validationRules' => [
                ['type' => 'compare', 'left' => 'weight_net', 'op' => 'lte', 'right' => 'ghost_attr'],
            ]], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function patchRejectsNonNumericAndMismatchedCompareSides(): void
    {
        ['client' => $client, 'productOt' => $productOt] = $this->seedWeights();

        // `name` is the seeded text attribute — not numeric.
        $client->request('PATCH', '/api/object_types/'.$productOt, [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['validationRules' => [
                ['type' => 'compare', 'left' => 'name', 'op' => 'lte', 'right' => 'weight_gross'],
            ]], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(422);

        // Metric vs number — numeric but mismatched types.
        $metric = $client->request('POST', '/api/attributes', [
            'headers' => ['content-type' => 'application/ld+json', 'accept' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'depth_metric',
                'type' => 'metric',
                'label' => ['pl' => 'Głębokość', 'en' => 'Depth'],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(201);
        \assert(\is_string($metric->toArray()['id']));

        $client->request('PATCH', '/api/object_types/'.$productOt, [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['validationRules' => [
                ['type' => 'compare', 'left' => 'depth_metric', 'op' => 'lte', 'right' => 'weight_gross'],
            ]], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function patchRejectsMalformedDsl(): void
    {
        ['client' => $client, 'productOt' => $productOt] = $this->seedWeights();

        $client->request('PATCH', '/api/object_types/'.$productOt, [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['validationRules' => [
                ['type' => 'compare', 'left' => 'weight_net', 'op' => 'between', 'right' => 'weight_gross'],
            ]], JSON_THROW_ON_ERROR),
        ]);

        self::assertResponseStatusCodeSame(422);
    }

    #[Test]
    public function compareViolationBlocksTheWholeWriteBeforeAnySave(): void
    {
        ['client' => $client, 'productOt' => $productOt] = $this->seedWeights();

        $client->request('PATCH', '/api/object_types/'.$productOt, [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['validationRules' => [
                ['type' => 'compare', 'left' => 'weight_net', 'op' => 'lte', 'right' => 'weight_gross'],
            ]], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(200);

        // Valid product first.
        $created = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'XF-1',
                'objectTypeId' => $productOt,
                'attributes' => ['name' => 'XF', 'weight_net' => 5, 'weight_gross' => 10],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(201);
        $productId = $created->toArray()['id'];
        \assert(\is_string($productId));

        // Violating PATCH: net > gross. BOTH incoming values must be rejected
        // atomically — the gross bump must not land either (phase-2 gate).
        $client->request('PATCH', '/api/objects/'.$productId, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'body' => json_encode([
                'attributes' => ['weight_net' => 50, 'weight_gross' => 20],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(422);

        $fetched = $client->request('GET', '/api/objects/'.$productId, [
            'headers' => ['accept' => 'application/json'],
        ])->toArray();
        /** @var array<string, array{value?: mixed}> $indexed */
        $indexed = \is_array($fetched['attributesIndexed'] ?? null) ? $fetched['attributesIndexed'] : [];
        self::assertSame(5, $indexed['weight_net']['value'] ?? null);
        self::assertSame(10, $indexed['weight_gross']['value'] ?? null);
    }

    #[Test]
    public function partialPayloadIsValidatedAgainstExistingValues(): void
    {
        ['client' => $client, 'productOt' => $productOt] = $this->seedWeights();

        $client->request('PATCH', '/api/object_types/'.$productOt, [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['validationRules' => [
                ['type' => 'compare', 'left' => 'weight_net', 'op' => 'lte', 'right' => 'weight_gross'],
            ]], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(200);

        $created = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'XF-2',
                'objectTypeId' => $productOt,
                'attributes' => ['name' => 'XF2', 'weight_net' => 5, 'weight_gross' => 10],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(201);
        $productId = $created->toArray()['id'];
        \assert(\is_string($productId));

        // Only weight_net in the payload — compared against the EXISTING gross.
        $client->request('PATCH', '/api/objects/'.$productId, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'body' => json_encode(['attributes' => ['weight_net' => 50]], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(422);

        // Satisfying value passes.
        $client->request('PATCH', '/api/objects/'.$productId, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'body' => json_encode(['attributes' => ['weight_net' => 7]], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(200);
    }

    #[Test]
    public function requireWhenFiresAndClearingConditionLiftsIt(): void
    {
        $client = $this->authenticatedClient();
        $productOt = $this->objectTypeIdFor(ObjectKind::Product);

        foreach ([['expandable_storage', 'boolean'], ['max_sd_card_gb', 'number']] as [$code, $type]) {
            $response = $client->request('POST', '/api/attributes', [
                'headers' => ['content-type' => 'application/ld+json', 'accept' => 'application/ld+json'],
                'body' => json_encode([
                    'code' => $code,
                    'type' => $type,
                    'label' => ['pl' => $code, 'en' => $code],
                ], JSON_THROW_ON_ERROR),
            ]);
            self::assertResponseStatusCodeSame(201);
            $attrId = $response->toArray()['id'];
            \assert(\is_string($attrId));
            $client->request('POST', '/api/object_types/'.$productOt.'/attributes/'.$attrId);
            self::assertResponseStatusCodeSame(204);
        }

        $client->request('PATCH', '/api/object_types/'.$productOt, [
            'headers' => ['content-type' => 'application/json'],
            'body' => json_encode(['validationRules' => [
                ['type' => 'require_when', 'if' => ['field' => 'expandable_storage', 'operator' => 'equals', 'value' => true], 'then' => ['required' => 'max_sd_card_gb']],
            ]], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(200);

        // Condition true + empty target => 422 already at create.
        $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'RW-1',
                'objectTypeId' => $productOt,
                'attributes' => ['name' => 'RW', 'expandable_storage' => true],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(422);

        // Target supplied alongside => 201.
        $created = $client->request('POST', '/api/products', [
            'headers' => ['content-type' => 'application/ld+json'],
            'body' => json_encode([
                'code' => 'RW-2',
                'objectTypeId' => $productOt,
                'attributes' => ['name' => 'RW2', 'expandable_storage' => true, 'max_sd_card_gb' => 512],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(201);
        $productId = $created->toArray()['id'];
        \assert(\is_string($productId));

        // Clearing the condition field lifts the requirement even when the
        // target is cleared in the same payload.
        $client->request('PATCH', '/api/objects/'.$productId, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'body' => json_encode([
                'attributes' => ['expandable_storage' => null, 'max_sd_card_gb' => null],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::assertResponseStatusCodeSame(200);
    }
}

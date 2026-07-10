<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;

/**
 * WFL-P0-03 (#2412) — the legacy PATCH `status` surface now resolves the
 * target against the `object_editorial` state machine (ADR-0029): a
 * reachable target applies the matching transition, an unreachable one
 * is a 409 (breaking change vs. the previous free-field behaviour,
 * documented in the ADR). `review` joins the status whitelist and the
 * `?status=` list filter.
 */
final class CatalogObjectStatusTransitionApiTest extends CatalogApiTestCase
{
    #[Test]
    public function patchToReachableStatusAppliesTransition(): void
    {
        $id = $this->seedProduct('WFL-PATCH-OK');

        $client = $this->authenticatedClient();
        $client->request('PATCH', '/api/products/'.$id, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['status' => CatalogObject::STATUS_REVIEW],
        ]);

        self::assertResponseIsSuccessful();
        $body = $client->request('GET', '/api/products/'.$id)->toArray();
        self::assertSame(CatalogObject::STATUS_REVIEW, $body['status']);
    }

    #[Test]
    public function patchDraftToPublishedUsesPublishShortcut(): void
    {
        $id = $this->seedProduct('WFL-PATCH-SHORTCUT');

        $client = $this->authenticatedClient();
        $client->request('PATCH', '/api/products/'.$id, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['status' => CatalogObject::STATUS_PUBLISHED],
        ]);

        self::assertResponseIsSuccessful();
        $body = $client->request('GET', '/api/products/'.$id)->toArray();
        self::assertSame(CatalogObject::STATUS_PUBLISHED, $body['status']);
    }

    #[Test]
    public function patchToUnreachableStatusConflicts(): void
    {
        $id = $this->seedProduct('WFL-PATCH-409', CatalogObject::STATUS_PUBLISHED);

        $client = $this->authenticatedClient();
        $client->request('PATCH', '/api/products/'.$id, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['status' => CatalogObject::STATUS_REVIEW],
        ]);

        self::assertResponseStatusCodeSame(409);

        $body = $client->request('GET', '/api/products/'.$id)->toArray();
        self::assertSame(CatalogObject::STATUS_PUBLISHED, $body['status']);
    }

    #[Test]
    public function patchArchivedStaysReadOnlyUntilRestore(): void
    {
        $id = $this->seedProduct('WFL-PATCH-ARCHIVED', CatalogObject::STATUS_ARCHIVED);

        $client = $this->authenticatedClient();
        $client->request('PATCH', '/api/products/'.$id, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['status' => CatalogObject::STATUS_PUBLISHED],
        ]);
        self::assertResponseStatusCodeSame(409);

        $client->request('PATCH', '/api/products/'.$id, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['status' => CatalogObject::STATUS_DRAFT],
        ]);
        self::assertResponseIsSuccessful();

        $body = $client->request('GET', '/api/products/'.$id)->toArray();
        self::assertSame(CatalogObject::STATUS_DRAFT, $body['status']);
    }

    #[Test]
    public function patchSameStatusIsANoOp(): void
    {
        $id = $this->seedProduct('WFL-PATCH-NOOP');

        $client = $this->authenticatedClient();
        $client->request('PATCH', '/api/products/'.$id, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['status' => CatalogObject::STATUS_DRAFT],
        ]);

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function reviewStatusFiltersOnTheList(): void
    {
        $inReview = $this->seedProduct('WFL-FILTER-REVIEW', CatalogObject::STATUS_REVIEW);
        $this->seedProduct('WFL-FILTER-DRAFT');

        $client = $this->authenticatedClient();
        $body = $client->request('GET', '/api/products?status=review')->toArray();

        $members = $body['hydra:member'] ?? $body['member'] ?? [];
        self::assertIsArray($members);

        $ids = [];
        foreach ($members as $member) {
            self::assertIsArray($member);
            self::assertIsString($member['id']);
            $ids[] = $member['id'];
        }
        self::assertSame([$inReview], $ids);
    }

    private function seedProduct(string $code, string $status = CatalogObject::STATUS_DRAFT): string
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $context = self::getContainer()->get(TenantContext::class);
        $context->set($tenant);

        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $type);

        $object = new CatalogObject($type, $code);
        if (CatalogObject::STATUS_DRAFT !== $status) {
            $object->forceStatus($status);
        }
        self::getContainer()->get(CatalogObjectRepositoryInterface::class)->save($object);
        $em->flush();
        $em->clear();

        return $object->getId()->toRfc4122();
    }
}

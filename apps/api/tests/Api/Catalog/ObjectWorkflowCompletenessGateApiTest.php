<?php

declare(strict_types=1);

namespace App\Tests\Api\Catalog;

use App\Catalog\Contracts\AttributeType;
use App\Catalog\Domain\Entity\Attribute;
use App\Catalog\Domain\Entity\AttributeGroup;
use App\Catalog\Domain\Entity\AttributeGroupAttribute;
use App\Catalog\Domain\Entity\CatalogObject;
use App\Catalog\Domain\Entity\ObjectTypeAttributeGroup;
use App\Catalog\Domain\ObjectKind;
use App\Catalog\Domain\Repository\CatalogObjectRepositoryInterface;
use App\Catalog\Domain\Repository\ObjectTypeRepositoryInterface;
use App\Shared\Application\TenantContext;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;

use const JSON_THROW_ON_ERROR;

/**
 * WFL-P1-04 (#2418) — completeness gate on publish/approve (ADR-0029
 * pillar 6). Default OFF (no behaviour change); ON blocks below the
 * threshold with the `completeness_gate` blocker and an actionable list
 * of missing required attributes; per_channel scope reads the §3
 * contract with a global fallback for channels without a computed pct.
 */
final class ObjectWorkflowCompletenessGateApiTest extends CatalogApiTestCase
{
    #[Test]
    public function gateOffPublishesRegardlessOfCompleteness(): void
    {
        $id = $this->seedProduct('WFL-CG-OFF', completenessPct: 0);

        $client = $this->authenticatedClient();
        $client->request('POST', '/api/objects/'.$id.'/workflow/transitions/publish');
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function gateBlocksBelowThresholdWithMissingAttributes(): void
    {
        $this->configureGate(['enabled' => true, 'min_completeness_pct' => 80]);
        $this->setRequired(['ean']);
        $id = $this->seedProduct('WFL-CG-BLOCK', completenessPct: 40);

        $client = $this->authenticatedClient();
        $response = $client->request('POST', '/api/objects/'.$id.'/workflow/transitions/publish');
        self::assertResponseStatusCodeSame(409);
        $detail = $response->getContent(false);
        self::assertStringContainsString('min 80%', $detail);
        self::assertStringContainsString('ean', $detail, '409 must list the missing required attribute');

        // Discovery mirrors the blocker with its machine code.
        $body = $client->request('GET', '/api/objects/'.$id.'/workflow')->toArray();
        $publish = null;
        $transitions = $body['transitions'] ?? [];
        self::assertIsArray($transitions);
        foreach ($transitions as $transition) {
            self::assertIsArray($transition);
            if ('publish' === $transition['name']) {
                $publish = $transition;
            }
        }
        self::assertNotNull($publish);
        self::assertFalse($publish['enabled']);
        $blockers = $publish['blockers'];
        self::assertIsArray($blockers);
        $first = $blockers[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('completeness_gate', $first['code'] ?? null);
    }

    #[Test]
    public function gatePassesAtThresholdAndGatesApproveToo(): void
    {
        $this->configureGate(['enabled' => true, 'min_completeness_pct' => 80]);
        $ok = $this->seedProduct('WFL-CG-OK', completenessPct: 80);
        $inReview = $this->seedProduct('WFL-CG-REVIEW', completenessPct: 10, status: CatalogObject::STATUS_REVIEW);

        $client = $this->authenticatedClient();
        $client->request('POST', '/api/objects/'.$ok.'/workflow/transitions/publish');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/objects/'.$inReview.'/workflow/transitions/approve');
        self::assertResponseStatusCodeSame(409, 'approve is a publishing transition and honours the gate');
    }

    #[Test]
    public function perChannelScopeReadsChannelPctWithGlobalFallback(): void
    {
        $this->configureGate([
            'enabled' => true,
            'min_completeness_pct' => 80,
            'scope' => 'per_channel',
            'channels' => ['web'],
        ]);
        $low = $this->seedProduct('WFL-CG-CH-LOW', completenessPct: 95, perChannel: ['web' => 50]);
        $high = $this->seedProduct('WFL-CG-CH-HIGH', completenessPct: 10, perChannel: ['web' => 90]);
        $fallback = $this->seedProduct('WFL-CG-CH-FB', completenessPct: 85, perChannel: []);

        $client = $this->authenticatedClient();

        $client->request('POST', '/api/objects/'.$low.'/workflow/transitions/publish');
        self::assertResponseStatusCodeSame(409, 'web=50 < 80 even though global is 95');

        $client->request('POST', '/api/objects/'.$high.'/workflow/transitions/publish');
        self::assertResponseIsSuccessful();

        $client->request('POST', '/api/objects/'.$fallback.'/workflow/transitions/publish');
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function submitForReviewBlockedWhenRequiredFieldsMissing(): void
    {
        // #2558 — a product missing required attributes must not enter review.
        $this->setRequired(['ean']);
        $this->addEanToForm(required: true);
        $id = $this->seedProduct('WFL-SUBMIT-BLOCK', completenessPct: 40);

        $client = $this->authenticatedClient();
        $response = $client->request('POST', '/api/objects/'.$id.'/workflow/transitions/submit_for_review');
        self::assertResponseStatusCodeSame(409);
        self::assertStringContainsString('ean', $response->getContent(false), '409 lists the missing required attribute');

        // Discovery mirrors the blocker with its machine code + structured
        // params (for the localized tooltip).
        $body = $client->request('GET', '/api/objects/'.$id.'/workflow')->toArray();
        $submit = null;
        $transitions = $body['transitions'] ?? [];
        self::assertIsArray($transitions);
        foreach ($transitions as $transition) {
            self::assertIsArray($transition);
            if ('submit_for_review' === $transition['name']) {
                $submit = $transition;
            }
        }
        self::assertNotNull($submit);
        self::assertFalse($submit['enabled']);
        $blockers = $submit['blockers'];
        self::assertIsArray($blockers);
        $first = $blockers[0] ?? null;
        self::assertIsArray($first);
        self::assertSame('missing_required', $first['code'] ?? null);
        $parameters = $first['parameters'] ?? null;
        self::assertIsArray($parameters);
        $missing = $parameters['missing_required'] ?? [];
        self::assertIsArray($missing);
        self::assertContains('ean', $missing);
    }

    #[Test]
    public function submitForReviewAllowedWhenRequiredFieldsPresent(): void
    {
        // Same required rule, but the product now carries the value — submit
        // proceeds (the numeric publish gate still applies later, at approve).
        $this->setRequired(['ean']);
        $this->addEanToForm(required: true);
        $id = $this->seedProduct('WFL-SUBMIT-OK', completenessPct: 40, indexed: ['ean' => '5901234123457']);

        $client = $this->authenticatedClient();
        $client->request('POST', '/api/objects/'.$id.'/workflow/transitions/submit_for_review');
        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function submitForReviewDoesNotBlockAnOptionalFormFieldListedForCompleteness(): void
    {
        // Regression: completeness is a publication/readiness measure, not a
        // second, hidden required flag on the review-submission form.
        $this->setRequired(['ean']);
        $this->addEanToForm(required: false);
        $id = $this->seedProduct('WFL-SUBMIT-OPTIONAL-EAN', completenessPct: 0);

        $client = $this->authenticatedClient();
        $client->request('POST', '/api/objects/'.$id.'/workflow/transitions/submit_for_review');

        self::assertResponseIsSuccessful();
    }

    #[Test]
    public function malformedGateConfigIsRejectedWith422(): void
    {
        $client = $this->authenticatedClient();
        $typeId = $this->objectTypeIdFor(ObjectKind::Product);

        $client->request('PATCH', '/api/object_types/'.$typeId, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['workflowPublishGate' => ['enabled' => true, 'min_completeness_pct' => 250]],
        ]);
        self::assertResponseStatusCodeSame(422);

        $body = $client->request('PATCH', '/api/object_types/'.$typeId, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['workflowPublishGate' => ['enabled' => true, 'min_completeness_pct' => 90]],
        ])->toArray();
        self::assertResponseIsSuccessful();
        $gate = $body['workflowPublishGate'];
        self::assertIsArray($gate);
        self::assertSame(90, $gate['min_completeness_pct'] ?? null);

        $body = $client->request('PATCH', '/api/object_types/'.$typeId, [
            'headers' => ['content-type' => 'application/merge-patch+json'],
            'json' => ['workflowPublishGate' => null],
        ])->toArray();
        self::assertResponseIsSuccessful();
        self::assertNull($body['workflowPublishGate'], 'null clears the gate');
    }

    /**
     * @param array<string, mixed> $gate
     */
    private function configureGate(array $gate): void
    {
        $em = $this->em();
        $type = $this->productType();
        $type->setWorkflowPublishGate($gate);
        $em->flush();
    }

    /**
     * @param list<string> $required
     */
    private function setRequired(array $required): void
    {
        $em = $this->em();
        $type = $this->productType();
        $type->updateCompletenessRules(['required' => $required]);
        $em->flush();
    }

    private function addEanToForm(bool $required): void
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);

        $context = self::getContainer()->get(TenantContext::class);
        $context->set($tenant);

        try {
            $group = new AttributeGroup('workflow_fields', ['en' => 'Workflow fields']);
            $ean = new Attribute('ean', ['en' => 'EAN'], AttributeType::Text);
            $ean->changeRequired($required);
            $em->persist($group);
            $em->persist($ean);
            $em->flush();

            $em->persist(new AttributeGroupAttribute($group, $ean, 1));
            $em->persist(new ObjectTypeAttributeGroup($this->productType(), $group, 1));
            $em->flush();
        } finally {
            $context->clear();
        }
    }

    /**
     * @param array<string, int>   $perChannel
     * @param array<string, mixed> $indexed    #2558 — denormalised attribute values to pin
     */
    private function seedProduct(
        string $code,
        int $completenessPct,
        array $perChannel = [],
        string $status = CatalogObject::STATUS_DRAFT,
        array $indexed = [],
    ): string {
        $em = $this->em();
        $object = new CatalogObject($this->productType(), $code);
        if (CatalogObject::STATUS_DRAFT !== $status) {
            $object->forceStatus($status);
        }
        self::getContainer()->get(CatalogObjectRepositoryInterface::class)->save($object);
        $em->flush();

        // The sync rebuild listener recomputes completeness on flush, so
        // the fixture value is pinned with raw SQL AFTER the flush (same
        // pattern as the DASH attributes_indexed lesson). The attribute
        // index is pinned the same way so the required-fields guard sees it.
        $completeness = ['global' => $completenessPct];
        if ([] !== $perChannel) {
            $completeness['per_channel'] = $perChannel;
        }
        $em->getConnection()->executeStatement(
            'UPDATE objects SET completeness = :c, completeness_pct = :pct, attributes_indexed = :ai WHERE id = :id',
            [
                'c' => \json_encode($completeness, JSON_THROW_ON_ERROR),
                'pct' => $completenessPct,
                'ai' => \json_encode($indexed, JSON_THROW_ON_ERROR),
                'id' => $object->getId()->toRfc4122(),
            ],
        );
        $em->clear();

        return $object->getId()->toRfc4122();
    }

    private function productType(): \App\Catalog\Domain\Entity\ObjectType
    {
        $em = $this->em();
        $tenant = $em->getRepository(Tenant::class)->findOneBy(['code' => self::TENANT_CODE]);
        \assert($tenant instanceof Tenant);
        self::getContainer()->get(TenantContext::class)->set($tenant);

        $type = self::getContainer()->get(ObjectTypeRepositoryInterface::class)
            ->findBuiltInByKind(ObjectKind::Product, $tenant);
        \assert(null !== $type);

        return $type;
    }
}

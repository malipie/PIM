<?php

declare(strict_types=1);

namespace App\Tests\Unit\Identity\Application\Policy;

use App\Identity\Application\PermissionResolverInterface;
use App\Identity\Application\Policy\WorkflowStatePolicy;
use App\Identity\Domain\Entity\User;
use App\Identity\Domain\Rbac\PermissionSet;
use App\Shared\Domain\Tenant;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * RBAC-P3-011 (#674) — WorkflowStatePolicy unit coverage of the
 * per-state edit matrix + auto-unpublish detection.
 */
final class WorkflowStatePolicyTest extends TestCase
{
    #[Test]
    public function ownerCanEditInAnyState(): void
    {
        $policy = new WorkflowStatePolicy($this->resolverWith([
            'products.edit',
            'workflow.edit_any_state',
        ]));

        self::assertTrue($policy->canEditInState($this->user(), WorkflowStatePolicy::STATE_DRAFT));
        self::assertTrue($policy->canEditInState($this->user(), WorkflowStatePolicy::STATE_PUBLISHED));
    }

    #[Test]
    public function catalogManagerCanEditDraftButNotPublished(): void
    {
        // Catalog Manager has products.edit but lacks workflow.edit_any_state.
        $policy = new WorkflowStatePolicy($this->resolverWith(['products.edit']));

        self::assertTrue($policy->canEditInState($this->user(), WorkflowStatePolicy::STATE_DRAFT));
        self::assertFalse($policy->canEditInState($this->user(), WorkflowStatePolicy::STATE_PUBLISHED));
    }

    #[Test]
    public function archivedStateLocksOutEveryone(): void
    {
        // Even Owner cannot edit archived entities — that goes through a
        // restoration endpoint, not a direct PATCH.
        $policy = new WorkflowStatePolicy($this->resolverWith([
            'products.edit',
            'workflow.edit_any_state',
        ]));

        self::assertFalse($policy->canEditInState($this->user(), WorkflowStatePolicy::STATE_ARCHIVED));
    }

    #[Test]
    public function viewerWithoutEditCannotEditAnyState(): void
    {
        $policy = new WorkflowStatePolicy($this->resolverWith(['products.view']));

        self::assertFalse($policy->canEditInState($this->user(), WorkflowStatePolicy::STATE_DRAFT));
        self::assertFalse($policy->canEditInState($this->user(), WorkflowStatePolicy::STATE_PUBLISHED));
    }

    #[Test]
    public function autoUnpublishOnlyForCatalogManagerWithTransitionCode(): void
    {
        // Catalog Manager + workflow.transition.unpublish → auto path active.
        $policy = new WorkflowStatePolicy($this->resolverWith([
            'products.edit',
            'workflow.transition.unpublish',
        ]));

        self::assertTrue($policy->requiresAutoUnpublish($this->user(), WorkflowStatePolicy::STATE_PUBLISHED));
        // Not in published state → false.
        self::assertFalse($policy->requiresAutoUnpublish($this->user(), WorkflowStatePolicy::STATE_DRAFT));
    }

    #[Test]
    public function ownerSkipsAutoUnpublishBecauseTheyEditInPlace(): void
    {
        // Owner has workflow.edit_any_state — they edit published directly,
        // no transition needed.
        $policy = new WorkflowStatePolicy($this->resolverWith([
            'products.edit',
            'workflow.edit_any_state',
            'workflow.transition.unpublish',
        ]));

        self::assertFalse($policy->requiresAutoUnpublish($this->user(), WorkflowStatePolicy::STATE_PUBLISHED));
    }

    #[Test]
    public function viewerCannotTriggerAutoUnpublish(): void
    {
        // No products.edit → no auto-unpublish even if transition code present.
        $policy = new WorkflowStatePolicy($this->resolverWith([
            'workflow.transition.unpublish',
        ]));

        self::assertFalse($policy->requiresAutoUnpublish($this->user(), WorkflowStatePolicy::STATE_PUBLISHED));
    }

    #[Test]
    public function unknownStateDenies(): void
    {
        $policy = new WorkflowStatePolicy($this->resolverWith([
            'products.edit',
            'workflow.edit_any_state',
        ]));

        // 'review' graduated to a real state (WFL-P1-02) — use a literal
        // that stays outside the machine to keep the default-deny pinned.
        self::assertFalse($policy->canEditInState($this->user(), 'limbo'));
    }

    #[Test]
    public function reviewIsEditableForReviewersAndEditAnywhere(): void
    {
        $reviewer = new WorkflowStatePolicy($this->resolverWith([
            'products.edit',
            'workflow.edit_in_review',
        ]));
        self::assertTrue($reviewer->canEditInState($this->user(), WorkflowStatePolicy::STATE_REVIEW));

        $editAnywhere = new WorkflowStatePolicy($this->resolverWith([
            'workflow.edit_any_state',
        ]));
        self::assertTrue($editAnywhere->canEditInState($this->user(), WorkflowStatePolicy::STATE_REVIEW));
    }

    #[Test]
    public function reviewLocksOutPlainEditors(): void
    {
        // WFL-P1-02 (#2416) — marketing edits drafts but not rows waiting
        // for a decision (PRD §3.8 macierz: edit-in-review = ✗).
        $policy = new WorkflowStatePolicy($this->resolverWith([
            'products.edit',
        ]));

        self::assertTrue($policy->canEditInState($this->user(), WorkflowStatePolicy::STATE_DRAFT));
        self::assertFalse($policy->canEditInState($this->user(), WorkflowStatePolicy::STATE_REVIEW));
    }

    private function user(): User
    {
        return new User(
            new Tenant('alpha', 'Alpha'),
            'tester@alpha.localhost',
            'placeholder',
            ['ROLE_USER'],
            Uuid::v7(),
        );
    }

    /**
     * @param list<string> $codes
     */
    private function resolverWith(array $codes): PermissionResolverInterface
    {
        $resolver = $this->createMock(PermissionResolverInterface::class);
        $resolver->method('resolve')->willReturn(new PermissionSet($codes));

        return $resolver;
    }
}

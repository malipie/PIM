<?php

declare(strict_types=1);

namespace App\Workflow\Contracts;

/**
 * Shared vocabulary of the `object_editorial` state machine (ADR-0029).
 *
 * Place names intentionally mirror `CatalogObject::STATUS_*` string
 * values without importing the entity — `Workflow_Contracts` may only
 * depend on Shared + Vendor (deptrac), while the marking itself lives
 * on the Catalog entity. The YAML definition in
 * `config/packages/workflow.yaml` is the runtime source of truth; this
 * class pins the names so cross-BC consumers never hardcode strings.
 */
final class ObjectEditorialWorkflow
{
    public const string NAME = 'object_editorial';

    /**
     * Role that automation assigns review / request-unpublish tasks to
     * (WFL-P4-02), and the virtual membership the "my tasks" audience
     * grants to any holder of {@see self::PERMISSION_APPROVE_REJECT} so
     * the task inbox matches the notification fan-out (#2495).
     */
    public const string REVIEWER_ROLE = 'approver';

    /** Permission gating every reviewer transition (approve/reject/…). */
    public const string PERMISSION_APPROVE_REJECT = 'workflow.approve_reject';

    public const string PLACE_DRAFT = 'draft';
    public const string PLACE_REVIEW = 'review';
    public const string PLACE_PUBLISHED = 'published';
    public const string PLACE_ARCHIVED = 'archived';

    public const string TRANSITION_SUBMIT_FOR_REVIEW = 'submit_for_review';
    public const string TRANSITION_PUBLISH = 'publish';
    public const string TRANSITION_APPROVE = 'approve';
    public const string TRANSITION_REJECT = 'reject';
    public const string TRANSITION_UNPUBLISH = 'unpublish';
    public const string TRANSITION_ARCHIVE = 'archive';
    public const string TRANSITION_RESTORE = 'restore';

    /**
     * @var list<string>
     */
    public const array PLACES = [
        self::PLACE_DRAFT,
        self::PLACE_REVIEW,
        self::PLACE_PUBLISHED,
        self::PLACE_ARCHIVED,
    ];

    /**
     * @var list<string>
     */
    public const array TRANSITIONS = [
        self::TRANSITION_SUBMIT_FOR_REVIEW,
        self::TRANSITION_PUBLISH,
        self::TRANSITION_APPROVE,
        self::TRANSITION_REJECT,
        self::TRANSITION_UNPUBLISH,
        self::TRANSITION_ARCHIVE,
        self::TRANSITION_RESTORE,
    ];

    private function __construct()
    {
    }
}

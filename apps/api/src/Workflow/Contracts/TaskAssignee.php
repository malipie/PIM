<?php

declare(strict_types=1);

namespace App\Workflow\Contracts;

use InvalidArgumentException;

/**
 * #2513 — the configured recipient of an auto-created workflow task
 * (review / request-unpublish). Exactly one of roleCode / userId is set
 * (XOR), mirroring {@see \App\Workflow\Domain\Entity\WorkflowTask}'s
 * assignee shape. Resolved from a tenant's WorkflowDefinition by
 * {@see EditorialWorkflowProviderInterface::reviewerFor()}; a null
 * result means "fall back to the built-in reviewer role".
 */
final readonly class TaskAssignee
{
    private function __construct(
        public ?string $roleCode,
        public ?string $userId,
    ) {
        if ((null === $roleCode) === (null === $userId)) {
            throw new InvalidArgumentException('TaskAssignee is a role OR a user, not both or neither.');
        }
    }

    public static function role(string $roleCode): self
    {
        return new self($roleCode, null);
    }

    public static function user(string $userId): self
    {
        return new self(null, $userId);
    }

    /**
     * @param array<string, mixed> $reviewer definition JSONB: {role_code}|{user_id}
     */
    public static function fromDefinition(array $reviewer): ?self
    {
        $roleCode = $reviewer['role_code'] ?? null;
        if (\is_string($roleCode) && '' !== $roleCode) {
            return self::role($roleCode);
        }

        $userId = $reviewer['user_id'] ?? null;
        if (\is_string($userId) && '' !== $userId) {
            return self::user($userId);
        }

        return null;
    }
}

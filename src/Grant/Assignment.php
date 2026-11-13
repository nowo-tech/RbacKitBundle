<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Grant;

use Nowo\RbacKitBundle\Context\ContextId;
use Nowo\RbacKitBundle\Context\RbacContextInterface;

use function sprintf;

/**
 * One permission set a user holds: in one context (`contextId`, a typed id `<type>:<id>`, see {@see ContextId}), or
 * globally (`contextId` null). Returned by the host {@see GrantProviderInterface} (memberships, group links,
 * invitations…). Build it with {@see self::global()}, {@see self::in()} or {@see self::of()}.
 */
final readonly class Assignment
{
    /**
     * @param string $permissionSet permission set code
     * @param string|null $contextId typed context id (`project:5`); null = global
     *
     * @throws InvalidAssignmentException when the context id is not typed (`5` instead of `project:5`)
     */
    public function __construct(
        public string $permissionSet,
        public ?string $contextId = null,
    ) {
        if ($contextId !== null && !ContextId::isTyped($contextId)) {
            throw new InvalidAssignmentException(sprintf('RbacKit: the context id "%s" of an assignment of "%s" must be typed ("<type>:<id>", e.g. "project:5"); use Assignment::in() or Assignment::of().', $contextId, $permissionSet));
        }
    }

    /**
     * A global assignment.
     */
    public static function global(string $permissionSet): self
    {
        return new self($permissionSet);
    }

    /**
     * An assignment in the context `<type>:<id>`.
     *
     * @throws InvalidAssignmentException when the type or the id is not acceptable
     */
    public static function in(string $permissionSet, string $contextType, string $contextId): self
    {
        return new self($permissionSet, ContextId::of($contextType, $contextId) ?? throw new InvalidAssignmentException(sprintf('RbacKit: invalid context type "%s" or id "%s" for an assignment of "%s".', $contextType, $contextId, $permissionSet)));
    }

    /**
     * An assignment in a context object (its type and id).
     *
     * @throws InvalidAssignmentException when the context's type or id is not acceptable
     */
    public static function of(string $permissionSet, RbacContextInterface $context): self
    {
        return self::in($permissionSet, $context->getRbacContextType(), $context->getRbacContextId());
    }
}

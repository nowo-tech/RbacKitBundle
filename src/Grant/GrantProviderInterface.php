<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Grant;

use Nowo\RbacKitBundle\Context\ContextId;

/**
 * Host service (`grant_provider`) listing the permission sets a user holds — direct memberships, group links, global
 * assignments. Called at most once per user and request ({@see GrantResolver} memoises it), so one query is enough.
 * A deactivated membership must simply not be returned.
 *
 * Check stored context ids with {@see ContextId::of()} and skip (or clean) those it
 * refuses before building an {@see Assignment}: building one throws {@see InvalidAssignmentException}, and then
 * {@see GrantResolver} gives the user no permission set from assignments and no fallback in that request
 * (`role_grants` and bypass roles still apply).
 */
interface GrantProviderInterface
{
    /**
     * @param object $user authenticated user (the security token user)
     *
     * @return iterable<Assignment>
     */
    public function assignmentsOf(object $user): iterable;
}

<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Grant;

/**
 * Host service (`grant_provider`) listing the permission sets a user holds — direct memberships, group links, global
 * assignments. Called at most once per user and request ({@see GrantResolver} memoises it), so one query is enough.
 * A deactivated membership must simply not be returned.
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

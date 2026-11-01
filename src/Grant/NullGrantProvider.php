<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Grant;

/**
 * Default grant provider: nobody holds a permission set (only `voter.bypass_roles`, `role_grants` and
 * `voter.fallback_permission_set` grant). Set `grant_provider` to your service.
 */
final readonly class NullGrantProvider implements GrantProviderInterface
{
    /**
     * No assignment.
     *
     * @return iterable<Assignment>
     */
    public function assignmentsOf(object $user): iterable
    {
        return [];
    }
}

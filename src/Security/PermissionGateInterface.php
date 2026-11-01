<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Security;

use Nowo\RbacKitBundle\Permission\Permission;

/**
 * Deny-first gate asked before anything else, bypass roles included (e.g. "the paying account's plan leaves this
 * module out"). Services implementing it are autoconfigured with `nowo_rbac_kit.permission_gate`. The decision is
 * memoised per request for (user, permission, context id): it must depend on nothing else.
 */
interface PermissionGateInterface
{
    /**
     * False refuses the permission whatever the user's sets.
     *
     * @param object $user authenticated user
     * @param object|null $subject voter subject (the context of a context permission; may be null for global ones)
     */
    public function allows(Permission $permission, object $user, ?object $subject): bool;
}

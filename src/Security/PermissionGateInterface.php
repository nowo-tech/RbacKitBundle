<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Security;

use Nowo\RbacKitBundle\Permission\Permission;

/**
 * Deny-first gate asked before anything else, bypass roles included (e.g. "the paying account's plan leaves this
 * module out"). Services implementing it are autoconfigured with `nowo_rbac_kit.permission_gate`.
 *
 * Memoisation: the decision is memoised per request for (user, roles, permission, typed context id of the subject,
 * bypass veto state) — for a global key asked with a context subject too — so a gate must depend on nothing else
 * (no mutable request state). A subject that is not a context is never memoised. Call
 * {@see PermissionVoter::reset()} if the state a gate reads changes during a request.
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

<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Security;

/**
 * Suspends the `voter.bypass_roles` superuser bypass for one decision (e.g. an administrator previewing a project
 * "as a member"): the user's own sets decide instead. Framework-free twin of the deprecated
 * {@see BypassVetoInterface} (since 1.2, REQ-ARCH-001 rule 3). Autoconfigured with `nowo_rbac_kit.bypass_veto`.
 *
 * Vetoes are asked on every decision of a user holding a bypass role (their answer is part of the memo key), so a
 * "view as member" switch toggled during a request is seen by the next check. They must be cheap and stateless. A
 * service implementing both interfaces is asked through this one.
 */
interface RbacBypassVetoInterface
{
    /**
     * True = do not apply the bypass to this decision.
     *
     * @param RbacActor $actor the authenticated user of the decision
     * @param object|null $subject voter subject
     */
    public function vetoesBypass(RbacActor $actor, ?object $subject): bool;
}

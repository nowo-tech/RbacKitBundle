<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Security;

use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Suspends the `voter.bypass_roles` superuser bypass for one decision (e.g. an administrator previewing a project
 * "as a member"): the user's own sets decide instead. Autoconfigured with `nowo_rbac_kit.bypass_veto`.
 */
interface BypassVetoInterface
{
    /**
     * True = do not apply the bypass to this decision.
     *
     * @param object|null $subject voter subject
     */
    public function vetoes(TokenInterface $token, ?object $subject): bool;
}

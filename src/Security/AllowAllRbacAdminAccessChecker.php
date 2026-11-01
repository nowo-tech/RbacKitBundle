<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Security;

/**
 * Grants everything: wired only with `security.allow_unauthenticated: true`, which the container accepts only when
 * `kernel.debug` is true (dev / demo).
 */
final class AllowAllRbacAdminAccessChecker implements RbacAdminAccessCheckerInterface
{
    /**
     * Always true.
     */
    public function canAccess(?object $user, string $area = self::AREA_VIEW): bool
    {
        return true;
    }
}

<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Security;

use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * Role-based default checker: `security.access_roles` for the read-only pages, `security.manage_roles` (default
 * `[ROLE_ADMIN]`, independent of the access roles) for mutations. An empty role list, an anonymous user or a missing
 * SecurityBundle denies (fail closed).
 */
final readonly class ConfigurableRbacAdminAccessChecker implements RbacAdminAccessCheckerInterface
{
    /**
     * @param list<string> $accessRoles roles that open the read-only pages (any of them)
     * @param list<string> $manageRoles roles allowed to manage (any of them)
     */
    public function __construct(
        private ?AuthorizationCheckerInterface $authorizationChecker,
        private array $accessRoles,
        private array $manageRoles = ['ROLE_ADMIN'],
    ) {
    }

    /**
     * Grants when the user holds any role of the area; anonymous and empty role lists are denied.
     */
    public function canAccess(?object $user, string $area = self::AREA_VIEW): bool
    {
        if ($user === null || !$this->authorizationChecker instanceof AuthorizationCheckerInterface) {
            return false;
        }

        $roles = $area === self::AREA_MANAGE ? $this->manageRoles : $this->accessRoles;
        foreach ($roles as $role) {
            if ($this->authorizationChecker->isGranted($role)) {
                return true;
            }
        }

        return false;
    }
}

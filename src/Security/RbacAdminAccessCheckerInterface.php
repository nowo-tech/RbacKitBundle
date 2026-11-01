<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Security;

/**
 * Decides who may use the RbacKit admin UI (REQ-UI-002). Set `security.access_checker` to a host service id
 * implementing this interface to replace the role-based default.
 */
interface RbacAdminAccessCheckerInterface
{
    /** Read-only pages: the permission set list and the catalogue. */
    public const AREA_VIEW = 'view';

    /** Mutations: create, edit and delete permission sets. */
    public const AREA_MANAGE = 'manage';

    /**
     * Whether the user may use an area ({@see self::AREA_VIEW} or {@see self::AREA_MANAGE}).
     *
     * @param object|null $user authenticated user of the main request firewall (null = anonymous)
     */
    public function canAccess(?object $user, string $area = self::AREA_VIEW): bool;
}

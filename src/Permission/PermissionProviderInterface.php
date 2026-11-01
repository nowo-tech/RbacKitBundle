<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Permission;

/**
 * Contributes permissions to the catalogue. Services implementing it are autoconfigured with the
 * `nowo_rbac_kit.permission_provider` tag; the bundle's own provider reads `catalogue.permissions`.
 *
 * Providers are read once per request (the catalogue is memoised and reset between worker requests); they must
 * return the same definitions for the same key (a redefinition fails the catalogue).
 */
interface PermissionProviderInterface
{
    /**
     * @return iterable<Permission>
     */
    public function permissions(): iterable;
}

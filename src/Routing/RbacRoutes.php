<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Routing;

/**
 * Public route names of the admin UI (stable API): link from the host menu with `path(RbacRoutes::PERMISSION_SETS)`.
 */
final class RbacRoutes
{
    /** Permission set list. */
    public const PERMISSION_SETS = 'nowo_rbac_kit_permission_sets';

    /** Permission catalogue (read-only). */
    public const CATALOGUE = 'nowo_rbac_kit_catalogue';

    /** New permission set (GET form, POST + CSRF). */
    public const NEW = 'nowo_rbac_kit_permission_set_new';

    /** Edit a permission set; parameter `code` (GET form, POST + CSRF). */
    public const EDIT = 'nowo_rbac_kit_permission_set_edit';

    /** Delete a custom permission set; parameter `code` (POST + CSRF). */
    public const DELETE = 'nowo_rbac_kit_permission_set_delete';
}

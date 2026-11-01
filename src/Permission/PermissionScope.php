<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Permission;

/**
 * Where a permission is evaluated.
 */
enum PermissionScope: string
{
    /**
     * Outside any context (a dashboard, a report, a global action): granted by a global assignment, a
     * `role_grants` entry, or — with `voter.global_aggregation` — when at least one of the user's contexts grants it.
     */
    case Global = 'global';

    /**
     * Inside one context (a project, a tenant, an organisation): granted when a permission set the user holds in that
     * context contains it. Asked without a context subject, it is refused.
     */
    case Context = 'context';

    /**
     * Sort weight: global permissions first.
     */
    public function weight(): int
    {
        return $this === self::Global ? 0 : 1;
    }
}

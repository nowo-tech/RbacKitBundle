<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Context;

use Nowo\RbacKitBundle\Grant\Assignment;

/**
 * Implemented by the host objects permissions are evaluated in (a project, a tenant, an organisation): the voter
 * subject of every context-scoped permission.
 */
interface RbacContextInterface
{
    /**
     * Stable, non-empty id of the context (the `contextId` of the {@see Assignment}s).
     */
    public function getRbacContextId(): string;
}

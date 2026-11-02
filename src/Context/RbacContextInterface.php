<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Context;

use Nowo\RbacKitBundle\Grant\Assignment;

/**
 * Implemented by the host objects permissions are evaluated in (a project, a tenant, an organisation): the voter
 * subject of every context-scoped permission. The default resolver turns it into the typed id
 * `<getRbacContextType()>:<getRbacContextId()>` ({@see ContextId}), so `Organization#5` and `Project#5` are two
 * different contexts; the {@see Assignment}s use the same typed id ({@see Assignment::of()}).
 */
interface RbacContextInterface
{
    /**
     * Stable alias of the context class (`project`, `organization`; {@see ContextId::TYPE_PATTERN}). Never a class
     * name: renaming a class must not move the grants.
     */
    public function getRbacContextType(): string;

    /**
     * Stable, non-empty id of the context inside its type (at most {@see ContextId::ID_MAX_LENGTH} characters).
     */
    public function getRbacContextId(): string;
}

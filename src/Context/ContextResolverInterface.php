<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Context;

/**
 * Maps a voter subject to its context id (`context.resolver` to replace the default, e.g. for entities you cannot make
 * implement {@see RbacContextInterface}). Null = not a context: context-scoped permissions are refused.
 */
interface ContextResolverInterface
{
    /**
     * Context id of a subject, or null.
     */
    public function contextId(object $subject): ?string;
}

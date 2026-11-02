<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Context;

/**
 * Maps a voter subject to its typed context id (`context_resolver` to replace the default, e.g. for entities you
 * cannot make implement {@see RbacContextInterface}). Null = not a context: context-scoped permissions are refused.
 * The id must be typed (`<type>:<id>`, build it with {@see ContextId::of()}); an untyped id is refused by the voter.
 */
interface ContextResolverInterface
{
    /**
     * Typed context id of a subject, or null.
     */
    public function contextId(object $subject): ?string;
}

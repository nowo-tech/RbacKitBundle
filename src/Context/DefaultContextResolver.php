<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Context;

/**
 * Default resolver: subjects implementing {@see RbacContextInterface} with an acceptable type and a non-empty id of at
 * most 191 characters, as the typed id `<type>:<id>` ({@see ContextId}); anything else is not a context (fail closed).
 */
final readonly class DefaultContextResolver implements ContextResolverInterface
{
    /**
     * The subject's typed id (`<getRbacContextType()>:<getRbacContextId()>`), or null.
     */
    public function contextId(object $subject): ?string
    {
        return $subject instanceof RbacContextInterface ? ContextId::of($subject->getRbacContextType(), $subject->getRbacContextId()) : null;
    }
}

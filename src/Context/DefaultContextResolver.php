<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Context;

use function strlen;
use function trim;

/**
 * Default resolver: subjects implementing {@see RbacContextInterface} with a non-empty id of at most 191 characters;
 * anything else is not a context (fail closed).
 */
final readonly class DefaultContextResolver implements ContextResolverInterface
{
    /** Longest accepted context id. */
    public const MAX_LENGTH = 191;

    /**
     * The subject's {@see RbacContextInterface::getRbacContextId()}, or null.
     */
    public function contextId(object $subject): ?string
    {
        if (!$subject instanceof RbacContextInterface) {
            return null;
        }
        $id = $subject->getRbacContextId();

        return trim($id) === '' || strlen($id) > self::MAX_LENGTH ? null : $id;
    }
}

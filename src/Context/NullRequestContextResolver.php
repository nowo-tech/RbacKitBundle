<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Context;

use Symfony\Component\HttpFoundation\Request;

/**
 * Default request context resolver: no request has a context, so the request guard leaves context-scoped routes to
 * their controllers.
 */
final readonly class NullRequestContextResolver implements RequestContextResolverInterface
{
    /**
     * Always null.
     */
    public function fromRequest(Request $request): ?object
    {
        return null;
    }
}

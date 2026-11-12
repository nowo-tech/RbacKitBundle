<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Context;

use Symfony\Component\HttpFoundation\Request;

/**
 * Default request context resolver: no request has a context, so the request guard leaves context-scoped routes to
 * their controllers. Implements the framework-free port and, until 2.0, the deprecated one.
 */
final readonly class NullRequestContextResolver implements RbacRequestContextResolverInterface, RequestContextResolverInterface
{
    /**
     * Always null.
     */
    public function contextOf(RbacRequest $request): ?object
    {
        return null;
    }

    /**
     * Always null (deprecated port, kept until 2.0).
     */
    public function fromRequest(Request $request): ?object
    {
        return null;
    }
}

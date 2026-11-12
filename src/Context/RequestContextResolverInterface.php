<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Context;

use Symfony\Component\HttpFoundation\Request;

/**
 * Host service (`request_guard.context_resolver`): the context a request is about (the project of
 * `/projects/{projectId}/issues/{id}`), for the request guard. Should memoise its lookup on the request attributes.
 *
 * @deprecated since 1.2, implement {@see RbacRequestContextResolverInterface} (framework-free {@see RbacRequest});
 *             removed in 2.0. A service implementing both is called through the new interface.
 */
interface RequestContextResolverInterface
{
    /**
     * The context subject of the request, or null.
     */
    public function fromRequest(Request $request): ?object;
}

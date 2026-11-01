<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Context;

use Symfony\Component\HttpFoundation\Request;

/**
 * Host service (`request_guard.context_resolver`): the context a request is about (the project of
 * `/projects/{projectId}/issues/{id}`), for the request guard. Should memoise its lookup on the request attributes.
 */
interface RequestContextResolverInterface
{
    /**
     * The context subject of the request, or null.
     */
    public function fromRequest(Request $request): ?object;
}

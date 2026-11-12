<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Fixtures;

use Nowo\RbacKitBundle\Context\RbacRequest;
use Nowo\RbacKitBundle\Context\RbacRequestContextResolverInterface;

use function is_string;

/**
 * Request context of the kernel tests (framework-free port, 1.2): the `{id}` route parameter as a project.
 */
final readonly class RouteContextResolver implements RbacRequestContextResolverInterface
{
    public function contextOf(RbacRequest $request): ?object
    {
        $id = $request->routeParam('id');

        return is_string($id) ? new Project($id) : null;
    }
}

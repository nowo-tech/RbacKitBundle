<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Fixtures\Ports;

use Nowo\RbacKitBundle\Context\RbacRequest;
use Nowo\RbacKitBundle\Context\RbacRequestContextResolverInterface;

use function is_string;

/**
 * DDD host adapter on the framework-free port: the `{id}` route parameter as its domain project.
 */
final readonly class DomainRequestContextResolver implements RbacRequestContextResolverInterface
{
    public function contextOf(RbacRequest $request): ?object
    {
        $id = $request->routeParam('id');

        return is_string($id) ? new DomainProject($id) : null;
    }
}

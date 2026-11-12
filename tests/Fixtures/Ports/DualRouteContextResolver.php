<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Fixtures\Ports;

use Nowo\RbacKitBundle\Context\RbacRequest;
use Nowo\RbacKitBundle\Context\RbacRequestContextResolverInterface;
use Nowo\RbacKitBundle\Context\RequestContextResolverInterface;
use Nowo\RbacKitBundle\Tests\Fixtures\Project;
use Symfony\Component\HttpFoundation\Request;

use function is_string;

/**
 * Implements both ports with different answers, to prove the guard asks the framework-free one: the new port returns
 * the `{id}` project, the deprecated one a project nobody holds.
 */
final readonly class DualRouteContextResolver implements RbacRequestContextResolverInterface, RequestContextResolverInterface
{
    public function contextOf(RbacRequest $request): ?object
    {
        $id = $request->routeParam('id');

        return is_string($id) ? new Project($id) : null;
    }

    public function fromRequest(Request $request): Project
    {
        return new Project('never-held');
    }
}

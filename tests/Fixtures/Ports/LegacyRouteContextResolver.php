<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Fixtures\Ports;

use Nowo\RbacKitBundle\Context\RequestContextResolverInterface;
use Nowo\RbacKitBundle\Tests\Fixtures\Project;
use Symfony\Component\HttpFoundation\Request;

use function is_string;

/**
 * The 1.1 request context resolver of the tests (deprecated port): the `{id}` request attribute as a project.
 */
final readonly class LegacyRouteContextResolver implements RequestContextResolverInterface
{
    public function fromRequest(Request $request): ?object
    {
        $id = $request->attributes->get('id');

        return is_string($id) ? new Project($id) : null;
    }
}

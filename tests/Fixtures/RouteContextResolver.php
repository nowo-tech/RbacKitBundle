<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Fixtures;

use Nowo\RbacKitBundle\Context\RequestContextResolverInterface;
use Symfony\Component\HttpFoundation\Request;

use function is_string;

/**
 * Request context of the kernel tests: the `{id}` route parameter as a project.
 */
final readonly class RouteContextResolver implements RequestContextResolverInterface
{
    public function fromRequest(Request $request): ?object
    {
        $id = $request->attributes->get('id');

        return is_string($id) ? new Project($id) : null;
    }
}

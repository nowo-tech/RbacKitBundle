<?php

declare(strict_types=1);

namespace App\Demo;

use Nowo\RbacKitBundle\Context\RequestContextResolverInterface;
use Symfony\Component\HttpFoundation\Request;

use function is_string;

/**
 * The project of a request (`{project}` route parameter), for the RbacKit request guard.
 */
final readonly class RouteProjectResolver implements RequestContextResolverInterface
{
    public function __construct(
        private ProjectDirectory $projects,
    ) {
    }

    public function fromRequest(Request $request): ?object
    {
        $slug = $request->attributes->get('project');

        return is_string($slug) ? $this->projects->find($slug) : null;
    }
}

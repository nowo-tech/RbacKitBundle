<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Fixtures\Ports;

use Nowo\RbacKitBundle\Context\RbacRequest;
use Nowo\RbacKitBundle\Context\RbacRequestContextResolverInterface;
use Nowo\RbacKitBundle\Tests\Fixtures\Project;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;

use function is_string;

/**
 * A host decorator, on the new port only, of the `test.request_context` service (a deprecated-port resolver): the guard
 * must use the decorator.
 */
#[AsDecorator('test.request_context')]
final readonly class LegacyToNewResolverDecorator implements RbacRequestContextResolverInterface
{
    public function contextOf(RbacRequest $request): ?object
    {
        $id = $request->routeParam('id');

        return is_string($id) ? new Project($id) : null;
    }
}

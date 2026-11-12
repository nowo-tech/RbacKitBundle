<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Fixtures\Ports;

use Nowo\RbacKitBundle\Context\ContextId;
use Nowo\RbacKitBundle\Context\ContextResolverInterface;

/**
 * DDD host adapter: maps its plain domain object to a typed context id.
 */
final readonly class DomainContextResolver implements ContextResolverInterface
{
    public function contextId(object $subject): ?string
    {
        return $subject instanceof DomainProject ? ContextId::of('project', $subject->id) : null;
    }
}

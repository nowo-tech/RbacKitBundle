<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Fixtures\Ports;

/**
 * A DDD host's domain object: plain PHP, no bundle interface (the host maps it with its own ContextResolverInterface).
 */
final readonly class DomainProject
{
    public function __construct(
        public string $id,
    ) {
    }
}

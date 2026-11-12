<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Fixtures\Ports;

/**
 * A service that implements neither port (the container build must fail when it is configured as one).
 */
final readonly class NotAPort
{
}

<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Fixtures;

use Nowo\RbacKitBundle\PermissionSet\PermissionSetUsageInterface;

/**
 * Usage provider of the tests.
 */
final readonly class StaticUsage implements PermissionSetUsageInterface
{
    /**
     * @param array<string, int> $counts
     */
    public function __construct(
        private array $counts = ['viewer' => 3, 'in_use' => 1],
    ) {
    }

    public function usageCounts(array $codes): array
    {
        return $this->counts;
    }
}

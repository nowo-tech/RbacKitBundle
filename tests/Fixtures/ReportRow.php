<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Fixtures;

/**
 * A row of a general page wrapping its project (not a context itself).
 */
final readonly class ReportRow
{
    public function __construct(
        public Project $project,
    ) {
    }
}

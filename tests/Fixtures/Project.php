<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Fixtures;

use Nowo\RbacKitBundle\Context\RbacContextInterface;

/**
 * A context of the tests (a "project").
 */
final readonly class Project implements RbacContextInterface
{
    public function __construct(
        public string $id,
    ) {
    }

    public function getRbacContextId(): string
    {
        return $this->id;
    }
}

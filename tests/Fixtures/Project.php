<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Fixtures;

use Nowo\RbacKitBundle\Context\RbacContextInterface;

/**
 * A context of the tests (a "project" by default; another type for the typed-id tests).
 */
final readonly class Project implements RbacContextInterface
{
    public function __construct(
        public string $id,
        public string $type = 'project',
    ) {
    }

    public function getRbacContextType(): string
    {
        return $this->type;
    }

    public function getRbacContextId(): string
    {
        return $this->id;
    }
}

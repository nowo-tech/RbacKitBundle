<?php

declare(strict_types=1);

namespace App\Demo;

use Nowo\RbacKitBundle\Context\RbacContextInterface;

/**
 * A demo project: the context permission sets are held in.
 */
final readonly class DemoProject implements RbacContextInterface
{
    /** Context type of the demo projects (typed context ids: `project:<slug>`). */
    public const TYPE = 'project';

    public function __construct(
        public string $slug,
        public string $name,
    ) {
    }

    public function getRbacContextType(): string
    {
        return self::TYPE;
    }

    public function getRbacContextId(): string
    {
        return $this->slug;
    }
}

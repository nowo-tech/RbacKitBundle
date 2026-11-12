<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Fixtures\Ports;

use Nowo\RbacKitBundle\Security\RbacActor;
use Nowo\RbacKitBundle\Security\RbacBypassVetoInterface;
use Nowo\RbacKitBundle\Tests\Fixtures\Project;

/**
 * Framework-free bypass veto of the tests: the bypass does not apply to the `preview` project ("view as member").
 */
final readonly class PreviewVeto implements RbacBypassVetoInterface
{
    public function vetoesBypass(RbacActor $actor, ?object $subject): bool
    {
        return $subject instanceof Project && $subject->id === 'preview';
    }
}

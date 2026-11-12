<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Fixtures\Ports;

use Nowo\RbacKitBundle\Security\RbacActor;
use Nowo\RbacKitBundle\Security\RbacBypassVetoInterface;

/**
 * DDD host adapter on the framework-free veto port: no bypass in the `preview` domain project.
 */
final readonly class DomainPreviewVeto implements RbacBypassVetoInterface
{
    public function vetoesBypass(RbacActor $actor, ?object $subject): bool
    {
        return $subject instanceof DomainProject && $subject->id === 'preview';
    }
}

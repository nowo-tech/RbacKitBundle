<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Fixtures\Ports;

use Nowo\RbacKitBundle\Security\BypassVetoInterface;
use Nowo\RbacKitBundle\Security\RbacActor;
use Nowo\RbacKitBundle\Security\RbacBypassVetoInterface;
use Nowo\RbacKitBundle\Tests\Fixtures\Project;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * Implements both veto ports with different answers, to prove the voter asks the framework-free one: the new port
 * vetoes the `preview` project, the deprecated one would veto everything.
 */
final readonly class DualPreviewVeto implements BypassVetoInterface, RbacBypassVetoInterface
{
    public function vetoesBypass(RbacActor $actor, ?object $subject): bool
    {
        return $subject instanceof Project && $subject->id === 'preview';
    }

    public function vetoes(TokenInterface $token, ?object $subject): bool
    {
        return true;
    }
}

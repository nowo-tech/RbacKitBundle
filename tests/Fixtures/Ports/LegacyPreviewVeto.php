<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Fixtures\Ports;

use Nowo\RbacKitBundle\Security\BypassVetoInterface;
use Nowo\RbacKitBundle\Tests\Fixtures\Project;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

/**
 * The 1.1 bypass veto of the tests (deprecated port): the bypass does not apply to the `preview` project.
 */
final readonly class LegacyPreviewVeto implements BypassVetoInterface
{
    public function vetoes(TokenInterface $token, ?object $subject): bool
    {
        return $subject instanceof Project && $subject->id === 'preview';
    }
}

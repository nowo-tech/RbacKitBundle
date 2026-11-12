<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Security;

use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_string;
use function method_exists;

/**
 * Builds the framework-free {@see RbacActor} of a security token for the bypass vetoes (since 1.2). Stateless.
 *
 * @internal
 */
final class RbacActorFactory
{
    /**
     * @param list<string> $reachableRoles the roles the voter decides with
     */
    public static function fromToken(TokenInterface $token, UserInterface $user, array $reachableRoles): RbacActor
    {
        $firewall = method_exists($token, 'getFirewallName') ? $token->getFirewallName() : null;

        return new RbacActor(
            $user->getUserIdentifier(),
            $token->getRoleNames(),
            $reachableRoles,
            $user,
            $token->getAttributes(),
            $token instanceof SwitchUserToken ? $token->getOriginalToken()->getUserIdentifier() : null,
            is_string($firewall) ? $firewall : null,
        );
    }
}

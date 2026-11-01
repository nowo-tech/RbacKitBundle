<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Twig;

use Nowo\RbacKitBundle\Security\PermissionChecker;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Twig functions `rbac_can('key', context = null)`, `rbac_can_route('route', context = null)` and
 * `rbac_can_area('alias', context = null)` for every page resource (tabs, cards, buttons, menus): templates ask a
 * permission, never a role. Stateless.
 */
final class RbacTwigExtension extends AbstractExtension
{
    /**
     * @param PermissionChecker $checker voter entry point
     */
    public function __construct(
        private readonly PermissionChecker $checker,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('rbac_can', $this->checker->can(...)),
            new TwigFunction('rbac_can_route', $this->checker->canRoute(...)),
            new TwigFunction('rbac_can_area', $this->checker->canArea(...)),
        ];
    }
}

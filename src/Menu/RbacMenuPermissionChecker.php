<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Menu;

use Nowo\DashboardMenuBundle\Attribute\PermissionCheckerLabel;
use Nowo\DashboardMenuBundle\Entity\MenuItem;
use Nowo\DashboardMenuBundle\Service\MenuPermissionCheckerInterface;
use Nowo\RbacKitBundle\Security\PermissionChecker;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

use function is_object;
use function str_starts_with;

/**
 * DashboardMenu integration (registered when nowo-tech/dashboard-menu-bundle is installed): an item with a route is
 * shown only when `permission_route:<route>` is granted; an item with permission keys needs at least one of them
 * (`ROLE_*` keys are asked to Symfony as roles, other keys to the catalogue). The menu context, when it is an object,
 * is the voter subject of context permissions. Stateless.
 */
#[PermissionCheckerLabel('RbacKit permissions')]
final readonly class RbacMenuPermissionChecker implements MenuPermissionCheckerInterface
{
    /**
     * @param AuthorizationCheckerInterface|null $authorization role checks of `ROLE_*` keys
     */
    public function __construct(
        private PermissionChecker $checker,
        private ?AuthorizationCheckerInterface $authorization = null,
    ) {
    }

    /**
     * Whether the item is visible to the current user.
     */
    public function canView(MenuItem $item, mixed $context = null): bool
    {
        $subject = is_object($context) ? $context : null;
        $route   = $item->getRouteName();
        if ($route !== null && $route !== '' && !$this->checker->canRoute($route, $subject)) {
            return false;
        }

        $keys = $item->getPermissionKeys() ?? [];
        if ($keys === []) {
            return true;
        }
        foreach ($keys as $key) {
            if (str_starts_with($key, 'ROLE_') ? $this->authorization?->isGranted($key) === true : $this->checker->can($key, $subject)) {
                return true;
            }
        }

        return false;
    }
}

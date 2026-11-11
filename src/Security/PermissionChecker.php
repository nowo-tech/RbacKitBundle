<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Security;

use Nowo\RbacKitBundle\Permission\Permission;
use Nowo\RbacKitBundle\Permission\PermissionCatalog;
use Nowo\RbacKitBundle\Permission\PermissionScope;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;

/**
 * PHP / Twig entry point of the voter: `can('key', $context)`, `canRoute('route', $context)`, `canArea('alias',
 * $context)` and `filter($contexts, 'key')` (the contexts that grant a key, for general pages that aggregate data).
 * A context key asked without a context is refused (a caller that forgets the context fails closed); an uncatalogued
 * key, alias or a missing Security component is refused too. Stateless: the voter memoises.
 */
final readonly class PermissionChecker implements PermissionCheckerInterface
{
    /**
     * @param AuthorizationCheckerInterface|null $authorization Symfony authorization checker (null = SecurityBundle missing: deny)
     */
    public function __construct(
        private PermissionCatalog $catalog,
        private ?AuthorizationCheckerInterface $authorization = null,
    ) {
    }

    /**
     * Whether the current user holds a permission key (in the context for a context key).
     */
    public function can(string $key, ?object $context = null): bool
    {
        return $this->allows($this->catalog->find($key), $context);
    }

    /**
     * Whether the current user may open a route (its catalogued permission; `catalogue.open_routes` pass).
     */
    public function canRoute(string $route, ?object $context = null): bool
    {
        return $this->authorization?->isGranted(PermissionVoter::ROUTE . $route, $context) === true;
    }

    /**
     * Whether the current user may use an area alias (`catalogue.aliases`); an unknown alias is refused.
     */
    public function canArea(string $alias, ?object $context = null): bool
    {
        return $this->allows($this->catalog->forAlias($alias), $context);
    }

    /**
     * The given contexts where the current user holds the key, in their order. For an `aggregate` global key this is
     * the isolation control of general pages: a context is kept only when a set held **in it** grants the key (or the
     * user holds the key platform-wide: role grant, global assignment, fallback set, bypass role). Pass **context
     * objects** (or objects the `context_resolver` maps to a context): any other object is never kept through
     * aggregation.
     *
     * @template T of object
     *
     * @param iterable<T> $contexts
     *
     * @return list<T>
     */
    public function filter(iterable $contexts, string $key): array
    {
        $granted = [];
        foreach ($contexts as $context) {
            if ($this->can($key, $context)) {
                $granted[] = $context;
            }
        }

        return $granted;
    }

    private function allows(?Permission $permission, ?object $context): bool
    {
        if (!$permission instanceof Permission || ($permission->scope === PermissionScope::Context && $context === null)) {
            return false;
        }

        return $this->authorization?->isGranted(PermissionVoter::PREFIX . $permission->key, $context) === true;
    }
}

<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Security;

/**
 * Permission check port (1.1, rule 1): catalogued keys, routes and areas, in an optional context object.
 *
 * Implemented by {@see PermissionChecker} (autowire this interface).
 */
interface PermissionCheckerInterface
{
    /**
     * Whether the current user holds the permission `$key` (in `$context`, when given).
     */
    public function can(string $key, ?object $context = null): bool;

    /**
     * Whether the current user may reach the catalogued route.
     */
    public function canRoute(string $route, ?object $context = null): bool;

    /**
     * Whether the current user may reach the catalogued area (alias).
     */
    public function canArea(string $alias, ?object $context = null): bool;

    /**
     * The contexts in which the current user holds `$key`.
     *
     * @template T of object
     *
     * @param iterable<T> $contexts
     *
     * @return list<T>
     */
    public function filter(iterable $contexts, string $key): array;
}

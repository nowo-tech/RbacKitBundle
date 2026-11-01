<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Permission;

use LogicException;
use Symfony\Contracts\Service\ResetInterface;

use function count;
use function sprintf;
use function uasort;

/**
 * Every permission a page, a page resource or a menu link can ask for — merged from the configured catalogue and the
 * tagged {@see PermissionProviderInterface} services — with a route index and area aliases.
 *
 * Built once per request (memoised, {@see self::reset()} between worker requests). A redefinition of a key, a route
 * guarded by two keys, an alias to an unknown key or more than `catalogue.max_permissions` entries fail loudly: a
 * catalogue that cannot be trusted must never grant. An empty catalogue denies everything (the voter checks
 * {@see self::isEmpty()} first).
 */
final class PermissionCatalog implements ResetInterface
{
    /** @var array<string, Permission>|null key => permission (global first, then by key) */
    private ?array $permissions = null;

    /** @var array<string, string> route => key */
    private array $routes = [];

    /**
     * @param iterable<PermissionProviderInterface> $providers catalogue sources
     * @param array<string, string> $aliases area alias => permission key (`catalogue.aliases`)
     * @param int $maxPermissions upper bound of the catalogue (`catalogue.max_permissions`)
     */
    public function __construct(
        private readonly iterable $providers,
        private readonly array $aliases = [],
        private readonly int $maxPermissions = 2000,
    ) {
    }

    /**
     * Forgets the memoised catalogue (kernel.reset, worker mode).
     */
    public function reset(): void
    {
        $this->permissions = null;
        $this->routes      = [];
    }

    /**
     * @return array<string, Permission> key => permission, global first, then by key
     */
    public function all(): array
    {
        return $this->permissions ?? $this->build();
    }

    /**
     * Whether no permission is catalogued (the voter then denies every attribute).
     */
    public function isEmpty(): bool
    {
        return $this->all() === [];
    }

    /**
     * The permission of a key, or null when the key is not catalogued.
     */
    public function find(string $key): ?Permission
    {
        return $this->all()[$key] ?? null;
    }

    /**
     * The permission guarding a route, or null when the catalogue does not guard it.
     */
    public function forRoute(string $route): ?Permission
    {
        $this->all();
        $key = $this->routes[$route] ?? null;

        return $key === null ? null : $this->find($key);
    }

    /**
     * The permission behind an area alias (`catalogue.aliases`), or null.
     */
    public function forAlias(string $alias): ?Permission
    {
        $key = $this->aliases[$alias] ?? null;

        return $key === null ? null : $this->find($key);
    }

    /**
     * @return array<string, Permission>
     */
    private function build(): array
    {
        $permissions = [];
        $routes      = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->permissions() as $permission) {
                $existing = $permissions[$permission->key] ?? null;
                if ($existing instanceof Permission) {
                    if (!$existing->equals($permission)) {
                        throw new LogicException(sprintf('RbacKit catalogue: permission "%s" is defined twice with different definitions.', $permission->key));
                    }
                    continue;
                }
                if (count($permissions) >= $this->maxPermissions) {
                    throw new LogicException(sprintf('RbacKit catalogue: more than %d permissions (catalogue.max_permissions).', $this->maxPermissions));
                }
                foreach ($permission->routes as $route) {
                    if (isset($routes[$route])) {
                        throw new LogicException(sprintf('RbacKit catalogue: route "%s" is guarded by both "%s" and "%s".', $route, $routes[$route], $permission->key));
                    }
                    $routes[$route] = $permission->key;
                }
                $permissions[$permission->key] = $permission;
            }
        }
        foreach ($this->aliases as $alias => $key) {
            if (!isset($permissions[$key])) {
                throw new LogicException(sprintf('RbacKit catalogue: alias "%s" points at the unknown permission "%s".', $alias, $key));
            }
        }
        uasort($permissions, static fn (Permission $a, Permission $b): int => [$a->scope->weight(), $a->key] <=> [$b->scope->weight(), $b->key]);

        // @igor-ignore - Per-request memo, cleared by reset() (kernel.reset).
        $this->routes = $routes;

        // @igor-ignore - Per-request memo, cleared by reset() (kernel.reset).
        return $this->permissions = $permissions;
    }
}

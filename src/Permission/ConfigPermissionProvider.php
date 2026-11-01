<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Permission;

/**
 * The permissions of `nowo_rbac_kit.catalogue.permissions`. Configuration only: safe in worker mode.
 */
final readonly class ConfigPermissionProvider implements PermissionProviderInterface
{
    /**
     * @param array<string, array{scope: string, routes: list<string>, group: string|null, label: string|null, description: string|null}> $permissions key => definition
     */
    public function __construct(
        private array $permissions = [],
    ) {
    }

    /**
     * One {@see Permission} per configured key.
     *
     * @return iterable<Permission>
     */
    public function permissions(): iterable
    {
        foreach ($this->permissions as $key => $definition) {
            yield new Permission(
                (string) $key,
                PermissionScope::from($definition['scope']),
                $definition['routes'],
                $definition['group'],
                $definition['label'],
                $definition['description'],
            );
        }
    }
}

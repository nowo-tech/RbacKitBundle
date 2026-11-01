<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Support;

use Nowo\RbacKitBundle\Permission\ConfigPermissionProvider;
use Nowo\RbacKitBundle\Permission\PermissionCatalog;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetRegistry;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetStorageInterface;

/**
 * The catalogue and the sets of the unit tests (the same as the kernel tests' configuration).
 */
final class Catalogues
{
    /**
     * @return array<string, array{scope: string, routes: list<string>, group: string|null, label: string|null, description: string|null}>
     */
    public static function permissions(): array
    {
        return [
            'summary.view'          => self::permission('global', ['dashboard_summary'], 'general'),
            'reports.export'        => self::permission('global', [], 'general'),
            'wiki.manage'           => self::permission('global'),
            'project.view'          => self::permission('context', ['project_show'], 'project'),
            'project.issues.triage' => self::permission('context', ['project_issue_triage'], 'project'),
            'project.settings.view' => self::permission('context', ['project_settings', 'project_settings_alerts', 'project_settings_access', 'project_settings_data'], 'project'),
        ];
    }

    /**
     * @return array<string, array{label: string|null, description: string|null, permissions: list<string>, all: bool, except: list<string>, locked: bool}>
     */
    public static function sets(): array
    {
        return [
            'viewer'  => self::set(['summary.view', 'project.view']),
            'triage'  => self::set(['project.issues.triage']),
            'owner'   => self::set([], true, ['wiki.manage'], true),
            'auditor' => self::set(['reports.export']),
        ];
    }

    public static function catalog(): PermissionCatalog
    {
        return new PermissionCatalog([new ConfigPermissionProvider(self::permissions())], ['product.summary' => 'summary.view']);
    }

    public static function registry(?PermissionSetStorageInterface $storage = null, ?PermissionCatalog $catalog = null): PermissionSetRegistry
    {
        return new PermissionSetRegistry($catalog ?? self::catalog(), $storage ?? new MemoryStorage(), self::sets());
    }

    /**
     * @param list<string> $routes
     *
     * @return array{scope: string, routes: list<string>, group: string|null, label: string|null, description: string|null}
     */
    private static function permission(string $scope, array $routes = [], ?string $group = null): array
    {
        return ['scope' => $scope, 'routes' => $routes, 'group' => $group, 'label' => null, 'description' => null];
    }

    /**
     * @param list<string> $permissions
     * @param list<string> $except
     *
     * @return array{label: string|null, description: string|null, permissions: list<string>, all: bool, except: list<string>, locked: bool}
     */
    private static function set(array $permissions = [], bool $all = false, array $except = [], bool $locked = false): array
    {
        return ['label' => null, 'description' => null, 'permissions' => $permissions, 'all' => $all, 'except' => $except, 'locked' => $locked];
    }
}

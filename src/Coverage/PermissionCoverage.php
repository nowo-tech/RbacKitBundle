<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Coverage;

use Nowo\RbacKitBundle\Permission\Permission;
use Nowo\RbacKitBundle\Permission\PermissionCatalog;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouterInterface;

use function array_diff;
use function array_keys;
use function array_values;
use function in_array;
use function preg_replace;
use function rtrim;
use function sort;
use function str_starts_with;

/**
 * Coverage check of the catalogue (generalises the host "every route has a permission" test): every route whose path
 * starts with one of the prefixes is guarded by a permission or listed in `catalogue.open_routes` / the ignore list;
 * every catalogue route exists; every configured set key is catalogued. Use it from a host test or
 * `nowo:rbac:check`. Stateless.
 */
final readonly class PermissionCoverage
{
    /**
     * @param array<string, array{permissions: list<string>, all: bool, except: list<string>}> $systemSets configured sets (`permission_sets`)
     * @param list<string> $openRoutes `catalogue.open_routes`
     */
    public function __construct(
        private PermissionCatalog $catalog,
        private RouterInterface $router,
        private array $systemSets = [],
        private array $openRoutes = [],
    ) {
    }

    /**
     * Builds the report. A leading `/{_locale}` is ignored when matching the prefixes.
     *
     * @param list<string> $prefixes path prefixes that must be guarded (e.g. `/dashboard`, `/projects`)
     * @param list<string> $ignoreRoutes route names exempt from the check
     */
    public function report(array $prefixes, array $ignoreRoutes = []): CoverageReport
    {
        $collection = $this->router->getRouteCollection();
        $unguarded  = [];
        foreach ($collection->all() as $name => $route) {
            $path = (string) preg_replace('#^/\{_locale\}#', '', $route->getPath());
            if (!$this->matches($path, $prefixes) || in_array($name, $ignoreRoutes, true) || in_array($name, $this->openRoutes, true)) {
                continue;
            }
            if (!$this->catalog->forRoute($name) instanceof Permission) {
                $unguarded[] = $name . ' ' . $route->getPath();
            }
        }

        $missing = [];
        foreach ($this->catalog->all() as $permission) {
            foreach ($permission->routes as $name) {
                if (!$collection->get($name) instanceof Route) {
                    $missing[] = $permission->key . ': ' . $name;
                }
            }
        }

        $catalogue = array_keys($this->catalog->all());
        $unknown   = [];
        foreach ($this->systemSets as $code => $definition) {
            $keys = array_values(array_diff([...$definition['permissions'], ...$definition['except']], $catalogue));
            if ($keys !== []) {
                $unknown[$code] = $keys;
            }
        }
        sort($unguarded);

        return new CoverageReport($unguarded, $missing, $unknown, $catalogue === []);
    }

    /**
     * @param list<string> $prefixes
     */
    private function matches(string $path, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if ($path === $prefix || str_starts_with($path, rtrim($prefix, '/') . '/')) {
                return true;
            }
        }

        return false;
    }
}

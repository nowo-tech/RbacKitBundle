<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\DependencyInjection;

use Nowo\RbacKitBundle\Permission\Permission;
use Nowo\RbacKitBundle\Permission\PermissionScope;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetManager;
use Nowo\RbacKitBundle\Ui\RbacPages;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

use function array_diff;
use function array_filter;
use function array_keys;
use function array_map;
use function array_unique;
use function array_values;
use function get_debug_type;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function strtolower;
use function trim;

use const ARRAY_FILTER_USE_BOTH;

/**
 * Configuration tree of `nowo_rbac_kit` (REQ-SF-006: strict nodes, closed sets, safe defaults — nothing is granted
 * until the host catalogues permissions and assigns sets; an empty catalogue denies everything).
 */
final class Configuration implements ConfigurationInterface
{
    /** Headers never copied into the RbacRequest (credentials, cookies, client-forged forwarding / rewrite headers). */
    private const REFUSED_HEADERS = ['authorization', 'proxy-authorization', 'cookie', 'set-cookie', 'forwarded', 'x-real-ip', 'x-original-url', 'x-rewrite-url', 'referer', 'ssl-client-cert'];

    /** Extension alias / root key. */
    public const ALIAS = 'nowo_rbac_kit';

    /** Default URL prefix of the admin UI. */
    public const DEFAULT_PATH_PREFIX = '/admin/rbac';

    /** Storage types. */
    public const STORAGE_TYPES = ['config', 'doctrine', 'service'];

    /**
     * Builds the tree.
     */
    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        /** @var ArrayNodeDefinition $root */
        $root = $treeBuilder->getRootNode();
        $root->info('Nowo RBAC Kit — permission catalogue, permission sets checked in their context, memoised voter, request guard and admin UI.');

        $children = $root->children();
        $this->addCatalogue($children->arrayNode('catalogue'));
        $this->addPermissionSets($children->arrayNode('permission_sets'));
        $this->addRoleGrants($children->arrayNode('role_grants'));
        $this->addVoter($children->arrayNode('voter'));
        $children->scalarNode('grant_provider')->info('Service id implementing GrantProviderInterface (the sets each user holds); null = nobody holds a set.')->defaultNull()->end();
        $children->scalarNode('context_resolver')->info('Service id implementing ContextResolverInterface; null = subjects implementing RbacContextInterface.')->defaultNull()->end();
        $this->addStorage($children->arrayNode('storage'));
        $this->addDoctrine($children->arrayNode('doctrine'));
        $this->addRequestGuard($children->arrayNode('request_guard'));
        $this->addCoverage($children->arrayNode('coverage'));
        $this->addWebUi($children->arrayNode('web_ui'));
        $this->addSecurity($children->arrayNode('security'));
        $children->end();

        $root
            ->validate()
                ->ifTrue(static fn (array $v): bool => is_string($v['voter']['fallback_permission_set'] ?? null) && !isset($v['permission_sets'][$v['voter']['fallback_permission_set']]))
                ->then(static function (array $v): never {
                    throw new InvalidConfigurationException(sprintf('nowo_rbac_kit.voter.fallback_permission_set "%s" is not a configured permission set; allowed: %s.', $v['voter']['fallback_permission_set'], implode(', ', array_keys($v['permission_sets'])) ?: '(none)'));
                })
            ->end();

        return $treeBuilder;
    }

    /**
     * The `request_guard.context_headers` entries that may not be copied: not a header name, a credential, cookie or
     * forwarding header, or a name that looks like a secret (since 1.2).
     *
     * @param array<array-key, mixed> $headers
     *
     * @return list<string>
     */
    public static function refusedContextHeaders(array $headers): array
    {
        $refused = [];
        foreach ($headers as $header) {
            if (!is_string($header) || preg_match('/^[a-z0-9][a-z0-9-]{0,63}$/', $header) !== 1
                || in_array($header, self::REFUSED_HEADERS, true)
                || preg_match('/token|secret|key|auth|session|signature|pass|pwd|cookie|csrf|jwt|bearer|credential|cert|hmac|otp|oidc|^x-forwarded-/', $header) === 1) {
                $refused[] = is_string($header) ? $header : get_debug_type($header);
            }
        }

        return $refused;
    }

    /**
     * Non-empty, trimmed strings of a configured list.
     *
     * @return list<string>
     */
    public static function strings(mixed $values): array
    {
        $list = [];
        foreach (is_array($values) ? $values : [] as $value) {
            if (is_string($value) && trim($value) !== '') {
                $list[] = trim($value);
            }
        }

        return $list;
    }

    private function addCatalogue(ArrayNodeDefinition $node): void
    {
        $node
            ->info('The permission catalogue (more permissions come from services tagged nowo_rbac_kit.permission_provider).')
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('translation_domain')->info('Domain of the permission labels (permission.<key>.label / .description) and system set labels.')->defaultValue('messages')->cannotBeEmpty()->end()
                ->integerNode('max_permissions')->info('Upper bound of the catalogue (configuration + providers).')->defaultValue(2000)->min(1)->max(20_000)->end()
                ->arrayNode('permissions')
                    ->info('Permission key => definition.')
                    ->useAttributeAsKey('key')
                    ->normalizeKeys(false)
                    ->arrayPrototype()
                        ->children()
                            ->enumNode('scope')
                                ->info('global = outside any context; context = inside one context (voter subject).')
                                ->values(array_map(static fn (PermissionScope $scope): string => $scope->value, PermissionScope::cases()))
                                ->defaultValue(PermissionScope::Global->value)
                            ->end()
                            ->arrayNode('routes')->info('Route names guarded by this permission (request guard, permission_route:, coverage).')->scalarPrototype()->cannotBeEmpty()->end()->end()
                            ->scalarNode('group')->info('Group shown in the admin catalogue.')->defaultNull()->end()
                            ->scalarNode('label')->info('Translation key of the label; null = permission.<key>.label.')->defaultNull()->end()
                            ->scalarNode('description')->info('Translation key of the description; null = permission.<key>.description.')->defaultNull()->end()
                            ->booleanNode('aggregate')->info('Global keys only: true = also granted when a set the user holds in any context contains it (general pages that show the data of those contexts). Default false: a context-held set never grants a global key.')->defaultFalse()->end()
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => self::aggregatingContextKeys($v) !== [])
                        ->then(static function (array $v): never {
                            throw new InvalidConfigurationException(sprintf('nowo_rbac_kit.catalogue.permissions "%s": aggregate is only allowed on global permissions.', implode('", "', self::aggregatingContextKeys($v))));
                        })
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => array_filter(array_keys($v), static fn (int|string $key): bool => !Permission::isValidKey((string) $key)) !== [])
                        ->then(static function (array $v): never {
                            throw new InvalidConfigurationException(sprintf('Invalid nowo_rbac_kit.catalogue.permissions key(s) "%s": expected lowercase segments separated by ".", "-" or ":" (e.g. "project.issues.view").', implode('", "', array_filter(array_keys($v), static fn (int|string $key): bool => !Permission::isValidKey((string) $key)))));
                        })
                    ->end()
                ->end()
                ->arrayNode('aliases')
                    ->info('Area alias => permission key (PermissionChecker::canArea(), rbac_can_area()).')
                    ->useAttributeAsKey('alias')
                    ->normalizeKeys(false)
                    ->scalarPrototype()->cannotBeEmpty()->end()
                ->end()
                ->arrayNode('open_routes')->info('Routes permission_route: always grants and coverage ignores (home, account, logout…).')->scalarPrototype()->cannotBeEmpty()->end()->end()
                ->enumNode('unmapped_routes')
                    ->info('permission_route: on a route the catalogue does not guard: deny (default, safe) or allow.')
                    ->values(['deny', 'allow'])
                    ->defaultValue('deny')
                ->end()
            ->end();
    }

    private function addPermissionSets(ArrayNodeDefinition $node): void
    {
        $node
            ->info('System permission sets (code => definition). Editable from the admin UI with a writable storage unless locked; never deletable.')
            ->useAttributeAsKey('code')
            ->normalizeKeys(false)
            ->arrayPrototype()
                ->children()
                    ->scalarNode('label')->info('Translation key of the name (catalogue domain); null = permission_set.<code>.label.')->defaultNull()->end()
                    ->scalarNode('description')->info('Description (translation key or text).')->defaultNull()->end()
                    ->arrayNode('permissions')->info('Permission keys of the set.')->scalarPrototype()->cannotBeEmpty()->end()->end()
                    ->booleanNode('all')->info('true = every catalogued **context** permission (minus except), e.g. an owner set held in a context.')->defaultFalse()->end()
                    ->booleanNode('include_global')->info('With all: true, also every catalogued global permission (a platform-wide set assigned globally only). Default false: an owner set held in a context never carries global keys.')->defaultFalse()->end()
                    ->arrayNode('except')->info('Keys left out of an "all" set.')->scalarPrototype()->cannotBeEmpty()->end()->end()
                    ->booleanNode('locked')->info('true = not editable from the admin UI (stored edits are ignored).')->defaultFalse()->end()
                ->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => array_filter($v, static fn (mixed $set): bool => is_array($set) && ($set['include_global'] ?? false) === true && ($set['all'] ?? false) !== true) !== [])
                ->thenInvalid('nowo_rbac_kit.permission_sets include_global needs all: true, got %s.')
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => array_filter(array_keys($v), static fn (int|string $code): bool => preg_match(PermissionSetManager::CODE_PATTERN, (string) $code) !== 1) !== [])
                ->thenInvalid('nowo_rbac_kit.permission_sets codes must match ^[a-z][a-z0-9_]{1,39}$, got %s.')
            ->end();
    }

    private function addRoleGrants(ArrayNodeDefinition $node): void
    {
        $node
            ->info('Symfony role => global permission keys the role holds whatever the user\'s sets (after the role hierarchy).')
            ->useAttributeAsKey('role')
            ->normalizeKeys(false)
            ->arrayPrototype()
                ->scalarPrototype()->cannotBeEmpty()->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => array_filter($v, static fn (mixed $keys, int|string $role): bool => !str_starts_with((string) $role, 'ROLE_') || self::strings($keys) === [], ARRAY_FILTER_USE_BOTH) !== [])
                ->thenInvalid('nowo_rbac_kit.role_grants keys must be ROLE_* names with a non-empty list of permission keys, got %s.')
            ->end();
    }

    private function addVoter(ArrayNodeDefinition $node): void
    {
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('bypass_roles')
                    ->info('Roles that pass every permission (superusers). Empty (default) = nobody bypasses the catalogue.')
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => array_filter($v, static fn (mixed $role): bool => !is_string($role) || !str_starts_with($role, 'ROLE_')) !== [])
                        ->thenInvalid('nowo_rbac_kit.voter.bypass_roles must list ROLE_* names, got %s.')
                    ->end()
                ->end()
                ->scalarNode('fallback_permission_set')->info('Set whose global permissions a user without any assignment holds (e.g. a brand-new account); null = none.')->defaultNull()->end()
                ->integerNode('max_assignments')->info('Assignments read per user and request; the rest are ignored (logged).')->defaultValue(5000)->min(1)->max(100_000)->end()
            ->end();
    }

    private function addStorage(ArrayNodeDefinition $node): void
    {
        $node
            ->info('Where custom sets and edits of system sets live.')
            ->addDefaultsIfNotSet()
            ->children()
                ->enumNode('type')
                    ->info('config = read-only (system sets only, default); doctrine = the bundle entity (table rbac_permission_set); service = storage.service.')
                    ->values(self::STORAGE_TYPES)
                    ->defaultValue('config')
                ->end()
                ->scalarNode('service')->info('Service id implementing PermissionSetStorageInterface (storage.type: service).')->defaultNull()->end()
                ->scalarNode('cache_pool')->info('Cache pool (Symfony\\Contracts\\Cache\\CacheInterface) holding the stored rows; null = read the storage once per request. Share it between web hosts.')->defaultValue('cache.app')->end()
                ->integerNode('cache_ttl')->info('Seconds the cached rows live: the longest a change made on another host (with a per-host pool) takes to be seen.')->defaultValue(300)->min(1)->max(86_400)->end()
                ->integerNode('max_sets')->info('Upper bound of stored rows (custom sets + edits): creation is refused beyond it, and a table holding more rows is an error (nothing is silently dropped).')->defaultValue(200)->min(1)->max(5000)->end()
                ->scalarNode('usage_provider')->info('Service id implementing PermissionSetUsageInterface (assignment counts). Without it custom sets cannot be deleted; with it a set in use cannot be deleted and a code still assigned cannot be re-created.')->defaultNull()->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => ($v['type'] ?? null) === 'service' && (!is_string($v['service'] ?? null) || trim($v['service']) === ''))
                ->thenInvalid('nowo_rbac_kit.storage.service must name a PermissionSetStorageInterface service when storage.type is "service", got %s.')
            ->end();
    }

    private function addDoctrine(ArrayNodeDefinition $node): void
    {
        $node
            ->addDefaultsIfNotSet()
            ->children()
                ->scalarNode('table_prefix')
                    ->info('Prefix of the bundle table and index names (REQ-ORM-002): "app_" gives app_rbac_permission_set.')
                    ->defaultValue('')
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => !is_string($v) || preg_match('/^[A-Za-z0-9_]*$/', $v) !== 1)
                        ->thenInvalid('nowo_rbac_kit.doctrine.table_prefix must contain only letters, digits and underscores, got %s.')
                    ->end()
                ->end()
                ->scalarNode('entity_manager')
                    ->info('Doctrine entity manager that maps the bundle entity (storage.type: doctrine, 1.1). Null (default): the default manager. When set, the entity is mapped on that manager ONLY (removed from the others, auto_mapping included), every read and write uses it, the bundle clears it at the end of each request / message / command, and the build fails when it is not declared. Writes are DBAL statements: no manager is ever flushed.')
                    ->defaultNull()
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => $v !== null && (!is_string($v) || preg_match('/^\w+$/', $v) !== 1))
                        ->thenInvalid('nowo_rbac_kit.doctrine.entity_manager must be an entity manager name or null, got %s.')
                    ->end()
                ->end()
            ->end();
    }

    private function addRequestGuard(ArrayNodeDefinition $node): void
    {
        $node
            ->info('Opt-in listener refusing (403) a catalogued route before its controller when its permission is not granted.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->defaultFalse()->end()
                ->scalarNode('context_resolver')->info('Service id implementing RbacRequestContextResolverInterface (or the deprecated RequestContextResolverInterface): the context of a request; null = no request has a context (context routes are refused unless skip_strangers or the route default _rbac_guard: false).')->defaultNull()->end()
                ->arrayNode('context_headers')
                    ->info('Since 1.2: request headers copied into the RbacRequest given to an RbacRequestContextResolverInterface (e.g. x-tenant). Default none. Values are client-controlled: read the context from the same source the controller loads data from. Credential, cookie and forwarding headers (x-forwarded-*, x-real-ip, forwarded), and names containing token, secret, key, auth, session, signature, pass, pwd, cookie, csrf, jwt, bearer, credential, cert, hmac, otp or oidc, are refused.')
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                    ->beforeNormalization()->ifArray()->then(static fn (array $v): array => array_values(array_unique(array_map(static fn (mixed $name): mixed => is_string($name) ? strtolower(trim($name)) : $name, $v))))->end()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => self::refusedContextHeaders($v) !== [])
                        ->then(static fn (array $v): never => throw new InvalidConfigurationException(sprintf('request_guard.context_headers: these headers may carry credentials or are not header names and cannot be copied into the RbacRequest: %s.', implode(', ', self::refusedContextHeaders($v)))))
                    ->end()
                ->end()
                ->booleanNode('skip_strangers')->info('true = users holding no set in the request\'s context, and requests whose context is not resolved, are left to the controller (its 404 / share-link answer). Default false: refused (403).')->defaultFalse()->end()
            ->end();
    }

    private function addCoverage(ArrayNodeDefinition $node): void
    {
        $node
            ->info('Defaults of nowo:rbac:check / PermissionCoverage.')
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('prefixes')->info('Path prefixes whose routes must be guarded (e.g. /dashboard, /projects).')->scalarPrototype()->cannotBeEmpty()->end()->end()
                ->arrayNode('ignore_routes')->info('Route names exempt from the check.')->scalarPrototype()->cannotBeEmpty()->end()->end()
            ->end();
    }

    private function addWebUi(ArrayNodeDefinition $node): void
    {
        $node
            ->info('Admin UI (REQ-UI-001 / REQ-UI-003): permission sets and catalogue.')
            ->addDefaultsIfNotSet()
            ->children()
                ->booleanNode('enabled')->info('true = register the admin controllers (routes still need the nowo_rbac_kit route import).')->defaultFalse()->end()
                ->scalarNode('path_prefix')
                    ->info('URL prefix of the admin routes; protect it with security.access_control in the host.')
                    ->defaultValue(self::DEFAULT_PATH_PREFIX)
                    ->validate()
                        ->ifTrue(static fn (mixed $v): bool => !is_string($v) || trim($v, '/ ') === '' || !str_starts_with($v, '/'))
                        ->thenInvalid('nowo_rbac_kit.web_ui.path_prefix must be an absolute path such as "/admin/rbac", got %s.')
                    ->end()
                ->end()
                ->scalarNode('layout_template')->info('Layout the pages extend. Null (default) = nowo_ui_kit.layouts.admin.')->defaultNull()->end()
                ->arrayNode('templates')
                    ->info('Per-page template overrides (page id => template): index, catalogue, form.')
                    ->useAttributeAsKey('page')
                    ->normalizeKeys(false)
                    ->scalarPrototype()->cannotBeEmpty()->end()
                    ->validate()
                        ->ifTrue(static fn (array $v): bool => array_diff(array_keys($v), array_keys(RbacPages::TEMPLATES)) !== [])
                        ->then(static function (array $v): never {
                            throw new InvalidConfigurationException(sprintf('Unknown nowo_rbac_kit.web_ui.templates page(s) "%s"; allowed: %s.', implode('", "', array_diff(array_keys($v), array_keys(RbacPages::TEMPLATES))), implode(', ', array_keys(RbacPages::TEMPLATES))));
                        })
                    ->end()
                ->end()
            ->end();
    }

    private function addSecurity(ArrayNodeDefinition $node): void
    {
        $node
            ->info('REQ-UI-002 private access of the admin UI.')
            ->addDefaultsIfNotSet()
            ->children()
                ->arrayNode('access_roles')
                    ->info('Roles that open the read-only pages (any of them). Must not be empty unless access_checker is set or allow_unauthenticated is true.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['ROLE_ADMIN'])
                ->end()
                ->arrayNode('manage_roles')
                    ->info('Roles allowed to create / edit / delete sets (root-equivalent over the catalogue). Independent of access_roles; never empty.')
                    ->scalarPrototype()->end()
                    ->defaultValue(['ROLE_ADMIN'])
                ->end()
                ->scalarNode('access_checker')->info('Service id implementing RbacAdminAccessCheckerInterface; null = role-based default.')->defaultNull()->end()
                ->booleanNode('allow_unauthenticated')->info('DEV / DEMO only (refused without kernel.debug): open the admin UI without login.')->defaultFalse()->end()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $v): bool => !self::hasCustomAccess($v) && (self::strings($v['access_roles'] ?? []) === [] || self::strings($v['manage_roles'] ?? []) === []))
                ->thenInvalid('nowo_rbac_kit.security.access_roles and manage_roles must contain at least one non-empty role when no access_checker is set and allow_unauthenticated is false (an empty list would deny everybody).')
            ->end();
    }

    /**
     * Context-scoped keys flagged `aggregate` (refused).
     *
     * @param array<int|string, mixed> $permissions
     *
     * @return list<string>
     */
    private static function aggregatingContextKeys(array $permissions): array
    {
        $keys = [];
        foreach ($permissions as $key => $definition) {
            if (is_array($definition) && ($definition['aggregate'] ?? false) === true && ($definition['scope'] ?? null) !== PermissionScope::Global->value) {
                $keys[] = (string) $key;
            }
        }

        return $keys;
    }

    /**
     * @param array<string, mixed> $security
     */
    private static function hasCustomAccess(array $security): bool
    {
        return ($security['allow_unauthenticated'] ?? false) === true || (is_string($security['access_checker'] ?? null) && $security['access_checker'] !== '');
    }
}

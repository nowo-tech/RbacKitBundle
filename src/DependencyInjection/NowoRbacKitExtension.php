<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\DependencyInjection;

use Doctrine\ORM\EntityManagerInterface;
use LogicException;
use Nowo\DashboardMenuBundle\Service\MenuPermissionCheckerInterface;
use Nowo\RbacKitBundle\Command\CheckCommand;
use Nowo\RbacKitBundle\Context\ContextResolverInterface;
use Nowo\RbacKitBundle\Context\DefaultContextResolver;
use Nowo\RbacKitBundle\Context\NullRequestContextResolver;
use Nowo\RbacKitBundle\Controller\PermissionSetController;
use Nowo\RbacKitBundle\Coverage\PermissionCoverage;
use Nowo\RbacKitBundle\Doctrine\TablePrefixListener;
use Nowo\RbacKitBundle\EventListener\PermissionRequestListener;
use Nowo\RbacKitBundle\EventListener\RbacAdminAccessListener;
use Nowo\RbacKitBundle\Form\PermissionSetType;
use Nowo\RbacKitBundle\Grant\GrantProviderInterface;
use Nowo\RbacKitBundle\Grant\GrantResolver;
use Nowo\RbacKitBundle\Grant\NullGrantProvider;
use Nowo\RbacKitBundle\Menu\RbacMenuPermissionChecker;
use Nowo\RbacKitBundle\NowoRbacKitBundle;
use Nowo\RbacKitBundle\Permission\ConfigPermissionProvider;
use Nowo\RbacKitBundle\Permission\PermissionCatalog;
use Nowo\RbacKitBundle\Permission\PermissionProviderInterface;
use Nowo\RbacKitBundle\PermissionSet\ConfigPermissionSetStorage;
use Nowo\RbacKitBundle\PermissionSet\DoctrinePermissionSetStorage;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetManager;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetManagerInterface;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetRegistry;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetStorageInterface;
use Nowo\RbacKitBundle\Routing\RbacRouteLoader;
use Nowo\RbacKitBundle\Security\AccessGuard;
use Nowo\RbacKitBundle\Security\AllowAllRbacAdminAccessChecker;
use Nowo\RbacKitBundle\Security\BypassVetoInterface;
use Nowo\RbacKitBundle\Security\ConfigurableRbacAdminAccessChecker;
use Nowo\RbacKitBundle\Security\PermissionChecker;
use Nowo\RbacKitBundle\Security\PermissionCheckerInterface;
use Nowo\RbacKitBundle\Security\PermissionGateInterface;
use Nowo\RbacKitBundle\Security\PermissionVoter;
use Nowo\RbacKitBundle\Security\RbacAdminAccessCheckerInterface;
use Nowo\RbacKitBundle\Twig\RbacTwigExtension;
use Nowo\RbacKitBundle\Ui\ActionForms;
use Nowo\RbacKitBundle\Ui\RbacPageFactory;
use Nowo\RbacKitBundle\Ui\RbacPages;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\KernelEvents;

use function array_key_exists;
use function array_key_first;
use function interface_exists;
use function is_array;
use function is_bool;
use function is_string;
use function sprintf;

/**
 * Loads `nowo_rbac_kit`: the catalogue (configuration + tagged providers), the permission set registry and storage,
 * the grant resolver, the memoised voter, the checker / Twig functions, the coverage command, the optional request
 * guard and DashboardMenu checker and, when enabled, the admin UI with its access checker, route loader and FormKit
 * profile. Every memo service is tagged `kernel.reset` (worker mode).
 */
final class NowoRbacKitExtension extends Extension implements PrependExtensionInterface
{
    /** Container parameter holding `doctrine.entity_manager` (storage.type: doctrine; null: the default manager). */
    public const ENTITY_MANAGER_PARAMETER = 'nowo_rbac_kit.doctrine.entity_manager';

    /**
     * `nowo_rbac_kit`.
     */
    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }

    /**
     * Prepends the Doctrine mapping of the bundle entity (`storage.type: doctrine`) and the FormKit profile.
     */
    public function prepend(ContainerBuilder $container): void
    {
        if ($this->rawStorageType($container) === 'doctrine' && $container->hasExtension('doctrine')) {
            $mappings = [
                'mappings' => [
                    'NowoRbacKitBundle' => [
                        'type'      => 'attribute',
                        'dir'       => __DIR__ . '/../Entity',
                        'prefix'    => 'Nowo\RbacKitBundle\Entity',
                        'alias'     => 'NowoRbacKit',
                        'is_bundle' => false,
                    ],
                ],
            ];
            // 1.1: on doctrine.entity_manager when the host declares it (pinning the host's own default so the
            // prepended entry never becomes the default manager); RbacEntityManagerPass keeps it there only and
            // fails on an undeclared manager.
            $entityManager = $this->rawEntityManager($container);
            if ($entityManager === null) {
                $container->prependExtensionConfig('doctrine', ['orm' => $mappings]);
            } elseif (($hostDefault = $this->hostDefaultEntityManager($container, $entityManager)) !== null) {
                $container->prependExtensionConfig('doctrine', ['orm' => [
                    'default_entity_manager' => $hostDefault,
                    'entity_managers'        => [$entityManager => $mappings],
                ]]);
            }
        }

        $this->prependFormKitProfile($container);
    }

    /**
     * Registers the services from the processed configuration.
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);
        $this->assertLiteralBooleans($config);

        /** @var array{catalogue: array{translation_domain: string, max_permissions: int, permissions: array<string, array{scope: string, routes: list<string>, group: string|null, label: string|null, description: string|null, aggregate: bool}>, aliases: array<string, string>, open_routes: list<string>, unmapped_routes: string}, permission_sets: array<string, array{label: string|null, description: string|null, permissions: list<string>, all: bool, include_global: bool, except: list<string>, locked: bool}>, role_grants: array<string, list<string>>, voter: array{bypass_roles: list<string>, fallback_permission_set: string|null, max_assignments: int}, grant_provider: string|null, context_resolver: string|null, storage: array{type: string, service: string|null, cache_pool: string|null, cache_ttl: int, max_sets: int, usage_provider: string|null}, doctrine: array{table_prefix: string}, request_guard: array{enabled: bool, context_resolver: string|null, skip_strangers: bool}, coverage: array{prefixes: list<string>, ignore_routes: list<string>}, web_ui: array{enabled: bool, path_prefix: string, layout_template: string|null, templates: array<string, string>}, security: array{access_roles: list<string>, manage_roles: list<string>, access_checker: string|null, allow_unauthenticated: bool}} $config */
        $webUi    = $config['web_ui']['enabled'];
        $security = $this->hasSecurityBundle($container);
        if ($webUi && !$config['security']['allow_unauthenticated'] && !$security) {
            throw new LogicException('NowoRbacKitBundle: the admin UI requires symfony/security-bundle (or security.allow_unauthenticated: true in dev / demo only).');
        }
        if ($config['request_guard']['enabled'] && !$security) {
            throw new LogicException('NowoRbacKitBundle: request_guard.enabled requires symfony/security-bundle.');
        }

        $container->setParameter('nowo_rbac_kit.web_ui.enabled', $webUi);
        $container->setParameter('nowo_rbac_kit.web_ui.path_prefix', $config['web_ui']['path_prefix']);
        $container->setParameter('nowo_rbac_kit.security.allow_unauthenticated', $config['security']['allow_unauthenticated']);

        $container->registerForAutoconfiguration(PermissionProviderInterface::class)->addTag(NowoRbacKitBundle::PERMISSION_PROVIDER_TAG);
        $container->registerForAutoconfiguration(PermissionGateInterface::class)->addTag(NowoRbacKitBundle::PERMISSION_GATE_TAG);
        $container->registerForAutoconfiguration(BypassVetoInterface::class)->addTag(NowoRbacKitBundle::BYPASS_VETO_TAG);

        $this->registerCatalogue($container, $config['catalogue']);
        $this->registerSets($container, $config);
        $this->registerVoter($container, $config);
        $this->registerAccess($container, $config['security']);
        $this->registerWebUi($container, $config);
    }

    /**
     * Default FormKit profile of the bundle POST forms: bundle translation domain, no auto help / placeholder keys, no
     * framework classes (the UiKit scaffold theme paints the fields through its slots, REQ-UI-003).
     *
     * @return array<string, mixed>
     */
    public static function formKitProfile(): array
    {
        return [
            'alias'              => NowoRbacKitBundle::FORM_KIT_PROFILE,
            'translation_domain' => NowoRbacKitBundle::TRANSLATION_DOMAIN,
            'auto_help'          => false,
            'auto_placeholder'   => false,
            'defaults'           => ['attr' => [], 'row_attr' => []],
            'field_types'        => [],
        ];
    }

    /**
     * Fails the configuration when an optional integration is enabled without its package.
     *
     * @param string $interface interface the integration needs
     * @param string $option configuration path that enabled it
     * @param string $package Composer package(s) providing it
     */
    public static function requirePackage(string $interface, string $option, string $package): void
    {
        if (!interface_exists($interface)) {
            throw new InvalidConfigurationException(sprintf('nowo_rbac_kit.%s needs %s (composer require %s).', $option, $package, $package));
        }
    }

    /**
     * @param array{translation_domain: string, max_permissions: int, permissions: array<string, mixed>, aliases: array<string, string>, open_routes: list<string>, unmapped_routes: string} $catalogue
     */
    private function registerCatalogue(ContainerBuilder $container, array $catalogue): void
    {
        $container->setDefinition(ConfigPermissionProvider::class, (new Definition(ConfigPermissionProvider::class, [$catalogue['permissions']]))
            ->addTag(NowoRbacKitBundle::PERMISSION_PROVIDER_TAG));
        $container->setDefinition(PermissionCatalog::class, (new Definition(PermissionCatalog::class, [
            new TaggedIteratorArgument(NowoRbacKitBundle::PERMISSION_PROVIDER_TAG),
            $catalogue['aliases'],
            $catalogue['max_permissions'],
        ]))->setPublic(true)->addTag('kernel.reset', ['method' => 'reset']));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerSets(ContainerBuilder $container, array $config): void
    {
        /** @var array{permission_sets: array<string, mixed>, voter: array{max_assignments: int}, grant_provider: string|null, storage: array{type: string, service: string|null, cache_pool: string|null, cache_ttl: int, max_sets: int, usage_provider: string|null}, doctrine: array{table_prefix: string, entity_manager: string|null}} $config */
        $storage = $config['storage'];
        if ($storage['type'] === 'doctrine') {
            self::requirePackage(EntityManagerInterface::class, 'storage.type: doctrine', 'doctrine/doctrine-bundle doctrine/orm');
            $container->setDefinition(DoctrinePermissionSetStorage::class, new Definition(DoctrinePermissionSetStorage::class, [
                new Reference('doctrine'),
                new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            ]));
            $container->setDefinition(TablePrefixListener::class, (new Definition(TablePrefixListener::class, [$config['doctrine']['table_prefix']]))
                ->addTag('doctrine.event_listener', ['event' => 'loadClassMetadata']));
            $container->setAlias(PermissionSetStorageInterface::class, DoctrinePermissionSetStorage::class)->setPublic(true);
            $container->setParameter(self::ENTITY_MANAGER_PARAMETER, $config['doctrine']['entity_manager']);
        } elseif ($storage['type'] === 'service') {
            $container->setAlias(PermissionSetStorageInterface::class, (string) $storage['service'])->setPublic(true);
        } else {
            $container->setDefinition(ConfigPermissionSetStorage::class, new Definition(ConfigPermissionSetStorage::class));
            $container->setAlias(PermissionSetStorageInterface::class, ConfigPermissionSetStorage::class)->setPublic(true);
        }

        $logger = new Reference('logger', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $container->setDefinition(PermissionSetRegistry::class, (new Definition(PermissionSetRegistry::class, [
            new Reference(PermissionCatalog::class),
            new Reference(PermissionSetStorageInterface::class),
            $config['permission_sets'],
            is_string($storage['cache_pool']) && $storage['cache_pool'] !== '' ? new Reference($storage['cache_pool']) : null,
            $storage['max_sets'],
            $logger,
            $storage['cache_ttl'],
        ]))->setPublic(true)->addTag('kernel.reset', ['method' => 'reset']));

        $container->setDefinition(PermissionSetManager::class, (new Definition(PermissionSetManager::class, [
            new Reference(PermissionSetRegistry::class),
            new Reference(PermissionCatalog::class),
            new Reference(PermissionSetStorageInterface::class),
            $storage['max_sets'],
            new Reference('event_dispatcher', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $this->optional($storage['usage_provider']),
            new Reference('clock', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->setPublic(true));
        $container->setAlias(PermissionSetManagerInterface::class, PermissionSetManager::class)->setPublic(true);

        if (is_string($config['grant_provider']) && $config['grant_provider'] !== '') {
            $container->setAlias(GrantProviderInterface::class, $config['grant_provider'])->setPublic(true);
        } else {
            $container->setDefinition(NullGrantProvider::class, new Definition(NullGrantProvider::class));
            $container->setAlias(GrantProviderInterface::class, NullGrantProvider::class)->setPublic(true);
        }
        $container->setDefinition(GrantResolver::class, (new Definition(GrantResolver::class, [
            new Reference(GrantProviderInterface::class),
            new Reference(PermissionSetRegistry::class),
            $config['voter']['max_assignments'],
            64,
            $logger,
        ]))->setPublic(true)->addTag('kernel.reset', ['method' => 'reset']));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerVoter(ContainerBuilder $container, array $config): void
    {
        /** @var array{catalogue: array{open_routes: list<string>, unmapped_routes: string}, permission_sets: array<string, array{permissions: list<string>, all: bool, except: list<string>}>, role_grants: array<string, list<string>>, voter: array{bypass_roles: list<string>, fallback_permission_set: string|null}, context_resolver: string|null, request_guard: array{enabled: bool, context_resolver: string|null, skip_strangers: bool}, coverage: array{prefixes: list<string>, ignore_routes: list<string>}} $config */
        if (is_string($config['context_resolver']) && $config['context_resolver'] !== '') {
            $container->setAlias(ContextResolverInterface::class, $config['context_resolver'])->setPublic(true);
        } else {
            $container->setDefinition(DefaultContextResolver::class, new Definition(DefaultContextResolver::class));
            $container->setAlias(ContextResolverInterface::class, DefaultContextResolver::class)->setPublic(true);
        }

        $container->setDefinition(PermissionVoter::class, (new Definition(PermissionVoter::class, [
            new Reference(PermissionCatalog::class),
            new Reference(GrantResolver::class),
            new Reference(PermissionSetRegistry::class),
            new Reference(ContextResolverInterface::class),
            new Reference('security.role_hierarchy', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $config['voter']['bypass_roles'],
            $config['role_grants'],
            $config['catalogue']['open_routes'],
            $config['catalogue']['unmapped_routes'] === 'allow',
            $config['voter']['fallback_permission_set'],
            new TaggedIteratorArgument(NowoRbacKitBundle::PERMISSION_GATE_TAG),
            new TaggedIteratorArgument(NowoRbacKitBundle::BYPASS_VETO_TAG),
        ]))->setPublic(true)->addTag('security.voter')->addTag('kernel.reset', ['method' => 'reset']));

        $authorization = new Reference('security.authorization_checker', ContainerInterface::NULL_ON_INVALID_REFERENCE);
        $container->setDefinition(PermissionChecker::class, (new Definition(PermissionChecker::class, [new Reference(PermissionCatalog::class), $authorization]))->setPublic(true));
        $container->setAlias(PermissionCheckerInterface::class, PermissionChecker::class)->setPublic(true);
        $container->setDefinition(RbacTwigExtension::class, (new Definition(RbacTwigExtension::class, [new Reference(PermissionChecker::class)]))->addTag('twig.extension'));

        $container->setDefinition(PermissionCoverage::class, (new Definition(PermissionCoverage::class, [
            new Reference(PermissionCatalog::class),
            new Reference('router'),
            $config['permission_sets'],
            $config['catalogue']['open_routes'],
        ]))->setPublic(true));
        $container->setDefinition(CheckCommand::class, (new Definition(CheckCommand::class, [
            new Reference(PermissionCoverage::class),
            $config['coverage']['prefixes'],
            $config['coverage']['ignore_routes'],
        ]))->addTag('console.command', ['command' => CheckCommand::NAME]));

        if (interface_exists(MenuPermissionCheckerInterface::class)) {
            $container->setDefinition(RbacMenuPermissionChecker::class, (new Definition(RbacMenuPermissionChecker::class, [new Reference(PermissionChecker::class), $authorization]))->setPublic(true));
        }

        $guard = $config['request_guard'];
        if ($guard['enabled']) {
            $requestContexts = $this->optional($guard['context_resolver']);
            if (!$requestContexts instanceof Reference) {
                $container->setDefinition(NullRequestContextResolver::class, new Definition(NullRequestContextResolver::class));
                $requestContexts = new Reference(NullRequestContextResolver::class);
            }
            $container->setDefinition(PermissionRequestListener::class, (new Definition(PermissionRequestListener::class, [
                new Reference(PermissionCatalog::class),
                new Reference('security.authorization_checker'),
                new Reference('security.token_storage'),
                $requestContexts,
                new Reference(ContextResolverInterface::class),
                new Reference(GrantResolver::class),
                $guard['skip_strangers'],
            ]))->addTag('kernel.event_listener', ['event' => KernelEvents::CONTROLLER]));
        }
    }

    /**
     * @param array{access_roles: list<string>, manage_roles: list<string>, access_checker: string|null, allow_unauthenticated: bool} $security
     */
    private function registerAccess(ContainerBuilder $container, array $security): void
    {
        $customId = $security['access_checker'];
        if (is_string($customId) && $customId !== '') {
            $container->setAlias(RbacAdminAccessCheckerInterface::class, $customId)->setPublic(true);
        } elseif ($security['allow_unauthenticated']) {
            $container->setDefinition(AllowAllRbacAdminAccessChecker::class, new Definition(AllowAllRbacAdminAccessChecker::class));
            $container->setAlias(RbacAdminAccessCheckerInterface::class, AllowAllRbacAdminAccessChecker::class)->setPublic(true);
        } else {
            $container->setDefinition(ConfigurableRbacAdminAccessChecker::class, new Definition(ConfigurableRbacAdminAccessChecker::class, [
                new Reference('security.authorization_checker', ContainerInterface::NULL_ON_INVALID_REFERENCE),
                Configuration::strings($security['access_roles']),
                Configuration::strings($security['manage_roles']),
            ]));
            $container->setAlias(RbacAdminAccessCheckerInterface::class, ConfigurableRbacAdminAccessChecker::class)->setPublic(true);
        }

        $container->setDefinition(AccessGuard::class, (new Definition(AccessGuard::class, [
            new Reference(RbacAdminAccessCheckerInterface::class),
            $security['allow_unauthenticated'],
            new Reference('security.token_storage', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('request_stack', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            new Reference('security.firewall.map', ContainerInterface::NULL_ON_INVALID_REFERENCE),
        ]))->setPublic(true));
    }

    /**
     * @param array<string, mixed> $config
     */
    private function registerWebUi(ContainerBuilder $container, array $config): void
    {
        /** @var array{catalogue: array{translation_domain: string}, storage: array{usage_provider: string|null}, web_ui: array{enabled: bool, path_prefix: string, layout_template: string|null, templates: array<string, string>}} $config */
        $webUi = $config['web_ui'];
        $container->setDefinition(RbacRouteLoader::class, (new Definition(RbacRouteLoader::class, [$webUi['enabled'], $webUi['path_prefix']]))
            ->addTag('routing.loader'));
        if (!$webUi['enabled']) {
            return;
        }

        $domain = $config['catalogue']['translation_domain'];
        $container->setDefinition(PermissionSetType::class, (new Definition(PermissionSetType::class))->addTag('form.type'));
        $container->setDefinition(ActionForms::class, new Definition(ActionForms::class, [new Reference('form.factory'), new Reference('router')]));
        $container->setDefinition(RbacPages::class, new Definition(RbacPages::class, [$webUi['layout_template'], $webUi['templates']]));
        $container->setDefinition(RbacPageFactory::class, (new Definition(RbacPageFactory::class, [
            new Reference('router'),
            new Reference(ActionForms::class),
            new Reference(RbacPages::class),
            $domain,
        ]))->setPublic(true));

        $container->setDefinition(RbacAdminAccessListener::class, (new Definition(RbacAdminAccessListener::class, [
            new Reference(AccessGuard::class),
            $webUi['path_prefix'],
        ]))->addTag('kernel.event_listener', ['event' => KernelEvents::REQUEST, 'method' => 'onKernelRequest', 'priority' => RbacAdminAccessListener::PRIORITY]));

        // REQ-SF-004 rule 7: not autoconfigured (no `routing.controller` tag from #[Route]); the routes come only from
        // the `nowo_rbac_kit` loader. Autowired only for AbstractController::setContainer().
        $container->setDefinition(PermissionSetController::class, (new Definition(PermissionSetController::class, [
            new Reference(AccessGuard::class),
            new Reference(PermissionSetRegistry::class),
            new Reference(PermissionSetManager::class),
            new Reference(PermissionCatalog::class),
            new Reference(RbacPageFactory::class),
            new Reference(ActionForms::class),
            new Reference('form.factory'),
            $this->optional($config['storage']['usage_provider']),
            new Reference('translator', ContainerInterface::NULL_ON_INVALID_REFERENCE),
            $domain,
        ]))
            ->setAutowired(true)
            ->setAutoconfigured(false)
            ->setPublic(true)
            ->addTag('controller.service_arguments')
            ->addTag('container.service_subscriber'));
    }

    /**
     * A reference to an optional host service id, or null.
     */
    private function optional(?string $id): ?Reference
    {
        return is_string($id) && $id !== '' ? new Reference($id) : null;
    }

    /**
     * Registers the FormKit profile {@see NowoRbacKitBundle::FORM_KIT_PROFILE} unless the host defines it.
     */
    private function prependFormKitProfile(ContainerBuilder $container): void
    {
        if (!$container->hasExtension('nowo_form_kit')) {
            return;
        }

        foreach ($container->getExtensionConfig('nowo_form_kit') as $config) {
            $profiles = $config['profiles'] ?? null;
            if (is_array($profiles) && array_key_exists(NowoRbacKitBundle::FORM_KIT_PROFILE, $profiles)) {
                return;
            }
        }

        $container->prependExtensionConfig('nowo_form_kit', [
            'profiles' => [NowoRbacKitBundle::FORM_KIT_PROFILE => self::formKitProfile()],
        ]);
    }

    /**
     * `storage.type` from the raw configuration (default `config`).
     */
    /**
     * `doctrine.entity_manager` from the raw configs (prepend runs before the configuration is processed).
     */
    private function rawEntityManager(ContainerBuilder $container): ?string
    {
        $name = null;
        foreach ($container->getExtensionConfig(Configuration::ALIAS) as $config) {
            if (isset($config['doctrine']) && is_array($config['doctrine']) && array_key_exists('entity_manager', $config['doctrine'])) {
                $value = $config['doctrine']['entity_manager'];
                $name  = is_string($value) && $value !== '' ? $value : null;
            }
        }

        return $name;
    }

    /**
     * The host's default entity manager (its `default_entity_manager`, else the first declared one) when the host
     * declares `$name` under `doctrine.orm.entity_managers`; null otherwise.
     */
    private function hostDefaultEntityManager(ContainerBuilder $container, string $name): ?string
    {
        $declared = false;
        $default  = null;
        $first    = null;
        foreach ($container->getExtensionConfig('doctrine') as $config) {
            $orm = is_array($config['orm'] ?? null) ? $config['orm'] : [];
            if (is_string($orm['default_entity_manager'] ?? null)) {
                $default = $orm['default_entity_manager'];
            }
            $managers = $orm['entity_managers'] ?? null;
            if (is_array($managers) && $managers !== []) {
                $first ??= (string) array_key_first($managers);
                $declared = $declared || isset($managers[$name]);
            }
        }

        return $declared ? ($default ?? $first) : null;
    }

    private function rawStorageType(ContainerBuilder $container): string
    {
        $type = 'config';
        foreach ($container->getExtensionConfig(Configuration::ALIAS) as $config) {
            $value = $config['storage']['type'] ?? null;
            if (is_string($value)) {
                $type = $value;
            }
        }

        return $type;
    }

    /**
     * Whether SecurityBundle is registered.
     */
    private function hasSecurityBundle(ContainerBuilder $container): bool
    {
        $bundles = $container->hasParameter('kernel.bundles') ? $container->getParameter('kernel.bundles') : [];

        return (is_array($bundles) && array_key_exists('SecurityBundle', $bundles)) || $container->hasExtension('security');
    }

    /**
     * Security switches must be literal booleans: an `%env(bool:…)%` placeholder is a non-empty string while the
     * container is built and would read as true (fail open).
     *
     * @param array<string, mixed> $config
     */
    private function assertLiteralBooleans(array $config): void
    {
        /** @var array<string, array<string, mixed>> $config */
        $switches = [
            'web_ui.enabled'                 => $config['web_ui']['enabled'] ?? null,
            'security.allow_unauthenticated' => $config['security']['allow_unauthenticated'] ?? null,
            'request_guard.enabled'          => $config['request_guard']['enabled'] ?? null,
            'request_guard.skip_strangers'   => $config['request_guard']['skip_strangers'] ?? null,
        ];
        foreach ($switches as $path => $value) {
            if (!is_bool($value)) {
                throw new InvalidConfigurationException(sprintf('nowo_rbac_kit.%s must be a literal boolean (true or false); environment placeholders such as %%env(bool:...)%% are not supported for security switches.', $path));
            }
        }
    }
}

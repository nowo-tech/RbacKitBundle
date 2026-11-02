<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\DependencyInjection;

use LogicException;
use Nowo\RbacKitBundle\Context\ContextResolverInterface;
use Nowo\RbacKitBundle\Context\DefaultContextResolver;
use Nowo\RbacKitBundle\Context\NullRequestContextResolver;
use Nowo\RbacKitBundle\Controller\PermissionSetController;
use Nowo\RbacKitBundle\DependencyInjection\NowoRbacKitExtension;
use Nowo\RbacKitBundle\Doctrine\TablePrefixListener;
use Nowo\RbacKitBundle\EventListener\PermissionRequestListener;
use Nowo\RbacKitBundle\Grant\GrantProviderInterface;
use Nowo\RbacKitBundle\Grant\NullGrantProvider;
use Nowo\RbacKitBundle\Menu\RbacMenuPermissionChecker;
use Nowo\RbacKitBundle\NowoRbacKitBundle;
use Nowo\RbacKitBundle\PermissionSet\ConfigPermissionSetStorage;
use Nowo\RbacKitBundle\PermissionSet\DoctrinePermissionSetStorage;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetRegistry;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetStorageInterface;
use Nowo\RbacKitBundle\Security\AllowAllRbacAdminAccessChecker;
use Nowo\RbacKitBundle\Security\ConfigurableRbacAdminAccessChecker;
use Nowo\RbacKitBundle\Security\PermissionVoter;
use Nowo\RbacKitBundle\Security\RbacAdminAccessCheckerInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

final class NowoRbacKitExtensionTest extends TestCase
{
    #[Test]
    public function itLoadsTheSafeDefaults(): void
    {
        $container = $this->load([]);

        self::assertSame('nowo_rbac_kit', (new NowoRbacKitExtension())->getAlias());
        self::assertSame(ConfigPermissionSetStorage::class, (string) $container->getAlias(PermissionSetStorageInterface::class));
        self::assertSame(NullGrantProvider::class, (string) $container->getAlias(GrantProviderInterface::class));
        self::assertSame(DefaultContextResolver::class, (string) $container->getAlias(ContextResolverInterface::class));
        self::assertSame(ConfigurableRbacAdminAccessChecker::class, (string) $container->getAlias(RbacAdminAccessCheckerInterface::class));
        self::assertFalse($container->hasDefinition(PermissionSetController::class), 'no admin UI by default');
        self::assertFalse($container->hasDefinition(PermissionRequestListener::class), 'no request guard by default');
        self::assertTrue($container->hasDefinition(RbacMenuPermissionChecker::class), 'DashboardMenu is installed (dev)');

        $voter = $container->getDefinition(PermissionVoter::class);
        self::assertTrue($voter->hasTag('security.voter'));
        self::assertTrue($voter->hasTag('kernel.reset'));
        self::assertSame([], $voter->getArgument(5), 'no bypass role');
        self::assertFalse($voter->getArgument(8), 'unmapped routes are denied');
        self::assertTrue($container->getDefinition(PermissionSetRegistry::class)->hasTag('kernel.reset'));
        self::assertEquals(new Reference('cache.app'), $container->getDefinition(PermissionSetRegistry::class)->getArgument(3));
        self::assertSame(300, $container->getDefinition(PermissionSetRegistry::class)->getArgument(6), 'storage.cache_ttl');
    }

    #[Test]
    public function itWiresTheUiDoctrineAndTheRequestGuard(): void
    {
        $container = $this->load([
            'grant_provider'   => 'app.grants',
            'context_resolver' => 'app.contexts',
            'storage'          => ['type' => 'doctrine', 'cache_pool' => null, 'usage_provider' => 'app.usage'],
            'doctrine'         => ['table_prefix' => 'app_'],
            'request_guard'    => ['enabled' => true, 'context_resolver' => 'app.request_context'],
            'web_ui'           => ['enabled' => true],
            'security'         => ['access_checker' => 'app.checker'],
        ]);

        self::assertSame(DoctrinePermissionSetStorage::class, (string) $container->getAlias(PermissionSetStorageInterface::class));
        self::assertSame('app_', $container->getDefinition(TablePrefixListener::class)->getArgument(0));
        self::assertNull($container->getDefinition(PermissionSetRegistry::class)->getArgument(3));
        self::assertSame('app.grants', (string) $container->getAlias(GrantProviderInterface::class));
        self::assertSame('app.contexts', (string) $container->getAlias(ContextResolverInterface::class));
        self::assertSame('app.checker', (string) $container->getAlias(RbacAdminAccessCheckerInterface::class));
        self::assertEquals(new Reference('app.request_context'), $container->getDefinition(PermissionRequestListener::class)->getArgument(3));

        $controller = $container->getDefinition(PermissionSetController::class);
        self::assertFalse($controller->isAutoconfigured(), 'REQ-SF-004 rule 7');
        self::assertTrue($controller->hasTag('controller.service_arguments'));
        self::assertEquals(new Reference('app.usage'), $controller->getArgument(7));

        $guard = $this->load(['request_guard' => ['enabled' => true]]);
        self::assertTrue($guard->hasDefinition(NullRequestContextResolver::class));
        $service = $this->load(['storage' => ['type' => 'service', 'service' => 'app.storage']]);
        self::assertSame('app.storage', (string) $service->getAlias(PermissionSetStorageInterface::class));
        $open = $this->load(['web_ui' => ['enabled' => true], 'security' => ['allow_unauthenticated' => true]]);
        self::assertSame(AllowAllRbacAdminAccessChecker::class, (string) $open->getAlias(RbacAdminAccessCheckerInterface::class));
    }

    #[Test]
    public function itPrependsTheDoctrineMappingAndTheFormKitProfile(): void
    {
        $container = new ContainerBuilder();
        foreach (['doctrine', 'nowo_form_kit'] as $alias) {
            $container->registerExtension(new class($alias) extends Extension {
                public function __construct(private readonly string $alias)
                {
                }

                public function load(array $configs, ContainerBuilder $container): void
                {
                }

                public function getAlias(): string
                {
                    return $this->alias;
                }
            });
        }
        $container->prependExtensionConfig('nowo_rbac_kit', ['storage' => ['type' => 'doctrine']]);
        (new NowoRbacKitExtension())->prepend($container);

        self::assertArrayHasKey('NowoRbacKitBundle', $container->getExtensionConfig('doctrine')[0]['orm']['mappings']);
        self::assertSame(NowoRbacKitExtension::formKitProfile(), $container->getExtensionConfig('nowo_form_kit')[0]['profiles'][NowoRbacKitBundle::FORM_KIT_PROFILE]);

        $hostProfile = new ContainerBuilder();
        $hostProfile->registerExtension($container->getExtension('nowo_form_kit'));
        $hostProfile->prependExtensionConfig('nowo_form_kit', ['profiles' => [NowoRbacKitBundle::FORM_KIT_PROFILE => ['translation_domain' => 'admin']]]);
        (new NowoRbacKitExtension())->prepend($hostProfile);
        self::assertCount(1, $hostProfile->getExtensionConfig('nowo_form_kit'), 'the host profile wins');

        $bare = new ContainerBuilder();
        (new NowoRbacKitExtension())->prepend($bare);
        self::assertSame([], $bare->getExtensionConfig('doctrine'));
    }

    #[Test]
    public function optionalIntegrationsNeedTheirPackage(): void
    {
        NowoRbacKitExtension::requirePackage(ContextResolverInterface::class, 'context_resolver', 'nowo-tech/rbac-kit-bundle');
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_rbac_kit.storage.type: doctrine needs doctrine/orm (composer require doctrine/orm).');
        NowoRbacKitExtension::requirePackage('Missing\\EntityManagerInterface', 'storage.type: doctrine', 'doctrine/orm');
    }

    #[Test]
    public function theUiNeedsSecurity(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the admin UI requires symfony/security-bundle');
        $this->load(['web_ui' => ['enabled' => true]], false);
    }

    #[Test]
    public function theRequestGuardNeedsSecurity(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('request_guard.enabled requires');
        $this->load(['request_guard' => ['enabled' => true]], false);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function load(array $config, bool $security = true): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', $security ? ['SecurityBundle' => 'x'] : []);
        (new NowoRbacKitExtension())->load([$config], $container);

        return $container;
    }
}

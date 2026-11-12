<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\RbacKitBundle\DependencyInjection\Compiler\RbacPortsPass;
use Nowo\RbacKitBundle\EventListener\PermissionRequestListener;
use Nowo\RbacKitBundle\NowoRbacKitBundle;
use Nowo\RbacKitBundle\Tests\Fixtures\Ports\DualPreviewVeto;
use Nowo\RbacKitBundle\Tests\Fixtures\Ports\DualRouteContextResolver;
use Nowo\RbacKitBundle\Tests\Fixtures\Ports\LegacyPreviewVeto;
use Nowo\RbacKitBundle\Tests\Fixtures\Ports\LegacyRouteContextResolver;
use Nowo\RbacKitBundle\Tests\Fixtures\Ports\NotAPort;
use Nowo\RbacKitBundle\Tests\Fixtures\Ports\PreviewVeto;
use Nowo\RbacKitBundle\Tests\Fixtures\RouteContextResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class RbacPortsPassTest extends TestCase
{
    /**
     * @return iterable<string, array{class-string}>
     */
    public static function portServices(): iterable
    {
        yield 'resolver on the new port' => [RouteContextResolver::class];
        yield 'resolver on both ports' => [DualRouteContextResolver::class];
    }

    /**
     * @param class-string $class
     */
    #[Test]
    #[DataProvider('portServices')]
    public function aResolverOnTheNewPortIsAccepted(string $class): void
    {
        $container = $this->container($class, null, named: $class === DualRouteContextResolver::class);
        (new RbacPortsPass())->process($container);
        self::assertTrue($container->hasDefinition('app.resolver'));
    }

    #[Test]
    public function aResolverOnTheDeprecatedPortOnlyIsDeprecated(): void
    {
        $this->expectUserDeprecationMessageMatches('/Since nowo-tech\/rbac-kit-bundle 1\.2: The request_guard\.context_resolver service "app\.resolver" .*LegacyRouteContextResolver.* implements only .*RequestContextResolverInterface"; implement .*RbacRequestContextResolverInterface" instead/');
        (new RbacPortsPass())->process($this->container(LegacyRouteContextResolver::class, null, alias: true));
    }

    #[Test]
    public function aResolverOnNoPortFailsTheBuild(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the request_guard.context_resolver service "app.resolver" (' . NotAPort::class . ') must implement');
        (new RbacPortsPass())->process($this->container(NotAPort::class, null));
    }

    #[Test]
    public function vetoesOnTheNewOrBothPortsAreAccepted(): void
    {
        $container = $this->container(null, PreviewVeto::class);
        $container->setDefinition('app.veto2', (new Definition(DualPreviewVeto::class))->addTag(NowoRbacKitBundle::BYPASS_VETO_TAG));
        (new RbacPortsPass())->process($container);
        self::assertTrue($container->hasDefinition('app.veto'));
    }

    #[Test]
    public function aVetoOnTheDeprecatedPortOnlyIsDeprecated(): void
    {
        $this->expectUserDeprecationMessageMatches('/The nowo_rbac_kit\.bypass_veto service "app\.veto" .*LegacyPreviewVeto.* implements only .*BypassVetoInterface"; implement .*RbacBypassVetoInterface" instead/');
        (new RbacPortsPass())->process($this->container(null, LegacyPreviewVeto::class));
    }

    #[Test]
    public function aVetoOnNoPortFailsTheBuild(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('the nowo_rbac_kit.bypass_veto service "app.veto" (' . NotAPort::class . ') must implement');
        (new RbacPortsPass())->process($this->container(null, NotAPort::class));
    }

    /**
     * Factories, synthetic services, unknown classes and missing definitions are left to the runtime checks.
     */
    #[Test]
    public function servicesWhoseClassIsUnknownAreLeftToRuntime(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(PermissionRequestListener::class, new Definition(PermissionRequestListener::class, [null, null, null, new Reference('app.missing')]));
        $container->setDefinition('app.factory', (new Definition(stdClass::class))->setFactory('make')->addTag(NowoRbacKitBundle::BYPASS_VETO_TAG));
        $container->setDefinition('app.synthetic', (new Definition(stdClass::class))->setSynthetic(true)->addTag(NowoRbacKitBundle::BYPASS_VETO_TAG));
        $container->setDefinition('App\\Unknown\\Veto', (new Definition())->addTag(NowoRbacKitBundle::BYPASS_VETO_TAG));
        (new RbacPortsPass())->process($container);

        $empty = new ContainerBuilder();
        $empty->setDefinition(PermissionRequestListener::class, new Definition(PermissionRequestListener::class, ['$requestContexts' => 'not a reference']));
        (new RbacPortsPass())->process($empty);
        (new RbacPortsPass())->process(new ContainerBuilder());
        self::assertTrue($container->hasDefinition('app.factory'));
    }

    /**
     * @param class-string|null $resolver
     * @param class-string|null $veto
     */
    private function container(?string $resolver, ?string $veto, bool $alias = false, bool $named = false): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('resolver.class', $resolver);
        if ($resolver !== null) {
            $container->setDefinition('app.resolver', new Definition($named ? '%resolver.class%' : $resolver));
            $target = 'app.resolver';
            if ($alias) {
                $container->setAlias('app.resolver_alias', 'app.resolver');
                $target = 'app.resolver_alias';
            }
            $arguments = $named ? ['$requestContexts' => new Reference($target)] : [null, null, null, new Reference($target)];
            $container->setDefinition(PermissionRequestListener::class, new Definition(PermissionRequestListener::class, $arguments));
        }
        if ($veto !== null) {
            $container->setDefinition('app.veto', (new Definition($veto))->addTag(NowoRbacKitBundle::BYPASS_VETO_TAG));
        }

        return $container;
    }
}

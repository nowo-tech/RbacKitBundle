<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Integration\Kernel;

use Nowo\RbacKitBundle\EventListener\PermissionRequestListener;
use Nowo\RbacKitBundle\Security\PermissionVoter;
use Nowo\RbacKitBundle\Tests\Fixtures\Ports\DualPreviewVeto;
use Nowo\RbacKitBundle\Tests\Fixtures\Ports\DualRouteContextResolver;
use Nowo\RbacKitBundle\Tests\Fixtures\Ports\LegacyPreviewVeto;
use Nowo\RbacKitBundle\Tests\Fixtures\Ports\LegacyRouteContextResolver;
use Nowo\RbacKitBundle\Tests\Fixtures\Ports\LegacyToNewResolverDecorator;
use Nowo\RbacKitBundle\Tests\Fixtures\Ports\PreviewVeto;
use Nowo\RbacKitBundle\Tests\Fixtures\RouteContextResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use ReflectionProperty;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

use function iterator_to_array;

/**
 * 1.2: `lint:container` (Symfony's CheckAliasValidityPass included) passes with the request context resolver and the
 * bypass veto on the framework-free port only, on the deprecated port only, on both, and with a host decorator on the
 * new port only over a deprecated-port service. The guard and the voter then use the service the host chose (the
 * decorator when there is one) and decide the same way in every case.
 */
final class PortsLintContainerKernelTest extends KernelTestCase
{
    /**
     * @return iterable<string, array{class-string, class-string, class-string|null, class-string}>
     */
    public static function hostPorts(): iterable
    {
        yield 'new ports only' => [RouteContextResolver::class, PreviewVeto::class, null, RouteContextResolver::class];
        yield 'deprecated ports only' => [LegacyRouteContextResolver::class, LegacyPreviewVeto::class, null, LegacyRouteContextResolver::class];
        yield 'both ports' => [DualRouteContextResolver::class, DualPreviewVeto::class, null, DualRouteContextResolver::class];
        yield 'new-port decorator over a deprecated resolver' => [LegacyRouteContextResolver::class, PreviewVeto::class, LegacyToNewResolverDecorator::class, LegacyToNewResolverDecorator::class];
    }

    /**
     * @param class-string $resolver
     * @param class-string $veto
     * @param class-string|null $decorator
     * @param class-string $expectedResolver
     */
    #[Test]
    #[DataProvider('hostPorts')]
    public function lintContainerPassesAndTheHostServicesDecide(string $resolver, string $veto, ?string $decorator, string $expectedResolver): void
    {
        $config                  = self::defaultConfig();
        $config['voter']         = ['bypass_roles' => ['ROLE_ADMIN']];
        $config['request_guard'] = ['enabled' => true, 'context_resolver' => 'test.request_context'];
        $services                = ['test.request_context' => $resolver, 'test.veto' => $veto];
        if ($decorator !== null) {
            $services[$decorator] = $decorator;
        }
        $kernel = $this->bootKernel($config, $services, debug: true);

        $application = new Application($kernel);
        $application->setAutoExit(false);
        $tester = new CommandTester($application->find('lint:container'));
        self::assertSame(0, $tester->execute([]), $tester->getDisplay());
        self::assertStringContainsString('linted successfully', $tester->getDisplay());

        $listener = $this->container($kernel)->get(PermissionRequestListener::class);
        self::assertInstanceOf(PermissionRequestListener::class, $listener);
        self::assertInstanceOf($expectedResolver, (new ReflectionProperty($listener, 'requestContexts'))->getValue($listener));

        $voter = $this->container($kernel)->get(PermissionVoter::class);
        self::assertInstanceOf(PermissionVoter::class, $voter);
        $vetoes = (new ReflectionProperty($voter, 'vetoes'))->getValue($voter);
        self::assertIsIterable($vetoes);
        $vetoes = iterator_to_array($vetoes, false);
        self::assertCount(1, $vetoes, 'a service on both ports is tagged once');
        self::assertInstanceOf($veto, $vetoes[0]);

        $alice = $this->client($kernel, 'alice');
        $alice->request('GET', '/projects/billing');
        self::assertSame(200, $alice->getResponse()->getStatusCode());
        $alice->request('GET', '/projects/billing/settings');
        self::assertSame(403, $alice->getResponse()->getStatusCode());
        $admin = $this->client($kernel, 'admin');
        $admin->request('GET', '/projects/other/settings');
        self::assertSame(200, $admin->getResponse()->getStatusCode(), 'bypass');
        $admin->request('GET', '/projects/preview/settings');
        self::assertSame(403, $admin->getResponse()->getStatusCode(), 'vetoed bypass: own sets decide');
    }
}

<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\RbacKitBundle\Event\PermissionSetChangedEvent;
use Nowo\RbacKitBundle\Tests\Fixtures\Controller\HostController;
use Nowo\RbacKitBundle\Tests\Fixtures\EventRecorder;
use Nowo\RbacKitBundle\Tests\Fixtures\MapGrantProvider;
use Nowo\RbacKitBundle\Tests\Fixtures\StaticUsage;
use Nowo\RbacKitBundle\Tests\Support\Catalogues;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;

use function count;
use function in_array;
use function preg_replace;

/**
 * Boots a fresh {@see RbacTestKernel} per call (own cache dir and SQLite file, schema created).
 */
abstract class KernelTestCase extends TestCase
{
    /** @var list<RbacTestKernel> */
    private array $kernels = [];

    protected function tearDown(): void
    {
        foreach ($this->kernels as $kernel) {
            $kernel->shutdown();
        }
        $this->kernels = [];
        (new Filesystem())->remove(RbacTestKernel::baseDir());
    }

    /**
     * The catalogue and sets of the tests (tests/Support/Catalogues) with the admin UI enabled on Doctrine.
     *
     * @return array<string, mixed>
     */
    public static function defaultConfig(): array
    {
        return [
            'catalogue'       => ['permissions' => Catalogues::permissions(), 'aliases' => ['product.summary' => 'summary.view'], 'open_routes' => ['host_home']],
            'permission_sets' => Catalogues::sets(),
            'grant_provider'  => 'test.grant_provider',
            'storage'         => ['type' => 'doctrine', 'usage_provider' => 'test.usage'],
            'web_ui'          => ['enabled' => true],
            'security'        => ['access_roles' => ['ROLE_ADMIN', 'ROLE_RBAC_VIEWER'], 'manage_roles' => ['ROLE_ADMIN']],
        ];
    }

    /**
     * @param array<string, mixed>|null $config nowo_rbac_kit configuration (null = {@see self::defaultConfig()})
     * @param array<string, string> $services extra service id => class (autowired, autoconfigured, public)
     * @param list<string> $without bundles to leave out
     * @param Closure(RoutingConfigurator): void|null $routes routes (default: the bundle loader + the host fixture routes)
     * @param array<string, array<string, mixed>> $extensions extra extension configs
     */
    protected function bootKernel(?array $config = null, array $services = [], array $without = [], ?Closure $routes = null, bool $debug = false, array $extensions = []): RbacTestKernel
    {
        $id     = (string) preg_replace('/\W+/', '_', static::class . '_' . $this->name() . '_' . count($this->kernels));
        $kernel = new RbacTestKernel(
            $id,
            static function (ContainerConfigurator $container) use ($config, $services, $extensions): void {
                $container->extension('nowo_rbac_kit', $config ?? self::defaultConfig());
                foreach ($extensions as $name => $extension) {
                    $container->extension($name, $extension);
                }
                $defaults = $container->services()->defaults()->autowire()->autoconfigure();
                $defaults->set('test.grant_provider', MapGrantProvider::class)->public();
                $defaults->set('test.usage', StaticUsage::class)->public();
                $defaults->set(HostController::class)->public()->tag('controller.service_arguments');
                $defaults->set(EventRecorder::class)->public()->tag('kernel.event_listener', ['event' => PermissionSetChangedEvent::class, 'method' => 'onChanged']);
                foreach ($services as $serviceId => $class) {
                    $defaults->set($serviceId, $class)->public();
                }
            },
            $routes ?? static function (RoutingConfigurator $routes): void {
                $routes->import('.', 'nowo_rbac_kit');
                $routes->import(HostController::class, 'attribute');
            },
            $without,
            $debug,
        );
        $this->kernels[] = $kernel;
        $kernel->boot();

        if (!in_array('doctrine', $without, true)) {
            $doctrine = $kernel->getContainer()->get('doctrine');
            self::assertInstanceOf(ManagerRegistry::class, $doctrine);
            $entityManager = $doctrine->getManager();
            if ($entityManager instanceof EntityManagerInterface) {
                (new SchemaTool($entityManager))->createSchema($entityManager->getMetadataFactory()->getAllMetadata());
            }
        }

        return $kernel;
    }

    protected function container(RbacTestKernel $kernel): ContainerInterface
    {
        /** @var ContainerInterface $container */
        $container = $kernel->getContainer()->get('test.service_container');

        return $container;
    }

    /**
     * Browser that keeps the session (CSRF tokens) and the database between requests.
     */
    protected function client(RbacTestKernel $kernel, ?string $user = 'admin'): KernelBrowser
    {
        /** @var KernelBrowser $client */
        $client = $kernel->getContainer()->get('test.client');
        $client->disableReboot();
        $client->catchExceptions(true);
        $client->setServerParameters($user === null ? ['REMOTE_ADDR' => '203.0.113.9'] : ['REMOTE_ADDR' => '203.0.113.9', 'PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => $user]);

        return $client;
    }
}

<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Integration\Kernel;

use Closure;
use Doctrine\Bundle\DoctrineBundle\DoctrineBundle;
use Nowo\FormKitBundle\NowoFormKitBundle;
use Nowo\RbacKitBundle\NowoRbacKitBundle;
use Nowo\UiKitBundle\NowoUiKitBundle;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\SecurityBundle\SecurityBundle;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Loader\Configurator\RoutingConfigurator;
use Twig\Extra\TwigExtraBundle\TwigExtraBundle;

use function dirname;
use function in_array;
use function sys_get_temp_dir;

/**
 * Kernel of the integration tests: Framework, Security (http_basic, in-memory users), Twig + Twig Extra, Doctrine on a
 * SQLite file, FormKit, UiKit and the bundle. Configuration and routes are injected per test.
 */
final class RbacTestKernel extends Kernel
{
    use MicroKernelTrait;

    /**
     * @param Closure(ContainerConfigurator): void $services extension configs and services
     * @param Closure(RoutingConfigurator): void $routes routes
     * @param list<string> $without bundle short names to leave out (security, doctrine)
     */
    public function __construct(
        private readonly string $id,
        private readonly Closure $services,
        private readonly Closure $routes,
        private readonly array $without = [],
        bool $debug = false,
    ) {
        parent::__construct('test', $debug);
    }

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        if (!in_array('security', $this->without, true)) {
            yield new SecurityBundle();
        }
        yield new TwigBundle();
        yield new TwigExtraBundle();
        if (!in_array('doctrine', $this->without, true)) {
            yield new DoctrineBundle();
        }
        yield new NowoFormKitBundle();
        yield new NowoUiKitBundle();
        yield new NowoRbacKitBundle();
    }

    public function getProjectDir(): string
    {
        return dirname(__DIR__, 3);
    }

    public function getCacheDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/cache';
    }

    public function getLogDir(): string
    {
        return self::baseDir() . '/' . $this->id . '/log';
    }

    public static function baseDir(): string
    {
        return sys_get_temp_dir() . '/nowo-rbac-kit-tests';
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'test'                  => true,
            'secret'                => 'test-secret',
            'http_method_override'  => false,
            'handle_all_throwables' => true,
            'php_errors'            => ['log' => true],
            'csrf_protection'       => true,
            'form'                  => ['csrf_protection' => true],
            'validation'            => true,
            'session'               => ['storage_factory_id' => 'session.storage.factory.mock_file'],
            'translator'            => ['default_path' => '%kernel.project_dir%/tests/Fixtures/translations', 'fallbacks' => ['en']],
            'router'                => ['utf8' => true],
        ]);
        $container->extension('twig', ['strict_variables' => true, 'default_path' => '%kernel.project_dir%/tests/Fixtures/templates']);
        if (!in_array('doctrine', $this->without, true)) {
            $container->extension('doctrine', [
                'dbal' => ['url' => 'sqlite:///' . self::baseDir() . '/' . $this->id . '/db.sqlite'],
                'orm'  => [],
            ]);
        }
        if (!in_array('security', $this->without, true)) {
            $container->extension('security', [
                'password_hashers' => ['Symfony\Component\Security\Core\User\InMemoryUser' => 'plaintext'],
                'role_hierarchy'   => ['ROLE_ADMIN' => ['ROLE_MANAGER']],
                'providers'        => ['memory' => ['memory' => ['users' => [
                    'admin'  => ['password' => 'admin', 'roles' => ['ROLE_ADMIN']],
                    'viewer' => ['password' => 'viewer', 'roles' => ['ROLE_RBAC_VIEWER']],
                    'alice'  => ['password' => 'alice', 'roles' => ['ROLE_USER']],
                    'bob'    => ['password' => 'bob', 'roles' => ['ROLE_USER']],
                ]]]],
                'firewalls' => ['main' => ['lazy' => true, 'http_basic' => true]],
            ]);
        }
        $container->services()->set('logger', NullLogger::class);
        ($this->services)($container);
    }

    protected function configureRoutes(RoutingConfigurator $routes): void
    {
        ($this->routes)($routes);
    }
}

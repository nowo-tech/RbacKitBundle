<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Integration\Persistence;

use Doctrine\DBAL\Connection;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetManagerInterface;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetRegistry;
use Nowo\RbacKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\RbacKitBundle\Tests\Integration\Kernel\RbacTestKernel;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function memory_get_usage;

/**
 * LH-RBAC-M1 (1.1): two kept-alive workers (FrankenPHP without kernel reset, no services resetter) on one database.
 * A permission revoked on worker A is refused by worker B on its next request; a rolled-back edit never stays in the
 * memo (LH-RBAC-L1).
 */
final class RbacWorkerMemoTest extends KernelTestCase
{
    public function testARevokedPermissionIsRefusedOnTheOtherWorkersNextRequest(): void
    {
        $workerA = $this->worker();
        $workerB = $this->worker();

        self::assertStringContainsString('triage:yes', $this->home($workerB));
        self::assertStringContainsString('triage:yes', $this->home($workerB));

        $this->manager($workerA)->update('triage', 'Triage team', null, []);

        self::assertStringContainsString('triage:no', $this->home($workerB), 'worker B sees the revocation on its next request');
        self::assertStringContainsString('triage:no', $this->home($workerA));

        $this->manager($workerA)->update('triage', 'Triage team', null, ['project.issues.triage']);
        self::assertStringContainsString('triage:yes', $this->home($workerB), 'and the grant again');

        $baseline = 0;
        for ($i = 0; $i < 200; ++$i) {
            $this->home($workerB);
            if ($i === 30) {
                $baseline = memory_get_usage();
            }
        }
        self::assertLessThan(4 * 1024 * 1024, memory_get_usage() - $baseline, 'memory stays flat');
    }

    public function testARolledBackEditLeavesNothingInTheMemo(): void
    {
        $worker   = $this->worker();
        $registry = $this->container($worker)->get(PermissionSetRegistry::class);
        self::assertInstanceOf(PermissionSetRegistry::class, $registry);
        $connection = $this->connection($worker);

        $connection->beginTransaction();
        $this->manager($worker)->update('triage', 'Triage team', null, []);
        self::assertSame([], $this->permissionsOf($registry), 'the open transaction sees its own edit');
        $connection->rollBack();

        self::assertSame(['project.issues.triage'], $this->permissionsOf($registry), 'LH-RBAC-L1: not memoised');
        self::assertStringContainsString('triage:yes', $this->home($worker));
    }

    private function worker(): RbacTestKernel
    {
        $config                          = self::defaultConfig();
        $config['storage']['cache_pool'] = null;
        (new Filesystem())->mkdir(RbacTestKernel::baseDir());

        return $this->bootKernel($config, extensions: ['doctrine' => ['dbal' => ['url' => 'sqlite:///' . RbacTestKernel::baseDir() . '/shared-' . $this->name() . '.sqlite']]]);
    }

    /**
     * GET / as alice through the `http_kernel` service (no kernel reset between requests).
     */
    private function home(RbacTestKernel $kernel): string
    {
        $httpKernel = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $httpKernel);
        $request  = Request::create('/', server: ['PHP_AUTH_USER' => 'alice', 'PHP_AUTH_PW' => 'alice', 'REMOTE_ADDR' => '203.0.113.9']);
        $response = $httpKernel->handle($request, HttpKernelInterface::MAIN_REQUEST, false);
        $httpKernel->terminate($request, $response);

        return (string) $response->getContent();
    }

    /**
     * @return list<string>|null
     */
    private function permissionsOf(PermissionSetRegistry $registry): ?array
    {
        return $registry->find('triage')?->permissions;
    }

    private function connection(RbacTestKernel $kernel): Connection
    {
        $connection = $this->container($kernel)->get('doctrine.dbal.default_connection');
        self::assertInstanceOf(Connection::class, $connection);

        return $connection;
    }

    private function manager(RbacTestKernel $kernel): PermissionSetManagerInterface
    {
        $manager = $this->container($kernel)->get(PermissionSetManagerInterface::class);
        self::assertInstanceOf(PermissionSetManagerInterface::class, $manager);

        return $manager;
    }
}

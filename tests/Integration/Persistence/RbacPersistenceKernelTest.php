<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Integration\Persistence;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\RbacKitBundle\Entity\PermissionSetRecord;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetManagerInterface;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetRegistry;
use Nowo\RbacKitBundle\Persistence\OwnEntityManagerSubscriber;
use Nowo\RbacKitBundle\Tests\Fixtures\Host\HostNote;
use Nowo\RbacKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\RbacKitBundle\Tests\Integration\Kernel\RbacTestKernel;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function dirname;
use function memory_get_usage;

/**
 * Rule 6 (1.1): permission-set writes never flush an entity manager (DBAL statements), and
 * `doctrine.entity_manager` keeps the record on a dedicated manager the bundle owns (kept-alive kernel).
 */
final class RbacPersistenceKernelTest extends KernelTestCase
{
    public function testWritesNeverFlushTheHostsPendingChanges(): void
    {
        $kernel  = $this->bootKernel(extensions: ['doctrine' => ['orm' => ['mappings' => $this->hostMapping()]]]);
        $default = $this->manager($kernel, 'default');
        $this->createSchema($default);
        self::assertFalse($this->container($kernel)->has(OwnEntityManagerSubscriber::class));

        $note = new HostNote('pending host change');
        $default->persist($note);
        $manager = $this->setManager($kernel);
        $manager->create('ops', 'Ops', null, ['summary.view']);
        $manager->update('ops', 'Operations', 'Ops team', ['summary.view', 'project.view']);

        $connection = $default->getConnection();
        self::assertSame('Operations', $connection->fetchOne('SELECT name FROM rbac_permission_set WHERE code = ?', ['ops']));
        self::assertSame(0, (int) $connection->fetchOne('SELECT COUNT(*) FROM host_note'), 'the host change was not flushed');
        self::assertTrue($default->getUnitOfWork()->isScheduledForInsert($note));
        self::assertSame(['summary.view', 'project.view'], $this->registry($kernel)->find('ops')?->permissions);
    }

    public function testDedicatedManagerWithAutoMappingOnDefault(): void
    {
        $kernel   = $this->bootDedicated(autoMapping: true);
        $doctrine = $this->doctrine($kernel);
        $default  = $this->manager($kernel, 'default');
        $rbac     = $this->manager($kernel, 'rbac');

        self::assertTrue($default->getMetadataFactory()->isTransient(PermissionSetRecord::class), 'not mapped on the auto-mapped default manager');
        self::assertSame($rbac, $doctrine->getManagerForClass(PermissionSetRecord::class));
        self::assertSame('default', $doctrine->getDefaultManagerName());

        $this->setManager($kernel)->create('ops', 'Ops', null, ['summary.view']);
        self::assertSame(1, (int) $rbac->getConnection()->fetchOne('SELECT COUNT(*) FROM rbac_permission_set'));
    }

    public function testManagersWithoutDefaultEntityManagerKeepTheHostDefault(): void
    {
        $kernel   = $this->bootDedicated(autoMapping: false, defaultName: false);
        $doctrine = $this->doctrine($kernel);

        self::assertSame('default', $doctrine->getDefaultManagerName());
        self::assertSame($this->manager($kernel, 'rbac'), $doctrine->getManagerForClass(PermissionSetRecord::class));
    }

    public function testUnknownEntityManagerFailsTheBuild(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('nowo_rbac_kit.doctrine.entity_manager is "missing"');

        $this->bootKernel(['doctrine' => ['entity_manager' => 'missing']] + self::defaultConfig());
    }

    public function testKeptAliveKernelClearsTheOwnManagerAndKeepsMemoryFlat(): void
    {
        $kernel = $this->bootDedicated(autoMapping: true);
        self::assertTrue($this->container($kernel)->has(OwnEntityManagerSubscriber::class));
        $rbac       = $this->manager($kernel, 'rbac');
        $manager    = $this->setManager($kernel);
        $dispatcher = $this->container($kernel)->get('event_dispatcher');
        self::assertInstanceOf(EventDispatcherInterface::class, $dispatcher);
        $httpKernel = $this->container($kernel)->get('http_kernel');
        self::assertInstanceOf(HttpKernelInterface::class, $httpKernel);
        $manager->create('ops', 'Ops', null, ['summary.view']);

        $baseline = 0;
        for ($i = 0; $i < 200; ++$i) {
            $manager->update('ops', 'Ops ' . $i, null, $i % 2 === 0 ? ['summary.view'] : ['project.view']);
            self::assertSame('Ops ' . $i, $this->registry($kernel)->find('ops')?->name, 'the read sees the DBAL write');
            $dispatcher->dispatch(new TerminateEvent($httpKernel, Request::create('/'), new Response()), 'kernel.terminate');
            self::assertSame(0, $rbac->getUnitOfWork()->size(), 'cleared at the end of the request');
            if ($i === 30) {
                $baseline = memory_get_usage();
            }
        }

        self::assertLessThan(4 * 1024 * 1024, memory_get_usage() - $baseline, 'memory stays flat');
    }

    private function bootDedicated(bool $autoMapping, bool $defaultName = true): RbacTestKernel
    {
        $orm = ['entity_managers' => [
            'default' => ['connection' => 'default', 'auto_mapping' => $autoMapping, 'mappings' => $this->hostMapping()],
            'rbac'    => ['connection' => 'default'],
        ]];
        if ($defaultName) {
            $orm = ['default_entity_manager' => 'default'] + $orm;
        }

        $kernel = $this->bootKernel(['doctrine' => ['entity_manager' => 'rbac']] + self::defaultConfig(), extensions: ['doctrine' => ['orm' => $orm]]);
        $this->createSchema($this->manager($kernel, 'rbac'));

        return $kernel;
    }

    private function setManager(RbacTestKernel $kernel): PermissionSetManagerInterface
    {
        $manager = $this->container($kernel)->get(PermissionSetManagerInterface::class);
        self::assertInstanceOf(PermissionSetManagerInterface::class, $manager);

        return $manager;
    }

    private function registry(RbacTestKernel $kernel): PermissionSetRegistry
    {
        $registry = $this->container($kernel)->get(PermissionSetRegistry::class);
        self::assertInstanceOf(PermissionSetRegistry::class, $registry);
        $registry->reset();

        return $registry;
    }

    private function createSchema(EntityManagerInterface $manager): void
    {
        $tool = new SchemaTool($manager);
        $tool->dropSchema($manager->getMetadataFactory()->getAllMetadata());
        $tool->createSchema($manager->getMetadataFactory()->getAllMetadata());
    }

    private function doctrine(RbacTestKernel $kernel): ManagerRegistry
    {
        $doctrine = $this->container($kernel)->get('doctrine');
        self::assertInstanceOf(ManagerRegistry::class, $doctrine);

        return $doctrine;
    }

    private function manager(RbacTestKernel $kernel, string $name): EntityManagerInterface
    {
        $manager = $this->doctrine($kernel)->getManager($name);
        self::assertInstanceOf(EntityManagerInterface::class, $manager);

        return $manager;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function hostMapping(): array
    {
        return ['Host' => [
            'type'      => 'attribute',
            'dir'       => dirname(__DIR__, 2) . '/Fixtures/Host',
            'prefix'    => 'Nowo\\RbacKitBundle\\Tests\\Fixtures\\Host',
            'is_bundle' => false,
        ]];
    }
}

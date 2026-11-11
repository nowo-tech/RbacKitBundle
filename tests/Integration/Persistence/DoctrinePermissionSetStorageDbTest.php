<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Integration\Persistence;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\RbacKitBundle\Entity\PermissionSetRecord;
use Nowo\RbacKitBundle\PermissionSet\DoctrinePermissionSetStorage;
use Nowo\RbacKitBundle\PermissionSet\StoredPermissionSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Throwable;

use function dirname;
use function getenv;

use const PHP_VERSION_ID;

/**
 * {@see DoctrinePermissionSetStorage} DBAL writes (1.1, rule 6) on a real ORM connection: SQLite always, MySQL /
 * PostgreSQL when `NOWO_TEST_MYSQL_URL` / `NOWO_TEST_PGSQL_URL` are set (CI database job).
 */
final class DoctrinePermissionSetStorageDbTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function platforms(): iterable
    {
        yield 'sqlite' => ['pdo-sqlite:///:memory:'];
        yield 'mysql' => [(string) getenv('NOWO_TEST_MYSQL_URL')];
        yield 'postgresql' => [(string) getenv('NOWO_TEST_PGSQL_URL')];
    }

    #[DataProvider('platforms')]
    public function testInsertUpdateDeleteWithoutTouchingTheUnitOfWork(string $url): void
    {
        $em      = $this->entityManager($url);
        $pending = new PermissionSetRecord('pending', 'Pending');
        $em->persist($pending);
        $storage = $this->storage($em);

        $storage->save(new StoredPermissionSet('ops', 'Ops', null, false, ['a.view'], 'admin', new DateTimeImmutable('2026-11-11 10:00:00')));
        $storage->save(new StoredPermissionSet('ops', 'Operations', 'Team', true, ['a.view', 'b.edit'], 'root'));

        $rows = $storage->load(10);
        self::assertCount(1, $rows);
        self::assertSame(['Operations', 'Team', true, ['a.view', 'b.edit'], 'root'], [$rows[0]->name, $rows[0]->description, $rows[0]->system, $rows[0]->permissions, $rows[0]->updatedBy]);
        self::assertSame(1, $storage->count());
        self::assertTrue($em->getUnitOfWork()->isScheduledForInsert($pending), 'the pending entity was not flushed');

        $storage->delete('ops');
        $storage->delete('missing');
        self::assertSame(0, $storage->count());
    }

    #[DataProvider('platforms')]
    public function testInsideAHostTransactionTheRowFollowsThatTransaction(string $url): void
    {
        $em         = $this->entityManager($url);
        $connection = $em->getConnection();
        $storage    = $this->storage($em);

        $connection->beginTransaction();
        $storage->save(new StoredPermissionSet('ops', 'Ops', null, false, []));
        $connection->rollBack();
        self::assertSame(0, $storage->count());

        $connection->beginTransaction();
        $storage->save(new StoredPermissionSet('ops', 'Ops', null, false, []));
        $connection->commit();
        self::assertSame(1, $storage->count());
    }

    #[DataProvider('platforms')]
    public function testAFailedWriteRethrowsAndKeepsTheHostTransactionUsable(string $url): void
    {
        $em         = $this->entityManager($url);
        $connection = $em->getConnection();
        // DDL outside the transaction (MySQL commits implicitly on DDL).
        $connection->executeStatement('DROP TABLE rbac_permission_set');
        $connection->beginTransaction();
        $connection->insert('host_marker', ['label' => 'before']);

        try {
            $this->storage($em)->save(new StoredPermissionSet('ops', 'Ops', null, false, []));
            self::fail('The write must fail.');
        } catch (Throwable $e) {
            self::assertStringContainsString('rbac_permission_set', $e->getMessage());
        }

        $connection->insert('host_marker', ['label' => 'after']);
        $connection->commit();
        self::assertSame(['before', 'after'], $connection->fetchFirstColumn('SELECT label FROM host_marker ORDER BY label DESC'));
    }

    public function testAFailingSavepointRollbackNeverHidesTheOriginalError(): void
    {
        $real       = $this->entityManager('pdo-sqlite:///:memory:');
        $connection = $this->createMock(Connection::class);
        $connection->method('isTransactionActive')->willReturn(true);
        $connection->expects(self::once())->method('createSavepoint');
        $connection->method('delete')->willThrowException(new RuntimeException('original'));
        $connection->method('rollbackSavepoint')->willThrowException(new RuntimeException('rollback failed'));
        $connection->expects(self::never())->method('releaseSavepoint');
        $em = $this->createStub(EntityManagerInterface::class);
        $em->method('getConnection')->willReturn($connection);
        $em->method('getClassMetadata')->willReturn($real->getClassMetadata(PermissionSetRecord::class));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('original');
        $this->storage($em)->delete('ops');
    }

    private function storage(EntityManagerInterface $em): DoctrinePermissionSetStorage
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);

        return new DoctrinePermissionSetStorage($registry);
    }

    private function entityManager(string $url): EntityManagerInterface
    {
        if ($url === '') {
            self::markTestSkipped('Database URL not set (NOWO_TEST_MYSQL_URL / NOWO_TEST_PGSQL_URL).');
        }

        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $params = (new DsnParser(['pdo-sqlite' => 'pdo_sqlite', 'mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql']))->parse($url);
        $em     = new EntityManager(DriverManager::getConnection($params, $config), $config);

        $connection = $em->getConnection();
        foreach (['rbac_permission_set', 'host_marker'] as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS ' . $table);
        }
        $connection->executeStatement('CREATE TABLE host_marker (label VARCHAR(16) NOT NULL)');
        (new SchemaTool($em))->createSchema([$em->getClassMetadata(PermissionSetRecord::class)]);

        return $em;
    }
}

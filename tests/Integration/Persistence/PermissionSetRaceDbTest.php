<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Integration\Persistence;

use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Tools\DsnParser;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\ORMSetup;
use Doctrine\ORM\Tools\SchemaTool;
use Doctrine\Persistence\ManagerRegistry;
use Nowo\RbacKitBundle\Entity\PermissionSetRecord;
use Nowo\RbacKitBundle\PermissionSet\DoctrinePermissionSetStorage;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetException;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetManager;
use Nowo\RbacKitBundle\PermissionSet\StoredPermissionSet;
use Nowo\RbacKitBundle\Tests\Support\Catalogues;
use Nowo\TransactionContract\TransientStorageFailure;
use Nowo\TransactionDoctrine\TransientFailureClassifier;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

use function dirname;
use function getenv;

use const PHP_VERSION_ID;

/**
 * Two-connection races on a real database (1.3, REQ-RUNTIME-003): MySQL 8.4, MariaDB 11.4 and PostgreSQL 16 when
 * `NOWO_TEST_MYSQL_URL` / `NOWO_TEST_MARIADB_URL` / `NOWO_TEST_PGSQL_URL` are set (CI "Databases" job). Connection A
 * holds its write in an open transaction (the "first request"); connection B races it and must wait on the real lock,
 * time out (1 s: `innodb_lock_wait_timeout` / `lock_timeout`, so a hang fails fast) as a TransientStorageFailure (the
 * admin pages answer 409 CONFLICT_001), stay usable, and once A committed be refused: never a 500, a duplicate, a lost
 * update or a re-created set.
 */
final class PermissionSetRaceDbTest extends TestCase
{
    /** @var list<EntityManagerInterface> */
    private array $managers = [];

    protected function tearDown(): void
    {
        foreach ($this->managers as $manager) {
            $connection = $manager->getConnection();
            while ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
            $connection->close();
        }
        $this->managers = [];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function databases(): iterable
    {
        yield 'mysql' => [(string) getenv('NOWO_TEST_MYSQL_URL')];
        yield 'mariadb' => [(string) getenv('NOWO_TEST_MARIADB_URL')];
        yield 'postgresql' => [(string) getenv('NOWO_TEST_PGSQL_URL')];
    }

    #[DataProvider('databases')]
    public function testTwoCreatesOfOneCodeGiveOneRowAndNo500(string $url): void
    {
        [$a, $b]  = $this->connections($url);
        $storageA = $this->storage($a);
        $storageB = $this->storage($b);

        $a->getConnection()->beginTransaction();
        self::assertTrue($storageA->insert(new StoredPermissionSet('ops', 'Mine', null, false, ['a.view'], 'alice')));

        $this->assertWaitsAndTimesOut(static fn (): bool => $storageB->insert(new StoredPermissionSet('ops', 'Theirs', null, false, ['b.edit'], 'bob')), $b);

        $a->getConnection()->commit();
        self::assertFalse($storageB->insert(new StoredPermissionSet('ops', 'Theirs', null, false, ['b.edit'], 'bob')), 'the unique code decides: CODE_TAKEN, not a 500');
        $rows = $storageB->load(10);
        self::assertCount(1, $rows);
        self::assertSame(['Mine', ['a.view']], [$rows[0]->name, $rows[0]->permissions], 'the first create is not overwritten');

        // The 1.2 upsert (save()) on the same race: insert refused by the unique code, then an update; no 500.
        $storageB->save(new StoredPermissionSet('ops', 'Upserted', null, false, [], 'bob'));
        self::assertSame('Upserted', $storageA->load(1)[0]->name);
    }

    #[DataProvider('databases')]
    public function testTwoEditsFromOneRevisionKeepTheFirstAndRefuseTheSecond(string $url): void
    {
        [$a, $b]  = $this->connections($url);
        $storageA = $this->storage($a);
        $storageB = $this->storage($b);
        $storageA->insert(new StoredPermissionSet('ops', 'Ops', null, false, ['a.view', 'b.edit'], 'root'));
        $revision = $storageA->load(1)[0]->revision();

        // A revokes b.edit and still holds the row lock; B, from the same revision, renames the set.
        $a->getConnection()->beginTransaction();
        self::assertTrue($storageA->replace(new StoredPermissionSet('ops', 'Ops', null, false, ['a.view'], 'alice'), $revision));

        $this->assertWaitsAndTimesOut(static fn (): bool => $storageB->replace(new StoredPermissionSet('ops', 'Renamed', null, false, ['a.view', 'b.edit'], 'bob'), $revision), $b);

        $a->getConnection()->commit();
        self::assertFalse($storageB->replace(new StoredPermissionSet('ops', 'Renamed', null, false, ['a.view', 'b.edit'], 'bob'), $revision), 'the stale edit is refused (STALE)');
        $row = $storageB->load(1)[0];
        self::assertSame(['Ops', ['a.view'], 'alice'], [$row->name, $row->permissions, $row->updatedBy], 'the revocation is not undone');

        self::assertTrue($storageB->replace(new StoredPermissionSet('ops', 'Renamed', null, false, ['a.view'], 'bob'), $row->revision()), 'from the current revision the edit goes through');
    }

    #[DataProvider('databases')]
    public function testAnEditRacingADeleteNeverRecreatesTheSet(string $url): void
    {
        [$a, $b]  = $this->connections($url);
        $storageA = $this->storage($a);
        $storageB = $this->storage($b);
        $storageA->insert(new StoredPermissionSet('ops', 'Ops', null, false, ['a.view'], 'root'));
        $revision = $storageA->load(1)[0]->revision();

        $a->getConnection()->beginTransaction();
        $storageA->delete('ops');

        $this->assertWaitsAndTimesOut(static fn (): bool => $storageB->replace(new StoredPermissionSet('ops', 'Ops', null, false, ['a.view', 'b.edit'], 'bob'), $revision), $b);

        $a->getConnection()->commit();
        self::assertFalse($storageB->replace(new StoredPermissionSet('ops', 'Ops', null, false, ['a.view', 'b.edit'], 'bob'), $revision));
        self::assertSame(0, $storageB->count(), 'no resurrection');
    }

    #[DataProvider('databases')]
    public function testTwoFirstEditsOfASystemSetKeepTheFirst(string $url): void
    {
        [$a, $b]  = $this->connections($url);
        $storageA = $this->storage($a);
        $storageB = $this->storage($b);

        $a->getConnection()->beginTransaction();
        self::assertTrue($storageA->replace(new StoredPermissionSet('viewer', 'Viewer', null, true, ['summary.view'], 'alice'), null));

        $this->assertWaitsAndTimesOut(static fn (): bool => $storageB->replace(new StoredPermissionSet('viewer', 'Viewer', null, true, ['summary.view', 'project.view'], 'bob'), null), $b);

        $a->getConnection()->commit();
        self::assertFalse($storageB->replace(new StoredPermissionSet('viewer', 'Viewer', null, true, ['summary.view', 'project.view'], 'bob'), null));
        self::assertSame(['summary.view'], $storageB->load(1)[0]->permissions);
    }

    #[DataProvider('databases')]
    public function testTwoManagersEditingFromOneReadEndWithOneEditAndAStale(string $url): void
    {
        [$a, $b]  = $this->connections($url);
        $storageA = $this->storage($a);
        $storageB = $this->storage($b);
        $storageA->insert(new StoredPermissionSet('reporting', 'Reporting', null, false, ['reports.export', 'summary.view'], 'root'));
        $registryA = Catalogues::registry($storageA);
        $registryB = Catalogues::registry($storageB);
        $managerA  = new PermissionSetManager($registryA, Catalogues::catalog(), $storageA);
        $managerB  = new PermissionSetManager($registryB, Catalogues::catalog(), $storageB);
        // Both requests read the set before either writes (the request memo keeps that read).
        self::assertNotNull($registryA->find('reporting'));
        self::assertNotNull($registryB->find('reporting'));

        $managerA->update('reporting', 'Reporting', null, ['reports.export']);
        try {
            $managerB->update('reporting', 'Renamed', null, ['reports.export', 'summary.view']);
            self::fail('The second edit must be refused.');
        } catch (PermissionSetException $e) {
            self::assertSame(PermissionSetException::STALE, $e->reason);
        }

        $registryA->reset();
        self::assertSame(['reports.export'], $registryA->find('reporting')?->permissions);
        self::assertSame('Reporting', $registryA->find('reporting')->name);
    }

    /**
     * B's write must block on A's lock, fail as a lock conflict within the timeout, and leave B's connection usable
     * with no transaction left open.
     *
     * @param callable(): bool $write
     */
    private function assertWaitsAndTimesOut(callable $write, EntityManagerInterface $b): void
    {
        try {
            $write();
            self::fail('The racing write must wait on the lock and time out.');
        } catch (TransientStorageFailure $e) {
            self::assertTrue((new TransientFailureClassifier())->isTransient($e->getPrevious() ?? $e), 'a lock wait timeout, mapped to 409 CONFLICT_001');
        } catch (Throwable $e) {
            self::fail('Expected a TransientStorageFailure, got ' . $e::class . ': ' . $e->getMessage());
        }

        $connection = $b->getConnection();
        self::assertFalse($connection->isTransactionActive(), 'the runner rolled its transaction back');
        self::assertSame(1, (int) $connection->fetchOne('SELECT 1'), 'the connection is still usable');
    }

    private function storage(EntityManagerInterface $em): DoctrinePermissionSetStorage
    {
        $registry = $this->createStub(ManagerRegistry::class);
        $registry->method('getManagerForClass')->willReturn($em);

        return new DoctrinePermissionSetStorage($registry, null, 1000);
    }

    /**
     * Two entity managers, each on its own connection to the same database, with a fresh table and 1 s lock waits.
     *
     * @return array{EntityManagerInterface, EntityManagerInterface}
     */
    private function connections(string $url): array
    {
        if ($url === '') {
            self::markTestSkipped('Database URL not set (NOWO_TEST_MYSQL_URL / NOWO_TEST_MARIADB_URL / NOWO_TEST_PGSQL_URL).');
        }

        $a = $this->entityManager($url);
        $b = $this->entityManager($url);
        $a->getConnection()->executeStatement('DROP TABLE IF EXISTS rbac_permission_set');
        if ($a->getConnection()->getDatabasePlatform() instanceof PostgreSQLPlatform) {
            // DBAL 3 maps the generated id to a sequence on PostgreSQL; it outlives the table.
            $a->getConnection()->executeStatement('DROP SEQUENCE IF EXISTS rbac_permission_set_id_seq');
        }
        (new SchemaTool($a))->createSchema([$a->getClassMetadata(PermissionSetRecord::class)]);

        return [$a, $b];
    }

    private function entityManager(string $url): EntityManagerInterface
    {
        $config = ORMSetup::createAttributeMetadataConfiguration([dirname(__DIR__, 3) . '/src/Entity'], true);
        if (PHP_VERSION_ID >= 80400) {
            $config->enableNativeLazyObjects(true);
        }
        $params           = (new DsnParser(['mysql' => 'pdo_mysql', 'pgsql' => 'pdo_pgsql']))->parse($url);
        $em               = new EntityManager(DriverManager::getConnection($params, $config), $config);
        $this->managers[] = $em;

        $connection = $em->getConnection();
        $connection->executeStatement($connection->getDatabasePlatform() instanceof PostgreSQLPlatform ? "SET lock_timeout = '1s'" : 'SET SESSION innodb_lock_wait_timeout = 1');

        return $em;
    }
}

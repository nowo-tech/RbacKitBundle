<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\PermissionSet;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\RbacKitBundle\Entity\PermissionSetRecord;
use Nowo\TransactionDoctrine\DoctrineTransactionRunner;
use Psr\Clock\ClockInterface;
use Throwable;

use function array_filter;
use function array_map;
use function array_values;
use function bin2hex;
use function implode;
use function is_array;
use function is_string;
use function random_bytes;
use function sprintf;

/**
 * Storage on the bundle entity {@see PermissionSetRecord} (`storage.type: doctrine`). Reads are bounded (`$limit`);
 * writes are DBAL statements on the connection of the manager that maps the entity (1.1: `doctrine.entity_manager`,
 * else `getManagerForClass()`), never a `flush()`. Creates rely on the unique code, edits on a row lock and the row's
 * revision (1.3, REQ-RUNTIME-003; see docs/CONCURRENCY.md). Stateless: the entity manager is resolved per call.
 */
final readonly class DoctrinePermissionSetStorage implements ConditionalPermissionSetStorageInterface
{
    /** Default PostgreSQL `lock_timeout` of the bundle's own write units, in milliseconds. */
    public const LOCK_TIMEOUT_MS = 5000;

    /** Fields of {@see PermissionSetRecord} that make a {@see StoredPermissionSet} (besides the code). */
    private const ROW_FIELDS = ['name', 'description', 'system', 'permissions', 'updatedBy', 'updatedAt'];

    /**
     * @param ManagerRegistry $doctrine Doctrine registry
     * @param ClockInterface|null $clock time of the edits (null = system time)
     * @param int $lockTimeoutMs PostgreSQL `lock_timeout` of the bundle's own write units (1.3), in milliseconds
     */
    public function __construct(
        private ManagerRegistry $doctrine,
        private ?ClockInterface $clock = null,
        private int $lockTimeoutMs = self::LOCK_TIMEOUT_MS,
    ) {
    }

    /**
     * Rows ordered by code, at most `$limit`.
     *
     * @return list<StoredPermissionSet>
     */
    public function load(int $limit): array
    {
        // HINT_REFRESH: rows are written with DBAL, so an already managed record must be re-read, not served stale.
        /** @var list<PermissionSetRecord> $records */
        $records = $this->manager()->createQueryBuilder()
            ->select('r')
            ->from(PermissionSetRecord::class, 'r')
            ->orderBy('r.code', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();

        return array_values(array_map(static fn (PermissionSetRecord $record): StoredPermissionSet => new StoredPermissionSet(
            $record->getCode(),
            $record->getName(),
            $record->getDescription(),
            $record->isSystem(),
            $record->getPermissions(),
            $record->getUpdatedBy(),
            $record->getUpdatedAt(),
        ), $records));
    }

    /**
     * Always writable.
     */
    public function isWritable(): bool
    {
        return true;
    }

    /**
     * Number of rows.
     */
    public function count(): int
    {
        return $this->manager()->getRepository(PermissionSetRecord::class)->count([]);
    }

    /**
     * Inserts the row of the code, or updates it when one exists (1.3, REQ-RUNTIME-003 rule 4: insert first and catch
     * the unique code, never a SELECT-then-INSERT, so two concurrent saves of one code never fail with a 500). The
     * admin manager uses {@see self::insert()} and {@see self::replace()} instead, which never overwrite a row they did
     * not read. One DBAL statement per unit, never a `flush()` (1.1, rule 6: changes the host left pending in the same
     * entity manager are not committed by a permission-set edit); see {@see self::unit()} for the transaction.
     */
    public function save(StoredPermissionSet $set): void
    {
        if ($this->insert($set)) {
            return;
        }

        $write = $this->write($set);
        $this->unit($write['connection'], static function () use ($write, $set): void {
            $write['connection']->update($write['table'], $write['row'], [$write['code'] => $set->code], $write['types']);
        });
    }

    /**
     * Inserts the row; false when the unique code refuses it (a row of that code exists, possibly created by a
     * concurrent request a moment ago). Nothing else is caught.
     */
    public function insert(StoredPermissionSet $set): bool
    {
        $write = $this->write($set);
        try {
            $this->unit($write['connection'], function () use ($write): void {
                $write['connection']->insert($write['table'], $this->withSequenceId($write), $write['types']);
            });
        } catch (UniqueConstraintViolationException) {
            return false;
        }

        return true;
    }

    /**
     * Replaces the row only while its revision is `$expectedRevision` (null: inserts it only while none exists). The
     * row is read with `SELECT … FOR UPDATE` and updated in the same unit, so two editors of one set are serialised and
     * the second one gets false instead of undoing the first one's change. A deleted row is never re-created.
     */
    public function replace(StoredPermissionSet $set, ?string $expectedRevision): bool
    {
        if ($expectedRevision === null) {
            return $this->insert($set);
        }

        $write = $this->write($set);

        return $this->unit($write['connection'], function () use ($write, $set, $expectedRevision): bool {
            $current = $this->lockedRow($write['connection'], $write['metadata'], $set->code);
            if (!$current instanceof StoredPermissionSet || $current->revision() !== $expectedRevision) {
                return false;
            }

            $write['connection']->update($write['table'], $write['row'], [$write['code'] => $set->code], $write['types']);

            return true;
        });
    }

    /**
     * Deletes the row of a code with one DBAL statement (no-op when missing; never flushes a unit of work).
     */
    public function delete(string $code): void
    {
        $manager    = $this->manager();
        $metadata   = $manager->getClassMetadata(PermissionSetRecord::class);
        $connection = $manager->getConnection();
        $this->unit($connection, static function () use ($connection, $metadata, $code): void {
            $connection->delete($metadata->getTableName(), [$metadata->getColumnName('code') => $code]);
        });
    }

    /**
     * The columns, values and types of the row of a set.
     *
     * @return array{connection: Connection, metadata: ClassMetadata<PermissionSetRecord>, table: string, code: string, row: array<string, mixed>, types: array<string, string>}
     */
    private function write(StoredPermissionSet $set): array
    {
        $manager  = $this->manager();
        $metadata = $manager->getClassMetadata(PermissionSetRecord::class);
        $record   = new PermissionSetRecord($set->code, $set->name, $set->system);
        $record->change($set->name, $set->description, $set->permissions, $set->system, $set->updatedBy, $set->updatedAt ?? $this->clock?->now() ?? new DateTimeImmutable());

        $row   = [];
        $types = [];
        foreach ($metadata->getFieldNames() as $field) {
            if ($metadata->isIdentifier($field)) {
                continue;
            }
            $column         = $metadata->getColumnName($field);
            $row[$column]   = $metadata->getFieldValue($record, $field);
            $types[$column] = $metadata->getTypeOfField($field) ?? Types::STRING;
        }

        return ['connection' => $manager->getConnection(), 'metadata' => $metadata, 'table' => $metadata->getTableName(), 'code' => $metadata->getColumnName('code'), 'row' => $row, 'types' => $types];
    }

    /**
     * The row to insert, with the next value of the id sequence when the mapping uses one (DBAL 3 maps the generated
     * id to a sequence on PostgreSQL; a DBAL insert must then fill the id itself, an ORM persist would).
     *
     * @param array{connection: Connection, metadata: ClassMetadata<PermissionSetRecord>, row: array<string, mixed>} $write
     *
     * @return array<string, mixed>
     */
    private function withSequenceId(array $write): array
    {
        $metadata = $write['metadata'];
        if (!$metadata->isIdGeneratorSequence()) {
            return $write['row'];
        }

        $sql = $write['connection']->getDatabasePlatform()->getSequenceNextValSQL($metadata->sequenceGeneratorDefinition['sequenceName']);

        return [$metadata->getSingleIdentifierColumnName() => (int) $write['connection']->fetchOne($sql)] + $write['row'];
    }

    /**
     * The row of a code read under a row lock (`FOR UPDATE`; SQLite locks the database on write instead), converted
     * with the mapped types like an ORM read, or null.
     *
     * @param ClassMetadata<PermissionSetRecord> $metadata
     */
    private function lockedRow(Connection $connection, ClassMetadata $metadata, string $code): ?StoredPermissionSet
    {
        $platform = $connection->getDatabasePlatform();
        $columns  = [];
        foreach (self::ROW_FIELDS as $field) {
            $columns[] = $metadata->getColumnName($field);
        }
        $row = $connection->fetchAssociative(sprintf(
            'SELECT %s FROM %s WHERE %s = ?%s',
            implode(', ', $columns),
            $metadata->getTableName(),
            $metadata->getColumnName('code'),
            $platform instanceof SQLitePlatform ? '' : ' FOR UPDATE',
        ), [$code]);
        if ($row === false) {
            return null;
        }

        $value       = static fn (string $field): mixed => Type::getType($metadata->getTypeOfField($field) ?? Types::STRING)->convertToPHPValue($row[$metadata->getColumnName($field)] ?? null, $platform);
        $name        = $value('name');
        $description = $value('description');
        $permissions = $value('permissions');
        $updatedBy   = $value('updatedBy');
        $updatedAt   = $value('updatedAt');

        return new StoredPermissionSet(
            $code,
            is_string($name) ? $name : '',
            is_string($description) ? $description : null,
            $value('system') === true,
            is_array($permissions) ? array_values(array_filter($permissions, is_string(...))) : [],
            is_string($updatedBy) ? $updatedBy : null,
            $updatedAt instanceof DateTimeImmutable ? $updatedAt : null,
        );
    }

    /**
     * One write unit (REQ-RUNTIME-003 rules 1 and 3):
     *
     * - no transaction open: its own transaction through transaction-doctrine's runner (DBAL only: no entity manager
     *   is flushed, cleared or reset). A deadlock, lock wait timeout or serialization failure comes out as the
     *   contract's TransientStorageFailure (the admin pages answer 409 CONFLICT_001). On PostgreSQL the unit sets
     *   `lock_timeout` (`$lockTimeoutMs`, default {@see self::LOCK_TIMEOUT_MS}) for its own transaction, so a row lock held elsewhere cannot hang
     *   the request; MySQL and MariaDB use `innodb_lock_wait_timeout`;
     * - inside the host's transaction: a SAVEPOINT, so the row commits or rolls back with that transaction; on failure
     *   the savepoint is rolled back (PostgreSQL then accepts further statements) and the ORIGINAL error is rethrown
     *   (a failing rollback never hides it). The host's session settings are left alone.
     *
     * @template T
     *
     * @param callable(): T $statements
     *
     * @return T
     */
    private function unit(Connection $connection, callable $statements): mixed
    {
        if ($connection->isTransactionActive()) {
            return $this->inSavepoint($connection, $statements);
        }

        $lockTimeoutMs = $this->lockTimeoutMs;

        return DoctrineTransactionRunner::forConnection($connection, null)->run(static function () use ($connection, $statements, $lockTimeoutMs): mixed {
            if ($connection->getDatabasePlatform() instanceof PostgreSQLPlatform) {
                $connection->executeStatement(sprintf("SET LOCAL lock_timeout = '%dms'", $lockTimeoutMs));
            }

            return $statements();
        });
    }

    /**
     * @template T
     *
     * @param callable(): T $statements
     *
     * @return T
     */
    private function inSavepoint(Connection $connection, callable $statements): mixed
    {
        $savepoint = 'nowo_rbac_' . bin2hex(random_bytes(6));
        $connection->createSavepoint($savepoint);
        try {
            $result = $statements();
        } catch (Throwable $e) {
            try {
                $connection->rollbackSavepoint($savepoint);
            } catch (Throwable) {
                // The original error matters more than a failing rollback.
            }

            throw $e;
        }

        $connection->releaseSavepoint($savepoint);

        return $result;
    }

    private function manager(): EntityManagerInterface
    {
        $manager = $this->doctrine->getManagerForClass(PermissionSetRecord::class);
        if (!$manager instanceof EntityManagerInterface) {
            throw new LogicException(sprintf('RbacKit: no Doctrine ORM entity manager maps %s (storage.type: doctrine).', PermissionSetRecord::class));
        }

        return $manager;
    }
}

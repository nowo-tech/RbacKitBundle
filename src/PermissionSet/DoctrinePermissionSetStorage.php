<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\PermissionSet;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\RbacKitBundle\Entity\PermissionSetRecord;
use Psr\Clock\ClockInterface;
use Throwable;

use function array_map;
use function array_values;
use function bin2hex;
use function random_bytes;
use function sprintf;

/**
 * Storage on the bundle entity {@see PermissionSetRecord} (`storage.type: doctrine`). Reads are bounded (`$limit`);
 * writes are single DBAL statements on the connection of the manager that maps the entity (1.1: `doctrine.entity_manager`,
 * else `getManagerForClass()`), never a `flush()`. Stateless: the entity manager is resolved per call.
 */
final readonly class DoctrinePermissionSetStorage implements PermissionSetStorageInterface
{
    /**
     * @param ManagerRegistry $doctrine Doctrine registry
     * @param ClockInterface|null $clock time of the edits (null = system time)
     */
    public function __construct(
        private ManagerRegistry $doctrine,
        private ?ClockInterface $clock = null,
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
     * Inserts or updates the row of the code with one DBAL statement (1.1, rule 6: never flushes a unit of work, so
     * changes the host left pending in the same entity manager are not committed by a permission-set edit). Inside
     * a SAVEPOINT when a transaction is open: the row then commits or rolls back with it.
     */
    public function save(StoredPermissionSet $set): void
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

        $connection = $manager->getConnection();
        $table      = $metadata->getTableName();
        $codeColumn = $metadata->getColumnName('code');
        $this->inSavepoint($connection, static function () use ($connection, $table, $codeColumn, $row, $types, $set): void {
            $exists = $connection->fetchOne(sprintf('SELECT 1 FROM %s WHERE %s = ?', $table, $codeColumn), [$set->code]);
            if ($exists === false) {
                $connection->insert($table, $row, $types);

                return;
            }

            $connection->update($table, $row, [$codeColumn => $set->code], $types);
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
        $this->inSavepoint($connection, static function () use ($connection, $metadata, $code): void {
            $connection->delete($metadata->getTableName(), [$metadata->getColumnName('code') => $code]);
        });
    }

    /**
     * Runs the statements in a SAVEPOINT when a transaction is open; on failure rolls it back and rethrows the
     * ORIGINAL error (a failing rollback never hides it).
     *
     * @param callable(): void $statements
     */
    private function inSavepoint(Connection $connection, callable $statements): void
    {
        if (!$connection->isTransactionActive()) {
            $statements();

            return;
        }

        $savepoint = 'nowo_rbac_' . bin2hex(random_bytes(6));
        $connection->createSavepoint($savepoint);
        try {
            $statements();
        } catch (Throwable $e) {
            try {
                $connection->rollbackSavepoint($savepoint);
            } catch (Throwable) {
                // The original error matters more than a failing rollback.
            }

            throw $e;
        }

        $connection->releaseSavepoint($savepoint);
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

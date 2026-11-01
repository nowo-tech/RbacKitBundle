<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\PermissionSet;

use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\RbacKitBundle\Entity\PermissionSetRecord;
use Psr\Clock\ClockInterface;

use function array_map;
use function array_values;
use function sprintf;

/**
 * Storage on the bundle entity {@see PermissionSetRecord} (`storage.type: doctrine`). Reads are bounded (`$limit`);
 * every write flushes at once. Stateless: the entity manager is resolved per call.
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
        /** @var list<PermissionSetRecord> $records */
        $records = $this->manager()->getRepository(PermissionSetRecord::class)->findBy([], ['code' => 'ASC'], $limit);

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
     * Inserts or updates the row of the code and flushes.
     */
    public function save(StoredPermissionSet $set): void
    {
        $manager = $this->manager();
        $record  = $manager->getRepository(PermissionSetRecord::class)->findOneBy(['code' => $set->code]);
        if (!$record instanceof PermissionSetRecord) {
            $record = new PermissionSetRecord($set->code, $set->name, $set->system);
            // @igor-ignore - Unit of work of the request's entity manager (DoctrineBundle resets it between requests).
            $manager->persist($record);
        }
        $record->change($set->name, $set->description, $set->permissions, $set->system, $set->updatedBy, $set->updatedAt ?? $this->clock?->now() ?? new DateTimeImmutable());
        $manager->flush();
    }

    /**
     * Deletes the row of a code and flushes (no-op when missing).
     */
    public function delete(string $code): void
    {
        $manager = $this->manager();
        $record  = $manager->getRepository(PermissionSetRecord::class)->findOneBy(['code' => $code]);
        if ($record instanceof PermissionSetRecord) {
            // @igor-ignore - Unit of work of the request's entity manager (DoctrineBundle resets it between requests).
            $manager->remove($record);
            $manager->flush();
        }
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

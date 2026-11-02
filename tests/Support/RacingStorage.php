<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Support;

use Nowo\RbacKitBundle\PermissionSet\PermissionSetStorageInterface;
use Nowo\RbacKitBundle\PermissionSet\StoredPermissionSet;

/**
 * Storage of the race tests: the first save also stores the rows of concurrent requests, written just before it (between
 * this request's bound check and its save) and just after it (a same-code write of another manager).
 */
final class RacingStorage implements PermissionSetStorageInterface
{
    private bool $raced = false;

    /**
     * @param list<StoredPermissionSet> $before
     * @param list<StoredPermissionSet> $after
     */
    public function __construct(
        public readonly MemoryStorage $inner,
        private readonly array $before,
        private readonly array $after = [],
    ) {
    }

    public function load(int $limit): array
    {
        return $this->inner->load($limit);
    }

    public function isWritable(): bool
    {
        return true;
    }

    public function count(): int
    {
        return $this->inner->count();
    }

    public function save(StoredPermissionSet $set): void
    {
        $raced       = $this->raced;
        $this->raced = true;
        foreach ($raced ? [] : $this->before as $row) {
            $this->inner->save($row);
        }
        $this->inner->save($set);
        foreach ($raced ? [] : $this->after as $row) {
            $this->inner->save($row);
        }
    }

    public function delete(string $code): void
    {
        $this->inner->delete($code);
    }
}

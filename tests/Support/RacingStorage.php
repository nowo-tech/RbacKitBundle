<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Support;

use Nowo\RbacKitBundle\PermissionSet\PermissionSetStorageInterface;
use Nowo\RbacKitBundle\PermissionSet\StoredPermissionSet;

/**
 * Storage of the race tests: the first save also stores the row of a concurrent request (written between this
 * request's bound check and its save).
 */
final class RacingStorage implements PermissionSetStorageInterface
{
    private ?StoredPermissionSet $concurrent;

    public function __construct(
        public readonly MemoryStorage $inner,
        StoredPermissionSet $concurrent,
    ) {
        $this->concurrent = $concurrent;
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
        if ($this->concurrent instanceof StoredPermissionSet) {
            $this->inner->save($this->concurrent);
            $this->concurrent = null;
        }
        $this->inner->save($set);
    }

    public function delete(string $code): void
    {
        $this->inner->delete($code);
    }
}

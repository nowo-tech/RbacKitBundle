<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Support;

use Nowo\RbacKitBundle\PermissionSet\PermissionSetStorageInterface;
use Nowo\RbacKitBundle\PermissionSet\StoredPermissionSet;

use function array_slice;
use function array_values;
use function count;
use function ksort;

/**
 * Writable in-memory storage of the unit tests; counts its loads.
 */
final class MemoryStorage implements PermissionSetStorageInterface
{
    public int $loads = 0;

    /** @var array<string, StoredPermissionSet> */
    public array $rows = [];

    /**
     * @param list<StoredPermissionSet> $rows
     */
    public function __construct(array $rows = [], private readonly bool $writable = true)
    {
        foreach ($rows as $row) {
            $this->rows[$row->code] = $row;
        }
    }

    public function load(int $limit): array
    {
        ++$this->loads;
        ksort($this->rows);

        return array_slice(array_values($this->rows), 0, $limit);
    }

    public function isWritable(): bool
    {
        return $this->writable;
    }

    public function count(): int
    {
        return count($this->rows);
    }

    public function save(StoredPermissionSet $set): void
    {
        $this->rows[$set->code] = $set;
    }

    public function delete(string $code): void
    {
        unset($this->rows[$code]);
    }
}

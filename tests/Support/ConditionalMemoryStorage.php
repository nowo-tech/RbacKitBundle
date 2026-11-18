<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Support;

use Closure;
use Nowo\RbacKitBundle\PermissionSet\ConditionalPermissionSetStorageInterface;
use Nowo\RbacKitBundle\PermissionSet\StoredPermissionSet;

/**
 * Conditional in-memory storage of the unit tests: {@see self::$beforeWrite} runs once, just before the next
 * conditional write, to play the write of a concurrent request (another manager) in the race window.
 */
final class ConditionalMemoryStorage implements ConditionalPermissionSetStorageInterface
{
    public readonly MemoryStorage $inner;

    /** @var (Closure(MemoryStorage): void)|null */
    public ?Closure $beforeWrite = null;

    /**
     * @param list<StoredPermissionSet> $rows
     */
    public function __construct(array $rows = [])
    {
        $this->inner = new MemoryStorage($rows);
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
        $this->inner->save($set);
    }

    public function delete(string $code): void
    {
        $this->inner->delete($code);
    }

    public function insert(StoredPermissionSet $set): bool
    {
        $this->race();
        if (isset($this->inner->rows[$set->code])) {
            return false;
        }
        $this->inner->save($set);

        return true;
    }

    public function replace(StoredPermissionSet $set, ?string $expectedRevision): bool
    {
        $this->race();
        $current = $this->inner->rows[$set->code] ?? null;
        if ($current?->revision() !== $expectedRevision) {
            return false;
        }
        $this->inner->save($set);

        return true;
    }

    private function race(): void
    {
        $race              = $this->beforeWrite;
        $this->beforeWrite = null;
        if ($race instanceof Closure) {
            $race($this->inner);
        }
    }
}

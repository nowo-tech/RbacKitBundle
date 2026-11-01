<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\PermissionSet;

use LogicException;

/**
 * Read-only storage (`storage.type: config`): only the configured system sets exist; the admin UI lists them without
 * create / edit / delete.
 */
final readonly class ConfigPermissionSetStorage implements PermissionSetStorageInterface
{
    /**
     * No stored rows.
     *
     * @return list<StoredPermissionSet>
     */
    public function load(int $limit): array
    {
        return [];
    }

    /**
     * Never writable.
     */
    public function isWritable(): bool
    {
        return false;
    }

    /**
     * Always 0.
     */
    public function count(): int
    {
        return 0;
    }

    /**
     * Refused.
     */
    public function save(StoredPermissionSet $set): void
    {
        throw new LogicException('RbacKit: permission sets are read-only with storage.type: config.');
    }

    /**
     * Refused.
     */
    public function delete(string $code): void
    {
        throw new LogicException('RbacKit: permission sets are read-only with storage.type: config.');
    }
}

<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\PermissionSet;

/**
 * Where custom permission sets and the edits of system sets live (`storage.type`: `config` = read-only, `doctrine` =
 * the bundle entity). Set `storage.type: service` with `storage.service` to plug your own.
 */
interface PermissionSetStorageInterface
{
    /**
     * Stored rows, ordered by code, at most `$limit`.
     *
     * @return list<StoredPermissionSet>
     */
    public function load(int $limit): array;

    /**
     * Whether {@see self::save()} and {@see self::delete()} are available (the admin UI mutates only then).
     */
    public function isWritable(): bool;

    /**
     * Number of stored rows.
     */
    public function count(): int;

    /**
     * Inserts or replaces the row of `$set->code`.
     */
    public function save(StoredPermissionSet $set): void;

    /**
     * Deletes the row of a code (no-op when missing).
     */
    public function delete(string $code): void;
}

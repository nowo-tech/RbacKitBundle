<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\PermissionSet;

/**
 * A storage that writes only when the row is still what the manager read (1.3, REQ-RUNTIME-003 rule 4). The manager
 * uses these two methods instead of {@see PermissionSetStorageInterface::save()} when the storage implements them:
 *
 * - a create cannot silently replace a set another manager created with the same code at the same time;
 * - an edit cannot silently undo a newer edit (a revoked permission granted again by a stale form) nor re-create a
 *   set another manager deleted.
 *
 * {@see DoctrinePermissionSetStorage} implements it (unique code + row lock). A storage that does not keeps the 1.2
 * behaviour: the manager then only compares the revision it read in the same call (see docs/CONCURRENCY.md).
 */
interface ConditionalPermissionSetStorageInterface extends PermissionSetStorageInterface
{
    /**
     * Inserts the row of `$set->code`; false, with nothing written, when a row of that code already exists.
     */
    public function insert(StoredPermissionSet $set): bool;

    /**
     * Replaces the row of `$set->code` only while its {@see StoredPermissionSet::revision()} is `$expectedRevision`;
     * with `$expectedRevision` null, only while no row of that code exists (the row is then inserted). False, with
     * nothing written, otherwise (the row changed, was deleted, or was created meanwhile).
     */
    public function replace(StoredPermissionSet $set, ?string $expectedRevision): bool;
}

<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\PermissionSet;

/**
 * Write port of the editable permission sets (1.1, rule 1): codes, names and permission keys; `$actor` is the
 * optional security user stored as its identifier. Each write dispatches a `PermissionSetChangedEvent`.
 *
 * Implemented by {@see PermissionSetManager} (autowire this interface).
 */
interface PermissionSetManagerInterface
{
    /**
     * Creates a custom set.
     *
     * @param list<string> $permissions catalogued keys
     *
     * @throws PermissionSetException
     */
    public function create(string $code, string $name, ?string $description, array $permissions, ?object $actor = null): PermissionSet;

    /**
     * Edits a set (name, description, permissions).
     *
     * @param list<string> $permissions catalogued keys
     *
     * @throws PermissionSetException
     */
    public function update(string $code, string $name, ?string $description, array $permissions, ?object $actor = null): PermissionSet;

    /**
     * Deletes a custom set.
     *
     * @throws PermissionSetException
     */
    public function delete(string $code, ?object $actor = null): void;

    /**
     * Whether custom sets can be deleted (a usage provider tells whether they are still assigned).
     */
    public function canDelete(): bool;
}

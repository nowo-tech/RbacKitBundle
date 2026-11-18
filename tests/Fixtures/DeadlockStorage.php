<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Fixtures;

use Doctrine\DBAL\Driver\AbstractException;
use Doctrine\DBAL\Exception\DeadlockException;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetStorageInterface;
use Nowo\RbacKitBundle\PermissionSet\StoredPermissionSet;

/**
 * Writable storage whose writes always lose a deadlock (MySQL 1213), for the conflict answer of the admin pages.
 */
final class DeadlockStorage implements PermissionSetStorageInterface
{
    public function load(int $limit): array
    {
        return [];
    }

    public function isWritable(): bool
    {
        return true;
    }

    public function count(): int
    {
        return 0;
    }

    public function save(StoredPermissionSet $set): void
    {
        throw $this->deadlock();
    }

    public function delete(string $code): void
    {
        throw $this->deadlock();
    }

    private function deadlock(): DeadlockException
    {
        return new DeadlockException(new class('Deadlock found when trying to get lock', '40001', 1213) extends AbstractException {
        }, null);
    }
}

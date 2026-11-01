<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\PermissionSet;

use DateTimeImmutable;
use Doctrine\Persistence\ManagerRegistry;
use LogicException;
use Nowo\RbacKitBundle\PermissionSet\ConfigPermissionSetStorage;
use Nowo\RbacKitBundle\PermissionSet\DoctrinePermissionSetStorage;
use Nowo\RbacKitBundle\PermissionSet\StoredPermissionSet;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class StorageTest extends TestCase
{
    #[Test]
    public function aStoredSetRoundTripsThroughItsCacheShape(): void
    {
        $set  = new StoredPermissionSet('custom', 'Custom', 'Text', false, ['a.b'], 'admin', new DateTimeImmutable('2026-10-01T10:00:00+00:00'));
        $copy = StoredPermissionSet::fromArray($set->toArray());

        self::assertEquals($set, $copy);
        self::assertSame('2026-10-01T10:00:00+00:00', $set->toArray()['updated_at']);

        $bare = StoredPermissionSet::fromArray(['code' => 'c', 'name' => 'n', 'system' => true, 'permissions' => ['a.b', 3], 'description' => 5, 'updated_by' => [], 'updated_at' => 'not a date']);
        self::assertNotNull($bare);
        self::assertSame(['a.b'], $bare->permissions);
        self::assertNull($bare->description);
        self::assertNull($bare->updatedBy);
        self::assertNull($bare->updatedAt);
        self::assertNull($bare->toArray()['updated_at']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidRows(): iterable
    {
        yield 'not an array' => ['row'];
        yield 'no code' => [['name' => 'n', 'system' => false, 'permissions' => []]];
        yield 'no name' => [['code' => 'c', 'system' => false, 'permissions' => []]];
        yield 'no system flag' => [['code' => 'c', 'name' => 'n', 'permissions' => []]];
        yield 'no permissions' => [['code' => 'c', 'name' => 'n', 'system' => false]];
    }

    #[Test]
    #[DataProvider('invalidRows')]
    public function invalidCacheRowsAreDropped(mixed $row): void
    {
        self::assertNull(StoredPermissionSet::fromArray($row));
    }

    #[Test]
    public function theConfigStorageIsReadOnly(): void
    {
        $storage = new ConfigPermissionSetStorage();
        self::assertSame([], $storage->load(10));
        self::assertFalse($storage->isWritable());
        self::assertSame(0, $storage->count());

        try {
            $storage->save(new StoredPermissionSet('c', 'n', null, false, []));
            self::fail('read-only');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->expectException(LogicException::class);
        $storage->delete('c');
    }

    #[Test]
    public function theDoctrineStorageNeedsAnEntityManager(): void
    {
        $doctrine = $this->createStub(ManagerRegistry::class);
        $doctrine->method('getManagerForClass')->willReturn(null);
        $storage = new DoctrinePermissionSetStorage($doctrine);
        self::assertTrue($storage->isWritable());

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('no Doctrine ORM entity manager');
        $storage->count();
    }
}

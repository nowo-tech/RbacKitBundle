<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\PermissionSet;

use DateTimeImmutable;
use Nowo\RbacKitBundle\Event\PermissionSetChangedEvent;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetException;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetManager;
use Nowo\RbacKitBundle\PermissionSet\StoredPermissionSet;
use Nowo\RbacKitBundle\Tests\Fixtures\EventRecorder;
use Nowo\RbacKitBundle\Tests\Fixtures\StaticUsage;
use Nowo\RbacKitBundle\Tests\Support\Catalogues;
use Nowo\RbacKitBundle\Tests\Support\MemoryStorage;
use Nowo\RbacKitBundle\Tests\Support\RacingStorage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use stdClass;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_keys;
use function str_repeat;

final class PermissionSetManagerTest extends TestCase
{
    private MemoryStorage $storage;

    private EventRecorder $events;

    protected function setUp(): void
    {
        $this->storage = new MemoryStorage([new StoredPermissionSet('in_use', 'Used', null, false, ['summary.view'])]);
        $this->events  = new EventRecorder();
    }

    #[Test]
    public function aCustomSetIsCreatedInCatalogueOrderAndAudited(): void
    {
        $set = $this->manager()->create('  Reporting ', ' Reporting ', '  ', ['project.view', 'reports.export'], new InMemoryUser('admin', null));

        self::assertSame('reporting', $set->code);
        self::assertSame('Reporting', $set->name);
        self::assertNull($set->description);
        self::assertSame(['reports.export', 'project.view'], $set->permissions);
        self::assertSame('admin', $this->storage->rows['reporting']->updatedBy);
        self::assertSame('2026-11-01', $this->storage->rows['reporting']->updatedAt?->format('Y-m-d'));

        $event = $this->events->events[0];
        self::assertInstanceOf(PermissionSetChangedEvent::class, $event);
        self::assertSame(PermissionSetChangedEvent::CREATED, $event->action);
        self::assertSame('reporting', $event->code);
        self::assertSame(['name' => 'Reporting', 'permissions' => 2, 'added' => ['reports.export', 'project.view'], 'removed' => []], $event->context);
        self::assertInstanceOf(InMemoryUser::class, $event->user);
    }

    #[Test]
    public function aSystemSetEditStoresARowAndReportsTheDifference(): void
    {
        $set = $this->manager()->update('viewer', 'Readers', 'Read only', ['summary.view', 'reports.export'], new stdClass());

        self::assertTrue($set->system);
        self::assertTrue($set->stored);
        self::assertSame(['reports.export', 'summary.view'], $set->permissions);
        self::assertTrue($this->storage->rows['viewer']->system);
        self::assertNull($this->storage->rows['viewer']->updatedBy, 'only Symfony users are recorded');

        $event = $this->events->events[0];
        self::assertInstanceOf(PermissionSetChangedEvent::class, $event);
        self::assertSame(PermissionSetChangedEvent::UPDATED, $event->action);
        self::assertSame(['reports.export'], $event->context['added']);
        self::assertSame(['project.view'], $event->context['removed']);
    }

    #[Test]
    public function aCustomSetIsDeletedAndAudited(): void
    {
        $this->storage->save(new StoredPermissionSet('temp', 'Temp', null, false, ['summary.view']));
        $this->manager()->delete('temp');

        self::assertArrayNotHasKey('temp', $this->storage->rows);
        $event = $this->events->events[0];
        self::assertInstanceOf(PermissionSetChangedEvent::class, $event);
        self::assertSame(PermissionSetChangedEvent::DELETED, $event->action);
        self::assertSame(['name' => 'Temp', 'permissions' => 1], $event->context);
    }

    /**
     * @return iterable<string, array{string, callable(PermissionSetManager): mixed}>
     */
    public static function refusals(): iterable
    {
        yield 'invalid code' => [PermissionSetException::INVALID_CODE, static fn (PermissionSetManager $m): mixed => $m->create('9lives', 'Name', null, [])];
        yield 'code with a slash' => [PermissionSetException::INVALID_CODE, static fn (PermissionSetManager $m): mixed => $m->create('a/b', 'Name', null, [])];
        yield 'code taken' => [PermissionSetException::CODE_TAKEN, static fn (PermissionSetManager $m): mixed => $m->create('viewer', 'Name', null, [])];
        yield 'unknown permission' => [PermissionSetException::UNKNOWN_PERMISSION, static fn (PermissionSetManager $m): mixed => $m->create('custom', 'Name', null, ['nope.key'])];
        yield 'empty name' => [PermissionSetException::INVALID_NAME, static fn (PermissionSetManager $m): mixed => $m->create('custom', ' ', null, [])];
        yield 'long name' => [PermissionSetException::INVALID_NAME, static fn (PermissionSetManager $m): mixed => $m->create('custom', str_repeat('n', 121), null, [])];
        yield 'long description' => [PermissionSetException::INVALID_NAME, static fn (PermissionSetManager $m): mixed => $m->create('custom', 'Name', str_repeat('d', 1001), [])];
        yield 'update unknown' => [PermissionSetException::NOT_FOUND, static fn (PermissionSetManager $m): mixed => $m->update('nope', 'Name', null, [])];
        yield 'update locked' => [PermissionSetException::LOCKED, static fn (PermissionSetManager $m): mixed => $m->update('owner', 'Name', null, [])];
        yield 'delete unknown' => [PermissionSetException::NOT_FOUND, static function (PermissionSetManager $m): mixed {
            $m->delete('nope');

            return null;
        }];
        yield 'delete locked' => [PermissionSetException::LOCKED, static function (PermissionSetManager $m): mixed {
            $m->delete('owner');

            return null;
        }];
        yield 'delete system' => [PermissionSetException::SYSTEM, static function (PermissionSetManager $m): mixed {
            $m->delete('viewer');

            return null;
        }];
        yield 'delete in use' => [PermissionSetException::IN_USE, static function (PermissionSetManager $m): mixed {
            $m->delete('in_use');

            return null;
        }];
    }

    /**
     * @param callable(PermissionSetManager): mixed $change
     */
    #[Test]
    #[DataProvider('refusals')]
    public function invalidChangesAreRefused(string $reason, callable $change): void
    {
        try {
            $change($this->manager());
            self::fail('refused');
        } catch (PermissionSetException $e) {
            self::assertSame($reason, $e->reason);
            self::assertSame('error.' . $reason, $e->getMessage());
        }
        self::assertSame([], $this->events->events, 'nothing is audited');
    }

    /**
     * Appendix CI L2: a code still held by assignments cannot be re-created, and without a usage provider nothing can
     * be deleted (the old holders of a deleted code would inherit a re-created set).
     */
    #[Test]
    public function deletedCodesAreNeverResurrected(): void
    {
        $manager = new PermissionSetManager(Catalogues::registry($this->storage), Catalogues::catalog(), $this->storage, 200, $this->events, new StaticUsage(['helpdesk' => 2]));
        self::assertTrue($manager->canDelete());
        try {
            $manager->create('helpdesk', 'Helpdesk', null, ['wiki.manage']);
            self::fail('stale assignments');
        } catch (PermissionSetException $e) {
            self::assertSame(PermissionSetException::CODE_ASSIGNED, $e->reason);
        }

        $this->storage->save(new StoredPermissionSet('temp', 'Temp', null, false, ['summary.view']));
        $blind = new PermissionSetManager(Catalogues::registry($this->storage), Catalogues::catalog(), $this->storage);
        self::assertFalse($blind->canDelete());
        try {
            $blind->delete('temp');
            self::fail('no usage provider');
        } catch (PermissionSetException $e) {
            self::assertSame(PermissionSetException::USAGE_UNKNOWN, $e->reason);
        }
        self::assertArrayHasKey('temp', $this->storage->rows);
        self::assertSame('other', $blind->create('other', 'Other', null, [])->code, 'creation without a usage provider still works');
    }

    #[Test]
    public function theStoredSetsAreBounded(): void
    {
        $manager = $this->manager(1);
        foreach ([static fn (): mixed => $manager->create('custom', 'Name', null, []), static fn (): mixed => $manager->update('viewer', 'Name', null, [])] as $change) {
            try {
                $change();
                self::fail('bounded');
            } catch (PermissionSetException $e) {
                self::assertSame(PermissionSetException::LIMIT, $e->reason);
            }
        }
        self::assertSame('Renamed', $manager->update('in_use', 'Renamed', null, [])->name, 'editing a stored row is not bounded');
    }

    /**
     * Re-verification N2: a concurrent create between the bound check and the save must not leave the table above
     * storage.max_sets (the registry would throw on every read): the new row is rolled back and the change refused.
     */
    #[Test]
    public function aConcurrentCreateCannotPushTheTablePastTheBound(): void
    {
        foreach ([static fn (PermissionSetManager $m): mixed => $m->create('custom', 'Name', null, []), static fn (PermissionSetManager $m): mixed => $m->update('viewer', 'Name', null, [])] as $change) {
            $inner    = new MemoryStorage([new StoredPermissionSet('in_use', 'Used', null, false, ['summary.view'])]);
            $storage  = new RacingStorage($inner, [new StoredPermissionSet('racer', 'Racer', null, false, [])]);
            $registry = Catalogues::registry($storage);
            $manager  = new PermissionSetManager($registry, Catalogues::catalog(), $storage, 2, $this->events, new StaticUsage());
            try {
                $change($manager);
                self::fail('the bound is enforced after the save');
            } catch (PermissionSetException $e) {
                self::assertSame(PermissionSetException::LIMIT, $e->reason);
            }
            self::assertSame(['in_use', 'racer'], array_keys($inner->rows), 'the row of this request is rolled back');
            self::assertArrayHasKey('racer', $registry->all(), 'the registry reads the table without overflowing');
        }
        self::assertSame([], $this->events->events);

        $quiet   = new RacingStorage(new MemoryStorage(), [new StoredPermissionSet('racer', 'Racer', null, false, [])]);
        $manager = new PermissionSetManager(Catalogues::registry($quiet), Catalogues::catalog(), $quiet, 2, null, new StaticUsage());
        self::assertSame('custom', $manager->create('custom', 'Name', null, [])->code, 'within the bound the race is harmless');
    }

    /**
     * Re-verification N8: the rollback removes only the row this request wrote, never another manager's same-code write.
     */
    #[Test]
    public function theRollbackKeepsAnotherManagersSameCodeRow(): void
    {
        $inner   = new MemoryStorage([new StoredPermissionSet('in_use', 'Used', null, false, ['summary.view'])]);
        $theirs  = new StoredPermissionSet('custom', 'Theirs', null, false, ['wiki.manage'], 'other-admin');
        $storage = new RacingStorage($inner, [new StoredPermissionSet('racer', 'Racer', null, false, [])], [$theirs]);
        $manager = new PermissionSetManager(Catalogues::registry($storage), Catalogues::catalog(), $storage, 2, null, new StaticUsage());
        try {
            $manager->create('custom', 'Mine', null, ['summary.view'], new InMemoryUser('admin', null));
            self::fail('over the bound');
        } catch (PermissionSetException $e) {
            self::assertSame(PermissionSetException::LIMIT, $e->reason);
        }
        self::assertSame($theirs, $inner->rows['custom'], "another manager's committed row is kept");
    }

    #[Test]
    public function aReadOnlyStorageRefusesEveryChange(): void
    {
        $manager = new PermissionSetManager(Catalogues::registry(new MemoryStorage([], false)), Catalogues::catalog(), new MemoryStorage([], false));
        foreach ([static fn (): mixed => $manager->create('custom', 'Name', null, []), static fn (): mixed => $manager->update('viewer', 'Name', null, []), static function () use ($manager): void {
            $manager->delete('viewer');
        }] as $change) {
            try {
                $change();
                self::fail('read-only');
            } catch (PermissionSetException $e) {
                self::assertSame(PermissionSetException::READ_ONLY, $e->reason);
            }
        }
    }

    private function manager(int $maxSets = 200): PermissionSetManager
    {
        $clock = new class implements ClockInterface {
            public function now(): DateTimeImmutable
            {
                return new DateTimeImmutable('2026-11-01T09:00:00+00:00');
            }
        };

        return new PermissionSetManager(Catalogues::registry($this->storage), Catalogues::catalog(), $this->storage, $maxSets, $this->events, new StaticUsage(), $clock);
    }
}

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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use stdClass;
use Symfony\Component\Security\Core\User\InMemoryUser;

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

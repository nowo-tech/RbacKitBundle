<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\PermissionSet;

use DateTimeImmutable;
use Nowo\RbacKitBundle\Event\PermissionSetChangedEvent;
use Nowo\RbacKitBundle\PermissionSet\PermissionSet;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetException;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetManager;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetRegistry;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetStorageInterface;
use Nowo\RbacKitBundle\PermissionSet\StoredPermissionSet;
use Nowo\RbacKitBundle\Tests\Fixtures\EventRecorder;
use Nowo\RbacKitBundle\Tests\Support\Catalogues;
use Nowo\RbacKitBundle\Tests\Support\ConditionalMemoryStorage;
use Nowo\RbacKitBundle\Tests\Support\MemoryStorage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function strlen;

/**
 * 1.3 (REQ-RUNTIME-003 rule 4): creates decided by the unique code, edits by the revision the editor saw. The
 * concurrent request is played by {@see ConditionalMemoryStorage::$beforeWrite}, between this request's read and its
 * write; the real two-connection races run in PermissionSetRaceDbTest.
 */
final class PermissionSetManagerRevisionTest extends TestCase
{
    private EventRecorder $events;

    protected function setUp(): void
    {
        $this->events = new EventRecorder();
    }

    #[Test]
    public function aConcurrentCreateOfTheSameCodeIsNeverOverwritten(): void
    {
        $storage              = new ConditionalMemoryStorage();
        $theirs               = new StoredPermissionSet('reporting', 'Theirs', null, false, ['summary.view'], 'bob');
        $storage->beforeWrite = static function (MemoryStorage $rows) use ($theirs): void {
            $rows->save($theirs);
        };
        [$manager, $registry] = $this->manager($storage);

        try {
            $manager->create('reporting', 'Mine', null, ['reports.export']);
            self::fail('The second create must be refused.');
        } catch (PermissionSetException $e) {
            self::assertSame(PermissionSetException::CODE_TAKEN, $e->reason);
        }

        self::assertSame($theirs, $storage->inner->rows['reporting'], 'the first create keeps its row');
        self::assertSame(['summary.view'], $registry->find('reporting')?->permissions, 'the registry was invalidated');
        self::assertSame([], $this->events->events, 'no CREATED event for a create that did not happen');
    }

    #[Test]
    public function aCreateOnAFreeCodeInsertsTheRow(): void
    {
        $storage   = new ConditionalMemoryStorage();
        [$manager] = $this->manager($storage);

        $set = $manager->create('reporting', 'Reporting', null, ['reports.export']);

        self::assertSame(['reports.export'], $set->permissions);
        self::assertSame($storage->inner->rows['reporting']->revision(), $set->revision);
    }

    #[Test]
    public function anEditFromTheCurrentRevisionIsSavedAndGetsANewRevision(): void
    {
        $storage              = new ConditionalMemoryStorage([new StoredPermissionSet('reporting', 'Reporting', null, false, ['reports.export', 'summary.view'])]);
        [$manager, $registry] = $this->manager($storage);
        $before               = $registry->find('reporting')?->revision;
        self::assertIsString($before);

        $set = $manager->updateRevision('reporting', $before, 'Reporting', null, ['reports.export']);

        self::assertSame(['reports.export'], $set->permissions);
        self::assertNotSame($before, $set->revision);
        self::assertSame(['summary.view'], $this->event()->context['removed']);
    }

    #[Test]
    public function aStaleFormCannotGrantARevokedPermissionAgain(): void
    {
        $storage              = new ConditionalMemoryStorage([new StoredPermissionSet('reporting', 'Reporting', null, false, ['reports.export', 'summary.view'])]);
        [$manager, $registry] = $this->manager($storage);
        $seen                 = $registry->find('reporting')?->revision;
        self::assertIsString($seen);
        // Another manager revokes summary.view after this form was loaded.
        $manager->updateRevision('reporting', $seen, 'Reporting', null, ['reports.export']);
        $this->events->events = [];

        try {
            $manager->updateRevision('reporting', $seen, 'Renamed', null, ['reports.export', 'summary.view']);
            self::fail('The stale form must be refused.');
        } catch (PermissionSetException $e) {
            self::assertSame(PermissionSetException::STALE, $e->reason);
        }

        self::assertSame(['reports.export'], $storage->inner->rows['reporting']->permissions, 'the revocation stays');
        self::assertSame([], $this->events->events);
    }

    #[Test]
    public function aChangeBetweenTheReadAndTheWriteIsRefused(): void
    {
        $storage              = new ConditionalMemoryStorage([new StoredPermissionSet('reporting', 'Reporting', null, false, ['reports.export', 'summary.view'])]);
        $theirs               = new StoredPermissionSet('reporting', 'Reporting', null, false, ['reports.export'], 'bob');
        $storage->beforeWrite = static function (MemoryStorage $rows) use ($theirs): void {
            $rows->save($theirs);
        };
        [$manager] = $this->manager($storage);

        try {
            // null: the revision this call reads, before the concurrent write lands.
            $manager->update('reporting', 'Mine', null, ['reports.export', 'summary.view']);
            self::fail('The lost update must be refused.');
        } catch (PermissionSetException $e) {
            self::assertSame(PermissionSetException::STALE, $e->reason);
        }

        self::assertSame($theirs, $storage->inner->rows['reporting']);
        self::assertSame([], $this->events->events);
    }

    #[Test]
    public function aSetDeletedMeanwhileIsNeverCreatedAgain(): void
    {
        $storage              = new ConditionalMemoryStorage([new StoredPermissionSet('reporting', 'Reporting', null, false, ['reports.export'])]);
        $storage->beforeWrite = static function (MemoryStorage $rows): void {
            $rows->delete('reporting');
        };
        [$manager] = $this->manager($storage);

        try {
            $manager->update('reporting', 'Reporting', null, ['reports.export', 'summary.view']);
            self::fail('The edit of a deleted set must be refused.');
        } catch (PermissionSetException $e) {
            self::assertSame(PermissionSetException::NOT_FOUND, $e->reason);
        }

        self::assertSame([], $storage->inner->rows, 'no resurrection');
    }

    #[Test]
    public function twoFirstEditsOfASystemSetDoNotOverwriteEachOther(): void
    {
        $storage              = new ConditionalMemoryStorage();
        $theirs               = new StoredPermissionSet('viewer', 'Viewer', null, true, ['summary.view'], 'bob');
        $storage->beforeWrite = static function (MemoryStorage $rows) use ($theirs): void {
            $rows->save($theirs);
        };
        [$manager, $registry] = $this->manager($storage);
        self::assertNull($registry->find('viewer')?->revision, 'an unstored system set has no revision');

        try {
            $manager->updateRevision('viewer', PermissionSet::UNSTORED, 'Viewer', null, ['summary.view', 'project.view', 'reports.export']);
            self::fail('The second first edit must be refused.');
        } catch (PermissionSetException $e) {
            self::assertSame(PermissionSetException::STALE, $e->reason);
        }

        self::assertSame($theirs, $storage->inner->rows['viewer']);
    }

    #[Test]
    public function theUnstoredMarkerIsStaleOnceAnEditExists(): void
    {
        $storage   = new ConditionalMemoryStorage([new StoredPermissionSet('viewer', 'Viewer', null, true, ['summary.view'])]);
        [$manager] = $this->manager($storage);

        $this->expectExceptionObject(PermissionSetException::of(PermissionSetException::STALE));
        $manager->updateRevision('viewer', PermissionSet::UNSTORED, 'Viewer', null, ['project.view']);
    }

    #[Test]
    public function theUnstoredMarkerSavesTheFirstEditOfASystemSet(): void
    {
        $storage   = new ConditionalMemoryStorage();
        [$manager] = $this->manager($storage);

        $set = $manager->updateRevision('viewer', PermissionSet::UNSTORED, 'Viewer', null, ['project.view']);

        self::assertTrue($set->stored);
        self::assertSame(['project.view'], $storage->inner->rows['viewer']->permissions);
    }

    #[Test]
    public function aPlainStorageStillComparesTheRevisionItRead(): void
    {
        $storage              = new MemoryStorage([new StoredPermissionSet('reporting', 'Reporting', null, false, ['reports.export', 'summary.view'])]);
        [$manager, $registry] = $this->manager($storage);
        $seen                 = $registry->find('reporting')?->revision;
        $storage->save(new StoredPermissionSet('reporting', 'Reporting', null, false, ['reports.export'], 'bob'));
        $registry->invalidate();

        $this->expectExceptionObject(PermissionSetException::of(PermissionSetException::STALE));
        $manager->updateRevision('reporting', $seen, 'Reporting', null, ['reports.export', 'summary.view']);
    }

    #[Test]
    public function theRevisionChangesWithEveryStoredFieldAndSurvivesTheCache(): void
    {
        $at       = new DateTimeImmutable('2026-11-18T10:00:00.654321+01:00');
        $base     = new StoredPermissionSet('ops', 'Ops', 'Team', false, ['a.view'], 'alice', $at);
        $variants = [
            new StoredPermissionSet('ops', 'Ops 2', 'Team', false, ['a.view'], 'alice', $at),
            new StoredPermissionSet('ops', 'Ops', null, false, ['a.view'], 'alice', $at),
            new StoredPermissionSet('ops', 'Ops', 'Team', true, ['a.view'], 'alice', $at),
            new StoredPermissionSet('ops', 'Ops', 'Team', false, ['a.view', 'b.edit'], 'alice', $at),
            new StoredPermissionSet('ops', 'Ops', 'Team', false, ['a.view'], 'bob', $at),
            new StoredPermissionSet('ops', 'Ops', 'Team', false, ['a.view'], 'alice', $at->modify('+1 second')),
            new StoredPermissionSet('ops', 'Ops', 'Team', false, ['a.view'], 'alice'),
        ];
        foreach ($variants as $variant) {
            self::assertNotSame($base->revision(), $variant->revision());
        }

        self::assertSame($base->revision(), StoredPermissionSet::fromArray($base->toArray())?->revision(), 'same value from the cache (second precision)');
        self::assertSame(64, strlen($base->revision()));
    }

    /**
     * @return array{PermissionSetManager, PermissionSetRegistry}
     */
    private function manager(PermissionSetStorageInterface $storage): array
    {
        $registry = Catalogues::registry($storage);

        return [new PermissionSetManager($registry, Catalogues::catalog(), $storage, 200, $this->events), $registry];
    }

    private function event(): PermissionSetChangedEvent
    {
        $event = $this->events->events[0] ?? null;
        self::assertInstanceOf(PermissionSetChangedEvent::class, $event);

        return $event;
    }
}

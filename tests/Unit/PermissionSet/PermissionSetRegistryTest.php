<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\PermissionSet;

use DateTimeImmutable;
use Nowo\RbacKitBundle\PermissionSet\ConfigPermissionSetStorage;
use Nowo\RbacKitBundle\PermissionSet\PermissionSet;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetRegistry;
use Nowo\RbacKitBundle\PermissionSet\StoredPermissionSet;
use Nowo\RbacKitBundle\Tests\Support\Catalogues;
use Nowo\RbacKitBundle\Tests\Support\MemoryStorage;
use OverflowException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;
use Psr\Log\AbstractLogger;
use Stringable;
use Symfony\Component\Cache\Adapter\ArrayAdapter;

use function array_keys;

final class PermissionSetRegistryTest extends TestCase
{
    #[Test]
    public function systemSetsComeFirstThenCustomSetsByName(): void
    {
        $at      = new DateTimeImmutable('2026-10-01T10:00:00+00:00');
        $storage = new MemoryStorage([
            new StoredPermissionSet('zeta', 'Alpha', null, false, ['summary.view'], 'admin', $at),
            new StoredPermissionSet('beta', 'Beta', 'Custom', false, ['project.view', 'summary.view']),
            new StoredPermissionSet('alpha', 'Beta', null, false, []),
            new StoredPermissionSet('viewer', 'Readers', 'Edited', true, ['project.view']),
            new StoredPermissionSet('owner', 'Ignored', null, true, []),
        ]);
        $registry = Catalogues::registry($storage);
        $sets     = $registry->all();

        self::assertSame(['viewer', 'triage', 'owner', 'auditor', 'zeta', 'alpha', 'beta'], array_keys($sets));
        self::assertSame($sets, $registry->all(), 'memoised');

        $viewer = $sets['viewer'];
        self::assertTrue($viewer->system);
        self::assertTrue($viewer->stored);
        self::assertSame('Readers', $viewer->name);
        self::assertSame('Edited', $viewer->description);
        self::assertSame(['project.view'], $viewer->permissions, 'the stored edit replaces the configured permissions');

        $owner = $sets['owner'];
        self::assertTrue($owner->locked);
        self::assertFalse($owner->stored, 'a locked set ignores its stored row');
        self::assertNull($owner->name);
        self::assertSame('permission_set.owner.label', $owner->labelKey());
        self::assertSame(['project.issues.triage', 'project.settings.view', 'project.view'], $owner->permissions, 'H1: all = every context key (minus except), catalogue order');

        self::assertSame(['summary.view', 'project.view'], $sets['beta']->permissions, 'catalogue order');
        self::assertSame('admin', $sets['zeta']->updatedBy);
        self::assertSame($at, $sets['zeta']->updatedAt);
        self::assertFalse($sets['zeta']->system);

        self::assertInstanceOf(PermissionSet::class, $registry->find('triage'));
        self::assertNull($registry->find('nope'));
        self::assertSame(['project.view' => true], $registry->keysOf('viewer'));
        self::assertSame([], $registry->keysOf('nope'), 'an unknown set grants nothing');
        self::assertSame(5, $registry->storedCount());
        self::assertTrue($registry->isWritable());
    }

    #[Test]
    public function unknownKeysGrantNothingAndAreLogged(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{string, array<array-key, mixed>}> */
            public array $records = [];

            public function log($level, string|Stringable $message, array $context = []): void
            {
                $this->records[] = [(string) $message, $context];
            }
        };
        $registry = new PermissionSetRegistry(Catalogues::catalog(), new MemoryStorage([new StoredPermissionSet('custom', 'Custom', null, false, ['gone.key', 'summary.view'])]), [
            'viewer' => ['label' => 'Viewers', 'description' => null, 'permissions' => ['summary.view', 'removed.key'], 'all' => false, 'include_global' => false, 'except' => [], 'locked' => false],
        ], null, 200, $logger);

        self::assertSame(['summary.view'], $registry->find('viewer')?->permissions);
        self::assertSame('Viewers', $registry->find('viewer')->labelKey());
        self::assertSame(['summary.view'], $registry->find('custom')?->permissions);
        self::assertCount(1, $logger->records);
        self::assertSame(['viewer' => 'removed.key', 'custom' => 'gone.key'], $logger->records[0][1]['sets']);
    }

    #[Test]
    public function storedRowsAreCachedUntilInvalidated(): void
    {
        $storage  = new MemoryStorage([new StoredPermissionSet('custom', 'Custom', null, false, ['summary.view'], 'admin', new DateTimeImmutable('2026-10-01'))]);
        $cache    = new ArrayAdapter();
        $registry = new PermissionSetRegistry(Catalogues::catalog(), $storage, Catalogues::sets(), $cache);

        self::assertSame('admin', $registry->find('custom')?->updatedBy);
        self::assertSame('2026-10-01', $registry->find('custom')->updatedAt?->format('Y-m-d'));
        $registry->reset();
        self::assertNotNull($registry->find('custom'));
        self::assertSame(1, $storage->loads, 'the second request reads the cache');

        $storage->delete('custom');
        $registry->invalidate();
        self::assertNull($registry->find('custom'));
        self::assertNull($registry->find('custom'));
        self::assertSame(3, $storage->loads, 'after a write: read from the storage, not memoised (LH-RBAC-L1)');
        self::assertSame([], $registry->keysOf('custom'));
        self::assertFalse($cache->hasItem(PermissionSetRegistry::CACHE_KEY), 'and not written to the cache');

        $storage->save(new StoredPermissionSet('custom', 'Custom', null, false, ['summary.view']));
        $registry->invalidate();
        $cache->get(PermissionSetRegistry::CACHE_KEY, static fn (): string => 'stale');
        $registry->reset(); // end of the unit: the cache entry is dropped once more
        self::assertFalse($cache->hasItem(PermissionSetRegistry::CACHE_KEY));
        self::assertNotNull($registry->find('custom'));

        $registry->reset();
        $cache->delete(PermissionSetRegistry::CACHE_KEY);
        $cache->get(PermissionSetRegistry::CACHE_KEY, static fn (): string => 'corrupted');
        self::assertNull($registry->find('custom'), 'a corrupted entry grants nothing');
    }

    #[Test]
    public function anAllSetIncludesGlobalKeysOnlyWhenAskedTo(): void
    {
        $registry = new PermissionSetRegistry(Catalogues::catalog(), new MemoryStorage(), ['root' => Catalogues::set([], true, ['wiki.manage'], true, true)]);
        self::assertSame(['reports.export', 'summary.view', 'project.issues.triage', 'project.settings.view', 'project.view'], $registry->find('root')?->permissions);
    }

    /**
     * Appendix CI L3: the cache entry expires after storage.cache_ttl, so a per-host pool sees a revocation.
     */
    #[Test]
    public function theCacheEntryExpires(): void
    {
        $clock = new class implements ClockInterface {
            public DateTimeImmutable $now;

            public function __construct()
            {
                $this->now = new DateTimeImmutable('2026-11-01T10:00:00+00:00');
            }

            public function now(): DateTimeImmutable
            {
                return $this->now;
            }
        };
        $storage  = new MemoryStorage([new StoredPermissionSet('viewer', 'Viewer', null, true, ['summary.view', 'project.view'])]);
        $registry = new PermissionSetRegistry(Catalogues::catalog(), $storage, Catalogues::sets(), new ArrayAdapter(clock: $clock), 200, null, 60);
        self::assertSame(['summary.view', 'project.view'], $registry->find('viewer')?->permissions);

        $storage->save(new StoredPermissionSet('viewer', 'Viewer', null, true, ['project.view'])); // another host
        $registry->reset();
        self::assertSame(['summary.view', 'project.view'], $registry->find('viewer')?->permissions, 'still cached');

        $clock->now = $clock->now->modify('+61 seconds');
        $registry->reset();
        self::assertSame(['project.view'], $registry->find('viewer')?->permissions, 'the revocation is seen after the TTL');
    }

    /**
     * Appendix CI L4: a storage holding more rows than storage.max_sets is an error, never a silent drop that would
     * restore a narrowed system set.
     */
    #[Test]
    public function rowsBeyondMaxSetsAreRefused(): void
    {
        $logger = new class extends AbstractLogger {
            public int $critical = 0;

            public function log($level, string|Stringable $message, array $context = []): void
            {
                ++$this->critical;
            }
        };
        $storage = new MemoryStorage([
            new StoredPermissionSet('aa', 'A', null, false, []),
            new StoredPermissionSet('ab', 'B', null, false, []),
            new StoredPermissionSet('viewer', 'Narrowed', null, true, ['project.view']),
        ]);
        self::assertSame(['project.view'], (new PermissionSetRegistry(Catalogues::catalog(), $storage, Catalogues::sets(), null, 3))->find('viewer')?->permissions, 'exactly max_sets rows');

        $registry = new PermissionSetRegistry(Catalogues::catalog(), $storage, Catalogues::sets(), new ArrayAdapter(), 2, $logger);
        try {
            $registry->find('viewer');
            self::fail('overflow');
        } catch (OverflowException $e) {
            self::assertStringContainsString('storage.max_sets (2)', $e->getMessage());
        }
        self::assertSame(1, $logger->critical);
    }

    #[Test]
    public function aReadOnlyStorageHasOnlyTheSystemSets(): void
    {
        $registry = Catalogues::registry(new ConfigPermissionSetStorage());
        self::assertSame(['viewer', 'triage', 'owner', 'auditor'], array_keys($registry->all()));
        self::assertFalse($registry->isWritable());
        self::assertSame(0, $registry->storedCount());
    }
}

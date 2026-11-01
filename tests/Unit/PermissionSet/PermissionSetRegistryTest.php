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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
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
        self::assertSame(['reports.export', 'summary.view', 'project.issues.triage', 'project.settings.view', 'project.view'], $owner->permissions, 'all minus except, catalogue order');

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
            'viewer' => ['label' => 'Viewers', 'description' => null, 'permissions' => ['summary.view', 'removed.key'], 'all' => false, 'except' => [], 'locked' => false],
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
        self::assertSame(2, $storage->loads);

        $storage->save(new StoredPermissionSet('custom', 'Custom', null, false, ['summary.view']));
        $registry->invalidate();
        $cache->get(PermissionSetRegistry::CACHE_KEY, static fn (): string => 'corrupted');
        self::assertNull($registry->find('custom'), 'a corrupted entry grants nothing');
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

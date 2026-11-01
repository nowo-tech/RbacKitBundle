<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\Permission;

use LogicException;
use Nowo\RbacKitBundle\Permission\Permission;
use Nowo\RbacKitBundle\Permission\PermissionCatalog;
use Nowo\RbacKitBundle\Permission\PermissionProviderInterface;
use Nowo\RbacKitBundle\Permission\PermissionScope;
use Nowo\RbacKitBundle\Tests\Support\Catalogues;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_values;

final class PermissionCatalogTest extends TestCase
{
    #[Test]
    public function theCatalogueMergesItsProvidersGlobalFirst(): void
    {
        $catalog = new PermissionCatalog([
            $this->provider(...array_values(Catalogues::catalog()->all())),
            $this->provider(new Permission('zz.extra'), new Permission('summary.view', PermissionScope::Global, ['dashboard_summary'], 'general')),
        ], ['product.summary' => 'summary.view']);

        self::assertSame(['reports.export', 'summary.view', 'wiki.manage', 'zz.extra', 'project.issues.triage', 'project.settings.view', 'project.view'], array_keys($catalog->all()));
        self::assertFalse($catalog->isEmpty());
        self::assertSame('project.view', $catalog->forRoute('project_show')?->key);
        self::assertSame('project.settings.view', $catalog->forRoute('project_settings_data')?->key);
        self::assertNull($catalog->forRoute('nope'));
        self::assertSame('summary.view', $catalog->forAlias('product.summary')?->key);
        self::assertNull($catalog->forAlias('nope'));
        self::assertNull($catalog->find('nope'));
        self::assertSame($catalog->all(), $catalog->all(), 'memoised');
    }

    #[Test]
    public function providersAreReadAgainAfterAReset(): void
    {
        $provider = new class implements PermissionProviderInterface {
            public int $calls = 0;

            public function permissions(): iterable
            {
                ++$this->calls;

                return [new Permission('a.b', PermissionScope::Global, ['r' . $this->calls])];
            }
        };
        $catalog = new PermissionCatalog([$provider]);
        self::assertSame('a.b', $catalog->forRoute('r1')?->key);
        $catalog->find('a.b');
        self::assertSame(1, $provider->calls);

        $catalog->reset();
        self::assertNull($catalog->forRoute('r1'));
        self::assertSame('a.b', $catalog->forRoute('r2')?->key);
        self::assertSame(2, $provider->calls);
    }

    #[Test]
    public function anEmptyCatalogueIsEmpty(): void
    {
        self::assertTrue((new PermissionCatalog([]))->isEmpty());
    }

    #[Test]
    public function aRedefinitionFails(): void
    {
        $catalog = new PermissionCatalog([$this->provider(new Permission('a.b')), $this->provider(new Permission('a.b', PermissionScope::Context))]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('defined twice');
        $catalog->all();
    }

    #[Test]
    public function aRouteGuardedTwiceFails(): void
    {
        $catalog = new PermissionCatalog([$this->provider(new Permission('a.b', routes: ['r']), new Permission('a.c', routes: ['r']))]);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('guarded by both');
        $catalog->isEmpty();
    }

    #[Test]
    public function anAliasToAnUnknownKeyFails(): void
    {
        $catalog = new PermissionCatalog([$this->provider(new Permission('a.b'))], ['area' => 'a.c']);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('unknown permission');
        $catalog->forAlias('area');
    }

    #[Test]
    public function theCatalogueIsBounded(): void
    {
        $catalog = new PermissionCatalog([$this->provider(new Permission('a.b'), new Permission('a.c'))], [], 1);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('more than 1 permissions');
        $catalog->all();
    }

    private function provider(Permission ...$permissions): PermissionProviderInterface
    {
        return new class($permissions) implements PermissionProviderInterface {
            /**
             * @param array<array-key, Permission> $permissions
             */
            public function __construct(private readonly array $permissions)
            {
            }

            public function permissions(): iterable
            {
                return $this->permissions;
            }
        };
    }
}

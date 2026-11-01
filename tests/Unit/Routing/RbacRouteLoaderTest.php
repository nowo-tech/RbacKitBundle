<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\Routing;

use Nowo\RbacKitBundle\Routing\RbacRouteLoader;
use Nowo\RbacKitBundle\Routing\RbacRoutes;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

use function array_keys;
use function array_values;
use function sort;

final class RbacRouteLoaderTest extends TestCase
{
    #[Test]
    public function itLoadsEveryRouteUnderThePrefix(): void
    {
        $loader = new RbacRouteLoader(true, '/ops/rbac/');
        self::assertTrue($loader->supports('.', RbacRouteLoader::TYPE));
        self::assertFalse($loader->supports('.', 'attribute'));

        $routes   = $loader->load('.', RbacRouteLoader::TYPE);
        $expected = array_values((new ReflectionClass(RbacRoutes::class))->getConstants());
        $actual   = array_keys($routes->all());
        sort($expected);
        sort($actual);
        self::assertSame($expected, $actual);
        self::assertSame('/ops/rbac', $routes->get(RbacRoutes::PERMISSION_SETS)?->getPath());
        self::assertSame('/ops/rbac/{code}/delete', $routes->get(RbacRoutes::DELETE)?->getPath());
        self::assertSame(['POST'], $routes->get(RbacRoutes::DELETE)->getMethods());
        self::assertSame('[a-z][a-z0-9_]{1,39}', $routes->get(RbacRoutes::EDIT)?->getRequirement('code'));

        $this->expectException(RuntimeException::class);
        $loader->load('.', RbacRouteLoader::TYPE);
    }

    #[Test]
    public function itLoadsNothingWhenTheUiIsOff(): void
    {
        self::assertCount(0, (new RbacRouteLoader(false, '/admin/rbac'))->load('.', RbacRouteLoader::TYPE));
    }
}

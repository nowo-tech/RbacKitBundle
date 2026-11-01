<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\Permission;

use InvalidArgumentException;
use Nowo\RbacKitBundle\Permission\ConfigPermissionProvider;
use Nowo\RbacKitBundle\Permission\Permission;
use Nowo\RbacKitBundle\Permission\PermissionScope;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function iterator_to_array;
use function str_repeat;

final class PermissionTest extends TestCase
{
    #[Test]
    public function aPermissionHasItsScopeRoutesAndTranslationKeys(): void
    {
        $permission = new Permission('project.issues.view', PermissionScope::Context, ['issues', 'issue_show', 'issues'], 'project');

        self::assertSame(['issues', 'issue_show'], $permission->routes, 'routes are de-duplicated');
        self::assertSame('permission.project.issues.view.label', $permission->labelKey());
        self::assertSame('permission.project.issues.view.description', $permission->descriptionKey());
        self::assertSame(1, $permission->scope->weight());
        self::assertSame(0, PermissionScope::Global->weight());

        $custom = new Permission('chat:use', label: 'chat.label', description: 'chat.help');
        self::assertSame('chat.label', $custom->labelKey());
        self::assertSame('chat.help', $custom->descriptionKey());
        self::assertSame(PermissionScope::Global, $custom->scope);
    }

    #[Test]
    public function equalityComparesEveryField(): void
    {
        $permission = new Permission('a.b', PermissionScope::Context, ['r']);
        self::assertTrue($permission->equals(new Permission('a.b', PermissionScope::Context, ['r'])));
        self::assertFalse($permission->equals(new Permission('a.b', PermissionScope::Global, ['r'])));
        self::assertFalse($permission->equals(new Permission('a.b', PermissionScope::Context, ['other'])));
        self::assertFalse($permission->equals(new Permission('a.b', PermissionScope::Context, ['r'], 'g')));
        self::assertFalse($permission->equals(new Permission('a.c', PermissionScope::Context, ['r'])));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'empty' => [''];
        yield 'upper case' => ['Project.view'];
        yield 'separator at the end' => ['project.'];
        yield 'slash' => ['project/view'];
        yield 'too long' => ['a' . str_repeat('b', 120)];
    }

    #[Test]
    #[DataProvider('invalidKeys')]
    public function invalidKeysAreRefused(string $key): void
    {
        self::assertFalse(Permission::isValidKey($key));
        $this->expectException(InvalidArgumentException::class);
        new Permission($key);
    }

    /**
     * @return iterable<string, array{list<mixed>}>
     */
    public static function invalidRoutes(): iterable
    {
        yield 'empty' => [['']];
        yield 'not a string' => [[42]];
        yield 'too long' => [[str_repeat('r', 201)]];
    }

    /**
     * @param list<mixed> $routes
     */
    #[Test]
    #[DataProvider('invalidRoutes')]
    public function invalidRoutesAreRefused(array $routes): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Permission('a.b', PermissionScope::Global, $routes);
    }

    #[Test]
    public function theConfigProviderYieldsTheConfiguredPermissions(): void
    {
        $permissions = iterator_to_array((new ConfigPermissionProvider([
            'project.view' => ['scope' => 'context', 'routes' => ['project_show'], 'group' => 'project', 'label' => 'l', 'description' => 'd'],
        ]))->permissions(), false);

        self::assertCount(1, $permissions);
        self::assertTrue($permissions[0]->equals(new Permission('project.view', PermissionScope::Context, ['project_show'], 'project', 'l', 'd')));
        self::assertSame([], iterator_to_array((new ConfigPermissionProvider())->permissions(), false));
    }
}

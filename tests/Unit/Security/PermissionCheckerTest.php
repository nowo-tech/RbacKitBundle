<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\Security;

use Nowo\DashboardMenuBundle\Entity\MenuItem;
use Nowo\RbacKitBundle\Menu\RbacMenuPermissionChecker;
use Nowo\RbacKitBundle\Security\PermissionChecker;
use Nowo\RbacKitBundle\Tests\Fixtures\Project;
use Nowo\RbacKitBundle\Tests\Support\Catalogues;
use Nowo\RbacKitBundle\Twig\RbacTwigExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

use function in_array;

final class PermissionCheckerTest extends TestCase
{
    #[Test]
    public function theCheckerAsksTheVoterAndFailsClosed(): void
    {
        $authorization = $this->authorization(['permission:summary.view', 'permission:project.view@billing', 'permission_route:dashboard_summary', 'ROLE_ADMIN']);
        $checker       = new PermissionChecker(Catalogues::catalog(), $authorization);

        self::assertTrue($checker->can('summary.view'));
        self::assertFalse($checker->can('reports.export'));
        self::assertFalse($checker->can('nope.key'), 'uncatalogued');
        self::assertTrue($checker->can('project.view', new Project('billing')));
        self::assertFalse($checker->can('project.view'), 'a context key without a context is refused before the voter');
        self::assertTrue($checker->canRoute('dashboard_summary'));
        self::assertFalse($checker->canRoute('other'));
        self::assertTrue($checker->canArea('product.summary'));
        self::assertFalse($checker->canArea('nope'));

        $projects = [new Project('billing'), new Project('mobile')];
        self::assertSame([$projects[0]], $checker->filter($projects, 'project.view'));

        $withoutSecurity = new PermissionChecker(Catalogues::catalog());
        self::assertFalse($withoutSecurity->can('summary.view'));
        self::assertFalse($withoutSecurity->canRoute('dashboard_summary'));
    }

    #[Test]
    public function twigFunctionsUseTheChecker(): void
    {
        $twig = new Environment(new ArrayLoader(['t' => "{{ rbac_can('summary.view') ? 'y' : 'n' }}{{ rbac_can('project.view', project) ? 'y' : 'n' }}{{ rbac_can_route('dashboard_summary') ? 'y' : 'n' }}{{ rbac_can_area('product.summary') ? 'y' : 'n' }}"]));
        $twig->addExtension(new RbacTwigExtension(new PermissionChecker(Catalogues::catalog(), $this->authorization(['permission:summary.view', 'permission_route:dashboard_summary']))));

        self::assertSame('ynyy', $twig->render('t', ['project' => new Project('billing')]));
    }

    #[Test]
    public function menuItemsNeedTheirRouteAndOneOfTheirKeys(): void
    {
        $menu = new RbacMenuPermissionChecker(new PermissionChecker(Catalogues::catalog(), $this->authorization(['permission_route:dashboard_summary', 'permission:summary.view', 'permission:project.view@billing', 'ROLE_ADMIN'])), $this->authorization(['ROLE_ADMIN']));

        self::assertTrue($menu->canView($this->item()));
        self::assertTrue($menu->canView($this->item('dashboard_summary')));
        self::assertFalse($menu->canView($this->item('other_route')));
        self::assertTrue($menu->canView($this->item(null, ['reports.export', 'summary.view'])));
        self::assertFalse($menu->canView($this->item(null, ['reports.export'])));
        self::assertTrue($menu->canView($this->item(null, ['ROLE_ADMIN'])));
        self::assertTrue($menu->canView($this->item(null, ['project.view']), new Project('billing')), 'the menu context is the voter subject');
        self::assertFalse($menu->canView($this->item(null, ['project.view']), 'not an object'));
        self::assertFalse((new RbacMenuPermissionChecker(new PermissionChecker(Catalogues::catalog())))->canView($this->item(null, ['ROLE_ADMIN'])));
    }

    /**
     * @param list<string>|null $keys
     */
    private function item(?string $route = null, ?array $keys = null): MenuItem
    {
        $item = new MenuItem();
        $item->setRouteName($route);
        $item->setPermissionKeys($keys);

        return $item;
    }

    /**
     * Grants the listed attributes; `attribute@context` grants it with that context subject only.
     *
     * @param list<string> $granted
     */
    private function authorization(array $granted): AuthorizationCheckerInterface
    {
        return new class($granted) implements AuthorizationCheckerInterface {
            /**
             * @param list<string> $granted
             */
            public function __construct(private readonly array $granted)
            {
            }

            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                $key = $attribute . ($subject instanceof Project ? '@' . $subject->id : '');

                return in_array($key, $this->granted, true);
            }
        };
    }
}

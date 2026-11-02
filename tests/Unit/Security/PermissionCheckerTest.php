<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\Security;

use Nowo\DashboardMenuBundle\Entity\MenuItem;
use Nowo\RbacKitBundle\Context\DefaultContextResolver;
use Nowo\RbacKitBundle\Grant\GrantResolver;
use Nowo\RbacKitBundle\Menu\RbacMenuPermissionChecker;
use Nowo\RbacKitBundle\Security\PermissionChecker;
use Nowo\RbacKitBundle\Security\PermissionVoter;
use Nowo\RbacKitBundle\Tests\Fixtures\MapGrantProvider;
use Nowo\RbacKitBundle\Tests\Fixtures\Project;
use Nowo\RbacKitBundle\Tests\Support\Catalogues;
use Nowo\RbacKitBundle\Twig\RbacTwigExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\User\InMemoryUser;
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

    /**
     * Re-verification N1: with the real voter, an `aggregate` key asked with a context is decided in that context, so
     * filter() and rbac_can(key, project) keep only the granting contexts; asked without a context it aggregates.
     */
    #[Test]
    public function aggregateKeysAreDecidedInTheGivenContext(): void
    {
        $projects = [new Project('billing'), new Project('mobile'), new Project('acme-hr')];

        $alice = $this->realChecker('alice');
        self::assertTrue($alice->can('summary.view'), 'the general page opens');
        self::assertTrue($alice->can('summary.view', $projects[0]));
        self::assertFalse($alice->can('summary.view', $projects[1]), 'a context that does not grant the key');
        self::assertSame([$projects[0]], $alice->filter($projects, 'summary.view'));

        $twig = new Environment(new ArrayLoader(['t' => "{% for p in projects %}{{ rbac_can('summary.view', p) ? 'y' : 'n' }}{% endfor %}{{ rbac_can('summary.view') ? 'y' : 'n' }}"]));
        $twig->addExtension(new RbacTwigExtension($alice));
        self::assertSame('ynny', $twig->render('t', ['projects' => $projects]));

        self::assertSame($projects, $this->realChecker('gwen')->filter($projects, 'summary.view'), 'a global holder sees every context');
        self::assertSame([], $this->realChecker('nobody')->filter($projects, 'summary.view'));
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

    private function realChecker(string $user): PermissionChecker
    {
        $catalog  = Catalogues::catalog();
        $registry = Catalogues::registry(null, $catalog);
        $provider = new MapGrantProvider(['alice' => [['viewer', 'billing']], 'gwen' => [['viewer', null]]]);
        $voter    = new PermissionVoter($catalog, new GrantResolver($provider, $registry), $registry, new DefaultContextResolver());
        $tokens   = new TokenStorage();
        $tokens->setToken(new UsernamePasswordToken(new InMemoryUser($user, null, ['ROLE_USER']), 'main', ['ROLE_USER']));

        return new PermissionChecker($catalog, new AuthorizationChecker($tokens, new AccessDecisionManager([$voter])));
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

<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\Coverage;

use Nowo\RbacKitBundle\Command\CheckCommand;
use Nowo\RbacKitBundle\Coverage\CoverageReport;
use Nowo\RbacKitBundle\Coverage\PermissionCoverage;
use Nowo\RbacKitBundle\Permission\PermissionCatalog;
use Nowo\RbacKitBundle\Tests\Support\Catalogues;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Routing\Generator\UrlGenerator;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Routing\RouterInterface;

final class PermissionCoverageTest extends TestCase
{
    #[Test]
    public function theReportListsWhatTheCatalogueMisses(): void
    {
        $report = $this->coverage()->report(['/dashboard', '/projects/'], ['legacy_redirect']);

        self::assertSame(['dashboard_reports /dashboard/reports', 'project_issues /{_locale}/projects/{id}/issues'], $report->unguardedRoutes);
        self::assertSame(['project.issues.triage: project_issue_triage', 'project.settings.view: project_settings_alerts', 'project.settings.view: project_settings_access', 'project.settings.view: project_settings_data'], $report->missingRoutes);
        self::assertSame(['broken' => ['gone.key', 'also.gone']], $report->unknownKeys);
        self::assertFalse($report->emptyCatalogue);
        self::assertFalse($report->isClean());
        self::assertTrue((new CoverageReport())->isClean());
    }

    #[Test]
    public function theCommandFailsUntilTheReportIsClean(): void
    {
        $tester = new CommandTester(new CheckCommand($this->coverage(), ['/dashboard']));
        self::assertSame(Command::FAILURE, $tester->execute([]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('2 route(s) without a permission', $display);
        self::assertStringContainsString('dashboard_reports', $display);
        self::assertStringContainsString('Catalogue routes that do not exist', $display);
        self::assertStringContainsString('broken: gone.key, also.gone', $display);

        $clean  = new CheckCommand(new PermissionCoverage(Catalogues::catalog(), $this->router(['dashboard_summary' => '/dashboard', 'project_show' => '/projects/{id}', 'project_issue_triage' => '/x', 'project_settings' => '/s', 'project_settings_alerts' => '/s/a', 'project_settings_access' => '/s/b', 'project_settings_data' => '/s/c'])));
        $tester = new CommandTester($clean);
        self::assertSame(Command::SUCCESS, $tester->execute(['--prefix' => ['/dashboard'], '--ignore' => ['other']]));
        self::assertStringContainsString('covers every checked route', $tester->getDisplay());

        $empty = new CommandTester(new CheckCommand(new PermissionCoverage(new PermissionCatalog([]), $this->router([]))));
        self::assertSame(Command::FAILURE, $empty->execute([]));
        self::assertStringContainsString('catalogue is empty', $empty->getDisplay());
    }

    private function coverage(): PermissionCoverage
    {
        return new PermissionCoverage(Catalogues::catalog(), $this->router([
            'dashboard_summary' => '/dashboard',
            'dashboard_reports' => '/dashboard/reports',
            'dashboard_home'    => '/dashboard/home',
            'legacy_redirect'   => '/dashboard/old',
            'project_show'      => '/{_locale}/projects/{id}',
            'project_issues'    => '/{_locale}/projects/{id}/issues',
            'project_settings'  => '/settings',
            'account'           => '/account',
            'dashboards'        => '/dashboards',
        ]), [
            'viewer' => ['permissions' => ['summary.view'], 'all' => false, 'except' => []],
            'broken' => ['permissions' => ['gone.key'], 'all' => true, 'except' => ['also.gone']],
        ], ['dashboard_home']);
    }

    /**
     * @param array<string, string> $paths
     */
    private function router(array $paths): RouterInterface
    {
        $collection = new RouteCollection();
        foreach ($paths as $name => $path) {
            $collection->add($name, new Route($path));
        }

        return new class($collection) implements RouterInterface {
            private RequestContext $context;

            public function __construct(private readonly RouteCollection $collection)
            {
                $this->context = new RequestContext();
            }

            public function getRouteCollection(): RouteCollection
            {
                return $this->collection;
            }

            public function setContext(RequestContext $context): void
            {
                $this->context = $context;
            }

            public function getContext(): RequestContext
            {
                return $this->context;
            }

            /**
             * @param array<string, mixed> $parameters
             */
            public function generate(string $name, array $parameters = [], int $referenceType = self::ABSOLUTE_PATH): string
            {
                return (new UrlGenerator($this->collection, $this->context))->generate($name, $parameters, $referenceType);
            }

            /**
             * @return array<string, mixed>
             */
            public function match(string $pathinfo): array
            {
                return (new UrlMatcher($this->collection, $this->context))->match($pathinfo);
            }
        };
    }
}

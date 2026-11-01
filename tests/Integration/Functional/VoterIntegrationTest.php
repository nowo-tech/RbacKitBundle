<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Integration\Functional;

use Nowo\RbacKitBundle\Grant\GrantProviderInterface;
use Nowo\RbacKitBundle\Tests\Fixtures\MapGrantProvider;
use Nowo\RbacKitBundle\Tests\Fixtures\RouteContextResolver;
use Nowo\RbacKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\RbacKitBundle\Tests\Integration\Kernel\RbacTestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\CommandLoader\CommandLoaderInterface;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The bundle wired in a kernel: the Symfony voter, the Twig functions, the opt-in request guard, kernel.reset (worker
 * mode) and nowo:rbac:check.
 */
final class VoterIntegrationTest extends KernelTestCase
{
    #[Test]
    public function twigAndTheVoterUseTheUsersSets(): void
    {
        $kernel = $this->bootKernel();
        $alice  = $this->client($kernel, 'alice');
        $alice->request('GET', '/');
        self::assertSame("summary:yes\nexport:no\ntriage:yes\nroute:yes\narea:yes\n", $alice->getResponse()->getContent());

        $bob = $this->client($kernel, 'bob');
        $bob->request('GET', '/');
        self::assertSame("summary:no\nexport:yes\ntriage:no\nroute:no\narea:no\n", $bob->getResponse()->getContent());

        $guest = $this->client($kernel, null);
        $guest->request('GET', '/');
        self::assertSame("summary:no\nexport:no\ntriage:no\nroute:no\narea:no\n", $guest->getResponse()->getContent());
    }

    #[Test]
    public function theRequestGuardRefusesMembersWithoutThePermission(): void
    {
        $config                  = self::defaultConfig();
        $config['request_guard'] = ['enabled' => true, 'context_resolver' => 'test.request_context'];
        $kernel                  = $this->bootKernel($config, ['test.request_context' => RouteContextResolver::class]);

        $alice = $this->client($kernel, 'alice');
        $alice->request('GET', '/projects/billing');
        self::assertSame(200, $alice->getResponse()->getStatusCode());
        $alice->request('GET', '/projects/billing/settings');
        self::assertSame(403, $alice->getResponse()->getStatusCode(), 'a member without project.settings.view');
        $alice->request('GET', '/projects/other/settings');
        self::assertSame(200, $alice->getResponse()->getStatusCode(), 'strangers are left to the controller');
        $alice->request('GET', '/dashboard/summary');
        self::assertSame(200, $alice->getResponse()->getStatusCode());

        $bob = $this->client($kernel, 'bob');
        $bob->request('GET', '/dashboard/summary');
        self::assertSame(403, $bob->getResponse()->getStatusCode());
    }

    #[Test]
    public function grantsAreLoadedOncePerRequestAndResetBetweenRequests(): void
    {
        $kernel   = $this->bootKernel();
        $provider = $this->container($kernel)->get(GrantProviderInterface::class);
        self::assertInstanceOf(MapGrantProvider::class, $provider);

        $alice = $this->client($kernel, 'alice');
        $alice->request('GET', '/');
        self::assertSame(1, $provider->calls, 'five checks, one provider call');
        $alice->request('GET', '/');
        self::assertSame(2, $provider->calls, 'kernel.reset between requests (worker mode)');
    }

    #[Test]
    public function theCheckCommandReportsTheCoverage(): void
    {
        $config             = self::defaultConfig();
        $config['coverage'] = ['prefixes' => ['/projects', '/dashboard']];
        $tester             = $this->command($this->bootKernel($config));

        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('project_issue_triage', $tester->getDisplay(), 'a catalogue route that does not exist');
        self::assertSame(1, $tester->execute(['--prefix' => ['/']]));
        self::assertStringContainsString('nowo_rbac_kit_permission_sets', $tester->getDisplay(), 'the admin routes are not catalogued');
    }

    private function command(RbacTestKernel $kernel): CommandTester
    {
        $loader = $kernel->getContainer()->get('console.command_loader');
        self::assertInstanceOf(CommandLoaderInterface::class, $loader);

        return new CommandTester($loader->get('nowo:rbac:check'));
    }
}

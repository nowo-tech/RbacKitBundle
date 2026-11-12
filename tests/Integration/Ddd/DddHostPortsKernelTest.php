<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Integration\Ddd;

use Nowo\RbacKitBundle\Tests\Fixtures\Ports\DomainContextResolver;
use Nowo\RbacKitBundle\Tests\Fixtures\Ports\DomainPreviewVeto;
use Nowo\RbacKitBundle\Tests\Fixtures\Ports\DomainRequestContextResolver;
use Nowo\RbacKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\RbacKitBundle\Tests\Integration\Kernel\RbacTestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernel;
use Symfony\Component\HttpKernel\HttpKernelInterface;

use function memory_get_usage;

/**
 * 1.2 (REQ-ARCH-001): a DDD / hexagonal host that uses **only** the framework-free ports. Its domain project is a
 * plain object without any bundle interface, mapped by its own `ContextResolverInterface`; the request guard gets the
 * context from an `RbacRequestContextResolverInterface`; a "view as member" switch is an `RbacBypassVetoInterface`.
 * Then one kept-alive kernel (no services resetter, kernel reset false) serves 300 requests as alice, the superuser
 * admin and bob in alternation, with the same answers every time and flat memory (REQ-RUNTIME-002).
 */
final class DddHostPortsKernelTest extends KernelTestCase
{
    /** [user, path, expected status] — each answer is the 1.1 one with the deprecated ports. */
    private const EXPECTED = [
        ['alice', '/projects/billing', 200],
        ['alice', '/projects/billing/settings', 403],
        ['alice', '/projects/other', 403],
        ['alice', '/dashboard/summary', 200],
        ['admin', '/projects/other/settings', 200],
        ['admin', '/projects/preview/settings', 403],
        ['admin', '/projects/preview', 403],
        ['bob', '/dashboard/summary', 403],
        ['bob', '/projects/billing', 403],
        [null, '/projects/billing', 401],
        [null, '/', 200],
    ];

    #[Test]
    public function theNewPortsAloneDriveTheGuardAndTheBypass(): void
    {
        $kernel = $this->dddKernel();
        foreach (self::EXPECTED as [$user, $path, $status]) {
            self::assertSame($status, $this->statusOf($kernel, $user, $path), ($user ?? 'guest') . ' ' . $path);
        }
    }

    #[Test]
    public function aKeptAliveWorkerAnswersEveryUserTheSameWay(): void
    {
        $kernel   = $this->dddKernel();
        $baseline = 0;
        for ($i = 0; $i < 300; ++$i) {
            [$user, $path, $status] = self::EXPECTED[$i % 11];
            self::assertSame($status, $this->statusOf($kernel, $user, $path), $i . ': ' . ($user ?? 'guest') . ' ' . $path);
            if ($i === 50) {
                $baseline = memory_get_usage();
            }
        }
        self::assertLessThan(4 * 1024 * 1024, memory_get_usage() - $baseline, 'memory stays flat');
    }

    private function dddKernel(): RbacTestKernel
    {
        $config                     = self::defaultConfig();
        $config['context_resolver'] = 'test.domain_contexts';
        $config['voter']            = ['bypass_roles' => ['ROLE_ADMIN']];
        $config['request_guard']    = ['enabled' => true, 'context_resolver' => 'test.request_context'];

        return $this->bootKernel($config, [
            'test.domain_contexts' => DomainContextResolver::class,
            'test.request_context' => DomainRequestContextResolver::class,
            'test.veto'            => DomainPreviewVeto::class,
        ]);
    }

    /**
     * One main request through the `http_kernel` service (no kernel reset, no services resetter between requests).
     */
    private function statusOf(RbacTestKernel $kernel, ?string $user, string $path): int
    {
        $httpKernel = $kernel->getContainer()->get('http_kernel');
        self::assertInstanceOf(HttpKernel::class, $httpKernel);
        $server   = $user === null ? ['REMOTE_ADDR' => '203.0.113.9'] : ['PHP_AUTH_USER' => $user, 'PHP_AUTH_PW' => $user, 'REMOTE_ADDR' => '203.0.113.9'];
        $request  = Request::create($path, server: $server);
        $response = $httpKernel->handle($request, HttpKernelInterface::MAIN_REQUEST, true);
        $httpKernel->terminate($request, $response);

        return $response->getStatusCode();
    }
}

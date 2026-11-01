<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Integration\Kernel;

use LogicException;
use Nowo\RbacKitBundle\Security\PermissionChecker;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;

/**
 * Container-level guards: demo-only switches, security bundle, literal booleans, Doctrine storage.
 */
final class InvalidConfigurationTest extends KernelTestCase
{
    public function testUnauthenticatedAccessOnlyInDebug(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('only allowed when kernel.debug is true');
        $this->bootKernel(['web_ui' => ['enabled' => true], 'security' => ['allow_unauthenticated' => true]]);
    }

    public function testUnauthenticatedAccessInDebugBoots(): void
    {
        $kernel = $this->bootKernel(['web_ui' => ['enabled' => true], 'security' => ['allow_unauthenticated' => true]], without: ['security', 'doctrine'], debug: true);
        $client = $this->client($kernel, null);
        $client->request('GET', '/admin/rbac/catalogue');
        self::assertSame(200, $client->getResponse()->getStatusCode());
        self::assertStringContainsString('Empty catalogue', (string) $client->getResponse()->getContent());
    }

    public function testUiWithoutSecurityBundleFails(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('requires symfony/security-bundle');
        $this->bootKernel(['web_ui' => ['enabled' => true]], without: ['security']);
    }

    public function testRequestGuardWithoutSecurityBundleFails(): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('request_guard.enabled requires');
        $this->bootKernel(['request_guard' => ['enabled' => true]], without: ['security']);
    }

    public function testTheCoreNeedsNoSecurityBundleButDeniesEverything(): void
    {
        $kernel  = $this->bootKernel([], without: ['security', 'doctrine']);
        $checker = $this->container($kernel)->get(PermissionChecker::class);
        self::assertInstanceOf(PermissionChecker::class, $checker);
        self::assertFalse($checker->can('summary.view'));
    }

    public function testEnvPlaceholderSwitchIsRefused(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('must be a literal boolean');
        $this->bootKernel(['security' => ['allow_unauthenticated' => '%env(bool:RBAC_OPEN)%']]);
    }
}

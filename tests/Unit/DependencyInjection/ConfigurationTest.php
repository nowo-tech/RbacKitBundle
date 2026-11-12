<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\DependencyInjection;

use Nowo\RbacKitBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    #[Test]
    public function theDefaultsAreSafe(): void
    {
        $config = $this->process([]);

        self::assertSame([], $config['catalogue']['permissions'], 'an empty catalogue denies everything');
        self::assertSame('deny', $config['catalogue']['unmapped_routes']);
        self::assertSame([], $config['voter']['bypass_roles'], 'nobody bypasses the catalogue');
        self::assertNull($config['voter']['fallback_permission_set']);
        self::assertNull($config['grant_provider']);
        self::assertSame('config', $config['storage']['type']);
        self::assertFalse($config['web_ui']['enabled']);
        self::assertSame('/admin/rbac', $config['web_ui']['path_prefix']);
        self::assertFalse($config['request_guard']['enabled']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['access_roles']);
        self::assertSame(['ROLE_ADMIN'], $config['security']['manage_roles'], 'L6: managing is not implied by access_roles');
        self::assertFalse($config['security']['allow_unauthenticated']);
        self::assertFalse($config['request_guard']['skip_strangers'], 'L1: strangers are refused');
        self::assertSame(300, $config['storage']['cache_ttl'], 'L3: the cache entry expires');
        self::assertArrayNotHasKey('global_aggregation', $config['voter'], 'H1: aggregation is per key');
        self::assertSame([], $config['request_guard']['context_headers'], '1.2: no header reaches the RbacRequest by default');
    }

    /**
     * 1.2: `request_guard.context_headers` is normalised (lower case, trimmed, unique) and refuses headers that may
     * carry credentials.
     */
    #[Test]
    public function contextHeadersAreNormalisedAndCredentialHeadersRefused(): void
    {
        $config = $this->process(['request_guard' => ['context_headers' => [' X-Tenant ', 'x-tenant', 'Accept-Language']]]);
        self::assertSame(['x-tenant', 'accept-language'], $config['request_guard']['context_headers']);

        foreach (['Authorization', 'cookie', 'X-Api-Key', 'x-auth-user', 'x-session-id', 'x-csrf-token', 'x-signature', 'x-password', 'forwarded', 'x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-real-ip', 'x-jwt-assertion', 'x-amzn-oidc-data', 'x-credential', 'x-pass', 'ssl-client-cert', 'x-shopify-hmac-sha256', 'x-otp', 'referer', 'bad header', ''] as $header) {
            try {
                $this->process(['request_guard' => ['context_headers' => [$header]]]);
                self::fail('accepted: ' . $header);
            } catch (InvalidConfigurationException $exception) {
                self::assertStringContainsString('request_guard.context_headers', $exception->getMessage(), $header);
            }
        }
        self::assertSame(['array'], Configuration::refusedContextHeaders([['not', 'a', 'name']]));
    }

    #[Test]
    public function aFullConfigurationIsAccepted(): void
    {
        $config = $this->process([
            'catalogue'       => ['permissions' => ['project.view' => ['scope' => 'context', 'routes' => ['project_show']], 'reports.view' => ['aggregate' => true]], 'aliases' => ['area' => 'project.view'], 'open_routes' => ['home'], 'unmapped_routes' => 'allow'],
            'permission_sets' => ['viewer' => ['permissions' => ['project.view']], 'owner' => ['all' => true, 'locked' => true], 'root' => ['all' => true, 'include_global' => true]],
            'role_grants'     => ['ROLE_MANAGER' => ['wiki.manage']],
            'voter'           => ['bypass_roles' => ['ROLE_ADMIN'], 'fallback_permission_set' => 'viewer'],
            'storage'         => ['type' => 'service', 'service' => 'app.storage', 'cache_pool' => null],
            'web_ui'          => ['enabled' => true, 'templates' => ['index' => 'admin/rbac.html.twig']],
            'security'        => ['access_roles' => [], 'access_checker' => 'app.checker'],
        ]);

        self::assertSame('context', $config['catalogue']['permissions']['project.view']['scope']);
        self::assertTrue($config['permission_sets']['owner']['all']);
        self::assertFalse($config['permission_sets']['owner']['include_global']);
        self::assertTrue($config['permission_sets']['root']['include_global']);
        self::assertTrue($config['catalogue']['permissions']['reports.view']['aggregate']);
        self::assertFalse($config['catalogue']['permissions']['project.view']['aggregate']);
        self::assertSame('viewer', $config['voter']['fallback_permission_set']);
        self::assertSame(['ROLE_A', 'ROLE_B'], Configuration::strings(['  ROLE_A ', '', 3, 'ROLE_B']));
        self::assertSame([], Configuration::strings('nope'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'invalid permission key' => [['catalogue' => ['permissions' => ['Bad Key' => []]]], 'Invalid nowo_rbac_kit.catalogue.permissions key(s) "Bad Key"'];
        yield 'invalid scope' => [['catalogue' => ['permissions' => ['a.b' => ['scope' => 'tenant']]]], 'scope'];
        yield 'invalid set code' => [['permission_sets' => ['Viewer' => []]], 'codes must match'];
        yield 'aggregating context key' => [['catalogue' => ['permissions' => ['project.view' => ['scope' => 'context', 'aggregate' => true], 'a.b' => []]]], 'permissions "project.view": aggregate is only allowed on global permissions'];
        yield 'include_global without all' => [['permission_sets' => ['viewer' => ['include_global' => true]]], 'include_global needs all: true'];
        yield 'removed global_aggregation' => [['voter' => ['global_aggregation' => true]], 'Unrecognized option "global_aggregation"'];
        yield 'cache ttl zero' => [['storage' => ['cache_ttl' => 0]], 'cache_ttl'];
        yield 'role grant without ROLE_' => [['role_grants' => ['MANAGER' => ['a.b']]], 'role_grants keys must be ROLE_*'];
        yield 'empty role grant' => [['role_grants' => ['ROLE_MANAGER' => []]], 'role_grants keys must be ROLE_*'];
        yield 'bypass role without ROLE_' => [['voter' => ['bypass_roles' => ['admin']]], 'bypass_roles must list ROLE_*'];
        yield 'unknown fallback set' => [['voter' => ['fallback_permission_set' => 'nope']], 'fallback_permission_set "nope" is not a configured permission set'];
        yield 'service storage without service' => [['storage' => ['type' => 'service']], 'storage.service must name'];
        yield 'invalid table prefix' => [['doctrine' => ['table_prefix' => 'app-']], 'table_prefix must contain only'];
        yield 'relative path prefix' => [['web_ui' => ['path_prefix' => 'admin']], 'must be an absolute path'];
        yield 'unknown template page' => [['web_ui' => ['templates' => ['nope' => 'x.html.twig']]], 'Unknown nowo_rbac_kit.web_ui.templates page(s) "nope"'];
        yield 'empty access roles' => [['security' => ['access_roles' => []]], 'must contain at least one non-empty role'];
        yield 'empty manage roles' => [['security' => ['manage_roles' => [' ']]], 'must contain at least one non-empty role'];
        yield 'null manage roles' => [['security' => ['manage_roles' => []]], 'must contain at least one non-empty role'];
        yield 'unknown key' => [['nope' => true], 'Unrecognized option "nope"'];
    }

    /**
     * @param array<string, mixed> $config
     */
    #[Test]
    #[DataProvider('invalidConfigurations')]
    public function invalidConfigurationsNameThePath(array $config, string $message): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage($message);
        $this->process($config);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        /* @var array<string, mixed> */
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}

<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Integration\Functional;

use Nowo\RbacKitBundle\Controller\PermissionSetController;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetRegistry;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetStorageInterface;
use Nowo\RbacKitBundle\PermissionSet\StoredPermissionSet;
use Nowo\RbacKitBundle\Tests\Fixtures\DeadlockStorage;
use Nowo\RbacKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\RbacKitBundle\Tests\Integration\Kernel\RbacTestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Form;

use function is_array;

/**
 * 1.3 (REQ-RUNTIME-003): the admin pages under contention. A stale edit answers a domain 409 (X-Error-Code, never
 * X-Conflict-Code) with the current version; a lock conflict answers 409 + X-Conflict-Code CONFLICT_001 +
 * Retry-After (503 when configured).
 */
final class AdminConcurrencyTest extends KernelTestCase
{
    #[Test]
    public function aStaleEditIsRefusedAndTheCurrentVersionIsShown(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel);
        $this->post($client, $client->request('GET', '/admin/rbac/new')->filter('form')->form(), ['code' => 'reporting', 'name' => 'Reporting', 'permissions' => ['reports.export', 'project.view']]);
        self::assertSame(302, $client->getResponse()->getStatusCode());

        // This manager opens the form; another one then revokes project.view.
        $stale = $client->request('GET', '/admin/rbac/reporting/edit')->filter('form[action="/admin/rbac/reporting/edit"]')->form();
        $this->storage($kernel)->save(new StoredPermissionSet('reporting', 'Reporting', null, false, ['reports.export'], 'bob'));

        $this->post($client, $stale, ['name' => 'Reports', 'permissions' => ['reports.export', 'project.view']]);

        $response = $client->getResponse();
        self::assertSame(409, $response->getStatusCode());
        self::assertSame(PermissionSetController::STALE_CODE, $response->headers->get('X-Error-Code'));
        self::assertFalse($response->headers->has('X-Conflict-Code'), 'a domain conflict carries no CONFLICT_* code');
        self::assertStringContainsString('changed by another manager', (string) $response->getContent());
        $current = $this->registry($kernel)->find('reporting');
        self::assertSame(['reports.export'], $current?->permissions, 'the revocation stays');
        self::assertSame('Reporting', $current->name);

        // The page shows the current version; saving from it works.
        $fresh = $client->getCrawler()->filter('form[action="/admin/rbac/reporting/edit"]')->form();
        self::assertSame($current->revision, $fresh->getValues()[$fresh->getName() . '[revision]'] ?? null);
        $this->post($client, $fresh, ['name' => 'Reports']);
        self::assertSame(302, $client->getResponse()->getStatusCode());
        self::assertSame('Reports', $this->registry($kernel)->find('reporting')?->name);
    }

    #[Test]
    public function theFirstEditOfASystemSetCarriesTheUnstoredMarker(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel);
        $stale  = $client->request('GET', '/admin/rbac/viewer/edit')->filter('form')->form();
        self::assertSame('unstored', $stale->getValues()[$stale->getName() . '[revision]'] ?? null);
        $this->storage($kernel)->save(new StoredPermissionSet('viewer', 'Viewer', null, true, ['summary.view'], 'bob'));

        $this->post($client, $stale, ['permissions' => ['summary.view', 'project.view', 'reports.export']]);

        self::assertSame(409, $client->getResponse()->getStatusCode());
        self::assertSame(['summary.view'], $this->registry($kernel)->find('viewer')?->permissions);
    }

    #[Test]
    public function aFormWithoutRevisionComparesWithTheSetAsTheRequestReadsIt(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel);
        $form   = $client->request('GET', '/admin/rbac/viewer/edit')->filter('form')->form();

        $this->post($client, $form, ['revision' => '', 'permissions' => ['project.view']]);

        self::assertSame(302, $client->getResponse()->getStatusCode(), '1.2 clients that post no revision keep working');
        self::assertSame(['project.view'], $this->registry($kernel)->find('viewer')?->permissions);
    }

    #[Test]
    public function aLockConflictAnswers409WithConflict001AndRetryAfter(): void
    {
        $client = $this->client($this->deadlockKernel());
        $this->post($client, $client->request('GET', '/admin/rbac/new')->filter('form')->form(), ['code' => 'reporting', 'name' => 'Reporting', 'permissions' => ['reports.export']]);

        $response = $client->getResponse();
        self::assertSame(409, $response->getStatusCode());
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertSame('1', $response->headers->get('Retry-After'));
        self::assertFalse($response->headers->has('X-Error-Code'));
    }

    #[Test]
    public function hostsCanKeepA503(): void
    {
        $client = $this->client($this->deadlockKernel(['status' => 503, 'retry_after' => 4]));
        $this->post($client, $client->request('GET', '/admin/rbac/new')->filter('form')->form(), ['code' => 'reporting', 'name' => 'Reporting', 'permissions' => ['reports.export']]);

        $response = $client->getResponse();
        self::assertSame(503, $response->getStatusCode());
        self::assertSame('CONFLICT_001', $response->headers->get('X-Conflict-Code'));
        self::assertSame('4', $response->headers->get('Retry-After'));
    }

    /**
     * @param array<string, int> $conflictResponse
     */
    private function deadlockKernel(array $conflictResponse = []): RbacTestKernel
    {
        $config            = self::defaultConfig();
        $config['storage'] = ['type' => 'service', 'service' => 'test.deadlock_storage', 'usage_provider' => 'test.usage'];
        if ($conflictResponse !== []) {
            $config['conflict_response'] = $conflictResponse;
        }

        return $this->bootKernel($config, ['test.deadlock_storage' => DeadlockStorage::class]);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function post(KernelBrowser $client, Form $form, array $fields): void
    {
        $values = $form->getPhpValues();
        $name   = $form->getName();
        $data   = is_array($values[$name] ?? null) ? $values[$name] : [];
        $client->request('POST', $form->getUri(), [$name => $fields + $data]);
    }

    private function storage(RbacTestKernel $kernel): PermissionSetStorageInterface
    {
        $storage = $this->container($kernel)->get(PermissionSetStorageInterface::class);
        self::assertInstanceOf(PermissionSetStorageInterface::class, $storage);

        return $storage;
    }

    private function registry(RbacTestKernel $kernel): PermissionSetRegistry
    {
        $registry = $this->container($kernel)->get(PermissionSetRegistry::class);
        self::assertInstanceOf(PermissionSetRegistry::class, $registry);
        $registry->reset();

        return $registry;
    }
}

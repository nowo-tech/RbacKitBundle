<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Integration\Functional;

use Nowo\RbacKitBundle\Event\PermissionSetChangedEvent;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetRegistry;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetStorageInterface;
use Nowo\RbacKitBundle\PermissionSet\StoredPermissionSet;
use Nowo\RbacKitBundle\Tests\Fixtures\EventRecorder;
use Nowo\RbacKitBundle\Tests\Integration\Kernel\KernelTestCase;
use Nowo\RbacKitBundle\Tests\Integration\Kernel\RbacTestKernel;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Form;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_filter;
use function array_map;
use function array_values;
use function is_array;

final class AdminUiTest extends KernelTestCase
{
    #[Test]
    public function managersSeeEverySetWithItsActions(): void
    {
        $kernel  = $this->bootKernel();
        $client  = $this->client($kernel);
        $crawler = $client->request('GET', '/admin/rbac');

        $this->assertResponseStatus($client, 200);
        $list = $crawler->filter('[data-testid="rbac-sets"]');
        self::assertStringContainsString('viewer', $list->text());
        self::assertStringContainsString('Owner', $list->text(), 'system set names are translated');
        self::assertCount(4, $crawler->filter('[data-testid="rbac-kind"]'));
        self::assertCount(1, $crawler->filter('a[href="/admin/rbac/new"]'));
        self::assertCount(3, $crawler->filter('a[href$="/edit"]'), 'the locked owner set is not editable');
        self::assertCount(0, $crawler->filter('[data-testid="rbac-delete"]'), 'system sets are never deletable');
        self::assertStringContainsString('Assignments', $crawler->filter('thead')->text());

        $catalogue = $client->request('GET', '/admin/rbac/catalogue');
        $this->assertResponseStatus($client, 200);
        self::assertStringContainsString('project.issues.triage', $catalogue->filter('[data-testid="rbac-catalogue"]')->text());
        self::assertStringContainsString('Triage issues', $catalogue->filter('[data-testid="rbac-catalogue"]')->text());
    }

    #[Test]
    public function viewersReadAndOthersAreRefused(): void
    {
        $kernel = $this->bootKernel();
        $viewer = $this->client($kernel, 'viewer');
        $page   = $viewer->request('GET', '/admin/rbac');
        $this->assertResponseStatus($viewer, 200);
        self::assertCount(0, $page->filter('a[href="/admin/rbac/new"]'));
        self::assertCount(0, $page->filter('a[href$="/edit"]'));
        $viewer->request('GET', '/admin/rbac/new');
        $this->assertResponseStatus($viewer, 403);
        $viewer->request('POST', '/admin/rbac/viewer/delete');
        $this->assertResponseStatus($viewer, 403);

        $alice = $this->client($kernel, 'alice');
        $alice->request('GET', '/admin/rbac/catalogue');
        $this->assertResponseStatus($alice, 403);

        $guest = $this->client($kernel, null);
        $guest->request('GET', '/admin/rbac');
        $this->assertResponseStatus($guest, 401);
    }

    #[Test]
    public function aCustomSetIsCreatedEditedAndDeleted(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel);

        $form = $client->request('GET', '/admin/rbac/new')->filter('form')->form();
        $form->getName();
        $this->post($client, $form, ['code' => 'reporting', 'name' => 'Reporting', 'description' => 'Exports', 'permissions' => ['reports.export', 'project.view']]);
        $this->assertResponseStatus($client, 302);
        self::assertSame('/admin/rbac', $client->getResponse()->headers->get('Location'), 'path-only redirect');
        self::assertSame(['reports.export', 'project.view'], $this->registry($kernel)->find('reporting')?->permissions);
        $list = $client->followRedirect();
        self::assertStringContainsString('Permission set "reporting" created.', $list->text());
        self::assertCount(1, $list->filter('form[action="/admin/rbac/reporting/delete"]'));

        $edit = $client->request('GET', '/admin/rbac/reporting/edit');
        self::assertCount(1, $edit->filter('[data-nowo-ui-region="danger-zone"]'));
        $this->post($client, $edit->filter('form[action="/admin/rbac/reporting/edit"]')->form(), ['name' => 'Reports', 'permissions' => ['reports.export']]);
        $this->assertResponseStatus($client, 302);
        self::assertSame('Reports', $this->registry($kernel)->find('reporting')?->name);
        self::assertSame(['reports.export'], $this->registry($kernel)->find('reporting')->permissions);

        $client->submit($client->request('GET', '/admin/rbac')->filter('form[action="/admin/rbac/reporting/delete"]')->form());
        $this->assertResponseStatus($client, 302);
        self::assertNull($this->registry($kernel)->find('reporting'));

        $events = $this->events($kernel);
        self::assertSame([PermissionSetChangedEvent::CREATED, PermissionSetChangedEvent::UPDATED, PermissionSetChangedEvent::DELETED], array_map(static fn (PermissionSetChangedEvent $e): string => $e->action, $events));
        self::assertInstanceOf(UserInterface::class, $events[0]->user);
        self::assertSame('admin', $events[0]->user->getUserIdentifier());
        self::assertSame(['admin', 'admin', 'admin'], array_map(static fn (PermissionSetChangedEvent $e): ?string => $e->actorId, $events), 'rule 4: the identifier, not only the user object');
        self::assertSame(['project.view'], $events[1]->context['removed']);
    }

    #[Test]
    public function aSystemSetEditIsStoredOverItsDefault(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel);
        $form   = $client->request('GET', '/admin/rbac/viewer/edit')->filter('form')->form();
        self::assertSame('Viewer', $form->getValues()[$form->getName() . '[name]'] ?? null, 'prefilled with the translated label');
        $this->post($client, $form, ['permissions' => ['summary.view']]);

        $this->assertResponseStatus($client, 302);
        $viewer = $this->registry($kernel)->find('viewer');
        self::assertTrue($viewer?->stored);
        self::assertSame(['summary.view'], $viewer->permissions);
    }

    #[Test]
    public function invalidSubmissionsAreRefused(): void
    {
        $kernel = $this->bootKernel();
        $client = $this->client($kernel);

        $form = $client->request('GET', '/admin/rbac/new')->filter('form')->form();
        $name = $form->getName();
        $client->submit($form, [$name . '[code]' => 'Bad Code', $name . '[name]' => '']);
        $this->assertResponseStatus($client, 422);

        $client->submit($form, [$name . '[code]' => 'viewer', $name . '[name]' => 'Again']);
        $this->assertResponseStatus($client, 422);
        self::assertStringContainsString('A permission set with this code already exists.', (string) $client->getResponse()->getContent());

        $client->request('POST', '/admin/rbac/new', [$name => ['code' => 'forged', 'name' => 'Forged', '_token' => 'nope']]);
        $this->assertResponseStatus($client, 422);
        self::assertNull($this->registry($kernel)->find('forged'), 'an invalid token never writes');

        $edit = $client->request('GET', '/admin/rbac/viewer/edit')->filter('form')->form();
        $client->submit($edit, [$edit->getName() . '[name]' => '']);
        $this->assertResponseStatus($client, 422);

        $client->request('GET', '/admin/rbac/owner/edit');
        $this->assertResponseStatus($client, 404);
        $client->request('GET', '/admin/rbac/nope/edit');
        $this->assertResponseStatus($client, 404);
        $client->request('GET', '/admin/rbac/Bad/edit');
        $this->assertResponseStatus($client, 404);
    }

    #[Test]
    public function deletionsNeedTheTokenOfThatSet(): void
    {
        $kernel  = $this->bootKernel();
        $storage = $this->container($kernel)->get(PermissionSetStorageInterface::class);
        self::assertInstanceOf(PermissionSetStorageInterface::class, $storage);
        foreach (['first', 'second', 'in_use'] as $code) {
            $storage->save(new StoredPermissionSet($code, $code, null, false, ['summary.view']));
        }
        $client  = $this->client($kernel);
        $crawler = $client->request('GET', '/admin/rbac');
        self::assertCount(3, $crawler->filter('form[action$="/delete"]'));

        $values = $crawler->filter('form[action="/admin/rbac/first/delete"]')->form()->getPhpValues();
        $client->request('POST', '/admin/rbac/second/delete', $values);
        $this->assertResponseStatus($client, 403, 'a token of another set is refused');
        $client->request('POST', '/admin/rbac/first/delete');
        $this->assertResponseStatus($client, 403, 'a missing token is refused');
        self::assertNotNull($this->registry($kernel)->find('second'));

        $client->submit($client->request('GET', '/admin/rbac/in_use/edit')->filter('form[action="/admin/rbac/in_use/delete"]')->form());
        $this->assertResponseStatus($client, 302);
        self::assertNotNull($this->registry($kernel)->find('in_use'), 'a set in use stays');
        self::assertStringContainsString('still assigned', $client->followRedirect()->text());
    }

    #[Test]
    public function theStoredSetsAreBounded(): void
    {
        $config = self::defaultConfig();
        $config['storage'] += ['max_sets' => 1];
        $kernel  = $this->bootKernel($config);
        $storage = $this->container($kernel)->get(PermissionSetStorageInterface::class);
        self::assertInstanceOf(PermissionSetStorageInterface::class, $storage);
        $storage->save(new StoredPermissionSet('custom', 'Custom', null, false, []));
        $client = $this->client($kernel);

        $form = $client->request('GET', '/admin/rbac/viewer/edit')->filter('form')->form();
        $this->post($client, $form, ['permissions' => ['summary.view']]);
        $this->assertResponseStatus($client, 422);
        self::assertStringContainsString('maximum number of stored permission sets', (string) $client->getResponse()->getContent());
    }

    #[Test]
    public function aReadOnlyCatalogueHasNoMutations(): void
    {
        $config            = self::defaultConfig();
        $config['storage'] = ['type' => 'config'];
        $kernel            = $this->bootKernel($config, without: ['doctrine']);
        $client            = $this->client($kernel);

        $page = $client->request('GET', '/admin/rbac');
        $this->assertResponseStatus($client, 200);
        self::assertStringContainsString('read-only', $page->text());
        self::assertCount(0, $page->filter('a[href$="/edit"]'));
        self::assertStringNotContainsString('Assignments', $page->text(), 'no usage provider');
        $client->request('GET', '/admin/rbac/new');
        $this->assertResponseStatus($client, 404);
    }

    /**
     * Submits a form with its current values replaced by `$fields` (checkbox lists included).
     *
     * @param array<string, mixed> $fields
     */
    private function post(KernelBrowser $client, Form $form, array $fields): void
    {
        $values = $form->getPhpValues();
        $name   = $form->getName();
        $data   = is_array($values[$name] ?? null) ? $values[$name] : [];
        $client->request('POST', $form->getUri(), [$name => $fields + $data]);
    }

    private function assertResponseStatus(KernelBrowser $client, int $status, string $message = ''): void
    {
        self::assertSame($status, $client->getResponse()->getStatusCode(), $message);
    }

    private function registry(RbacTestKernel $kernel): PermissionSetRegistry
    {
        $registry = $this->container($kernel)->get(PermissionSetRegistry::class);
        self::assertInstanceOf(PermissionSetRegistry::class, $registry);
        $registry->reset();

        return $registry;
    }

    /**
     * @return list<PermissionSetChangedEvent>
     */
    private function events(RbacTestKernel $kernel): array
    {
        $recorder = $this->container($kernel)->get(EventRecorder::class);
        self::assertInstanceOf(EventRecorder::class, $recorder);

        return array_values(array_filter($recorder->events, static fn (object $e): bool => $e instanceof PermissionSetChangedEvent));
    }
}

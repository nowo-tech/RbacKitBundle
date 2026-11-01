<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Integration\Contract;

use Nowo\RbacKitBundle\Tests\Support\PageFixtures;
use Nowo\UiKitBundle\Test\PageContractTestCase;
use Nowo\UiKitBundle\Test\PageFixture;
use PHPUnit\Framework\Attributes\DataProvider;

use function dirname;

/**
 * REQ-UI-003 page contract: every page extends a UiKit scaffold; no <html>, CDN, inline handler or confirm(); framework
 * classes only through theme slots; the stub theme is reflected; no raw or missing translation (strict); the CSRF
 * delete forms keep their hidden token; the editor identifier never reaches the HTML.
 */
final class PageContractTest extends PageContractTestCase
{
    public static function pageFixtures(): iterable
    {
        $dir = '@NowoRbacKitBundle/admin/';
        yield 'sets (manager)' => new PageFixture($dir . 'index.html.twig', PageFixtures::index());
        yield 'sets (viewer)' => new PageFixture($dir . 'index.html.twig', PageFixtures::index(false));
        yield 'sets (read-only)' => new PageFixture($dir . 'index.html.twig', PageFixtures::index(true, false));
        yield 'sets (empty)' => new PageFixture($dir . 'index.html.twig', PageFixtures::index(true, true, true));
        yield 'catalogue' => new PageFixture($dir . 'catalogue.html.twig', PageFixtures::catalogue());
        yield 'catalogue (empty)' => new PageFixture($dir . 'catalogue.html.twig', PageFixtures::catalogue(true));
        yield 'new set' => new PageFixture($dir . 'form.html.twig', PageFixtures::form());
        yield 'edit custom set' => new PageFixture($dir . 'form.html.twig', PageFixtures::form('reporting'));
        yield 'edit system set' => new PageFixture($dir . 'form.html.twig', PageFixtures::form('viewer'));
    }

    /**
     * The editor identifier never reaches the HTML.
     */
    #[DataProvider('contractPages')]
    public function testPageNeverPrintsTheEditor(PageFixture $fixture): void
    {
        foreach (['none', 'bootstrap5', 'tailwind'] as $framework) {
            self::assertStringNotContainsString(PageFixtures::EDITOR, $this->renderPage($fixture, $framework)->html);
        }
    }

    /**
     * Delete forms keep their CSRF token bound to the set.
     */
    public function testDeleteFormsCarryTheirToken(): void
    {
        $html = $this->renderPage(new PageFixture('@NowoRbacKitBundle/admin/index.html.twig', PageFixtures::index()), 'none')->html;
        self::assertStringContainsString('token-nowo_rbac/delete/reporting', $html);
        self::assertStringNotContainsString('token-nowo_rbac/delete/viewer', $html);
    }

    protected static function templatePaths(): array
    {
        return ['NowoRbacKitBundle' => dirname(__DIR__, 3) . '/src/Resources/views'];
    }

    protected static function translationDirs(): array
    {
        return [dirname(__DIR__, 3) . '/src/Resources/translations', dirname(__DIR__, 2) . '/Fixtures/translations'];
    }

    protected static function templatesDocument(): string
    {
        return dirname(__DIR__, 3) . '/docs/TEMPLATES.md';
    }

    protected static function strictMissingTranslations(): bool
    {
        return true;
    }
}

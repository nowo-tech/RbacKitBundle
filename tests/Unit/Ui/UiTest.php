<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\Ui;

use InvalidArgumentException;
use Nowo\RbacKitBundle\DependencyInjection\NowoRbacKitExtension;
use Nowo\RbacKitBundle\NowoRbacKitBundle;
use Nowo\RbacKitBundle\Tests\Support\PageFixtures;
use Nowo\RbacKitBundle\Ui\ActionForms;
use Nowo\RbacKitBundle\Ui\RbacPages;
use Nowo\UiKitBundle\View\Label;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\DependencyInjection\ContainerBuilder;

use function array_filter;
use function str_starts_with;

final class UiTest extends TestCase
{
    #[Test]
    public function tokenIdsAreBoundToTheActionAndTheTarget(): void
    {
        self::assertSame('nowo_rbac/delete/viewer', ActionForms::tokenId(ActionForms::DELETE, 'viewer'));
        self::assertNotSame(ActionForms::formName('delete', 'a'), ActionForms::formName('delete', 'b'));
        self::assertStringStartsWith('rbac_', ActionForms::formName('edit', 'a'));

        $this->expectException(InvalidArgumentException::class);
        ActionForms::tokenId('delete', 'a/b');
    }

    #[Test]
    public function anEmptyTokenPartIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ActionForms::tokenId('', 'viewer');
    }

    #[Test]
    public function pagesResolveTheirTemplatesAndLayout(): void
    {
        $pages = new RbacPages('layouts/admin.html.twig', ['index' => 'admin/sets.html.twig', 'form' => '']);
        self::assertSame('admin/sets.html.twig', $pages->template('index'));
        self::assertSame(RbacPages::TEMPLATES['form'], $pages->template('form'));
        self::assertSame('unknown', $pages->template('unknown'));
        self::assertSame('layouts/admin.html.twig', $pages->layout());
        self::assertNull((new RbacPages(''))->layout());
        self::assertEquals(new Label('nav.sets', NowoRbacKitBundle::TRANSLATION_DOMAIN), RbacPages::label('nav.sets'));

        $factory = PageFixtures::factory($pages);
        self::assertSame('admin/sets.html.twig', $factory->template('index'));
        self::assertSame('Reporting', $factory->name(PageFixtures::sets()['reporting']));
        self::assertEquals(new Label('permission_set.viewer.label', 'messages'), $factory->name(PageFixtures::sets()['viewer']));
    }

    #[Test]
    public function theBundleRegistersItsPassesAndExtension(): void
    {
        $bundle    = new NowoRbacKitBundle();
        $container = new ContainerBuilder();
        $bundle->build($container);

        self::assertInstanceOf(NowoRbacKitExtension::class, $bundle->getContainerExtension());
        self::assertSame($bundle->getContainerExtension(), $bundle->getContainerExtension());
        self::assertCount(6, array_filter($container->getCompilerPassConfig()->getPasses(), static fn (object $pass): bool => str_starts_with($pass::class, 'Nowo\\RbacKitBundle\\')));
    }
}

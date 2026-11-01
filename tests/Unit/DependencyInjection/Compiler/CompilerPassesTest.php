<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\DependencyInjection\Compiler;

use LogicException;
use Nowo\RbacKitBundle\Controller\PermissionSetController;
use Nowo\RbacKitBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use Nowo\RbacKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\RbacKitBundle\DependencyInjection\Compiler\UnauthenticatedAccessPass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Twig\Loader\FilesystemLoader;

use function mkdir;
use function sys_get_temp_dir;

final class CompilerPassesTest extends TestCase
{
    #[Test]
    public function twigPathsPrependTheOverrideDirectory(): void
    {
        $project = sys_get_temp_dir() . '/rbac-twig-pass';
        @mkdir($project . '/templates/bundles/NowoRbacKitBundle', 0o777, true);
        $container = new ContainerBuilder();
        $container->setParameter('kernel.project_dir', $project);
        $container->setDefinition('twig.loader.native_filesystem', new Definition(FilesystemLoader::class));
        $container->setAlias('twig.loader.native', 'twig.loader.native_filesystem');

        (new TwigPathsPass())->process($container);

        $calls = $container->getDefinition('twig.loader.native_filesystem')->getMethodCalls();
        self::assertSame('prependPath', $calls[0][0]);
        self::assertSame([$project . '/templates/bundles/NowoRbacKitBundle', 'NowoRbacKitBundle'], $calls[0][1]);
        self::assertSame('addPath', $calls[1][0]);
        self::assertStringEndsWith('/Resources/views', (string) $calls[1][1][0]);

        $withoutOverride = new ContainerBuilder();
        $withoutOverride->setDefinition('twig.loader.native_filesystem', new Definition(FilesystemLoader::class));
        (new TwigPathsPass())->process($withoutOverride);
        self::assertCount(1, $withoutOverride->getDefinition('twig.loader.native_filesystem')->getMethodCalls());

        $noTwig = new ContainerBuilder();
        (new TwigPathsPass())->process($noTwig);
        self::assertFalse($noTwig->hasDefinition('twig.loader.native_filesystem'));
    }

    #[Test]
    public function csrfFailsClosed(): void
    {
        $container = new ContainerBuilder();
        (new CsrfProtectionPass())->process($container);

        $container->setDefinition(PermissionSetController::class, new Definition(PermissionSetController::class));
        $container->setDefinition('security.csrf.token_manager', new Definition('stdClass'));
        $container->setDefinition('form.type_extension.csrf', new Definition('stdClass'));
        (new CsrfProtectionPass())->process($container);

        $container->setParameter('form.type_extension.csrf.enabled', false);
        $this->expectException(LogicException::class);
        (new CsrfProtectionPass())->process($container);
    }

    #[Test]
    public function csrfFailsWithoutTheTokenManager(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition(PermissionSetController::class, new Definition(PermissionSetController::class));
        $this->expectException(LogicException::class);
        (new CsrfProtectionPass())->process($container);
    }

    #[Test]
    public function unauthenticatedAccessNeedsDebug(): void
    {
        $container = new ContainerBuilder();
        (new UnauthenticatedAccessPass())->process($container);
        $container->setParameter(UnauthenticatedAccessPass::PARAMETER, true);
        $container->setParameter('kernel.debug', true);
        (new UnauthenticatedAccessPass())->process($container);

        $container->setParameter('kernel.debug', false);
        $this->expectException(InvalidConfigurationException::class);
        (new UnauthenticatedAccessPass())->process($container);
    }
}

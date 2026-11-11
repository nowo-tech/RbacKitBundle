<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\DependencyInjection\Compiler;

use Nowo\RbacKitBundle\DependencyInjection\Compiler\RbacEntityManagerPass;
use Nowo\RbacKitBundle\DependencyInjection\NowoRbacKitExtension;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

#[CoversClass(RbacEntityManagerPass::class)]
final class RbacEntityManagerPassTest extends TestCase
{
    #[Test]
    public function doesNothingWithoutADedicatedManager(): void
    {
        $container = new ContainerBuilder();
        (new RbacEntityManagerPass())->process($container);
        $container->setParameter(NowoRbacKitExtension::ENTITY_MANAGER_PARAMETER, null);
        (new RbacEntityManagerPass())->process($container);
        self::assertFalse($container->hasParameter('doctrine.entity_managers'));
    }

    #[Test]
    public function removesTheBundleMappingFromEveryOtherManager(): void
    {
        $container = $this->container(['default' => 'x', 'rbac' => 'y', 'reporting' => 'z']);
        $container->setDefinition('doctrine.orm.default_metadata_driver', $this->chain(true, true));
        $container->setDefinition('doctrine.orm.rbac_metadata_driver', $this->chain(true, false));

        (new RbacEntityManagerPass())->process($container);

        self::assertCount(1, $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls(), 'host mapping kept');
        self::assertCount(1, $container->getDefinition('doctrine.orm.rbac_metadata_driver')->getMethodCalls());
    }

    #[Test]
    public function unknownManagerFailsEvenInCheckOnlyMode(): void
    {
        $container = $this->container(['default' => 'x']);

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('known: "default"');
        (new RbacEntityManagerPass(checkOnly: true))->process($container);
    }

    #[Test]
    public function checkOnlyModeLeavesTheMappingAlone(): void
    {
        $container = $this->container(['default' => 'x', 'rbac' => 'y']);
        $container->setDefinition('doctrine.orm.default_metadata_driver', $this->chain(true, true));
        (new RbacEntityManagerPass(checkOnly: true))->process($container);
        self::assertCount(2, $container->getDefinition('doctrine.orm.default_metadata_driver')->getMethodCalls());
    }

    #[Test]
    public function managerWithoutTheBundleMappingFails(): void
    {
        $container = $this->container(['default' => 'x', 'rbac' => 'y']);
        $container->setDefinition('doctrine.orm.rbac_metadata_driver', $this->chain(false, true));

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('does not map');
        (new RbacEntityManagerPass())->process($container);
    }

    /**
     * @param array<string, string> $managers
     */
    private function container(array $managers): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter(NowoRbacKitExtension::ENTITY_MANAGER_PARAMETER, 'rbac');
        $container->setParameter('doctrine.entity_managers', $managers);

        return $container;
    }

    private function chain(bool $bundle, bool $host): Definition
    {
        $chain = new Definition();
        if ($bundle) {
            $chain->addMethodCall('addDriver', [new Definition(), RbacEntityManagerPass::ENTITY_NAMESPACE]);
        }
        if ($host) {
            $chain->addMethodCall('addDriver', [new Definition(), 'App\\Entity']);
        }

        return $chain;
    }
}

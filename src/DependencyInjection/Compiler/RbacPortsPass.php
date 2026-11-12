<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\DependencyInjection\Compiler;

use LogicException;
use Nowo\RbacKitBundle\Context\RbacRequestContextResolverInterface;
use Nowo\RbacKitBundle\Context\RequestContextResolverInterface;
use Nowo\RbacKitBundle\EventListener\PermissionRequestListener;
use Nowo\RbacKitBundle\NowoRbacKitBundle;
use Nowo\RbacKitBundle\Security\BypassVetoInterface;
use Nowo\RbacKitBundle\Security\RbacBypassVetoInterface;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;

use function array_key_exists;
use function class_exists;
use function is_a;
use function is_string;
use function sprintf;
use function trigger_deprecation;

/**
 * Checks the services behind the two adapter SPIs that gained a framework-free twin in 1.2 (REQ-ARCH-001 rule 3):
 * the `request_guard.context_resolver` service and every `nowo_rbac_kit.bypass_veto` service.
 *
 * Per service, once the container knows its final class (after decoration, so a host decorator decides):
 *
 * - implements the framework-free port (alone or with the deprecated one): nothing to do, the bundle calls the new one;
 * - implements only the deprecated port: a deprecation (removed in 2.0), the bundle keeps calling it as in 1.1;
 * - implements neither: the container build fails, so a security SPI is never skipped silently.
 *
 * A service whose class cannot be known before the container is compiled (factory, synthetic, unknown class) is left to
 * the runtime check (the listener's typed constructor; the voter throws for a veto that implements neither port).
 *
 * The bundle registers no interface alias for these SPIs (the context resolver is a configured service id, vetoes are
 * tagged), so there is no alias for `lint:container` (CheckAliasValidityPass) to reject. Runs before the removing passes.
 */
final class RbacPortsPass implements CompilerPassInterface
{
    /** Position of the request context resolver in {@see PermissionRequestListener::__construct()}. */
    private const RESOLVER_ARGUMENT = 3;

    /**
     * @throws LogicException when a configured context resolver or a tagged veto implements neither port
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition(PermissionRequestListener::class)) {
            $arguments = $container->getDefinition(PermissionRequestListener::class)->getArguments();
            $resolver  = array_key_exists(self::RESOLVER_ARGUMENT, $arguments) ? $arguments[self::RESOLVER_ARGUMENT] : ($arguments['$requestContexts'] ?? null);
            if ($resolver instanceof Reference) {
                $this->check($container, (string) $resolver, 'request_guard.context_resolver', RbacRequestContextResolverInterface::class, RequestContextResolverInterface::class);
            }
        }

        foreach ($container->findTaggedServiceIds(NowoRbacKitBundle::BYPASS_VETO_TAG) as $id => $tags) {
            $this->check($container, $id, NowoRbacKitBundle::BYPASS_VETO_TAG, RbacBypassVetoInterface::class, BypassVetoInterface::class);
        }
    }

    /**
     * @param class-string $new
     * @param class-string $legacy
     */
    private function check(ContainerBuilder $container, string $id, string $role, string $new, string $legacy): void
    {
        $id    = $this->resolve($container, $id);
        $class = $this->serviceClass($container, $id);
        if ($class === null || is_a($class, $new, true)) {
            return;
        }
        if (!is_a($class, $legacy, true)) {
            throw new LogicException(sprintf('NowoRbacKitBundle: the %s service "%s" (%s) must implement "%s".', $role, $id, $class, $new));
        }

        trigger_deprecation('nowo-tech/rbac-kit-bundle', '1.2', 'The %s service "%s" (%s) implements only "%s"; implement "%s" instead (the deprecated interface is removed in 2.0).', $role, $id, $class, $legacy, $new);
    }

    /** @return class-string|null null when the class cannot be known before the container is compiled */
    private function serviceClass(ContainerBuilder $container, string $id): ?string
    {
        if (!$container->hasDefinition($id)) {
            return null;
        }

        $definition = $container->getDefinition($id);
        if ($definition->getFactory() !== null || $definition->isSynthetic()) {
            return null;
        }

        // A definition without a class uses its id as the class (as ResolveClassPass does).
        $class = $container->getParameterBag()->resolveValue($definition->getClass() ?? $id);

        return is_string($class) && class_exists($class) ? $class : null;
    }

    /**
     * Follows aliases to the service id that is defined.
     */
    private function resolve(ContainerBuilder $container, string $id): string
    {
        $seen = [];
        while ($container->hasAlias($id) && !isset($seen[$id])) {
            $seen[$id] = true;
            $id        = (string) $container->getAlias($id);
        }

        return $id;
    }
}

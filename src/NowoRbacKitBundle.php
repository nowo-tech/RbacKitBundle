<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle;

use Nowo\RbacKitBundle\DependencyInjection\Compiler\CsrfProtectionPass;
use Nowo\RbacKitBundle\DependencyInjection\Compiler\TwigPathsPass;
use Nowo\RbacKitBundle\DependencyInjection\Compiler\UnauthenticatedAccessPass;
use Nowo\RbacKitBundle\DependencyInjection\NowoRbacKitExtension;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\HttpKernel\Bundle\Bundle;

/**
 * Role-based access control for Symfony: a configurable permission catalogue, roles as permission sets checked in
 * their context (global or a tenant / project subject), a memoised worker-safe voter, a route guard, coverage checks
 * and a UiKit admin UI.
 *
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoRbacKitBundle extends Bundle
{
    /** Translation domain of the bundle UI copy (REQ-I18N-003) and Twig namespace (REQ-TWIG-002). */
    public const TRANSLATION_DOMAIN = 'NowoRbacKitBundle';

    /** FormKit profile (`nowo_form_kit.profiles.rbac_kit`) of the bundle POST forms (REQ-TWIG-005 rule 11). */
    public const FORM_KIT_PROFILE = 'rbac_kit';

    /** Service tag of the {@see Permission\PermissionProviderInterface} services. */
    public const PERMISSION_PROVIDER_TAG = 'nowo_rbac_kit.permission_provider';

    /** Service tag of the {@see Security\PermissionGateInterface} services. */
    public const PERMISSION_GATE_TAG = 'nowo_rbac_kit.permission_gate';

    /** Service tag of the {@see Security\BypassVetoInterface} services. */
    public const BYPASS_VETO_TAG = 'nowo_rbac_kit.bypass_veto';

    /**
     * Registers the compiler passes: Twig paths, CSRF fail-closed guard and the demo-only unauthenticated access.
     */
    public function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new TwigPathsPass());
        $container->addCompilerPass(new CsrfProtectionPass());
        $container->addCompilerPass(new UnauthenticatedAccessPass());
    }

    /**
     * The extension alias is `nowo_rbac_kit` (not the default derived from the class name).
     */
    public function getContainerExtension(): ?ExtensionInterface
    {
        if ($this->extension === null) {
            // @igor-ignore - Boot-time Symfony Bundle extension cache (not request state).
            $this->extension = new NowoRbacKitExtension();
        }

        return $this->extension instanceof ExtensionInterface ? $this->extension : null;
    }
}

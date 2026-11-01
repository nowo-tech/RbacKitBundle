<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\EventListener;

use Nowo\RbacKitBundle\Context\ContextResolverInterface;
use Nowo\RbacKitBundle\Context\RequestContextResolverInterface;
use Nowo\RbacKitBundle\Grant\GrantResolver;
use Nowo\RbacKitBundle\Permission\Permission;
use Nowo\RbacKitBundle\Permission\PermissionCatalog;
use Nowo\RbacKitBundle\Permission\PermissionScope;
use Nowo\RbacKitBundle\Security\PermissionVoter;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\UserInterface;

use function is_string;
use function sprintf;

/**
 * Opt-in request guard (`request_guard.enabled`): a main request to a route the catalogue guards is refused before
 * its controller runs (the firewall's 403 — branded page or JSON) when the user lacks the route's permission (with
 * the request's context for a context key).
 *
 * Guests are left to the firewall; a context route whose context the {@see RequestContextResolverInterface} cannot
 * resolve is left to the controller; with `request_guard.skip_strangers` (default) users holding no set in the
 * context are left to the controller too, which keeps its own answer (404 existence mask, share links…). Registered
 * with an explicit `kernel.event_listener` tag (the attribute documents the event). Stateless.
 */
final readonly class PermissionRequestListener
{
    /**
     * @param bool $skipStrangers `request_guard.skip_strangers`
     */
    public function __construct(
        private PermissionCatalog $catalog,
        private AuthorizationCheckerInterface $authorization,
        private TokenStorageInterface $tokens,
        private RequestContextResolverInterface $requestContexts,
        private ContextResolverInterface $contexts,
        private GrantResolver $grants,
        private bool $skipStrangers = true,
    ) {
    }

    /**
     * Throws {@see AccessDeniedException} when the route's permission is not granted.
     */
    #[AsEventListener(event: KernelEvents::CONTROLLER)]
    public function __invoke(ControllerEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }
        $request = $event->getRequest();
        $route   = $request->attributes->get('_route');
        $user    = $this->tokens->getToken()?->getUser();
        if (!is_string($route) || !$user instanceof UserInterface) {
            return;
        }
        $permission = $this->catalog->forRoute($route);
        if (!$permission instanceof Permission) {
            return;
        }

        $context = null;
        if ($permission->scope === PermissionScope::Context) {
            $context   = $this->requestContexts->fromRequest($request);
            $contextId = $context === null ? null : $this->contexts->contextId($context);
            if ($contextId === null || ($this->skipStrangers && !$this->grants->holdsSetIn($user, $contextId))) {
                return;
            }
        }

        if (!$this->authorization->isGranted(PermissionVoter::PREFIX . $permission->key, $context)) {
            throw new AccessDeniedException(sprintf('Permission "%s".', $permission->key));
        }
    }
}

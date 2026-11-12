<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\EventListener;

use Nowo\RbacKitBundle\Context\ContextResolverInterface;
use Nowo\RbacKitBundle\Context\RbacRequest;
use Nowo\RbacKitBundle\Context\RbacRequestContextResolverInterface;
use Nowo\RbacKitBundle\Context\RequestContextResolverInterface;
use Nowo\RbacKitBundle\Grant\GrantResolver;
use Nowo\RbacKitBundle\Http\RbacRequestFactory;
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
 * its controller runs (the firewall's 403 — branded page or JSON — or, for a guest, the firewall's entry point) when
 * the user lacks the route's permission (with the request's context for a context key). Fail closed by default:
 * guests, users holding no set in the context and context routes whose context cannot be resolved are refused.
 *
 * Opt-outs: the route default `_rbac_guard: false` leaves one route to its controller (share links, public pages);
 * `request_guard.skip_strangers: true` leaves users holding no set in the context — and unresolved contexts — to the
 * controller, which keeps its own answer (404 existence mask). Registered with an explicit `kernel.event_listener` tag
 * (the attribute documents the event). Stateless.
 *
 * The request's context comes from the `request_guard.context_resolver` service: through the framework-free
 * {@see RbacRequestContextResolverInterface} (given an {@see RbacRequest} built per
 * request) when it implements it, otherwise through the deprecated {@see RequestContextResolverInterface}.
 */
final readonly class PermissionRequestListener
{
    /** Route default that leaves a catalogued route to its controller (`_rbac_guard: false`). */
    public const ROUTE_OPT_OUT = '_rbac_guard';

    /**
     * @param bool $skipStrangers `request_guard.skip_strangers`
     * @param list<string> $contextHeaders `request_guard.context_headers` (lower-case names copied into the RbacRequest)
     */
    public function __construct(
        private PermissionCatalog $catalog,
        private AuthorizationCheckerInterface $authorization,
        private TokenStorageInterface $tokens,
        private RbacRequestContextResolverInterface|RequestContextResolverInterface $requestContexts,
        private ContextResolverInterface $contexts,
        private GrantResolver $grants,
        private bool $skipStrangers = false,
        private array $contextHeaders = [],
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
        if (!is_string($route) || $request->attributes->get(self::ROUTE_OPT_OUT) === false) {
            return;
        }
        $permission = $this->catalog->forRoute($route);
        if (!$permission instanceof Permission) {
            return;
        }
        $user = $this->tokens->getToken()?->getUser();
        if (!$user instanceof UserInterface) {
            throw new AccessDeniedException(sprintf('Permission "%s": authentication required.', $permission->key));
        }

        $context = null;
        if ($permission->scope === PermissionScope::Context) {
            $context = $this->requestContexts instanceof RbacRequestContextResolverInterface
                ? $this->requestContexts->contextOf(RbacRequestFactory::fromRequest($request, $this->contextHeaders))
                : $this->requestContexts->fromRequest($request);
            $contextId = $context === null ? null : $this->contexts->contextId($context);
            if ($this->skipStrangers && ($contextId === null || !$this->grants->holdsSetIn($user, $contextId))) {
                return;
            }
        }

        if (!$this->authorization->isGranted(PermissionVoter::PREFIX . $permission->key, $context)) {
            throw new AccessDeniedException(sprintf('Permission "%s".', $permission->key));
        }
    }
}

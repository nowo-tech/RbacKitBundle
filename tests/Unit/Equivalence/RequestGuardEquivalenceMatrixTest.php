<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\Equivalence;

use Nowo\RbacKitBundle\Context\DefaultContextResolver;
use Nowo\RbacKitBundle\Context\RbacRequest;
use Nowo\RbacKitBundle\Context\RbacRequestContextResolverInterface;
use Nowo\RbacKitBundle\Context\RequestContextResolverInterface;
use Nowo\RbacKitBundle\EventListener\PermissionRequestListener;
use Nowo\RbacKitBundle\Grant\GrantResolver;
use Nowo\RbacKitBundle\Security\PermissionVoter;
use Nowo\RbacKitBundle\Tests\Fixtures\MapGrantProvider;
use Nowo\RbacKitBundle\Tests\Fixtures\Ports\DualRouteContextResolver;
use Nowo\RbacKitBundle\Tests\Fixtures\Ports\LegacyRouteContextResolver;
use Nowo\RbacKitBundle\Tests\Fixtures\Project;
use Nowo\RbacKitBundle\Tests\Fixtures\RouteContextResolver;
use Nowo\RbacKitBundle\Tests\Support\Catalogues;
use Nowo\RbacKitBundle\Tests\Support\Legacy\PermissionRequestListenerV110;
use Nowo\RbacKitBundle\Tests\Support\Legacy\PermissionVoterV110;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function count;
use function implode;
use function is_string;
use function sprintf;

/**
 * 1.2 (REQ-ARCH-001 rule 3, immediate audit): the request guard decides exactly the same with the framework-free
 * request context resolver as with the deprecated one, over a real voter and access decision manager, and exactly like
 * the verbatim 1.1.0 listener and voter ({@see PermissionRequestListenerV110}, the reference; audit MX-R-3).
 *
 * Resolver pairs read the same value through each port: the `{id}` route parameter, and a tenant header copied through
 * `request_guard.context_headers`. A service implementing both ports (whose deprecated method answers a context nobody
 * holds) must decide like the new port. Matrix: route (open, global, context, unmapped, none) × `{id}` (held, partly
 * held, not held, vetoed, absent) × header × user (guest, member, global auditor, superuser, hierarchy superuser) ×
 * `skip_strangers` × `_rbac_guard: false` × main / sub request.
 */
final class RequestGuardEquivalenceMatrixTest extends TestCase
{
    #[Test]
    public function theGuardDecidesIdenticallyThroughBothPorts(): void
    {
        $pairs = [
            'route parameter' => [new LegacyRouteContextResolver(), new RouteContextResolver(), new DualRouteContextResolver()],
            'tenant header'   => [$this->legacyHeaderResolver(), $this->newHeaderResolver(), null],
        ];

        $compared    = 0;
        $differences = [];
        $outcomes    = [];
        foreach ($pairs as $pairName => [$legacy, $new, $dual]) {
            foreach ([false, true] as $skipStrangers) {
                foreach ([null, 'alice', 'bob', 'root', 'admin'] as $user) {
                    $reference = $this->listenerV110($legacy, $user, $skipStrangers);
                    $listeners = ['legacy' => $this->listener($legacy, $user, $skipStrangers), 'new' => $this->listener($new, $user, $skipStrangers)];
                    if ($dual instanceof DualRouteContextResolver) {
                        $listeners['dual'] = $this->listener($dual, $user, $skipStrangers);
                    }
                    foreach ([null, 'host_home', 'dashboard_summary', 'project_show', 'project_issue_triage', 'project_settings', 'unmapped_route'] as $route) {
                        foreach ([null, 'billing', 'mobile', 'other', 'preview'] as $id) {
                            foreach ([null, 'billing', 'other'] as $tenant) {
                                foreach ([false, true] as $optOut) {
                                    foreach ([HttpKernelInterface::MAIN_REQUEST, HttpKernelInterface::SUB_REQUEST] as $type) {
                                        $event    = $this->event($route, $id, $tenant, $optOut, $type);
                                        $expected = $this->outcome($reference, $event);
                                        ++$compared;
                                        $outcomes[$expected] = true;
                                        foreach ($listeners as $name => $listener) {
                                            $answer = $this->outcome($listener, $event);
                                            if ($answer !== $expected) {
                                                $differences[] = sprintf('%s skip=%d %s %s id=%s tenant=%s optOut=%d type=%d: %s "%s", 1.1.0 "%s"', $pairName, (int) $skipStrangers, $user ?? 'guest', $route ?? '-', $id ?? '-', $tenant ?? '-', (int) $optOut, $type, $name, $answer, $expected);
                                            }
                                        }
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }

        self::assertSame([], $differences, implode("\n", $differences));
        self::assertSame(2 * 2 * 5 * 7 * 5 * 3 * 2 * 2, $compared);
        self::assertGreaterThanOrEqual(4, count($outcomes), 'pass, refused, authentication required and context refusals all occur');
    }

    private function outcome(PermissionRequestListener|PermissionRequestListenerV110 $listener, ControllerEvent $event): string
    {
        try {
            $listener($event);

            return 'pass';
        } catch (AccessDeniedException $exception) {
            return 'denied: ' . $exception->getMessage();
        }
    }

    private function listener(RbacRequestContextResolverInterface|RequestContextResolverInterface $resolver, ?string $user, bool $skipStrangers): PermissionRequestListener
    {
        $tokens = $this->tokens($user);
        $grants = $this->grants();
        $voter  = new PermissionVoter(Catalogues::catalog(), $grants, Catalogues::registry(), new DefaultContextResolver(), new RoleHierarchy(['ROLE_ADMIN' => ['ROLE_SUPER']]), ['ROLE_SUPER']);

        return new PermissionRequestListener(Catalogues::catalog(), new AuthorizationChecker($tokens, new AccessDecisionManager([$voter])), $tokens, $resolver, new DefaultContextResolver(), $grants, $skipStrangers, ['x-tenant']);
    }

    /**
     * The verbatim 1.1.0 listener over the verbatim 1.1.0 voter, with the deprecated-port resolver: the reference.
     */
    private function listenerV110(RequestContextResolverInterface $resolver, ?string $user, bool $skipStrangers): PermissionRequestListenerV110
    {
        $tokens = $this->tokens($user);
        $grants = $this->grants();
        $voter  = new PermissionVoterV110(Catalogues::catalog(), $grants, Catalogues::registry(), new DefaultContextResolver(), new RoleHierarchy(['ROLE_ADMIN' => ['ROLE_SUPER']]), ['ROLE_SUPER']);

        return new PermissionRequestListenerV110(Catalogues::catalog(), new AuthorizationChecker($tokens, new AccessDecisionManager([$voter])), $tokens, $resolver, new DefaultContextResolver(), $grants, $skipStrangers);
    }

    private function tokens(?string $user): TokenStorage
    {
        $tokens = new TokenStorage();
        if (is_string($user)) {
            $roles = match ($user) {
                'root'  => ['ROLE_SUPER'],
                'admin' => ['ROLE_ADMIN'],
                default => ['ROLE_USER'],
            };
            $tokens->setToken(new UsernamePasswordToken(new InMemoryUser($user, null, $roles), 'main', $roles));
        }

        return $tokens;
    }

    private function grants(): GrantResolver
    {
        $provider = new MapGrantProvider(['alice' => [['viewer', 'billing'], ['triage', 'billing'], ['viewer', 'mobile']], 'bob' => [['auditor', null]], 'admin' => [['viewer', 'preview']]]);

        return new GrantResolver($provider, Catalogues::registry());
    }

    /**
     * A routed request as the RouterListener leaves it: `_route`, `_route_params` and the parameters as attributes.
     */
    private function event(?string $route, ?string $id, ?string $tenant, bool $optOut, int $type): ControllerEvent
    {
        $request = Request::create($id === null ? '/' : '/projects/' . $id);
        if ($tenant !== null) {
            $request->headers->set('X-Tenant', $tenant);
        }
        if ($route !== null) {
            $params = $id === null ? [] : ['id' => $id];
            $request->attributes->add(['_route' => $route, '_route_params' => $params] + $params);
        }
        if ($optOut) {
            $request->attributes->set(PermissionRequestListener::ROUTE_OPT_OUT, false);
        }

        return new ControllerEvent($this->createStub(HttpKernelInterface::class), static fn (): null => null, $request, $type);
    }

    private function legacyHeaderResolver(): RequestContextResolverInterface
    {
        return new class implements RequestContextResolverInterface {
            public function fromRequest(Request $request): ?object
            {
                $tenant = $request->headers->get('x-tenant');

                return is_string($tenant) ? new Project($tenant) : null;
            }
        };
    }

    private function newHeaderResolver(): RbacRequestContextResolverInterface
    {
        return new class implements RbacRequestContextResolverInterface {
            public function contextOf(RbacRequest $request): ?object
            {
                $tenant = $request->header('X-Tenant');

                return is_string($tenant) ? new Project($tenant) : null;
            }
        };
    }
}

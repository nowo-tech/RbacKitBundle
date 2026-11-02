<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\EventListener;

use ArrayObject;
use Nowo\RbacKitBundle\Context\DefaultContextResolver;
use Nowo\RbacKitBundle\Context\RequestContextResolverInterface;
use Nowo\RbacKitBundle\EventListener\PermissionRequestListener;
use Nowo\RbacKitBundle\Grant\GrantResolver;
use Nowo\RbacKitBundle\Tests\Fixtures\MapGrantProvider;
use Nowo\RbacKitBundle\Tests\Fixtures\Project;
use Nowo\RbacKitBundle\Tests\Support\Catalogues;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecision;
use Symfony\Component\Security\Core\Authorization\AuthorizationCheckerInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function in_array;
use function is_string;

final class PermissionRequestListenerTest extends TestCase
{
    /** @var ArrayObject<int, string> */
    private ArrayObject $asked;

    protected function setUp(): void
    {
        $this->asked = new ArrayObject();
    }

    #[Test]
    public function unguardedRequestsPass(): void
    {
        $listener = $this->listener('alice');
        $listener($this->event('home'));
        $listener($this->event(null));
        $listener($this->event('dashboard_summary', HttpKernelInterface::SUB_REQUEST));
        $this->listener(null)($this->event('home'));
        $this->listener(null)($this->event('dashboard_summary', optOut: true));
        $this->listener('alice')($this->event('project_settings', optOut: true));
        self::assertSame([], $this->asked->getArrayCopy());
    }

    /**
     * Appendix CI L1: guests are refused on catalogued routes (the firewall turns it into its entry point).
     */
    #[Test]
    public function guestsAreRefusedOnGuardedRoutes(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('authentication required');
        $this->listener(null)($this->event('dashboard_summary'));
    }

    #[Test]
    public function aGlobalRouteNeedsItsPermission(): void
    {
        $this->listener('alice', ['permission:summary.view'])($this->event('dashboard_summary'));
        self::assertSame(['permission:summary.view'], $this->asked->getArrayCopy());

        $this->expectException(AccessDeniedException::class);
        $this->expectExceptionMessage('Permission "summary.view"');
        $this->listener('alice')($this->event('dashboard_summary'));
    }

    #[Test]
    public function withSkipStrangersContextRoutesAreLeftToTheControllerWithoutAContextOrForStrangers(): void
    {
        $this->listener('alice', [], null, true)($this->event('project_settings'));
        $this->listener('alice', [], new Project('other'), true)($this->event('project_settings'));
        $this->listener('alice', ['permission:project.view'], new Project('billing'), true)($this->event('project_show'));
        self::assertSame(['permission:project.view'], $this->asked->getArrayCopy());
    }

    /**
     * Appendix CI L1: by default strangers and unresolved contexts are refused.
     */
    #[Test]
    public function strangersAreRefusedByDefault(): void
    {
        try {
            $this->listener('alice', [], new Project('other'))($this->event('project_settings'));
            self::fail('a stranger must be refused');
        } catch (AccessDeniedException) {
        }

        $this->expectException(AccessDeniedException::class);
        $this->listener('alice')($this->event('project_settings'));
    }

    #[Test]
    public function aMemberWithoutTheContextPermissionIsRefused(): void
    {
        $this->expectException(AccessDeniedException::class);
        $this->listener('alice', [], new Project('billing'))($this->event('project_settings'));
    }

    /**
     * @param list<string> $granted
     */
    private function listener(?string $user, array $granted = [], ?Project $context = null, bool $skipStrangers = false): PermissionRequestListener
    {
        $tokens = new TokenStorage();
        if (is_string($user)) {
            $tokens->setToken(new UsernamePasswordToken(new InMemoryUser($user, null), 'main', ['ROLE_USER']));
        }
        $authorization = new class($granted, $this->asked) implements AuthorizationCheckerInterface {
            /**
             * @param list<string> $granted
             * @param ArrayObject<int, string> $asked
             */
            public function __construct(private readonly array $granted, private readonly ArrayObject $asked)
            {
            }

            public function isGranted(mixed $attribute, mixed $subject = null, ?AccessDecision $accessDecision = null): bool
            {
                $this->asked[] = (string) $attribute;

                return in_array($attribute, $this->granted, true);
            }
        };
        $contexts = new class($context) implements RequestContextResolverInterface {
            public function __construct(private readonly ?Project $context)
            {
            }

            public function fromRequest(Request $request): ?object
            {
                return $this->context;
            }
        };

        return new PermissionRequestListener(Catalogues::catalog(), $authorization, $tokens, $contexts, new DefaultContextResolver(), new GrantResolver(new MapGrantProvider(), Catalogues::registry()), $skipStrangers);
    }

    private function event(?string $route, int $type = HttpKernelInterface::MAIN_REQUEST, bool $optOut = false): ControllerEvent
    {
        $request = Request::create('/');
        if ($route !== null) {
            $request->attributes->set('_route', $route);
        }
        if ($optOut) {
            $request->attributes->set(PermissionRequestListener::ROUTE_OPT_OUT, false);
        }

        return new ControllerEvent($this->createStub(HttpKernelInterface::class), static fn (): null => null, $request, $type);
    }
}

<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\Security;

use Nowo\RbacKitBundle\Context\DefaultContextResolver;
use Nowo\RbacKitBundle\Grant\GrantResolver;
use Nowo\RbacKitBundle\Permission\Permission;
use Nowo\RbacKitBundle\Permission\PermissionCatalog;
use Nowo\RbacKitBundle\Security\BypassVetoInterface;
use Nowo\RbacKitBundle\Security\PermissionGateInterface;
use Nowo\RbacKitBundle\Security\PermissionVoter;
use Nowo\RbacKitBundle\Tests\Fixtures\MapGrantProvider;
use Nowo\RbacKitBundle\Tests\Fixtures\Project;
use Nowo\RbacKitBundle\Tests\Support\Catalogues;
use Nowo\RbacKitBundle\Tests\Support\MemoryStorage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Security\Core\User\InMemoryUser;

final class PermissionVoterTest extends TestCase
{
    private MapGrantProvider $provider;

    protected function setUp(): void
    {
        $this->provider = new MapGrantProvider();
    }

    #[Test]
    public function contextPermissionsNeedASetInThatContext(): void
    {
        $voter = $this->voter();
        $alice = $this->token('alice');

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($alice, new Project('billing'), ['permission:project.issues.triage']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($alice, new Project('mobile'), ['permission:project.issues.triage']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($alice, new Project('other'), ['permission:project.view']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($alice, null, ['permission:project.view']), 'no context subject');
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($alice, new stdClass(), ['permission:project.view']), 'not a context');
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($alice, new Project('billing'), ['permission_route:project_issue_triage']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($alice, new Project('mobile'), ['permission_route:project_issue_triage']));
    }

    #[Test]
    public function globalPermissionsAggregateTheContextsOrUseGlobalSets(): void
    {
        $voter = $this->voter();
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token('alice'), null, ['permission:summary.view']), 'one context grants it');
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token('alice'), new Project('x'), ['permission:summary.view']), 'the subject is ignored for global keys');
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token('alice'), null, ['permission:reports.export']));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token('bob'), null, ['permission:reports.export']), 'global assignment');
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token('alice'), null, ['permission_route:dashboard_summary']));

        $noAggregation = $this->voter(globalAggregation: false);
        self::assertSame(VoterInterface::ACCESS_DENIED, $noAggregation->vote($this->token('alice'), null, ['permission:summary.view']));
    }

    #[Test]
    public function theFallbackSetCoversUsersWithoutAssignments(): void
    {
        $voter = $this->voter(fallbackSet: 'viewer');
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token('newcomer'), null, ['permission:summary.view']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token('newcomer'), null, ['permission:reports.export']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token('bob'), null, ['permission:summary.view']), 'bob holds an assignment');
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote($this->token('newcomer'), null, ['permission:summary.view']), 'no fallback by default');
    }

    #[Test]
    public function roleGrantsAndBypassRolesFollowTheHierarchy(): void
    {
        $hierarchy = new RoleHierarchy(['ROLE_ADMIN' => ['ROLE_MANAGER']]);
        $voter     = $this->voter(hierarchy: $hierarchy, bypassRoles: ['ROLE_SUPER'], roleGrants: ['ROLE_MANAGER' => ['wiki.manage', 'project.view']]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token('carol', ['ROLE_ADMIN']), null, ['permission:wiki.manage']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token('carol', ['ROLE_ADMIN']), new Project('billing'), ['permission:project.view']), 'role grants are global only');
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token('carol', ['ROLE_USER']), null, ['permission:wiki.manage']));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token('root', ['ROLE_SUPER']), new Project('any'), ['permission:project.settings.view']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token('root', ['ROLE_SUPER']), null, ['permission:nope.key']), 'unknown keys are refused even to superusers');
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter()->vote($this->token('root', ['ROLE_ADMIN']), null, ['permission:wiki.manage']), 'no bypass role by default');
    }

    #[Test]
    public function gatesRefuseFirstAndVetoesSuspendTheBypass(): void
    {
        $gate = new class implements PermissionGateInterface {
            public function allows(Permission $permission, object $user, ?object $subject): bool
            {
                return $permission->key !== 'project.issues.triage';
            }
        };
        $veto = new class implements BypassVetoInterface {
            public function vetoes(TokenInterface $token, ?object $subject): bool
            {
                return $subject instanceof Project && $subject->id === 'preview';
            }
        };
        $voter = $this->voter(bypassRoles: ['ROLE_SUPER'], gates: [$gate], vetoes: [$veto]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token('alice'), new Project('billing'), ['permission:project.issues.triage']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token('root', ['ROLE_SUPER']), new Project('billing'), ['permission:project.issues.triage']), 'gates bind superusers too');
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token('root', ['ROLE_SUPER']), new Project('billing'), ['permission:project.view']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token('root', ['ROLE_SUPER']), new Project('preview'), ['permission:project.view']), 'vetoed bypass: own sets decide');
    }

    #[Test]
    public function routesGuestsAndEmptyCataloguesFailClosed(): void
    {
        $voter = $this->voter(openRoutes: ['home']);
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token('alice'), null, ['permission_route:unmapped']));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote(new NullToken(), null, ['permission_route:home']), 'open routes');
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote(new NullToken(), null, ['permission:summary.view']), 'guests');
        self::assertSame(VoterInterface::ACCESS_GRANTED, $this->voter(allowUnmapped: true)->vote($this->token('alice'), null, ['permission_route:unmapped']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->token('alice'), null, ['ROLE_USER']));
        self::assertSame(VoterInterface::ACCESS_ABSTAIN, $voter->vote($this->token('alice'), 'string subject', ['permission:summary.view']));
        self::assertTrue($voter->supportsAttribute('permission_route:x'));
        self::assertFalse($voter->supportsAttribute('ROLE_USER'));

        $empty = $this->voter(catalog: new PermissionCatalog([]), bypassRoles: ['ROLE_SUPER'], openRoutes: ['home'], allowUnmapped: true);
        $vote  = new Vote();
        self::assertSame(VoterInterface::ACCESS_DENIED, $empty->vote($this->token('root', ['ROLE_SUPER']), null, ['permission:summary.view'], $vote), 'P1: an empty catalogue denies everything');
        self::assertSame(VoterInterface::ACCESS_DENIED, $empty->vote($this->token('root', ['ROLE_SUPER']), null, ['permission_route:home']));
        self::assertNotSame([], $vote->reasons);
    }

    #[Test]
    public function decisionsAreMemoisedPerRequestAndReset(): void
    {
        $gate = new class implements PermissionGateInterface {
            public int $calls = 0;

            public function allows(Permission $permission, object $user, ?object $subject): bool
            {
                ++$this->calls;

                return true;
            }
        };
        $voter = $this->voter(gates: [$gate], memoLimit: 2);
        $alice = $this->token('alice');
        $voter->vote($alice, new Project('billing'), ['permission:project.view']);
        $voter->vote($alice, new Project('billing'), ['permission:project.view']);
        self::assertSame(1, $gate->calls, 'memoised');

        $voter->vote($alice, new Project('mobile'), ['permission:project.view']);
        $voter->vote($alice, new Project('other'), ['permission:project.view']);
        $voter->vote($alice, new Project('billing'), ['permission:project.view']);
        self::assertSame(4, $gate->calls, 'the bounded memo was cleared');

        $voter->reset();
        $voter->vote($alice, new Project('billing'), ['permission:project.view']);
        self::assertSame(5, $gate->calls);
        self::assertSame(1, $this->provider->calls, 'one grant load for the whole request');
    }

    /**
     * @param list<string> $bypassRoles
     * @param array<string, list<string>> $roleGrants
     * @param list<string> $openRoutes
     * @param list<PermissionGateInterface> $gates
     * @param list<BypassVetoInterface> $vetoes
     */
    private function voter(
        ?PermissionCatalog $catalog = null,
        ?RoleHierarchy $hierarchy = null,
        array $bypassRoles = [],
        array $roleGrants = [],
        array $openRoutes = [],
        bool $allowUnmapped = false,
        bool $globalAggregation = true,
        ?string $fallbackSet = null,
        array $gates = [],
        array $vetoes = [],
        int $memoLimit = 2048,
    ): PermissionVoter {
        $catalog ??= Catalogues::catalog();
        $sets = Catalogues::registry(new MemoryStorage(), $catalog);

        return new PermissionVoter($catalog, new GrantResolver($this->provider, $sets), $sets, new DefaultContextResolver(), $hierarchy, $bypassRoles, $roleGrants, $openRoutes, $allowUnmapped, $globalAggregation, $fallbackSet, $gates, $vetoes, $memoLimit);
    }

    /**
     * @param list<string> $roles
     */
    private function token(string $user, array $roles = ['ROLE_USER']): UsernamePasswordToken
    {
        return new UsernamePasswordToken(new InMemoryUser($user, null, $roles), 'main', $roles);
    }
}

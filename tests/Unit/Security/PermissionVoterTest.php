<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\Security;

use ArrayObject;
use LogicException;
use Nowo\RbacKitBundle\Context\ContextResolverInterface;
use Nowo\RbacKitBundle\Context\DefaultContextResolver;
use Nowo\RbacKitBundle\Grant\GrantResolver;
use Nowo\RbacKitBundle\Permission\ConfigPermissionProvider;
use Nowo\RbacKitBundle\Permission\Permission;
use Nowo\RbacKitBundle\Permission\PermissionCatalog;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetRegistry;
use Nowo\RbacKitBundle\Security\BypassVetoInterface;
use Nowo\RbacKitBundle\Security\PermissionGateInterface;
use Nowo\RbacKitBundle\Security\PermissionVoter;
use Nowo\RbacKitBundle\Security\RbacActor;
use Nowo\RbacKitBundle\Security\RbacBypassVetoInterface;
use Nowo\RbacKitBundle\Tests\Fixtures\MapGrantProvider;
use Nowo\RbacKitBundle\Tests\Fixtures\Project;
use Nowo\RbacKitBundle\Tests\Fixtures\ReportRow;
use Nowo\RbacKitBundle\Tests\Support\Catalogues;
use Nowo\RbacKitBundle\Tests\Support\MemoryStorage;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
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
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token('alice'), new Project('x'), ['permission:summary.view']), 'with a subject, an aggregate key is decided in that context');
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token('alice'), null, ['permission:reports.export']));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token('bob'), null, ['permission:reports.export']), 'global assignment');
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token('alice'), null, ['permission_route:dashboard_summary']));
    }

    /**
     * Re-verification N1: an aggregate key asked with a context subject is decided in that context only.
     */
    #[Test]
    public function aggregateKeysWithAContextSubjectAreDecidedInThatContext(): void
    {
        $this->provider = new MapGrantProvider(['alice' => [['viewer', 'billing']], 'gwen' => [['viewer', null]]]);
        $voter          = $this->voter(roleGrants: ['ROLE_REPORTER' => ['summary.view']]);
        $alice          = $this->token('alice');

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($alice, null, ['permission:summary.view']), 'without a subject: any context');
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($alice, new Project('billing'), ['permission:summary.view']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($alice, new Project('mobile'), ['permission:summary.view']), 'a non-granting context');
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($alice, new Project('billing', 'organization'), ['permission:summary.view']), 'same id, other type');
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($alice, new stdClass(), ['permission:summary.view']), 'N7: a non-context subject grants nothing through aggregation');
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token('gwen'), new Project('mobile'), ['permission:summary.view']), 'a global assignment holds it everywhere');
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token('rita', ['ROLE_REPORTER']), new Project('mobile'), ['permission:summary.view']), 'a role grant holds it everywhere');
    }

    /**
     * Re-verification N7: a subject that is given but does not resolve to a typed context fails closed for an aggregate
     * key (a context key is refused in the same case); a resolver that unwraps the subject decides in its context.
     */
    #[Test]
    public function unresolvedSubjectsGrantNothingThroughAggregation(): void
    {
        $this->provider = new MapGrantProvider(['alice' => [['viewer', 'billing']]]);
        $voter          = $this->voter();
        $alice          = $this->token('alice');
        $row            = new ReportRow(new Project('billing'));

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($alice, $row, ['permission:summary.view']), 'a report row wrapping a project');
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($alice, new Project('billing', 'Project'), ['permission:summary.view']), 'a wrong-case type');
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($alice, new Project(' '), ['permission:summary.view']), 'a blank id');
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($alice, null, ['permission:summary.view']), 'without a subject it still aggregates');

        $untyped = new class implements ContextResolverInterface {
            public function contextId(object $subject): string
            {
                return 'billing';
            }
        };
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter(resolver: $untyped)->vote($alice, new Project('billing'), ['permission:summary.view']), 'a custom resolver that forgets the type');

        $unwrapping = new class implements ContextResolverInterface {
            public function contextId(object $subject): ?string
            {
                return (new DefaultContextResolver())->contextId($subject instanceof ReportRow ? $subject->project : $subject);
            }
        };
        $voter = $this->voter(resolver: $unwrapping);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($alice, $row, ['permission:summary.view']), 'a resolver that unwraps the row');
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($alice, new ReportRow(new Project('mobile')), ['permission:summary.view']));
    }

    /**
     * Appendix CI H1: a set held in a context never grants a global key unless the key is flagged `aggregate`, and an
     * `all: true` owner set held in a context carries context keys only.
     */
    #[Test]
    public function contextHeldSetsNeverPromoteGlobalKeys(): void
    {
        $this->provider = new MapGrantProvider([
            'eve'  => [['owner', 'eve-sandbox']],
            'mike' => [['mixed', 'billing']],
        ]);
        $sets  = Catalogues::sets() + ['mixed' => Catalogues::set(['reports.export', 'summary.view', 'project.view'])];
        $voter = $this->voter(sets: $sets);
        $eve   = $this->token('eve');

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($eve, new Project('eve-sandbox'), ['permission:project.settings.view']), 'the owner keeps every context key in its context');
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($eve, new Project('billing'), ['permission:project.view']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($eve, null, ['permission:reports.export']), 'all: true holds no global key');
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($eve, null, ['permission:summary.view']), 'not even an aggregating one');
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($eve, new Project('eve-sandbox'), ['permission:reports.export']), 'nor with its context as subject');

        $mike = $this->token('mike');
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($mike, null, ['permission:reports.export']), 'a global key of a context-held set is not promoted');
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($mike, null, ['permission:summary.view']), 'unless the key is flagged aggregate');

        $this->provider = new MapGrantProvider(['root' => [['owner', null]]]);
        $global         = $this->voter(sets: ['owner' => Catalogues::set([], true, [], true, true)]);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $global->vote($this->token('root'), null, ['permission:wiki.manage']), 'include_global: a global owner set');
    }

    /**
     * Appendix CI H1, the shipped demo: carol owns the Mobile project only and must not hold the global export that
     * only the auditor set gives; alice (viewer) still opens the aggregated reports page.
     */
    #[Test]
    public function theDemoOwnerOfOneProjectHoldsNoGlobalKey(): void
    {
        $catalog = new PermissionCatalog([new ConfigPermissionProvider([
            'reports.view'           => ['scope' => 'global', 'routes' => ['demo_reports'], 'group' => 'general', 'label' => null, 'description' => null, 'aggregate' => true],
            'reports.export'         => ['scope' => 'global', 'routes' => [], 'group' => 'general', 'label' => null, 'description' => null],
            'project.view'           => ['scope' => 'context', 'routes' => ['demo_project'], 'group' => 'project', 'label' => null, 'description' => null],
            'project.issues.triage'  => ['scope' => 'context', 'routes' => [], 'group' => 'project', 'label' => null, 'description' => null],
            'project.settings.view'  => ['scope' => 'context', 'routes' => ['demo_project_settings'], 'group' => 'project', 'label' => null, 'description' => null],
            'project.members.manage' => ['scope' => 'context', 'routes' => [], 'group' => 'project', 'label' => null, 'description' => null],
        ])]);
        $this->provider = new MapGrantProvider([
            'alice' => [['viewer', 'billing'], ['triage', 'billing'], ['viewer', 'mobile']],
            'bob'   => [['auditor', null]],
            'carol' => [['owner', 'mobile']],
        ]);
        $voter = $this->voter(catalog: $catalog, sets: [
            'viewer'  => Catalogues::set(['reports.view', 'project.view']),
            'triage'  => Catalogues::set(['project.view', 'project.issues.triage']),
            'auditor' => Catalogues::set(['reports.view', 'reports.export']),
            'owner'   => Catalogues::set([], true, [], true),
        ]);

        $carol = $this->token('carol');
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($carol, null, ['permission:reports.export']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($carol, null, ['permission:reports.view']));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($carol, new Project('mobile'), ['permission:project.members.manage']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($carol, new Project('billing'), ['permission:project.view']));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token('alice'), null, ['permission:reports.view']), 'reports.view aggregates');
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($this->token('alice'), null, ['permission:reports.export']));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token('bob'), null, ['permission:reports.export']), 'the global auditor');
    }

    /**
     * Appendix CI M1: context ids are typed — `organization:5` and `project:5` are two contexts.
     */
    #[Test]
    public function contextTypesWithTheSameIdDoNotShareGrants(): void
    {
        $this->provider = new MapGrantProvider(['dave' => [['owner', 'organization:5']]]);
        $voter          = $this->voter();
        $dave           = $this->token('dave');

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($dave, new Project('5', 'organization'), ['permission:project.settings.view']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($dave, new Project('5'), ['permission:project.settings.view']), 'another type, same id');
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($dave, new Project('5', 'Bad Type'), ['permission:project.settings.view']), 'invalid type: not a context');

        $untyped = new class implements ContextResolverInterface {
            public function contextId(object $subject): string
            {
                return '5';
            }
        };
        $vote = new Vote();
        self::assertSame(VoterInterface::ACCESS_DENIED, $this->voter(resolver: $untyped)->vote($dave, new Project('5', 'organization'), ['permission:project.settings.view'], $vote), 'an untyped id from a custom resolver is refused');
        self::assertContains('The context resolver returned an untyped context id.', $vote->reasons);
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
        $voter = $this->voter(gates: [$gate], memoLimit: 3);
        $alice = $this->token('alice');
        $voter->vote($alice, new Project('billing'), ['permission:project.view']);
        $voter->vote($alice, new Project('billing'), ['permission:project.view']);
        self::assertSame(1, $gate->calls, 'memoised');

        $voter->vote($alice, new Project('mobile'), ['permission:project.view']);
        $voter->vote($alice, new Project('other'), ['permission:project.view']);
        $voter->vote($alice, new Project('more'), ['permission:project.view']);
        $voter->vote($alice, new Project('billing'), ['permission:project.view']);
        self::assertSame(5, $gate->calls, 'the bounded memo was cleared');

        $voter->reset();
        $voter->vote($alice, new Project('billing'), ['permission:project.view']);
        self::assertSame(6, $gate->calls);
        self::assertSame(1, $this->provider->calls, 'one grant load for the whole request');
    }

    /**
     * Appendix CI L5: the memo includes the subject's context for global keys, skips non-context subjects and the
     * veto state is part of the key.
     */
    #[Test]
    public function theMemoFollowsTheSubjectAndTheVetoState(): void
    {
        $gate = new class implements PermissionGateInterface {
            public int $calls = 0;

            public function allows(Permission $permission, object $user, ?object $subject): bool
            {
                ++$this->calls;

                return (!$subject instanceof Project || $subject->id !== 'mobile') && !($subject instanceof stdClass);
            }
        };
        $veto = new class implements BypassVetoInterface {
            public bool $asMember = false;

            public function vetoes(TokenInterface $token, ?object $subject): bool
            {
                return $this->asMember;
            }
        };
        $voter = $this->voter(bypassRoles: ['ROLE_SUPER'], gates: [$gate], vetoes: [$veto]);
        $alice = $this->token('alice');

        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($alice, new Project('billing'), ['permission:summary.view']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($alice, new Project('mobile'), ['permission:summary.view']), 'global key, other subject: the gate is asked again');
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($alice, new stdClass(), ['permission:summary.view']));
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($alice, new stdClass(), ['permission:summary.view']));
        self::assertSame(4, $gate->calls, 'non-context subjects are not memoised');

        $root = $this->token('root', ['ROLE_SUPER']);
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($root, null, ['permission:wiki.manage']));
        $veto->asMember = true;
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($root, null, ['permission:wiki.manage']), 'a veto switched on mid-request is seen');
    }

    /**
     * 1.2: a framework-free veto suspends the bypass like a deprecated one; a tagged object on neither port fails closed
     * (throws) instead of being skipped.
     */
    #[Test]
    public function frameworkFreeVetoesSuspendTheBypass(): void
    {
        $root = $this->token('root', ['ROLE_SUPER']);
        $root->setAttribute('view_as_member', true);

        $new = new class implements RbacBypassVetoInterface {
            public ?RbacActor $seen = null;

            public function vetoesBypass(RbacActor $actor, ?object $subject): bool
            {
                $this->seen = $actor;

                return $actor->tokenAttribute('view_as_member') === true;
            }
        };
        $voter = $this->voter(bypassRoles: ['ROLE_SUPER'], vetoes: [$new]);
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($root, new Project('billing'), ['permission:project.view']));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($this->token('root', ['ROLE_SUPER']), new Project('billing'), ['permission:project.view']));
        self::assertInstanceOf(RbacActor::class, $new->seen);
        self::assertSame('root', $new->seen->userIdentifier);
        self::assertSame(['ROLE_SUPER'], $new->seen->reachableRoles);

        $notAPort = new stdClass();
        $broken   = $this->voter(bypassRoles: ['ROLE_SUPER'], vetoes: [$notAPort]); // @phpstan-ignore argument.type (a tagged object on neither port)
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('The bypass veto "stdClass" must implement');
        $broken->vote($root, new Project('billing'), ['permission:project.view']);
    }

    /**
     * Audit gap: a mixed list (deprecated port, new port) is asked in order and stops at the first veto, as in 1.1; an
     * exception thrown by a veto propagates (never read as "no veto").
     */
    #[Test]
    public function mixedVetoListsShortCircuitAndExceptionsPropagate(): void
    {
        $calls  = new ArrayObject();
        $legacy = new class($calls) implements BypassVetoInterface {
            /** @param ArrayObject<int, string> $calls */
            public function __construct(private readonly ArrayObject $calls)
            {
            }

            public function vetoes(TokenInterface $token, ?object $subject): bool
            {
                $this->calls[] = 'legacy';

                return $subject instanceof Project && $subject->id === 'preview';
            }
        };
        $new = new class($calls) implements RbacBypassVetoInterface {
            /** @param ArrayObject<int, string> $calls */
            public function __construct(private readonly ArrayObject $calls)
            {
            }

            public function vetoesBypass(RbacActor $actor, ?object $subject): bool
            {
                $this->calls[] = 'new';

                return $subject instanceof Project && $subject->id === 'staging';
            }
        };
        $voter = $this->voter(bypassRoles: ['ROLE_SUPER'], vetoes: [$legacy, $new]);
        $root  = $this->token('root', ['ROLE_SUPER']);

        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($root, new Project('preview'), ['permission:project.view']));
        self::assertSame(['legacy'], $calls->getArrayCopy(), 'the first veto short-circuits');
        self::assertSame(VoterInterface::ACCESS_DENIED, $voter->vote($root, new Project('staging'), ['permission:project.view']));
        self::assertSame(VoterInterface::ACCESS_GRANTED, $voter->vote($root, new Project('billing'), ['permission:project.view']));
        self::assertSame(['legacy', 'legacy', 'new', 'legacy', 'new'], $calls->getArrayCopy());

        $throwing = new class implements RbacBypassVetoInterface {
            public function vetoesBypass(RbacActor $actor, ?object $subject): bool
            {
                throw new RuntimeException('veto backend down');
            }
        };
        $this->expectException(RuntimeException::class);
        $this->voter(bypassRoles: ['ROLE_SUPER'], vetoes: [$throwing])->vote($root, new Project('billing'), ['permission:project.view']);
    }

    /**
     * @param list<string> $bypassRoles
     * @param array<string, list<string>> $roleGrants
     * @param list<string> $openRoutes
     * @param list<PermissionGateInterface> $gates
     * @param list<BypassVetoInterface|RbacBypassVetoInterface> $vetoes
     * @param array<string, array{label: string|null, description: string|null, permissions: list<string>, all: bool, include_global: bool, except: list<string>, locked: bool}>|null $sets
     */
    private function voter(
        ?PermissionCatalog $catalog = null,
        ?RoleHierarchy $hierarchy = null,
        array $bypassRoles = [],
        array $roleGrants = [],
        array $openRoutes = [],
        bool $allowUnmapped = false,
        ?string $fallbackSet = null,
        array $gates = [],
        array $vetoes = [],
        int $memoLimit = 2048,
        ?array $sets = null,
        ?ContextResolverInterface $resolver = null,
    ): PermissionVoter {
        $catalog ??= Catalogues::catalog();
        $registry = new PermissionSetRegistry($catalog, new MemoryStorage(), $sets ?? Catalogues::sets());

        return new PermissionVoter($catalog, new GrantResolver($this->provider, $registry), $registry, $resolver ?? new DefaultContextResolver(), $hierarchy, $bypassRoles, $roleGrants, $openRoutes, $allowUnmapped, $fallbackSet, $gates, $vetoes, $memoLimit);
    }

    /**
     * @param list<string> $roles
     */
    private function token(string $user, array $roles = ['ROLE_USER']): UsernamePasswordToken
    {
        return new UsernamePasswordToken(new InMemoryUser($user, null, $roles), 'main', $roles);
    }
}

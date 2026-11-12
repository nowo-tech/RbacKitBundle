<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\Equivalence;

use Closure;
use Nowo\RbacKitBundle\Context\DefaultContextResolver;
use Nowo\RbacKitBundle\Grant\GrantResolver;
use Nowo\RbacKitBundle\Permission\Permission;
use Nowo\RbacKitBundle\Security\BypassVetoInterface;
use Nowo\RbacKitBundle\Security\PermissionGateInterface;
use Nowo\RbacKitBundle\Security\PermissionVoter;
use Nowo\RbacKitBundle\Security\RbacActor;
use Nowo\RbacKitBundle\Security\RbacBypassVetoInterface;
use Nowo\RbacKitBundle\Tests\Fixtures\MapGrantProvider;
use Nowo\RbacKitBundle\Tests\Fixtures\Project;
use Nowo\RbacKitBundle\Tests\Fixtures\ReportRow;
use Nowo\RbacKitBundle\Tests\Support\Catalogues;
use Nowo\RbacKitBundle\Tests\Support\Legacy\PermissionVoterV110;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;
use Symfony\Component\Security\Core\Authentication\Token\NullToken;
use Symfony\Component\Security\Core\Authentication\Token\SwitchUserToken;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;
use Symfony\Component\Security\Core\Role\RoleHierarchy;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function array_keys;
use function array_values;
use function count;
use function implode;
use function in_array;
use function is_object;
use function sort;
use function sprintf;
use function str_starts_with;
use function trim;

/**
 * 1.2 (REQ-ARCH-001 rule 3, immediate audit): the framework-free bypass veto port decides exactly like the deprecated
 * one, and both decide exactly like the 1.1.0 voter.
 *
 * For every combination of voter configuration (fallback set, unmapped routes, role grants), bypass veto predicate,
 * deny-first gate, token (guest, members, a global auditor, a fallback user, a direct and a hierarchy-reached
 * superuser, a superuser "viewing as member", a role-hierarchy reporter, an impersonation), attribute (every catalogue
 * key, an unknown key, mapped / open / unmapped routes, an unsupported attribute) and subject (none, contexts, the
 * vetoed and gated contexts, a same-id other-type context, a blank id, a non-context object, a wrapper row, a scalar),
 * four voters are asked twice (the second pass hits the memo):
 *
 * - the verbatim 1.1.0 voter ({@see PermissionVoterV110}) with the predicate behind the deprecated port (reference);
 * - the 1.2 voter with the predicate behind the deprecated port;
 * - the 1.2 voter with the predicate behind the framework-free port;
 * - the 1.2 voter with a service implementing both ports, whose deprecated method vetoes everything (so any call of
 *   the deprecated method by mistake shows up as a difference).
 *
 * Every answer must be identical. The deny-by-default invariants are asserted on the same matrix.
 */
final class PortEquivalenceMatrixTest extends TestCase
{
    /** Users of the matrix grant provider. */
    private const MAP = [
        'alice' => [['viewer', 'billing'], ['triage', 'billing'], ['viewer', 'mobile']],
        'bob'   => [['auditor', null]],
        'admin' => [['viewer', 'preview']],
    ];

    /** @var array<int, int> */
    private array $outcomes = [];

    #[Test]
    public function everyDecisionIsIdenticalAcrossPortsAndTo110(): void
    {
        $compared    = 0;
        $differences = [];
        foreach ($this->configs() as $configName => $config) {
            foreach ($this->vetoPredicates() as $vetoName => $predicate) {
                foreach ($this->gates() as $gateName => $gates) {
                    $voters = $this->voters($config, $predicate, $gates);
                    for ($pass = 1; $pass <= 2; ++$pass) {
                        foreach ($this->tokens() as $tokenName => $token) {
                            foreach ($this->attributes() as $attribute) {
                                foreach ($this->subjects() as $subjectName => $subject) {
                                    $answers = [];
                                    foreach ($voters as $voterName => $voter) {
                                        $answers[$voterName] = $voter->vote($token, $subject, [$attribute]);
                                    }
                                    ++$compared;
                                    $reference = $answers['1.1.0'];
                                    foreach ($answers as $voterName => $answer) {
                                        if ($answer !== $reference) {
                                            $differences[] = sprintf('%s/%s/%s pass %d: %s %s on %s: %s answered %d, 1.1.0 %d', $configName, $vetoName, $gateName, $pass, $tokenName, $attribute, $subjectName, $voterName, $answer, $reference);
                                        }
                                    }
                                    $this->assertDenyByDefault($config, $gateName, $tokenName, $attribute, $subject, $reference);
                                    $this->outcomes[$reference] = ($this->outcomes[$reference] ?? 0) + 1;
                                }
                            }
                        }
                    }
                }
            }
        }

        self::assertSame([], $differences, implode("\n", $differences));
        self::assertSame(2 * 6 * 3 * 2 * count($this->tokens()) * count($this->attributes()) * count($this->subjects()), $compared);
        self::assertGreaterThan(50000, $compared, 'the matrix is exhaustive, not a sample');
        foreach ([VoterInterface::ACCESS_GRANTED, VoterInterface::ACCESS_DENIED, VoterInterface::ACCESS_ABSTAIN] as $outcome) {
            self::assertGreaterThan(100, $this->outcomes[$outcome] ?? 0, 'every outcome occurs: ' . $outcome);
        }
    }

    /**
     * The bypass and its veto change decisions in the matrix (it is not trivially equal): a superuser passes a context
     * key in a context they hold nothing in, a veto on the subject makes their own sets decide, and the framework-free
     * actor carries the token attribute, the impersonator and the reachable roles the deprecated port read off the token.
     */
    #[Test]
    public function theVetoPredicatesReallyMatter(): void
    {
        $config = $this->configs()['strict'];
        $tokens = $this->tokens();
        foreach (['legacy', 'new'] as $port) {
            $none      = $this->voters($config, $this->vetoPredicates()['none'], [])[$port];
            $preview   = $this->voters($config, $this->vetoPredicates()['preview subject'], [])[$port];
            $viewAs    = $this->voters($config, $this->vetoPredicates()['view-as-member attribute'], [])[$port];
            $impostor  = $this->voters($config, $this->vetoPredicates()['impersonation'], [])[$port];
            $hierarchy = $this->voters($config, $this->vetoPredicates()['reached through ROLE_ADMIN'], [])[$port];

            self::assertSame(VoterInterface::ACCESS_GRANTED, $none->vote($tokens['root'], new Project('other'), ['permission:project.settings.view']), $port);
            self::assertSame(VoterInterface::ACCESS_GRANTED, $none->vote($tokens['root'], new Project('preview'), ['permission:project.settings.view']), $port);
            self::assertSame(VoterInterface::ACCESS_DENIED, $preview->vote($tokens['root'], new Project('preview'), ['permission:project.settings.view']), $port);
            self::assertSame(VoterInterface::ACCESS_DENIED, $preview->vote($tokens['admin'], new Project('preview'), ['permission:project.settings.view']), $port . ': admin holds only viewer in preview');
            self::assertSame(VoterInterface::ACCESS_GRANTED, $preview->vote($tokens['admin'], new Project('preview'), ['permission:project.view']), $port);
            self::assertSame(VoterInterface::ACCESS_GRANTED, $viewAs->vote($tokens['admin'], new Project('other'), ['permission:project.view']), $port);
            self::assertSame(VoterInterface::ACCESS_DENIED, $viewAs->vote($tokens['admin viewing as member'], new Project('other'), ['permission:project.view']), $port);
            self::assertSame(VoterInterface::ACCESS_DENIED, $impostor->vote($tokens['root impersonating'], new Project('other'), ['permission:project.view']), $port);
            self::assertSame(VoterInterface::ACCESS_GRANTED, $impostor->vote($tokens['root'], new Project('other'), ['permission:project.view']), $port);
            self::assertSame(VoterInterface::ACCESS_DENIED, $hierarchy->vote($tokens['admin'], new Project('other'), ['permission:project.view']), $port);
            self::assertSame(VoterInterface::ACCESS_GRANTED, $hierarchy->vote($tokens['root'], new Project('other'), ['permission:project.view']), $port);
        }
    }

    /**
     * Deny by default, whatever the port: unknown keys, unsupported subjects, guests (except open routes and, when
     * allowed, unmapped routes), gated decisions and context keys without a typed context are never granted.
     *
     * @param array{fallback: string|null, unmapped: bool, roleGrants: array<string, list<string>>} $config
     */
    private function assertDenyByDefault(array $config, string $gateName, string $tokenName, string $attribute, mixed $subject, int $answer): void
    {
        $supported = str_starts_with($attribute, PermissionVoter::PREFIX) || str_starts_with($attribute, PermissionVoter::ROUTE);
        if (!$supported || ($subject !== null && !is_object($subject))) {
            self::assertSame(VoterInterface::ACCESS_ABSTAIN, $answer, $attribute);

            return;
        }
        $openRoute     = $attribute === PermissionVoter::ROUTE . 'host_home';
        $unmappedRoute = $attribute === PermissionVoter::ROUTE . 'unmapped_route';
        if ($openRoute || ($unmappedRoute && $config['unmapped'])) {
            self::assertSame(VoterInterface::ACCESS_GRANTED, $answer, $attribute);

            return;
        }
        if ($unmappedRoute || $attribute === PermissionVoter::PREFIX . 'unknown.key' || $tokenName === 'guest') {
            self::assertSame(VoterInterface::ACCESS_DENIED, $answer, $tokenName . ' ' . $attribute);

            return;
        }
        if ($gateName === 'deny everything in locked' && $subject instanceof Project && $subject->id === 'locked' && $subject->type === 'project') {
            self::assertSame(VoterInterface::ACCESS_DENIED, $answer, 'a gate binds every user, bypass included');
        }
        if ($gateName === 'deny wiki.manage to bob' && $tokenName === 'bob' && $attribute === PermissionVoter::PREFIX . 'wiki.manage') {
            self::assertSame(VoterInterface::ACCESS_DENIED, $answer);
        }
        $contextKeys = ['project.view', 'project.issues.triage', 'project.settings.view'];
        foreach ($contextKeys as $key) {
            if ($attribute === PermissionVoter::PREFIX . $key && (!$subject instanceof Project || trim($subject->id) === '')) {
                self::assertSame(VoterInterface::ACCESS_DENIED, $answer, sprintf('%s %s: a context key needs a typed context subject', $tokenName, $attribute));
            }
        }
    }

    /**
     * @param array{fallback: string|null, unmapped: bool, roleGrants: array<string, list<string>>} $config
     * @param (Closure(string, list<string>, list<string>, mixed, string|null, object|null): bool)|null $predicate
     * @param list<PermissionGateInterface> $gates
     *
     * @return array<string, VoterInterface>
     */
    private function voters(array $config, ?Closure $predicate, array $gates): array
    {
        $hierarchy = new RoleHierarchy(['ROLE_ADMIN' => ['ROLE_SUPER'], 'ROLE_LEAD' => ['ROLE_REPORTER']]);
        $legacy    = $predicate instanceof Closure ? [$this->legacyVeto($predicate, $hierarchy)] : [];
        $new       = $predicate instanceof Closure ? [$this->newVeto($predicate)] : [];
        $dual      = $predicate instanceof Closure ? [$this->dualVeto($predicate)] : [];

        $make = static function (string $class, array $vetoes) use ($config, $gates, $hierarchy): VoterInterface {
            $registry = Catalogues::registry();

            $voter = new $class(Catalogues::catalog(), new GrantResolver(new MapGrantProvider(self::MAP), $registry), $registry, new DefaultContextResolver(), $hierarchy, ['ROLE_SUPER'], $config['roleGrants'], ['host_home'], $config['unmapped'], $config['fallback'], $gates, $vetoes);
            self::assertInstanceOf(VoterInterface::class, $voter);

            return $voter;
        };

        return [
            '1.1.0'  => $make(PermissionVoterV110::class, $legacy),
            'legacy' => $make(PermissionVoter::class, $legacy),
            'new'    => $make(PermissionVoter::class, $new),
            'dual'   => $make(PermissionVoter::class, $dual),
        ];
    }

    /**
     * @return array<string, array{fallback: string|null, unmapped: bool, roleGrants: array<string, list<string>>}>
     */
    private function configs(): array
    {
        return [
            'strict'  => ['fallback' => null, 'unmapped' => false, 'roleGrants' => ['ROLE_REPORTER' => ['summary.view', 'reports.export']]],
            'lenient' => ['fallback' => 'viewer', 'unmapped' => true, 'roleGrants' => []],
        ];
    }

    /**
     * Veto predicates over what both ports can see: user identifier, token roles, reachable roles, the
     * `view_as_member` token attribute, the impersonator and the subject. Null = no veto service at all.
     *
     * @return array<string, (Closure(string, list<string>, list<string>, mixed, string|null, object|null): bool)|null>
     */
    private function vetoPredicates(): array
    {
        return [
            'none'                       => null,
            'never'                      => static fn (): bool => false,
            'preview subject'            => static fn (string $user, array $roles, array $reachable, mixed $viewAs, ?string $impersonator, ?object $subject): bool => $subject instanceof Project && $subject->id === 'preview',
            'view-as-member attribute'   => static fn (string $user, array $roles, array $reachable, mixed $viewAs): bool => $viewAs === true,
            'impersonation'              => static fn (string $user, array $roles, array $reachable, mixed $viewAs, ?string $impersonator): bool => $impersonator !== null,
            'reached through ROLE_ADMIN' => static fn (string $user, array $roles, array $reachable): bool => in_array('ROLE_ADMIN', $roles, true) && in_array('ROLE_SUPER', $reachable, true),
        ];
    }

    /**
     * @return array<string, list<PermissionGateInterface>>
     */
    private function gates(): array
    {
        $bob = new class implements PermissionGateInterface {
            public function allows(Permission $permission, object $user, ?object $subject): bool
            {
                return !($permission->key === 'wiki.manage' && $user instanceof InMemoryUser && $user->getUserIdentifier() === 'bob');
            }
        };
        $locked = new class implements PermissionGateInterface {
            public function allows(Permission $permission, object $user, ?object $subject): bool
            {
                return !($subject instanceof Project && $subject->id === 'locked' && $subject->type === 'project');
            }
        };

        return ['no gate' => [], 'deny wiki.manage to bob' => [$bob], 'deny everything in locked' => [$locked]];
    }

    /**
     * @return array<string, TokenInterface>
     */
    private function tokens(): array
    {
        $viewAs = $this->token('admin', ['ROLE_ADMIN']);
        $viewAs->setAttribute('view_as_member', true);
        $root = $this->token('root', ['ROLE_SUPER']);

        return [
            'guest'                   => new NullToken(),
            'alice'                   => $this->token('alice', ['ROLE_USER']),
            'bob'                     => $this->token('bob', ['ROLE_USER']),
            'carol (no assignment)'   => $this->token('carol', ['ROLE_USER']),
            'root'                    => $root,
            'admin'                   => $this->token('admin', ['ROLE_ADMIN']),
            'admin viewing as member' => $viewAs,
            'lead (hierarchy)'        => $this->token('lead', ['ROLE_LEAD']),
            'root impersonating'      => new SwitchUserToken(new InMemoryUser('root', null, ['ROLE_SUPER']), 'main', ['ROLE_SUPER'], $this->token('alice', ['ROLE_USER'])),
        ];
    }

    /**
     * @return list<string>
     */
    private function attributes(): array
    {
        $attributes = [];
        foreach (array_keys(Catalogues::permissions()) as $key) {
            $attributes[] = PermissionVoter::PREFIX . $key;
        }

        return [
            ...$attributes,
            PermissionVoter::PREFIX . 'unknown.key',
            PermissionVoter::ROUTE . 'dashboard_summary',
            PermissionVoter::ROUTE . 'project_show',
            PermissionVoter::ROUTE . 'project_issue_triage',
            PermissionVoter::ROUTE . 'project_settings',
            PermissionVoter::ROUTE . 'host_home',
            PermissionVoter::ROUTE . 'unmapped_route',
            'ROLE_USER',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function subjects(): array
    {
        return [
            'no subject'           => null,
            'billing'              => new Project('billing'),
            'mobile'               => new Project('mobile'),
            'preview'              => new Project('preview'),
            'locked'               => new Project('locked'),
            'other'                => new Project('other'),
            'organization:billing' => new Project('billing', 'organization'),
            'blank id'             => new Project(' '),
            'non-context'          => new stdClass(),
            'report row'           => new ReportRow(new Project('billing')),
            'scalar'               => 'billing',
        ];
    }

    /**
     * @param list<string> $roles
     */
    private function token(string $user, array $roles): UsernamePasswordToken
    {
        return new UsernamePasswordToken(new InMemoryUser($user, null, $roles), 'main', $roles);
    }

    /**
     * The predicate behind the deprecated port: read everything off the token, as a 1.1 host did.
     *
     * @param Closure(string, list<string>, list<string>, mixed, string|null, object|null): bool $predicate
     */
    private function legacyVeto(Closure $predicate, RoleHierarchy $hierarchy): BypassVetoInterface
    {
        return new class($predicate, $hierarchy) implements BypassVetoInterface {
            /**
             * @param Closure(string, list<string>, list<string>, mixed, string|null, object|null): bool $predicate
             */
            public function __construct(private readonly Closure $predicate, private readonly RoleHierarchy $hierarchy)
            {
            }

            public function vetoes(TokenInterface $token, ?object $subject): bool
            {
                $reachable = $this->hierarchy->getReachableRoleNames($token->getRoleNames());
                sort($reachable);

                return ($this->predicate)(
                    $token->getUserIdentifier(),
                    array_values($token->getRoleNames()),
                    $reachable,
                    $token->hasAttribute('view_as_member') ? $token->getAttribute('view_as_member') : null,
                    $token instanceof SwitchUserToken ? $token->getOriginalToken()->getUserIdentifier() : null,
                    $subject,
                );
            }
        };
    }

    /**
     * The same predicate behind the framework-free port: read everything off the actor.
     *
     * @param Closure(string, list<string>, list<string>, mixed, string|null, object|null): bool $predicate
     */
    private function newVeto(Closure $predicate): RbacBypassVetoInterface
    {
        return new class($predicate) implements RbacBypassVetoInterface {
            /**
             * @param Closure(string, list<string>, list<string>, mixed, string|null, object|null): bool $predicate
             */
            public function __construct(private readonly Closure $predicate)
            {
            }

            public function vetoesBypass(RbacActor $actor, ?object $subject): bool
            {
                return ($this->predicate)($actor->userIdentifier, $actor->roles, $actor->reachableRoles, $actor->tokenAttribute('view_as_member'), $actor->impersonatorIdentifier, $subject);
            }
        };
    }

    /**
     * Both ports; the deprecated method vetoes everything, so calling it by mistake changes decisions.
     *
     * @param Closure(string, list<string>, list<string>, mixed, string|null, object|null): bool $predicate
     */
    private function dualVeto(Closure $predicate): RbacBypassVetoInterface
    {
        return new class($predicate) implements BypassVetoInterface, RbacBypassVetoInterface {
            /**
             * @param Closure(string, list<string>, list<string>, mixed, string|null, object|null): bool $predicate
             */
            public function __construct(private readonly Closure $predicate)
            {
            }

            public function vetoesBypass(RbacActor $actor, ?object $subject): bool
            {
                return ($this->predicate)($actor->userIdentifier, $actor->roles, $actor->reachableRoles, $actor->tokenAttribute('view_as_member'), $actor->impersonatorIdentifier, $subject);
            }

            public function vetoes(TokenInterface $token, ?object $subject): bool
            {
                return true;
            }
        };
    }
}

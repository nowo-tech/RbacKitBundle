<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Security;

use Nowo\RbacKitBundle\Context\ContextId;
use Nowo\RbacKitBundle\Context\ContextResolverInterface;
use Nowo\RbacKitBundle\Grant\GrantResolver;
use Nowo\RbacKitBundle\Permission\Permission;
use Nowo\RbacKitBundle\Permission\PermissionCatalog;
use Nowo\RbacKitBundle\Permission\PermissionScope;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetRegistry;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Role\RoleHierarchyInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Service\ResetInterface;

use function array_intersect;
use function count;
use function implode;
use function in_array;
use function is_object;
use function sort;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * `permission:<key>` and `permission_route:<route>`: whether the user may use a page, a page resource or a menu link.
 *
 * Order of the decision: an empty catalogue, an unknown key, an unmapped route (unless `catalogue.open_routes` or
 * `catalogue.unmapped_routes: allow`), a guest, or a context permission without a resolvable context subject are
 * refused (fail closed); then every {@see PermissionGateInterface} may refuse; then `voter.bypass_roles` pass (unless
 * a {@see BypassVetoInterface} suspends it); a **context** key passes when a set the user holds in that context (its
 * typed id, {@see ContextId}) grants it; a **global** key passes on `role_grants` (after the role hierarchy), a global
 * assignment, — only for keys flagged `aggregate` — when at least one of the user's contexts grants it, or — for users
 * without any assignment — when `voter.fallback_permission_set` grants it. A set held in a context never grants a
 * global key that is not flagged `aggregate` (context → global isolation).
 *
 * Decisions are memoised per request (bounded) for (user, roles, key, context id of the subject, bypass veto state)
 * and forgotten by {@see self::reset()} (kernel.reset, worker mode). A subject that is not a context is not memoised
 * (a gate may look at it); vetoes are asked on every decision of a bypass role.
 *
 * @extends Voter<string, object|null>
 */
final class PermissionVoter extends Voter implements ResetInterface
{
    /** Attribute prefix of a permission key. */
    public const PREFIX = 'permission:';

    /** Attribute prefix of a route (menus, links): the route's catalogued permission. */
    public const ROUTE = 'permission_route:';

    /** @var array<string, bool> memo key => decision */
    private array $decisions = [];

    /**
     * @param list<string> $bypassRoles roles that pass every permission (`voter.bypass_roles`, default none)
     * @param array<string, list<string>> $roleGrants role => global permission keys (`role_grants`)
     * @param list<string> $openRoutes routes the route attribute always grants (`catalogue.open_routes`)
     * @param bool $allowUnmappedRoutes `catalogue.unmapped_routes: allow`
     * @param string|null $fallbackSet `voter.fallback_permission_set`
     * @param iterable<PermissionGateInterface> $gates deny-first gates
     * @param iterable<BypassVetoInterface> $vetoes bypass vetoes
     * @param int $memoLimit decisions memoised per request before the memo is cleared
     */
    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly GrantResolver $grants,
        private readonly PermissionSetRegistry $sets,
        private readonly ContextResolverInterface $contexts,
        private readonly ?RoleHierarchyInterface $roleHierarchy = null,
        private readonly array $bypassRoles = [],
        private readonly array $roleGrants = [],
        private readonly array $openRoutes = [],
        private readonly bool $allowUnmappedRoutes = false,
        private readonly ?string $fallbackSet = null,
        private readonly iterable $gates = [],
        private readonly iterable $vetoes = [],
        private readonly int $memoLimit = 2048,
    ) {
    }

    /**
     * Forgets the memoised decisions (kernel.reset, worker mode).
     */
    public function reset(): void
    {
        $this->decisions = [];
    }

    /**
     * Both attribute prefixes (cacheable voter).
     */
    public function supportsAttribute(string $attribute): bool
    {
        return str_starts_with($attribute, self::PREFIX) || str_starts_with($attribute, self::ROUTE);
    }

    /**
     * A supported attribute with no subject or an object subject.
     */
    protected function supports(string $attribute, mixed $subject): bool
    {
        return $this->supportsAttribute($attribute) && ($subject === null || is_object($subject));
    }

    /**
     * The decision described in the class comment.
     */
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        if ($this->catalog->isEmpty()) {
            $vote?->addReason('The permission catalogue is empty.');

            return false;
        }
        $subject = is_object($subject) ? $subject : null;

        if (str_starts_with($attribute, self::ROUTE)) {
            $route = substr($attribute, strlen(self::ROUTE));
            if (in_array($route, $this->openRoutes, true)) {
                return true;
            }
            $permission = $this->catalog->forRoute($route);
            if (!$permission instanceof Permission) {
                return $this->allowUnmappedRoutes;
            }
        } else {
            $permission = $this->catalog->find(substr($attribute, strlen(self::PREFIX)));
            if (!$permission instanceof Permission) {
                $vote?->addReason('Unknown permission.');

                return false;
            }
        }

        $user = $token->getUser();
        if (!$user instanceof UserInterface) {
            return false;
        }

        $subjectId = $subject === null ? null : $this->contexts->contextId($subject);
        if ($subjectId !== null && !ContextId::isTyped($subjectId)) {
            $vote?->addReason('The context resolver returned an untyped context id.');
            $subjectId = null;
        }
        if ($permission->scope === PermissionScope::Context && $subjectId === null) {
            $vote?->addReason('A context permission needs a context subject.');

            return false;
        }

        $roles = $this->roleHierarchy?->getReachableRoleNames($token->getRoleNames()) ?? $token->getRoleNames();
        sort($roles);
        $bypass = array_intersect($this->bypassRoles, $roles) !== [] && !$this->bypassVetoed($token, $subject);
        if ($subject !== null && $subjectId === null) {
            // A subject that is not a context: a gate may depend on it, so the decision is not memoised.
            return $this->decide($permission, $user, $subject, null, $roles, $bypass);
        }

        $memo = GrantResolver::userKey($user) . "\0" . implode(',', $roles) . "\0" . $permission->key . "\0" . ($subjectId ?? '') . "\0" . ($bypass ? '1' : '0');
        if (isset($this->decisions[$memo])) {
            return $this->decisions[$memo];
        }
        if (count($this->decisions) >= $this->memoLimit) {
            // @igor-ignore - Bounded per-request memo, cleared by reset() (kernel.reset).
            $this->decisions = [];
        }

        // @igor-ignore - Per-request memo, cleared by reset() (kernel.reset).
        return $this->decisions[$memo] = $this->decide($permission, $user, $subject, $permission->scope === PermissionScope::Context ? $subjectId : null, $roles, $bypass);
    }

    /**
     * @param list<string> $roles reachable role names
     * @param bool $bypass a bypass role applies (not vetoed)
     */
    private function decide(Permission $permission, UserInterface $user, ?object $subject, ?string $contextId, array $roles, bool $bypass): bool
    {
        foreach ($this->gates as $gate) {
            if (!$gate->allows($permission, $user, $subject)) {
                return false;
            }
        }

        if ($bypass) {
            return true;
        }

        if ($contextId !== null) {
            return $this->grants->grants($user, $contextId, $permission->key);
        }

        foreach ($roles as $role) {
            if (in_array($permission->key, $this->roleGrants[$role] ?? [], true)) {
                return true;
            }
        }

        return $this->grants->grantsGlobally($user, $permission->key)
            || ($permission->aggregate && $this->grants->contextsGranting($user, $permission->key) !== [])
            || ($this->fallbackSet !== null && !$this->grants->holdsAnyAssignment($user) && isset($this->sets->keysOf($this->fallbackSet)[$permission->key]));
    }

    private function bypassVetoed(TokenInterface $token, ?object $subject): bool
    {
        foreach ($this->vetoes as $veto) {
            if ($veto->vetoes($token, $subject)) {
                return true;
            }
        }

        return false;
    }
}

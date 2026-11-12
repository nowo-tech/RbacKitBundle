<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Security;

use LogicException;
use Nowo\RbacKitBundle\Internal\HiddenValues;

use function array_values;
use function in_array;
use function is_object;
use function is_string;

/**
 * Framework-free view of the authenticated user a decision is about, given to a {@see RbacBypassVetoInterface}
 * (since 1.2, REQ-ARCH-001 rule 3: no Symfony `TokenInterface` in a public port).
 *
 * Its properties hold the user identifier, the token's role names, the reachable roles the voter decides with (after
 * the role hierarchy, sorted), the identifier of the impersonating user when the token is a switch-user token, and the
 * firewall name. The user object ({@see user()}) and the security token attributes ({@see tokenAttribute()}) are
 * **not** properties: they live in an internal {@see \WeakMap} keyed by this object, so a dump, `var_export()`,
 * `(array)` cast, `json_encode()` or `serialize()` of the VO never shows a password hash or a credential kept on the
 * token, and a `clone` or an unserialized copy has none of them.
 *
 * Built by the voter for the decision and never stored (FrankenPHP worker mode, kernel reset false).
 */
final readonly class RbacActor
{
    /** Bag of the user object. */
    private const USER = 'user';

    /** Bag of the token attributes. */
    private const TOKEN = 'token';

    /** @var list<string> */
    public array $roles;

    /** @var list<string> */
    public array $reachableRoles;

    /**
     * @param string $userIdentifier the user's identifier
     * @param array<int, string> $roles the security token's role names
     * @param array<int, string> $reachableRoles the roles after the role hierarchy (what the voter decides with)
     * @param object $user the host's user object (hidden, see the class comment)
     * @param array<string, mixed> $tokenAttributes the security token attributes (hidden, see the class comment)
     * @param string|null $impersonatorIdentifier identifier of the original user of a switch-user token
     * @param string|null $firewallName the firewall that authenticated the token, when known
     */
    public function __construct(
        public string $userIdentifier,
        array $roles,
        array $reachableRoles,
        object $user,
        array $tokenAttributes = [],
        public ?string $impersonatorIdentifier = null,
        public ?string $firewallName = null,
    ) {
        $this->roles          = array_values($roles);
        $this->reachableRoles = array_values($reachableRoles);
        HiddenValues::put($this, [self::USER => [self::USER => $user], self::TOKEN => $tokenAttributes]);
    }

    /**
     * The host's user object (the security token user).
     *
     * @throws LogicException on a clone or an unserialized copy, which carry no user
     */
    public function user(): object
    {
        $user = HiddenValues::get($this, self::USER, self::USER);
        if (!is_object($user)) {
            throw new LogicException('This RbacActor is a copy without its user: use the instance the voter passed.');
        }

        return $user;
    }

    /** Whether the user holds this role (after the role hierarchy). */
    public function hasRole(string $role): bool
    {
        return in_array($role, $this->reachableRoles, true);
    }

    /** True when the token is a switch-user token (an impersonation). */
    public function isImpersonated(): bool
    {
        return is_string($this->impersonatorIdentifier);
    }

    /** The raw value of a security token attribute, or null. */
    public function tokenAttribute(string $name): mixed
    {
        return HiddenValues::get($this, self::TOKEN, $name);
    }

    /** True when the token has this attribute (even with a null value). */
    public function hasTokenAttribute(string $name): bool
    {
        return HiddenValues::has($this, self::TOKEN, $name);
    }
}

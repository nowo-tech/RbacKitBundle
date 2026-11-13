<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Grant;

use Nowo\RbacKitBundle\Context\ContextId;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Contracts\Service\ResetInterface;

use function count;
use function spl_object_id;

/**
 * The permissions a user holds — globally and in each of their contexts — as the **union** of every permission set
 * they hold there (no "highest role wins"). Loaded once per user and request from the {@see GrantProviderInterface}
 * (one call), bounded (`voter.max_assignments`, `voter.max_users_per_request`) and reset between requests
 * ({@see self::reset()}, worker mode); a page with N contexts checks N contexts without N queries.
 *
 * When the provider fails to build an assignment ({@see InvalidAssignmentException}, a stored context id that
 * {@see ContextId::of()} refuses), the user holds nothing in that request and a warning
 * is logged (fail closed); any other exception from the provider propagates.
 */
final class GrantResolver implements ResetInterface
{
    /** @var array<string, array{global: array<string, true>, contexts: array<string, array<string, true>>, any: bool}> user key => grants */
    private array $byUser = [];

    /**
     * @param int $maxAssignments assignments read per user (`voter.max_assignments`); the rest are ignored and logged
     * @param int $maxUsers users memoised per request before the memo is cleared
     */
    public function __construct(
        private readonly GrantProviderInterface $provider,
        private readonly PermissionSetRegistry $sets,
        private readonly int $maxAssignments = 5000,
        private readonly int $maxUsers = 64,
        private readonly ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * Forgets every memoised user (kernel.reset, worker mode).
     */
    public function reset(): void
    {
        $this->byUser = [];
    }

    /**
     * Stable memo key of a user (class + identifier; object id for non-Symfony users).
     */
    public static function userKey(object $user): string
    {
        return $user::class . '#' . ($user instanceof UserInterface ? $user->getUserIdentifier() : (string) spl_object_id($user));
    }

    /**
     * Whether a set the user holds in the context grants the key.
     */
    public function grants(object $user, string $contextId, string $key): bool
    {
        return isset($this->load($user)['contexts'][$contextId][$key]);
    }

    /**
     * Whether a set the user holds globally grants the key.
     */
    public function grantsGlobally(object $user, string $key): bool
    {
        return isset($this->load($user)['global'][$key]);
    }

    /**
     * Whether the user holds a set in the context (strangers hold none).
     */
    public function holdsSetIn(object $user, string $contextId): bool
    {
        return isset($this->load($user)['contexts'][$contextId]);
    }

    /**
     * Whether the user holds any assignment at all (global or in a context).
     */
    public function holdsAnyAssignment(object $user): bool
    {
        return $this->load($user)['any'];
    }

    /**
     * The contexts where a set the user holds grants the key (general pages show their data only).
     *
     * @return list<string>
     */
    public function contextsGranting(object $user, string $key): array
    {
        $ids = [];
        foreach ($this->load($user)['contexts'] as $contextId => $keys) {
            if (isset($keys[$key])) {
                $ids[] = (string) $contextId;
            }
        }

        return $ids;
    }

    /**
     * @return array{global: array<string, true>, contexts: array<string, array<string, true>>, any: bool}
     */
    private function load(object $user): array
    {
        $userKey = self::userKey($user);
        if (isset($this->byUser[$userKey])) {
            return $this->byUser[$userKey];
        }

        $grants = ['global' => [], 'contexts' => [], 'any' => false];
        $read   = 0;

        try {
            foreach ($this->provider->assignmentsOf($user) as $assignment) {
                if ($read >= $this->maxAssignments) {
                    $this->logger?->warning('RbacKit: a user holds more than voter.max_assignments assignments; the rest are ignored.', ['limit' => $this->maxAssignments]);
                    break;
                }
                ++$read;
                $grants['any'] = true;
                $keys          = $this->sets->keysOf($assignment->permissionSet);
                if ($assignment->contextId === null) {
                    $grants['global'] += $keys;
                } else {
                    $grants['contexts'][$assignment->contextId] = ($grants['contexts'][$assignment->contextId] ?? []) + $keys;
                }
            }
        } catch (InvalidAssignmentException $e) {
            // The provider built an Assignment from a stored context id ContextId::of() refuses. The exception ends
            // the provider's iterable, so the remaining assignments are unknown: the user holds nothing in this request
            // (fail closed, a denial instead of an error on every check). Any other exception still propagates.
            $this->logger?->warning('RbacKit: the grant provider failed to build an assignment (refused context id); the user holds no permission set in this request. Check stored ids with ContextId::of() in the provider.', ['exception' => $e]);
            $grants = ['global' => [], 'contexts' => [], 'any' => false];
        }

        if (count($this->byUser) >= $this->maxUsers) {
            // @igor-ignore - Bounded per-request memo, cleared by reset() (kernel.reset).
            $this->byUser = [];
        }

        // @igor-ignore - Per-request memo, cleared by reset() (kernel.reset).
        return $this->byUser[$userKey] = $grants;
    }
}

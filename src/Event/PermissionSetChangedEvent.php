<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Event;

/**
 * Dispatched after every permission set mutation (create, update, delete) so the host can audit it (AuditKit, an
 * action log…). The context holds the set code, its name, the permission count and the added / removed keys — no
 * secret material.
 */
final readonly class PermissionSetChangedEvent
{
    /** A custom set was created. */
    public const CREATED = 'created';

    /** A set was edited (a system set's first edit stores a row over its configured default). */
    public const UPDATED = 'updated';

    /** A custom set was deleted. */
    public const DELETED = 'deleted';

    /**
     * @param string $action one of the class constants
     * @param string $code set code
     * @param array<string, mixed> $context name, permissions count, added / removed keys
     * @param object|null $user authenticated user of the request (null from the CLI or with allow_unauthenticated)
     */
    public function __construct(
        public string $action,
        public string $code,
        public array $context = [],
        public ?object $user = null,
    ) {
    }
}

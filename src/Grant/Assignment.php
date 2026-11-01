<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Grant;

use Nowo\RbacKitBundle\Context\ContextResolverInterface;

/**
 * One permission set a user holds: in one context (`contextId`), or globally (`contextId` null). Returned by the host
 * {@see GrantProviderInterface} (memberships, group links, invitations…).
 */
final readonly class Assignment
{
    /**
     * @param string $permissionSet permission set code
     * @param string|null $contextId context id ({@see ContextResolverInterface}); null = global
     */
    public function __construct(
        public string $permissionSet,
        public ?string $contextId = null,
    ) {
    }
}

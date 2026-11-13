<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Grant;

use InvalidArgumentException;
use Nowo\RbacKitBundle\Context\ContextId;

/**
 * Thrown by {@see Assignment} when its context type or id is not acceptable (see {@see ContextId}).
 * Still an {@see InvalidArgumentException}, so existing catch blocks keep working; {@see GrantResolver} catches this one
 * only, to deny instead of erroring out when a host grant provider builds an assignment from a stored id that is no
 * longer accepted (since 1.2.1).
 */
final class InvalidAssignmentException extends InvalidArgumentException
{
}

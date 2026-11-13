<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Context;

use function preg_match;
use function strlen;
use function strpos;
use function substr;
use function trim;

/**
 * Typed context ids (`<type>:<id>`, e.g. `project:5` and `organization:5`): two context classes sharing an id are two
 * different contexts. The type is a stable alias chosen by the host (never a class name, which changes on refactors).
 * Every context id the voter compares — from the {@see ContextResolverInterface} and in every assignment — has this
 * form; an untyped id is not a context (fail closed).
 */
final class ContextId
{
    /** Accepted context types: lowercase letter, then lowercase letters, digits and underscores (at most 40). */
    public const TYPE_PATTERN = '/^[a-z][a-z0-9_]{0,39}$/';

    /** Longest accepted id inside a type. */
    public const ID_MAX_LENGTH = 191;

    /** Refused in an id: leading or trailing whitespace, and control or format characters (Cc, Cf, ...) anywhere. */
    private const ID_REFUSED_PATTERN = '/^[\s\p{Z}]|[\s\p{Z}]$|\p{C}/u';

    /** Separator between the type and the id. */
    public const SEPARATOR = ':';

    /**
     * The typed id of a context, or null when the type or the id is not acceptable (blank, longer than
     * {@see self::ID_MAX_LENGTH}, with edge whitespace, with control or format characters, or not valid UTF-8).
     */
    public static function of(string $type, string $id): ?string
    {
        if (preg_match(self::TYPE_PATTERN, $type) !== 1 || trim($id) === '' || strlen($id) > self::ID_MAX_LENGTH || preg_match(self::ID_REFUSED_PATTERN, $id) !== 0) {
            return null;
        }

        return $type . self::SEPARATOR . $id;
    }

    /**
     * Whether a string is a typed context id (`<type>:<id>` with an acceptable type and id).
     */
    public static function isTyped(string $contextId): bool
    {
        $separator = strpos($contextId, self::SEPARATOR);

        return $separator !== false && self::of(substr($contextId, 0, $separator), substr($contextId, $separator + 1)) === $contextId;
    }
}

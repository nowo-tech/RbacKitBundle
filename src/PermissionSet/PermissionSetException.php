<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\PermissionSet;

use DomainException;

/**
 * Why a permission set change was refused; {@see self::$reason} is the suffix of the `error.<reason>` translation key
 * (bundle domain) shown in the admin UI.
 */
final class PermissionSetException extends DomainException
{
    /** The code does not match `^[a-z][a-z0-9_]{1,39}$`. */
    public const INVALID_CODE = 'invalid_code';

    /** The name is empty or longer than 120 characters, or the description longer than 1000. */
    public const INVALID_NAME = 'invalid_name';

    /** A set with this code already exists. */
    public const CODE_TAKEN = 'code_taken';

    /** A permission key is not catalogued. */
    public const UNKNOWN_PERMISSION = 'unknown_permission';

    /** The set is `locked` in the configuration. */
    public const LOCKED = 'locked';

    /** System sets cannot be deleted. */
    public const SYSTEM = 'system';

    /** The set is still assigned ({@see PermissionSetUsageInterface}). */
    public const IN_USE = 'in_use';

    /** No set with this code. */
    public const NOT_FOUND = 'not_found';

    /** `storage.max_sets` reached. */
    public const LIMIT = 'limit';

    /** The storage is read-only (`storage.type: config`). */
    public const READ_ONLY = 'read_only';

    private function __construct(
        public readonly string $reason,
    ) {
        parent::__construct('error.' . $reason);
    }

    /**
     * The exception of a reason constant.
     */
    public static function of(string $reason): self
    {
        return new self($reason);
    }
}

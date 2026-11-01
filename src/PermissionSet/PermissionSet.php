<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\PermissionSet;

use DateTimeImmutable;

use function in_array;

/**
 * A permission set (a "role") as the application sees it: a system set from `nowo_rbac_kit.permission_sets` (possibly
 * edited, then `stored`), or a custom set from the storage. Two sets need not be comparable: a user's permissions in
 * a context are the union of every set they hold there.
 */
final readonly class PermissionSet
{
    /**
     * @param string $code stable code (`^[a-z][a-z0-9_]{1,39}$`)
     * @param string|null $name name stored by an edit or a custom set (null = translate {@see self::$label})
     * @param string|null $label translation key of a system set name (catalogue domain)
     * @param string|null $description description (stored text, or a system set translation key)
     * @param bool $system defined in the configuration (cannot be deleted)
     * @param bool $locked not editable from the admin UI
     * @param list<string> $permissions catalogued permission keys, catalogue order
     * @param bool $stored a storage row exists (custom set, or an edited system set)
     * @param string|null $updatedBy identifier of the last editor
     * @param DateTimeImmutable|null $updatedAt time of the last edit
     */
    public function __construct(
        public string $code,
        public ?string $name,
        public ?string $label,
        public ?string $description,
        public bool $system,
        public bool $locked,
        public array $permissions,
        public bool $stored = false,
        public ?string $updatedBy = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * Whether the set grants a permission key.
     */
    public function grants(string $key): bool
    {
        return in_array($key, $this->permissions, true);
    }

    /**
     * Translation key of the name of a system set without a stored name.
     */
    public function labelKey(): string
    {
        return $this->label ?? 'permission_set.' . $this->code . '.label';
    }
}

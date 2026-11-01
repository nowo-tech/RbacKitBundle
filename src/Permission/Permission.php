<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Permission;

use InvalidArgumentException;

use function array_values;
use function is_string;
use function preg_match;
use function sprintf;
use function strlen;

/**
 * One catalogued permission key: its scope, the routes it guards, an optional group (admin UI ordering) and its
 * translation keys. Immutable and validated: a key outside {@see self::KEY_PATTERN} is refused.
 */
final readonly class Permission
{
    /** Accepted permission keys: lowercase segments separated by `.`, `-` or `:` (at most 120 characters). */
    public const KEY_PATTERN = '/^[a-z][a-z0-9_]*(?:[.:-][a-z0-9_]+)*$/';

    /** Longest accepted permission key. */
    public const KEY_MAX_LENGTH = 120;

    /** Longest accepted route name. */
    public const ROUTE_MAX_LENGTH = 200;

    /** @var list<string> */
    public array $routes;

    /**
     * @param string $key permission key, e.g. `project.issues.view`
     * @param PermissionScope $scope global or context
     * @param iterable<mixed> $routes route names guarded by this permission
     * @param string|null $group group shown in the admin UI (free text or translation key)
     * @param string|null $label translation key of the label (null = `permission.<key>.label`)
     * @param string|null $description translation key of the description (null = `permission.<key>.description`)
     */
    public function __construct(
        public string $key,
        public PermissionScope $scope = PermissionScope::Global,
        iterable $routes = [],
        public ?string $group = null,
        public ?string $label = null,
        public ?string $description = null,
    ) {
        if (!self::isValidKey($key)) {
            throw new InvalidArgumentException(sprintf('Invalid permission key "%s": expected lowercase segments separated by ".", "-" or ":" (at most %d characters).', $key, self::KEY_MAX_LENGTH));
        }

        $list = [];
        foreach ($routes as $route) {
            if (!is_string($route) || $route === '' || strlen($route) > self::ROUTE_MAX_LENGTH) {
                throw new InvalidArgumentException(sprintf('Permission "%s": route names must be non-empty strings of at most %d characters.', $key, self::ROUTE_MAX_LENGTH));
            }
            $list[$route] = $route;
        }
        $this->routes = array_values($list);
    }

    /**
     * Whether a string is an acceptable permission key.
     */
    public static function isValidKey(string $key): bool
    {
        return strlen($key) <= self::KEY_MAX_LENGTH && preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * Translation key of the label (catalogue translation domain).
     */
    public function labelKey(): string
    {
        return $this->label ?? 'permission.' . $this->key . '.label';
    }

    /**
     * Translation key of the description (catalogue translation domain).
     */
    public function descriptionKey(): string
    {
        return $this->description ?? 'permission.' . $this->key . '.description';
    }

    /**
     * Whether two definitions of the same key are identical (providers may repeat a key, never redefine it).
     */
    public function equals(self $other): bool
    {
        return $this->key === $other->key
            && $this->scope === $other->scope
            && $this->routes === $other->routes
            && $this->group === $other->group
            && $this->label === $other->label
            && $this->description === $other->description;
    }
}

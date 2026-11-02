<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\PermissionSet;

use Nowo\RbacKitBundle\Permission\Permission;
use Nowo\RbacKitBundle\Permission\PermissionCatalog;
use Nowo\RbacKitBundle\Permission\PermissionScope;
use OverflowException;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\Service\ResetInterface;

use function array_diff;
use function array_fill_keys;
use function array_filter;
use function array_intersect;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function implode;
use function is_array;
use function sprintf;
use function strcmp;
use function uasort;

/**
 * Every permission set and its permissions: the configured system sets (`nowo_rbac_kit.permission_sets`), replaced by
 * their stored edit when one exists (unless `locked`), then the custom sets from the storage, ordered by name.
 *
 * Permissions are always intersected with the catalogue: a key that is not (or no longer) catalogued grants nothing
 * (fail closed, logged once per request). An `all` set holds every **context** key (plus the global ones only with
 * `include_global`). Read once per request from an optional cache entry of the whole (bounded) table that lives
 * `storage.cache_ttl` seconds and is rebuilt after a change ({@see self::invalidate()}); a table holding more than
 * `storage.max_sets` rows is an error (no row is silently dropped). {@see self::reset()} clears the per-request memo.
 */
final class PermissionSetRegistry implements ResetInterface
{
    /** Cache key of the stored rows. */
    public const CACHE_KEY = 'nowo_rbac_kit.permission_sets.v1';

    /** @var array<string, PermissionSet>|null */
    private ?array $sets = null;

    /** @var array<string, array<string, true>> code => permission key set */
    private array $keySets = [];

    /**
     * @param array<string, array{label: string|null, description: string|null, permissions: list<string>, all: bool, include_global?: bool, except: list<string>, locked: bool}> $systemSets configured sets
     * @param int $maxSets upper bound of stored rows (`storage.max_sets`); more rows in the storage is an error
     * @param int $cacheTtl seconds the cache entry lives (`storage.cache_ttl`)
     */
    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly PermissionSetStorageInterface $storage,
        private readonly array $systemSets = [],
        private readonly ?CacheInterface $cache = null,
        private readonly int $maxSets = 200,
        private readonly ?LoggerInterface $logger = null,
        private readonly int $cacheTtl = 300,
    ) {
    }

    /**
     * Forgets the per-request memo (kernel.reset, worker mode).
     */
    public function reset(): void
    {
        $this->sets    = null;
        $this->keySets = [];
    }

    /**
     * Drops the cache entry and the memo after a change.
     */
    public function invalidate(): void
    {
        $this->cache?->delete(self::CACHE_KEY);
        $this->reset();
    }

    /**
     * System sets first (configuration order), then custom sets by name.
     *
     * @return array<string, PermissionSet> code => set
     */
    public function all(): array
    {
        if ($this->sets !== null) {
            return $this->sets;
        }

        $catalogue = array_keys($this->catalog->all());
        $context   = array_keys(array_filter($this->catalog->all(), static fn (Permission $permission): bool => $permission->scope === PermissionScope::Context));
        $stored    = [];
        foreach ($this->rows() as $row) {
            $stored[$row->code] = $row;
        }

        $sets    = [];
        $unknown = [];
        foreach ($this->systemSets as $code => $definition) {
            $row = $definition['locked'] ? null : ($stored[$code] ?? null);
            unset($stored[$code]);
            $requested   = $row instanceof StoredPermissionSet ? $row->permissions : ($definition['all'] ? array_values(array_diff(($definition['include_global'] ?? false) ? $catalogue : $context, $definition['except'])) : $definition['permissions']);
            $sets[$code] = new PermissionSet(
                $code,
                $row?->name,
                $definition['label'],
                $row instanceof StoredPermissionSet ? $row->description : $definition['description'],
                true,
                $definition['locked'],
                $this->known($code, $requested, $catalogue, $unknown),
                $row instanceof StoredPermissionSet,
                $row?->updatedBy,
                $row?->updatedAt,
            );
        }

        $custom = [];
        foreach ($stored as $code => $row) {
            $custom[$code] = new PermissionSet($code, $row->name, null, $row->description, false, false, $this->known($code, $row->permissions, $catalogue, $unknown), true, $row->updatedBy, $row->updatedAt);
        }
        uasort($custom, static fn (PermissionSet $a, PermissionSet $b): int => strcmp((string) $a->name, (string) $b->name) ?: strcmp($a->code, $b->code));

        if ($unknown !== []) {
            $this->logger?->warning('RbacKit: permission sets reference keys that are not catalogued; they grant nothing.', ['sets' => array_map(static fn (array $keys): string => implode(', ', $keys), $unknown)]);
        }

        // @igor-ignore - Per-request memo, cleared by reset() (kernel.reset).
        return $this->sets = $sets + $custom;
    }

    /**
     * The set of a code, or null.
     */
    public function find(string $code): ?PermissionSet
    {
        return $this->all()[$code] ?? null;
    }

    /**
     * The permission keys a set grants, as a set (empty for an unknown code: it grants nothing).
     *
     * @return array<string, true>
     */
    public function keysOf(string $code): array
    {
        if (!isset($this->keySets[$code])) {
            // @igor-ignore - Per-request memo, cleared by reset() (kernel.reset).
            $this->keySets[$code] = array_fill_keys($this->find($code)->permissions ?? [], true);
        }

        return $this->keySets[$code];
    }

    /**
     * Number of stored rows (custom sets + edits), for the `storage.max_sets` bound.
     */
    public function storedCount(): int
    {
        return $this->storage->count();
    }

    /**
     * Whether the storage accepts changes.
     */
    public function isWritable(): bool
    {
        return $this->storage->isWritable();
    }

    /**
     * @return list<StoredPermissionSet>
     */
    private function rows(): array
    {
        if (!$this->cache instanceof CacheInterface) {
            return $this->load();
        }

        $cached = $this->cache->get(self::CACHE_KEY, function (ItemInterface $item): array {
            $item->expiresAfter($this->cacheTtl);

            return array_map(static fn (StoredPermissionSet $row): array => $row->toArray(), $this->load());
        });

        return is_array($cached) ? array_values(array_filter(array_map(StoredPermissionSet::fromArray(...), $cached))) : [];
    }

    /**
     * The stored rows; more than `storage.max_sets` is refused (a dropped row could revert a narrowed system set).
     *
     * @throws OverflowException
     *
     * @return list<StoredPermissionSet>
     */
    private function load(): array
    {
        $rows = $this->storage->load($this->maxSets + 1);
        if (count($rows) > $this->maxSets) {
            $this->logger?->critical('RbacKit: the permission set storage holds more rows than storage.max_sets.', ['limit' => $this->maxSets]);

            throw new OverflowException(sprintf('RbacKit: the permission set storage holds more than storage.max_sets (%d) rows; raise storage.max_sets or delete rows. No row is ignored silently: a dropped edit could restore broader permissions.', $this->maxSets));
        }

        return $rows;
    }

    /**
     * The catalogued keys of a request, in catalogue order; records the unknown ones.
     *
     * @param list<string> $requested
     * @param list<string> $catalogue
     * @param array<string, list<string>> $unknown
     *
     * @return list<string>
     */
    private function known(string $code, array $requested, array $catalogue, array &$unknown): array
    {
        $missing = array_values(array_diff($requested, $catalogue));
        if ($missing !== []) {
            $unknown[$code] = $missing;
        }

        return array_values(array_intersect($catalogue, $requested));
    }
}

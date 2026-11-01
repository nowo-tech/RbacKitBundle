<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\PermissionSet;

use Nowo\RbacKitBundle\Permission\PermissionCatalog;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Service\ResetInterface;

use function array_diff;
use function array_fill_keys;
use function array_filter;
use function array_intersect;
use function array_keys;
use function array_map;
use function array_values;
use function implode;
use function is_array;
use function strcmp;
use function uasort;

/**
 * Every permission set and its permissions: the configured system sets (`nowo_rbac_kit.permission_sets`), replaced by
 * their stored edit when one exists (unless `locked`), then the custom sets from the storage, ordered by name.
 *
 * Permissions are always intersected with the catalogue: a key that is not (or no longer) catalogued grants nothing
 * (fail closed, logged once per request). Read once per request from an optional cache entry of the whole (bounded)
 * table, rebuilt after a change ({@see self::invalidate()}); {@see self::reset()} clears the per-request memo.
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
     * @param array<string, array{label: string|null, description: string|null, permissions: list<string>, all: bool, except: list<string>, locked: bool}> $systemSets configured sets
     * @param int $maxSets upper bound of stored rows read (`storage.max_sets`)
     */
    public function __construct(
        private readonly PermissionCatalog $catalog,
        private readonly PermissionSetStorageInterface $storage,
        private readonly array $systemSets = [],
        private readonly ?CacheInterface $cache = null,
        private readonly int $maxSets = 200,
        private readonly ?LoggerInterface $logger = null,
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
        $stored    = [];
        foreach ($this->rows() as $row) {
            $stored[$row->code] = $row;
        }

        $sets    = [];
        $unknown = [];
        foreach ($this->systemSets as $code => $definition) {
            $row = $definition['locked'] ? null : ($stored[$code] ?? null);
            unset($stored[$code]);
            $requested   = $row instanceof StoredPermissionSet ? $row->permissions : ($definition['all'] ? array_values(array_diff($catalogue, $definition['except'])) : $definition['permissions']);
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
            return $this->storage->load($this->maxSets);
        }

        $cached = $this->cache->get(self::CACHE_KEY, fn (): array => array_map(static fn (StoredPermissionSet $row): array => $row->toArray(), $this->storage->load($this->maxSets)));

        return is_array($cached) ? array_values(array_filter(array_map(StoredPermissionSet::fromArray(...), $cached))) : [];
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

<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\PermissionSet;

use Nowo\RbacKitBundle\Event\PermissionSetChangedEvent;
use Nowo\RbacKitBundle\Permission\PermissionCatalog;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function array_diff;
use function array_filter;
use function array_keys;
use function array_values;
use function count;
use function in_array;
use function mb_strlen;
use function mb_substr;
use function preg_match;
use function strtolower;
use function trim;

/**
 * Creates, edits and deletes permission sets. System sets can be edited (their first edit stores a row over the
 * configured default) but not deleted; `locked` sets cannot be changed. Deleting a custom set needs the usage provider
 * (`storage.usage_provider`) and is refused while the set is assigned; a code that is still assigned (stale host
 * assignments) cannot be created, so a re-created code never inherits old holders. Every change
 * is validated against the catalogue, bounded by `storage.max_sets`, refreshes the {@see PermissionSetRegistry} and
 * dispatches a {@see PermissionSetChangedEvent}. Stateless.
 */
final readonly class PermissionSetManager implements PermissionSetManagerInterface
{
    /** Accepted set codes (no `/`: the admin CSRF token ids use it as separator). */
    public const CODE_PATTERN = '/^[a-z][a-z0-9_]{1,39}$/';

    /** Longest name. */
    public const NAME_MAX_LENGTH = 120;

    /** Longest description. */
    public const DESCRIPTION_MAX_LENGTH = 1000;

    /**
     * @param int $maxSets `storage.max_sets`
     */
    public function __construct(
        private PermissionSetRegistry $registry,
        private PermissionCatalog $catalog,
        private PermissionSetStorageInterface $storage,
        private int $maxSets = 200,
        private ?EventDispatcherInterface $events = null,
        private ?PermissionSetUsageInterface $usage = null,
        private ?ClockInterface $clock = null,
    ) {
    }

    /**
     * Creates a custom set.
     *
     * @param list<string> $permissions catalogued keys
     *
     * @throws PermissionSetException
     */
    public function create(string $code, string $name, ?string $description, array $permissions, ?object $actor = null): PermissionSet
    {
        $this->requireWritable();
        $code = strtolower(trim($code));
        if (preg_match(self::CODE_PATTERN, $code) !== 1) {
            throw PermissionSetException::of(PermissionSetException::INVALID_CODE);
        }
        if ($this->registry->find($code) instanceof PermissionSet) {
            throw PermissionSetException::of(PermissionSetException::CODE_TAKEN);
        }
        if ($this->assignments($code) > 0) {
            throw PermissionSetException::of(PermissionSetException::CODE_ASSIGNED);
        }
        if ($this->registry->storedCount() >= $this->maxSets) {
            throw PermissionSetException::of(PermissionSetException::LIMIT);
        }

        $keys = $this->known($permissions);
        $row  = new StoredPermissionSet($code, $this->name($name), $this->description($description), false, $keys, $this->actorId($actor), $this->clock?->now());
        $this->storage->save($row);
        $this->enforceBound($row);

        return $this->changed(PermissionSetChangedEvent::CREATED, $code, ['permissions' => count($keys), 'added' => $keys, 'removed' => []], $actor);
    }

    /**
     * Edits a set (name, description, permissions).
     *
     * @param list<string> $permissions catalogued keys
     *
     * @throws PermissionSetException
     */
    public function update(string $code, string $name, ?string $description, array $permissions, ?object $actor = null): PermissionSet
    {
        $this->requireWritable();
        $set = $this->registry->find($code) ?? throw PermissionSetException::of(PermissionSetException::NOT_FOUND);
        if ($set->locked) {
            throw PermissionSetException::of(PermissionSetException::LOCKED);
        }
        if (!$set->stored && $this->registry->storedCount() >= $this->maxSets) {
            throw PermissionSetException::of(PermissionSetException::LIMIT);
        }

        $keys = $this->known($permissions);
        $row  = new StoredPermissionSet($code, $this->name($name), $this->description($description), $set->system, $keys, $this->actorId($actor), $this->clock?->now());
        $this->storage->save($row);
        if (!$set->stored) {
            $this->enforceBound($row);
        }

        return $this->changed(PermissionSetChangedEvent::UPDATED, $code, [
            'permissions' => count($keys),
            'added'       => array_values(array_diff($keys, $set->permissions)),
            'removed'     => array_values(array_diff($set->permissions, $keys)),
        ], $actor);
    }

    /**
     * Deletes a custom set.
     *
     * @throws PermissionSetException
     */
    public function delete(string $code, ?object $actor = null): void
    {
        $this->requireWritable();
        $set = $this->registry->find($code) ?? throw PermissionSetException::of(PermissionSetException::NOT_FOUND);
        if ($set->locked) {
            throw PermissionSetException::of(PermissionSetException::LOCKED);
        }
        if ($set->system) {
            throw PermissionSetException::of(PermissionSetException::SYSTEM);
        }
        if (!$this->usage instanceof PermissionSetUsageInterface) {
            throw PermissionSetException::of(PermissionSetException::USAGE_UNKNOWN);
        }
        if ($this->assignments($code) > 0) {
            throw PermissionSetException::of(PermissionSetException::IN_USE);
        }

        $this->storage->delete($code);
        $this->registry->invalidate();
        $this->events?->dispatch(new PermissionSetChangedEvent(PermissionSetChangedEvent::DELETED, $code, ['name' => $set->name, 'permissions' => count($set->permissions)], $actor, $this->actorId($actor)));
    }

    /**
     * @param array<string, mixed> $context
     */
    private function changed(string $action, string $code, array $context, ?object $actor): PermissionSet
    {
        $this->registry->invalidate();
        $set = $this->registry->find($code) ?? throw PermissionSetException::of(PermissionSetException::NOT_FOUND);
        $this->events?->dispatch(new PermissionSetChangedEvent($action, $code, ['name' => $set->name] + $context, $actor, $this->actorId($actor)));

        return $set;
    }

    /**
     * Whether custom sets can be deleted (a usage provider tells whether they are still assigned).
     */
    public function canDelete(): bool
    {
        return $this->usage instanceof PermissionSetUsageInterface;
    }

    /**
     * The bound is checked before saving without a lock: when concurrent creates pushed the table past
     * `storage.max_sets`, the row this request wrote is removed again and the change is refused (the registry would
     * otherwise throw on every read). Only the row this request wrote is removed: when another manager's write replaced
     * it (same code, other content), that row is kept.
     *
     * @throws PermissionSetException
     */
    private function enforceBound(StoredPermissionSet $saved): void
    {
        $stored = $this->registry->storedCount();
        if ($stored <= $this->maxSets) {
            return;
        }

        foreach ($this->storage->load($stored) as $row) {
            if ($row->code === $saved->code && $this->sameContent($row, $saved)) {
                $this->storage->delete($saved->code);
            }
        }
        $this->registry->invalidate();

        throw PermissionSetException::of(PermissionSetException::LIMIT);
    }

    /**
     * Whether a stored row is the one this request wrote (the storage may set its own timestamp).
     */
    private function sameContent(StoredPermissionSet $a, StoredPermissionSet $b): bool
    {
        return [$a->name, $a->description, $a->system, $a->permissions, $a->updatedBy] === [$b->name, $b->description, $b->system, $b->permissions, $b->updatedBy];
    }

    /**
     * Assignments of a code according to the usage provider (0 without one).
     */
    private function assignments(string $code): int
    {
        return $this->usage?->usageCounts([$code])[$code] ?? 0;
    }

    private function requireWritable(): void
    {
        if (!$this->storage->isWritable()) {
            throw PermissionSetException::of(PermissionSetException::READ_ONLY);
        }
    }

    /**
     * The catalogued keys of a submission, in catalogue order (an unknown key refuses the whole change).
     *
     * @param list<string> $permissions
     *
     * @return list<string>
     */
    private function known(array $permissions): array
    {
        $catalogue = array_keys($this->catalog->all());
        foreach ($permissions as $key) {
            if (!in_array($key, $catalogue, true)) {
                throw PermissionSetException::of(PermissionSetException::UNKNOWN_PERMISSION);
            }
        }

        return array_values(array_filter($catalogue, static fn (string $key): bool => in_array($key, $permissions, true)));
    }

    private function name(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > self::NAME_MAX_LENGTH) {
            throw PermissionSetException::of(PermissionSetException::INVALID_NAME);
        }

        return $name;
    }

    private function description(?string $description): ?string
    {
        $description = trim((string) $description);
        if (mb_strlen($description) > self::DESCRIPTION_MAX_LENGTH) {
            throw PermissionSetException::of(PermissionSetException::INVALID_NAME);
        }

        return $description === '' ? null : $description;
    }

    private function actorId(?object $actor): ?string
    {
        return $actor instanceof UserInterface ? mb_substr($actor->getUserIdentifier(), 0, 180) : null;
    }
}

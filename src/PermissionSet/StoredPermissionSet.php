<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\PermissionSet;

use DateTimeImmutable;
use Throwable;

use function array_filter;
use function array_values;
use function hash;
use function is_array;
use function is_bool;
use function is_string;
use function json_encode;

use const DATE_ATOM;
use const JSON_THROW_ON_ERROR;
use const JSON_UNESCAPED_UNICODE;

/**
 * One storage row: a custom permission set, or the edit of a system set. Plain data (cache-friendly array shape).
 */
final readonly class StoredPermissionSet
{
    /**
     * @param list<string> $permissions permission keys
     */
    public function __construct(
        public string $code,
        public string $name,
        public ?string $description,
        public bool $system,
        public array $permissions,
        public ?string $updatedBy = null,
        public ?DateTimeImmutable $updatedAt = null,
    ) {
    }

    /**
     * Revision of the row (1.3, REQ-RUNTIME-003 rule 4): a hash of everything it stores (the edit time to the second,
     * the precision every supported database keeps), so a change made by anyone since a manager read the row gives
     * another value. The admin form sends it back and {@see ConditionalPermissionSetStorageInterface::replace()}
     * compares it under a row lock: a stale edit is refused instead of silently undoing the newer one.
     */
    public function revision(): string
    {
        return hash('sha256', json_encode([
            $this->name,
            $this->description,
            $this->system,
            $this->permissions,
            $this->updatedBy,
            $this->updatedAt?->getTimestamp(),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Cache shape.
     *
     * @return array{code: string, name: string, description: string|null, system: bool, permissions: list<string>, updated_by: string|null, updated_at: string|null}
     */
    public function toArray(): array
    {
        return [
            'code'        => $this->code,
            'name'        => $this->name,
            'description' => $this->description,
            'system'      => $this->system,
            'permissions' => $this->permissions,
            'updated_by'  => $this->updatedBy,
            'updated_at'  => $this->updatedAt?->format(DATE_ATOM),
        ];
    }

    /**
     * From the cache shape; null when the array is not a valid row (a corrupted cache entry grants nothing).
     */
    public static function fromArray(mixed $row): ?self
    {
        if (!is_array($row) || !is_string($row['code'] ?? null) || !is_string($row['name'] ?? null) || !is_bool($row['system'] ?? null) || !is_array($row['permissions'] ?? null)) {
            return null;
        }
        $description = $row['description'] ?? null;
        $updatedBy   = $row['updated_by'] ?? null;
        $updatedAt   = $row['updated_at'] ?? null;
        try {
            $at = is_string($updatedAt) ? new DateTimeImmutable($updatedAt) : null;
        } catch (Throwable) {
            $at = null;
        }

        return new self(
            $row['code'],
            $row['name'],
            is_string($description) ? $description : null,
            $row['system'],
            array_values(array_filter($row['permissions'], is_string(...))),
            is_string($updatedBy) ? $updatedBy : null,
            $at,
        );
    }
}

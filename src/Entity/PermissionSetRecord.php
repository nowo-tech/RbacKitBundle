<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

use function array_unique;
use function array_values;

/**
 * Storage row of a permission set (`storage.type: doctrine`): a custom set, or the edit of a system set (the row then
 * replaces the configured permissions). Table `rbac_permission_set` (+ `doctrine.table_prefix`), unique code.
 */
#[ORM\Entity]
#[ORM\Table(name: self::TABLE)]
#[ORM\UniqueConstraint(name: 'uniq_rbac_permission_set_code', columns: ['code'])]
class PermissionSetRecord
{
    /** Unprefixed table name. */
    public const TABLE = 'rbac_permission_set';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: Types::INTEGER)]
    private ?int $id = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $description = null;

    /** @var list<string> */
    #[ORM\Column(type: Types::JSON)]
    private array $permissions = [];

    #[ORM\Column(name: 'updated_by', length: 180, nullable: true)]
    private ?string $updatedBy = null;

    #[ORM\Column(name: 'updated_at', type: Types::DATETIME_IMMUTABLE)]
    private DateTimeImmutable $updatedAt;

    /**
     * @param string $code set code
     * @param string $name set name
     * @param bool $system edit of a configured system set (`is_system`: `system` is reserved in MySQL 8)
     */
    public function __construct(
        #[ORM\Column(length: 40)]
        private string $code,
        #[ORM\Column(length: 120)]
        private string $name,
        #[ORM\Column(name: 'is_system')]
        private bool $system = false,
    ) {
        // @igor-ignore - Doctrine entity (not a service): an edit of this row, flushed by the storage.
        $this->updatedAt = new DateTimeImmutable();
    }

    /**
     * Auto-increment id (null before the row is flushed).
     */
    public function getId(): ?int
    {
        return $this->id;
    }

    /**
     * Set code.
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * Set name.
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Description (null = none).
     */
    public function getDescription(): ?string
    {
        return $this->description;
    }

    /**
     * Whether the row edits a system set.
     */
    public function isSystem(): bool
    {
        return $this->system;
    }

    /**
     * @return list<string>
     */
    public function getPermissions(): array
    {
        return $this->permissions;
    }

    /**
     * Identifier of the last editor.
     */
    public function getUpdatedBy(): ?string
    {
        return $this->updatedBy;
    }

    /**
     * Time of the last edit.
     */
    public function getUpdatedAt(): DateTimeImmutable
    {
        return $this->updatedAt;
    }

    /**
     * Replaces the editable fields.
     *
     * @param list<string> $permissions
     */
    public function change(string $name, ?string $description, array $permissions, bool $system, ?string $updatedBy, DateTimeImmutable $updatedAt): void
    {
        // @igor-ignore - Doctrine entity (not a service): an edit of this row, flushed by the storage.
        $this->name = $name;
        // @igor-ignore - Doctrine entity (not a service): an edit of this row, flushed by the storage.
        $this->description = $description;
        // @igor-ignore - Doctrine entity (not a service): an edit of this row, flushed by the storage.
        $this->permissions = array_values(array_unique($permissions));
        // @igor-ignore - Doctrine entity (not a service): an edit of this row, flushed by the storage.
        $this->system = $system;
        // @igor-ignore - Doctrine entity (not a service): an edit of this row, flushed by the storage.
        $this->updatedBy = $updatedBy;
        // @igor-ignore - Doctrine entity (not a service): an edit of this row, flushed by the storage.
        $this->updatedAt = $updatedAt;
    }
}

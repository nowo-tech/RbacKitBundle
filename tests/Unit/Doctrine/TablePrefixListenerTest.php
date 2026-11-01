<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\Doctrine;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\LoadClassMetadataEventArgs;
use Doctrine\ORM\Mapping\ClassMetadata;
use Nowo\RbacKitBundle\Doctrine\TablePrefixListener;
use Nowo\RbacKitBundle\Entity\PermissionSetRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

final class TablePrefixListenerTest extends TestCase
{
    #[Test]
    public function itPrefixesOnlyBundleEntities(): void
    {
        $bundle = $this->metadata(PermissionSetRecord::class);
        (new TablePrefixListener('app_'))->loadClassMetadata(new LoadClassMetadataEventArgs($bundle, $this->createStub(EntityManagerInterface::class)));
        self::assertSame('app_rbac_permission_set', $bundle->getTableName());
        self::assertArrayHasKey('app_uniq_code', $bundle->table['indexes'] ?? []);
        self::assertArrayHasKey(0, $bundle->table['uniqueConstraints'] ?? []);

        $host = $this->metadata(stdClass::class);
        (new TablePrefixListener('app_'))->loadClassMetadata(new LoadClassMetadataEventArgs($host, $this->createStub(EntityManagerInterface::class)));
        self::assertSame('host_table', $host->getTableName());

        $bare = new ClassMetadata(PermissionSetRecord::class);
        $bare->setPrimaryTable(['name' => PermissionSetRecord::TABLE]);
        (new TablePrefixListener('app_'))->loadClassMetadata(new LoadClassMetadataEventArgs($bare, $this->createStub(EntityManagerInterface::class)));
        self::assertSame('app_rbac_permission_set', $bare->getTableName());

        $unprefixed = $this->metadata(PermissionSetRecord::class);
        (new TablePrefixListener())->loadClassMetadata(new LoadClassMetadataEventArgs($unprefixed, $this->createStub(EntityManagerInterface::class)));
        self::assertSame('rbac_permission_set', $unprefixed->getTableName());
    }

    /**
     * @param class-string $class
     *
     * @return ClassMetadata<object>
     */
    private function metadata(string $class): ClassMetadata
    {
        $metadata = new ClassMetadata($class);
        $metadata->setPrimaryTable([
            'name'              => $class === stdClass::class ? 'host_table' : PermissionSetRecord::TABLE,
            'indexes'           => ['uniq_code' => ['columns' => ['finished_at']]],
            'uniqueConstraints' => [0 => ['columns' => ['run_id']]],
        ]);

        return $metadata;
    }
}

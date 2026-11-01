<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\Doctrine;

use DateTimeImmutable;
use Nowo\RbacKitBundle\Entity\PermissionSetRecord;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PermissionSetRecordTest extends TestCase
{
    #[Test]
    public function aRecordHoldsTheEditableFields(): void
    {
        $record = new PermissionSetRecord('viewer', 'Viewer', true);
        self::assertNull($record->getId());
        self::assertTrue($record->isSystem());

        $at = new DateTimeImmutable('2026-11-01');
        $record->change('Readers', 'Read only', ['a.b', 'a.b', 'c.d'], true, 'admin', $at);
        self::assertSame('Readers', $record->getName());
        self::assertSame('Read only', $record->getDescription());
        self::assertSame(['a.b', 'c.d'], $record->getPermissions());
        self::assertSame('admin', $record->getUpdatedBy());
        self::assertSame($at, $record->getUpdatedAt());
        self::assertSame('viewer', $record->getCode());
    }
}

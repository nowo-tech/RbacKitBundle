<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\Context;

use InvalidArgumentException;
use Nowo\RbacKitBundle\Context\ContextId;
use Nowo\RbacKitBundle\Grant\Assignment;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class ContextIdTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function refusedIds(): iterable
    {
        yield 'leading space' => [' 5'];
        yield 'trailing space' => ['5 '];
        yield 'trailing newline' => ["5\n"];
        yield 'leading tab' => ["\t5"];
        yield 'non-breaking space edge' => ["5\u{00A0}"];
        yield 'zero-width space inside' => ["5\u{200B}6"];
        yield 'bidi override' => ["5\u{202E}"];
        yield 'control character' => ["5\x006"];
        yield 'invalid utf-8' => ["5\xC3\x28"];
        yield 'line separator inside' => ["5\u{2028}6"];
        yield 'paragraph separator inside' => ["5\u{2029}6"];
        yield 'private use character' => ["5\u{E000}6"];
        yield 'soft hyphen (format)' => ["5\u{00AD}6"];
        yield 'zero-width joiner (format) in an emoji sequence' => ["\u{1F468}\u{200D}\u{1F469}"];
    }

    #[Test]
    #[DataProvider('refusedIds')]
    public function refusesUnsafeIds(string $id): void
    {
        self::assertNull(ContextId::of('project', $id));
        self::assertFalse(ContextId::isTyped('project:' . $id));

        $this->expectException(InvalidArgumentException::class);
        Assignment::in('editor', 'project', $id);
    }

    #[Test]
    public function acceptsPlainIds(): void
    {
        self::assertSame('project:5', ContextId::of('project', '5'));
        self::assertSame('project:a b', ContextId::of('project', 'a b'));
        self::assertSame('project:é-ü', ContextId::of('project', 'é-ü'));
        self::assertTrue(ContextId::isTyped('project:5'));
    }

    /**
     * Audit OI-K2: only Cc, Cf, Cs and Co are refused, not unassigned code points (Cn), so an emoji newer than the
     * PCRE Unicode tables of the host is still accepted.
     *
     * @return iterable<string, array{string}>
     */
    public static function emojiIds(): iterable
    {
        yield 'emoji' => ["team-\u{1F600}"];
        yield 'Unicode 15 emoji' => ["moose-\u{1FACE}"];
        yield 'Unicode 16 emoji' => ["face-\u{1FAE9}"];
        yield 'unassigned code point (Cn)' => ["x-\u{2FFFD}"];
    }

    #[Test]
    #[DataProvider('emojiIds')]
    public function acceptsEmojiIds(string $id): void
    {
        self::assertSame('project:' . $id, ContextId::of('project', $id));
        self::assertSame('project:' . $id, Assignment::in('editor', 'project', $id)->contextId);
    }
}

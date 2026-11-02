<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\Grant;

use InvalidArgumentException;
use Nowo\RbacKitBundle\Context\ContextId;
use Nowo\RbacKitBundle\Context\DefaultContextResolver;
use Nowo\RbacKitBundle\Context\NullRequestContextResolver;
use Nowo\RbacKitBundle\Grant\Assignment;
use Nowo\RbacKitBundle\Grant\GrantResolver;
use Nowo\RbacKitBundle\Grant\NullGrantProvider;
use Nowo\RbacKitBundle\Tests\Fixtures\MapGrantProvider;
use Nowo\RbacKitBundle\Tests\Fixtures\Project;
use Nowo\RbacKitBundle\Tests\Support\Catalogues;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use stdClass;
use Stringable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Security\Core\User\InMemoryUser;

use function iterator_to_array;
use function str_repeat;

final class GrantResolverTest extends TestCase
{
    #[Test]
    public function aUserHoldsTheUnionOfTheirSetsPerContext(): void
    {
        $provider = new MapGrantProvider();
        $grants   = new GrantResolver($provider, Catalogues::registry());
        $alice    = new InMemoryUser('alice', null);

        self::assertTrue($grants->grants($alice, 'project:billing', 'project.view'));
        self::assertTrue($grants->grants($alice, 'project:billing', 'project.issues.triage'), 'union of viewer and triage');
        self::assertFalse($grants->grants($alice, 'project:mobile', 'project.issues.triage'));
        self::assertFalse($grants->grants($alice, 'project:other', 'project.view'));
        self::assertFalse($grants->grantsGlobally($alice, 'summary.view'), 'context sets are not global');
        self::assertSame(['project:billing', 'project:mobile'], $grants->contextsGranting($alice, 'summary.view'));
        self::assertSame(['project:billing'], $grants->contextsGranting($alice, 'project.issues.triage'));
        self::assertTrue($grants->holdsSetIn($alice, 'project:mobile'));
        self::assertFalse($grants->holdsSetIn($alice, 'project:other'));
        self::assertTrue($grants->holdsAnyAssignment($alice));
        self::assertSame(1, $provider->calls, 'one provider call per user and request');

        $bob = new InMemoryUser('bob', null);
        self::assertTrue($grants->grantsGlobally($bob, 'reports.export'));
        self::assertSame([], $grants->contextsGranting($bob, 'reports.export'));

        $nobody = new InMemoryUser('nobody', null);
        self::assertFalse($grants->holdsAnyAssignment($nobody));

        $grants->reset();
        $grants->grants($alice, 'project:billing', 'project.view');
        self::assertSame(4, $provider->calls, 'reset forgets every user');
    }

    #[Test]
    public function assignmentsAndMemoisedUsersAreBounded(): void
    {
        $logger = new class extends AbstractLogger {
            public int $warnings = 0;

            public function log($level, string|Stringable $message, array $context = []): void
            {
                ++$this->warnings;
            }
        };
        $provider = new MapGrantProvider(['alice' => [['viewer', 'billing'], ['triage', 'billing'], ['viewer', 'mobile']]]);
        $grants   = new GrantResolver($provider, Catalogues::registry(), 2, 1, $logger);
        $alice    = new InMemoryUser('alice', null);

        self::assertTrue($grants->grants($alice, 'project:billing', 'project.issues.triage'));
        self::assertFalse($grants->holdsSetIn($alice, 'project:mobile'), 'the third assignment is ignored');
        self::assertSame(1, $logger->warnings);

        $grants->holdsAnyAssignment(new InMemoryUser('bob', null));
        $grants->holdsAnyAssignment($alice);
        self::assertSame(3, $provider->calls, 'the memo keeps one user');
    }

    #[Test]
    public function userKeysAreStable(): void
    {
        self::assertSame(InMemoryUser::class . '#alice', GrantResolver::userKey(new InMemoryUser('alice', null)));
        $other = new stdClass();
        self::assertSame(GrantResolver::userKey($other), GrantResolver::userKey($other));
        self::assertSame([], iterator_to_array((new NullGrantProvider())->assignmentsOf($other), false));
    }

    #[Test]
    public function theDefaultContextResolverAcceptsOnlyRbacContexts(): void
    {
        $resolver = new DefaultContextResolver();
        self::assertSame('project:billing', $resolver->contextId(new Project('billing')));
        self::assertSame('organization:billing', $resolver->contextId(new Project('billing', 'organization')), 'typed: another type is another context');
        self::assertNull($resolver->contextId(new stdClass()));
        self::assertNull($resolver->contextId(new Project(' ')));
        self::assertNull($resolver->contextId(new Project(str_repeat('x', 192))));
        self::assertSame('project:' . str_repeat('x', 191), $resolver->contextId(new Project(str_repeat('x', 191))));
        self::assertNull($resolver->contextId(new Project('5', 'Project')), 'a class-like type is refused');
        self::assertNull($resolver->contextId(new Project('5', '')));
        self::assertNull((new NullRequestContextResolver())->fromRequest(Request::create('/')));
    }

    /**
     * Appendix CI M1: assignments carry typed context ids; an untyped id is refused when the assignment is built.
     */
    #[Test]
    public function assignmentsUseTypedContextIds(): void
    {
        self::assertNull(Assignment::global('auditor')->contextId);
        self::assertSame('project:5', Assignment::in('owner', 'project', '5')->contextId);
        self::assertSame('organization:5', Assignment::of('owner', new Project('5', 'organization'))->contextId);
        self::assertSame('project:a:b', (new Assignment('owner', 'project:a:b'))->contextId, 'the id may contain the separator');
        self::assertTrue(ContextId::isTyped('project:5'));
        self::assertFalse(ContextId::isTyped('5'));
        self::assertFalse(ContextId::isTyped('project:'));
        self::assertFalse(ContextId::isTyped(':5'));
        self::assertFalse(ContextId::isTyped('Project:5'));

        try {
            new Assignment('owner', '5');
            self::fail('untyped');
        } catch (InvalidArgumentException $e) {
            self::assertStringContainsString('must be typed', $e->getMessage());
        }

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('invalid context type');
        Assignment::in('owner', 'Bad Type', '5');
    }
}

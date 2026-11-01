<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Unit\Grant;

use Nowo\RbacKitBundle\Context\DefaultContextResolver;
use Nowo\RbacKitBundle\Context\NullRequestContextResolver;
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

        self::assertTrue($grants->grants($alice, 'billing', 'project.view'));
        self::assertTrue($grants->grants($alice, 'billing', 'project.issues.triage'), 'union of viewer and triage');
        self::assertFalse($grants->grants($alice, 'mobile', 'project.issues.triage'));
        self::assertFalse($grants->grants($alice, 'other', 'project.view'));
        self::assertFalse($grants->grantsGlobally($alice, 'summary.view'), 'context sets are not global');
        self::assertSame(['billing', 'mobile'], $grants->contextsGranting($alice, 'summary.view'));
        self::assertSame(['billing'], $grants->contextsGranting($alice, 'project.issues.triage'));
        self::assertTrue($grants->holdsSetIn($alice, 'mobile'));
        self::assertFalse($grants->holdsSetIn($alice, 'other'));
        self::assertTrue($grants->holdsAnyAssignment($alice));
        self::assertSame(1, $provider->calls, 'one provider call per user and request');

        $bob = new InMemoryUser('bob', null);
        self::assertTrue($grants->grantsGlobally($bob, 'reports.export'));
        self::assertSame([], $grants->contextsGranting($bob, 'reports.export'));

        $nobody = new InMemoryUser('nobody', null);
        self::assertFalse($grants->holdsAnyAssignment($nobody));

        $grants->reset();
        $grants->grants($alice, 'billing', 'project.view');
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

        self::assertTrue($grants->grants($alice, 'billing', 'project.issues.triage'));
        self::assertFalse($grants->holdsSetIn($alice, 'mobile'), 'the third assignment is ignored');
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
        self::assertSame('billing', $resolver->contextId(new Project('billing')));
        self::assertNull($resolver->contextId(new stdClass()));
        self::assertNull($resolver->contextId(new Project(' ')));
        self::assertNull($resolver->contextId(new Project(str_repeat('x', 192))));
        self::assertNull((new NullRequestContextResolver())->fromRequest(Request::create('/')));
    }
}

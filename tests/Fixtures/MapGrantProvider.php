<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Fixtures;

use Nowo\RbacKitBundle\Grant\Assignment;
use Nowo\RbacKitBundle\Grant\GrantProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Grant provider of the tests: user identifier => list of [set, context id|null]; counts its calls.
 */
final class MapGrantProvider implements GrantProviderInterface
{
    public int $calls = 0;

    /**
     * @param array<string, list<array{0: string, 1: string|null}>> $map
     */
    public function __construct(
        private readonly array $map = [
            'alice' => [['viewer', 'billing'], ['triage', 'billing'], ['viewer', 'mobile']],
            'bob'   => [['auditor', null]],
        ],
    ) {
    }

    public function assignmentsOf(object $user): iterable
    {
        ++$this->calls;
        $identifier = $user instanceof UserInterface ? $user->getUserIdentifier() : '';
        foreach ($this->map[$identifier] ?? [] as [$set, $context]) {
            yield new Assignment($set, $context);
        }
    }
}

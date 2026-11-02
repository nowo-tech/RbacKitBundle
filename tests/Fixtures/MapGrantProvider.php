<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Tests\Fixtures;

use Nowo\RbacKitBundle\Grant\Assignment;
use Nowo\RbacKitBundle\Grant\GrantProviderInterface;
use Symfony\Component\Security\Core\User\UserInterface;

use function str_contains;

/**
 * Grant provider of the tests: user identifier => list of [set, context|null]; counts its calls. A context without a
 * type (`billing`) is a project (`project:billing`); a typed one (`organization:5`) is used as is.
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
            yield match (true) {
                $context === null           => Assignment::global($set),
                str_contains($context, ':') => new Assignment($set, $context),
                default                     => Assignment::in($set, 'project', $context),
            };
        }
    }
}

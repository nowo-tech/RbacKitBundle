<?php

declare(strict_types=1);

namespace App\Demo;

use Nowo\RbacKitBundle\Grant\Assignment;
use Nowo\RbacKitBundle\Grant\GrantProviderInterface;
use Nowo\RbacKitBundle\PermissionSet\PermissionSetUsageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * The permission sets each demo user holds (an application reads its memberships in one query):
 * alice is a viewer of both projects and triages Billing (two incomparable sets, union in Billing); bob audits reports
 * globally; carol owns Mobile. admin bypasses the catalogue (voter.bypass_roles).
 */
final class DemoGrantProvider implements GrantProviderInterface, PermissionSetUsageInterface
{
    /** @var array<string, list<array{0: string, 1: string|null}>> */
    private const ASSIGNMENTS = [
        'alice' => [['viewer', 'billing'], ['triage', 'billing'], ['viewer', 'mobile']],
        'bob'   => [['auditor', null]],
        'carol' => [['owner', 'mobile']],
    ];

    public function assignmentsOf(object $user): iterable
    {
        $identifier = $user instanceof UserInterface ? $user->getUserIdentifier() : '';
        foreach (self::ASSIGNMENTS[$identifier] ?? [] as [$set, $context]) {
            yield new Assignment($set, $context);
        }
    }

    public function usageCounts(array $codes): array
    {
        $counts = [];
        foreach (self::ASSIGNMENTS as $assignments) {
            foreach ($assignments as [$set]) {
                $counts[$set] = ($counts[$set] ?? 0) + 1;
            }
        }

        return $counts;
    }
}

<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\PermissionSet;

/**
 * Optional host service (`storage.usage_provider`): how many assignments use each set. The admin UI shows the counts,
 * refuses to delete a set in use, and needs it to delete custom sets at all.
 *
 * Contract: count the host's assignments **by code, whether or not a set with that code exists** (a deleted set's
 * stale assignments included). Creating a set is refused while its code still has assignments (`code_assigned`), so a
 * re-created code never gives its keys to the holders of a deleted set — that guarantee holds only if the provider
 * also counts codes that no longer exist.
 */
interface PermissionSetUsageInterface
{
    /**
     * Assignment count per set code (codes without assignments may be omitted).
     *
     * @param list<string> $codes the codes asked for (the listed sets, or the code being created or deleted)
     *
     * @return array<string, int>
     */
    public function usageCounts(array $codes): array;
}

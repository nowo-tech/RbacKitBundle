<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\PermissionSet;

/**
 * Optional host service (`storage.usage_provider`): how many assignments use each set. The admin UI shows the counts
 * and refuses to delete a set in use; without it a deleted custom set simply grants nothing any more.
 */
interface PermissionSetUsageInterface
{
    /**
     * Assignment count per set code (codes without assignments may be omitted).
     *
     * @param list<string> $codes the codes listed on the page (bounded by `storage.max_sets` + the system sets)
     *
     * @return array<string, int>
     */
    public function usageCounts(array $codes): array;
}

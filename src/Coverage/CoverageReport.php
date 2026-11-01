<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Coverage;

/**
 * Result of {@see PermissionCoverage::report()}: routes under the checked prefixes that no permission guards, routes
 * the catalogue names that do not exist, and the uncatalogued keys referenced by permission sets.
 */
final readonly class CoverageReport
{
    /**
     * @param list<string> $unguardedRoutes `name path` of routes under the prefixes without a permission
     * @param list<string> $missingRoutes `key: route` of catalogue routes the router does not know
     * @param array<string, list<string>> $unknownKeys set code => configured keys missing from the catalogue
     * @param bool $emptyCatalogue no permission is catalogued (every permission is denied)
     */
    public function __construct(
        public array $unguardedRoutes = [],
        public array $missingRoutes = [],
        public array $unknownKeys = [],
        public bool $emptyCatalogue = false,
    ) {
    }

    /**
     * Whether nothing needs fixing.
     */
    public function isClean(): bool
    {
        return $this->unguardedRoutes === [] && $this->missingRoutes === [] && $this->unknownKeys === [] && !$this->emptyCatalogue;
    }
}

<?php

declare(strict_types=1);

namespace Nowo\RbacKitBundle\Command;

use Nowo\RbacKitBundle\Coverage\PermissionCoverage;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

use function array_filter;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function implode;
use function is_array;
use function is_string;
use function sprintf;

/**
 * `nowo:rbac:check`: fails (exit 1) when a route under the checked prefixes has no permission, a catalogue route does
 * not exist, a permission set names an uncatalogued key, or the catalogue is empty. Read-only; meant for CI.
 */
#[AsCommand(name: self::NAME, description: 'Check that the permission catalogue covers the routes and the permission sets')]
final class CheckCommand extends Command
{
    /** Command name. */
    public const NAME = 'nowo:rbac:check';

    /**
     * @param list<string> $prefixes default path prefixes (`coverage.prefixes`)
     * @param list<string> $ignoreRoutes default exempt routes (`coverage.ignore_routes`)
     */
    public function __construct(
        private readonly PermissionCoverage $coverage,
        private readonly array $prefixes = [],
        private readonly array $ignoreRoutes = [],
    ) {
        parent::__construct();
    }

    /**
     * `--prefix` and `--ignore` (repeatable) override the configured lists.
     */
    protected function configure(): void
    {
        $this
            ->addOption('prefix', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Path prefix whose routes must be guarded (default: coverage.prefixes)')
            ->addOption('ignore', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Route name exempt from the check (default: coverage.ignore_routes)');
    }

    /**
     * Prints the report; FAILURE when it is not clean.
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io       = new SymfonyStyle($input, $output);
        $prefixes = $this->strings($input->getOption('prefix'));
        $ignore   = $this->strings($input->getOption('ignore'));
        $report   = $this->coverage->report($prefixes === [] ? $this->prefixes : $prefixes, $ignore === [] ? $this->ignoreRoutes : $ignore);

        if ($report->emptyCatalogue) {
            $io->error('The permission catalogue is empty: every permission is denied.');
        }
        if ($report->unguardedRoutes !== []) {
            $io->section(sprintf('%d route(s) without a permission', count($report->unguardedRoutes)));
            $io->listing($report->unguardedRoutes);
        }
        if ($report->missingRoutes !== []) {
            $io->section('Catalogue routes that do not exist');
            $io->listing($report->missingRoutes);
        }
        if ($report->unknownKeys !== []) {
            $io->section('Permission sets with uncatalogued keys');
            $io->listing(array_values(array_map(static fn (string $code, array $keys): string => $code . ': ' . implode(', ', $keys), array_keys($report->unknownKeys), $report->unknownKeys)));
        }

        if (!$report->isClean()) {
            return Command::FAILURE;
        }
        $io->success('The permission catalogue covers every checked route and permission set.');

        return Command::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function strings(mixed $value): array
    {
        return is_array($value) ? array_values(array_filter($value, static fn (mixed $v): bool => is_string($v) && $v !== '')) : [];
    }
}

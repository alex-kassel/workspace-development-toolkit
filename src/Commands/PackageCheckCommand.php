<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Enums\CheckStatus;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageGraph;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageVerifier;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class PackageCheckCommand extends BasePackageCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:check
        {package? : Package name in vendor/package format (e.g. acme/my-pkg)}
        {--all : Verify all packages across workspaces}
        {--quick : Run only quick checks (Composer validate and Pint)}
        {--fix : Automatically fix code style errors (enabled by default)}
        {--dry-run : Only check code style without applying automatic fixes (recommended for CI)}
        {--only= : Comma-separated list of checks to run (composer,pint,phpstan,tests)}
        {--isolated : Install and test an independent temporary package copy (Phase 3)}
        {--with-workspace-deps : Link sibling workspace packages during isolated verification}
        {--affected : Also run regression tests for packages that depend on this package}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run quality checks (Composer validate, Pint, PHPStan, Tests) on a package';

    public function __construct(
        WorkspaceManager $workspace,
        ComposerManager $composer,
        protected readonly PackageVerifier $verifier,
        protected readonly ?PackageGraph $graph = null,
    ) {
        parent::__construct($workspace, $composer);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rawPackage = (string) $this->argument('package');
        $all = (bool) $this->option('all');
        $quick = (bool) $this->option('quick');
        $dryRun = (bool) $this->option('dry-run')
            || (getenv('CI') === 'true')
            || (getenv('GITHUB_ACTIONS') !== false);
        $fix = (bool) $this->option('fix') || ! $dryRun;
        $isolated = (bool) $this->option('isolated');
        $withWorkspaceDeps = (bool) $this->option('with-workspace-deps');
        $affected = (bool) $this->option('affected');
        $tier = $quick ? 'quick' : 'deep';

        $rawOnly = (string) $this->option('only');
        $only = $rawOnly !== '' ? array_map('trim', explode(',', $rawOnly)) : [];

        if ($all) {
            return $this->handleAllPackages($tier, $only, $fix, $isolated, $withWorkspaceDeps);
        }

        if ($rawPackage === '') {
            $this->error('Please specify a package name or use the [--all] flag to check all packages.');
            $this->line('  <comment>How to fix:</comment> Provide a package name or use --all:');
            $this->line('  <info>php artisan package:check vendor/package</info>');
            $this->line('  <info>php artisan package:check --all</info>');

            return self::FAILURE;
        }

        return $this->handleSinglePackage($rawPackage, $tier, $only, $fix, $isolated, $withWorkspaceDeps, $affected);
    }

    /**
     * Handle quality check for a single package.
     *
     * @param  array<int, string>  $only
     */
    protected function handleSinglePackage(
        string $rawPackage,
        string $tier,
        array $only,
        bool $fix,
        bool $isolated = false,
        bool $withWorkspaceDeps = false,
        bool $affected = false
    ): int {
        $packagePath = $this->workspace->findPackagePath($rawPackage);

        if ($packagePath === null || ! File::isDirectory(base_path($packagePath))) {
            $this->error("Package [{$rawPackage}] not found.");
            $this->line('  <comment>How to fix:</comment> View registered packages using:');
            $this->line('  <info>php artisan workspace:list</info>');

            return self::FAILURE;
        }

        $package = $this->workspace->resolveCanonicalPackageName($rawPackage);

        $this->info("Verifying package [{$package}] in [{$packagePath}]...");
        $this->newLine();

        try {
            $results = $this->verifier->checkAll($packagePath, $package, $tier, $only, $fix, $isolated, $withWorkspaceDeps);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $hasFailure = false;
        $rows = [];

        foreach ($results as $result) {
            $statusFormatted = CheckStatus::format($result->status);

            if ($result->status === 'failed') {
                $hasFailure = true;
            }

            $rows[] = [
                strtoupper($result->check),
                $statusFormatted,
                number_format($result->durationSeconds, 2).'s',
            ];
        }

        $this->table(['Check', 'Status', 'Duration'], $rows);

        foreach ($results as $result) {
            if ($result->status === 'failed' && ! empty($result->output)) {
                $this->newLine();
                $this->error("Details for [{$result->check}]:");
                $this->line($result->output);
            }
        }

        // Handle affected dependent packages regression checking
        if ($affected) {
            $graph = $this->graph ?? app(PackageGraph::class);
            $dependents = $graph->getDependents($package, recursive: true);

            if (empty($dependents)) {
                $this->newLine();
                $this->info("No other workspace packages depend on [{$package}].");
            } else {
                $this->newLine();
                $this->info('Running regression test suites for '.count($dependents).' dependent package(s)...');
                $this->newLine();

                $dependentRows = [];
                foreach ($dependents as $depPackage) {
                    $depPath = $this->workspace->findPackagePath($depPackage);
                    if ($depPath === null || ! File::isDirectory(base_path($depPath))) {
                        continue;
                    }

                    $testResult = $this->verifier->checkTests(base_path($depPath), $depPackage);
                    $statusFormatted = CheckStatus::format($testResult->status);

                    if ($testResult->status === 'failed') {
                        $hasFailure = true;
                    }

                    $dependentRows[] = [
                        $depPackage,
                        $statusFormatted,
                        number_format($testResult->durationSeconds, 2).'s',
                    ];

                    if ($testResult->status === 'failed' && ! empty($testResult->output)) {
                        $this->newLine();
                        $this->error("Regression failure in dependent package [{$depPackage}]:");
                        $this->line($testResult->output);
                    }
                }

                $this->table(['Dependent Package (Affected)', 'Tests Status', 'Duration'], $dependentRows);
            }
        }

        $this->newLine();

        if ($hasFailure) {
            $this->error("✖ Verification failed for package [{$package}].");

            return self::FAILURE;
        }

        $this->info("✔ Verification passed for package [{$package}].");

        return self::SUCCESS;
    }

    /**
     * Handle quality check across all packages in workspace.
     *
     * @param  array<int, string>  $only
     */
    protected function handleAllPackages(string $tier, array $only, bool $fix, bool $isolated = false, bool $withWorkspaceDeps = false): int
    {
        $this->workspace->sync();
        $packagesToVerify = array_filter(
            $this->workspace->getAllLocalPackages(),
            fn (string $path) => File::isDirectory(base_path($path))
        );

        if (empty($packagesToVerify)) {
            $this->warn('No local packages found in registered workspaces.');

            return self::SUCCESS;
        }

        $count = count($packagesToVerify);
        $this->info("Verifying {$count} package(s) across workspaces...");
        $this->newLine();

        try {
            $allResults = $this->verifier->checkAllPackages($packagesToVerify, $tier, $only, $fix, $isolated, $withWorkspaceDeps);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $tableRows = [];
        $totalFailures = 0;
        $includeIsolated = $isolated || in_array('isolated', $only, true);

        foreach ($allResults as $path => $results) {
            $pkgName = $results[0]->package ?? (string) array_search($path, $packagesToVerify, true);
            $statusMap = [];
            $pkgFailed = false;

            foreach ($results as $r) {
                $statusMap[$r->check] = CheckStatus::format($r->status);

                if ($r->status === 'failed') {
                    $pkgFailed = true;
                }
            }

            if ($pkgFailed) {
                $totalFailures++;
            }

            $row = [
                $pkgName,
                $statusMap['composer'] ?? '-',
                $statusMap['pint'] ?? '-',
                $statusMap['phpstan'] ?? '-',
                $statusMap['tests'] ?? '-',
            ];

            if ($includeIsolated) {
                $row[] = $statusMap['isolated'] ?? '-';
            }

            $row[] = $pkgFailed ? '<fg=red>FAIL</>' : '<fg=green>PASS</>';
            $tableRows[] = $row;
        }

        $headers = ['Package', 'Composer', 'Pint', 'PHPStan', 'Tests'];
        if ($includeIsolated) {
            $headers[] = 'Isolated';
        }
        $headers[] = 'Status';

        $this->table($headers, $tableRows);
        $this->newLine();

        if ($totalFailures > 0) {
            $this->error("✖ Verification failed for {$totalFailures} package(s).");

            return self::FAILURE;
        }

        $this->info("✔ All {$count} package(s) passed verification.");

        return self::SUCCESS;
    }
}

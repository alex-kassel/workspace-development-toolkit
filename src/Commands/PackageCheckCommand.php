<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

class PackageCheckCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:check
        {name? : Package name or alias}
        {--all : Verify all packages across workspaces}
        {--quick : Run only quick checks (Composer validate and Pint)}
        {--dry-run : Only check code style without applying automatic fixes (recommended for CI)}
        {--fix : Automatically fix code style issues with Pint (default; kept for backwards compatibility)}
        {--only= : Comma-separated list of checks to run (composer,pint,phpstan,tests)}
        {--isolated : Install and test an independent temporary package copy (Phase 3)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Run quality checks (Composer validate, Pint, PHPStan, Tests) on a package';

    public function __construct(
        protected PackageVerifier $verifier
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rawName = (string) $this->argument('name');
        $all = (bool) $this->option('all');
        $quick = (bool) $this->option('quick');
        $dryRun = (bool) $this->option('dry-run')
            || (getenv('CI') === 'true')
            || (getenv('GITHUB_ACTIONS') !== false);
        $fix = ! $dryRun;
        $isolated = (bool) $this->option('isolated');
        $tier = $quick ? 'quick' : 'deep';

        $rawOnly = (string) $this->option('only');
        $only = $rawOnly !== '' ? array_map('trim', explode(',', $rawOnly)) : [];

        if ($all) {
            return $this->handleAllPackages($tier, $only, $fix, $isolated);
        }

        if ($rawName === '') {
            $this->error('Please specify a package name or use the [--all] flag to check all packages.');
            $this->line('  <comment>How to fix:</comment> Provide a package name or use --all:');
            $this->line('  <info>php artisan package:check vendor/package</info>');
            $this->line('  <info>php artisan package:check --all</info>');

            return self::FAILURE;
        }

        return $this->handleSinglePackage($rawName, $tier, $only, $fix, $isolated);
    }

    /**
     * Handle quality check for a single package.
     *
     * @param  array<int, string>  $only
     */
    protected function handleSinglePackage(string $name, string $tier, array $only, bool $fix, bool $isolated = false): int
    {
        $packagePath = Workspace::findPackagePath($name);

        if ($packagePath === null || ! File::isDirectory(base_path($packagePath))) {
            $this->error("Package [{$name}] not found.");
            $this->line('  <comment>How to fix:</comment> View registered packages using:');
            $this->line('  <info>php artisan workspace:list</info>');

            return self::FAILURE;
        }

        $canonicalName = Workspace::resolveCanonicalPackageName($name);

        $this->info("Verifying package [{$canonicalName}] in [{$packagePath}]...");
        $this->newLine();

        try {
            $results = $this->verifier->checkAll($packagePath, $canonicalName, $tier, $only, $fix, $isolated);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $hasFailure = false;
        $rows = [];

        foreach ($results as $result) {
            $statusFormatted = match ($result->status) {
                'passed' => '<fg=green>PASS</>',
                'failed' => '<fg=red>FAIL</>',
                'skipped' => '<fg=yellow>SKIP</>',
                default => $result->status,
            };

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
            if ($result->status === 'failed' && trim($result->output) !== '') {
                $this->newLine();
                $this->error("Failure in [{$result->check}]:");
                $this->line($result->output);
            }
        }

        $this->newLine();

        if ($hasFailure) {
            $this->error("✖ Verification failed for package [{$canonicalName}].");

            return self::FAILURE;
        }

        $this->info("✔ Verification passed for package [{$canonicalName}].");

        return self::SUCCESS;
    }

    /**
     * Handle quality check across all packages in workspace.
     *
     * @param  array<int, string>  $only
     */
    protected function handleAllPackages(string $tier, array $only, bool $fix, bool $isolated = false): int
    {
        $packagesToVerify = [];
        $data = Workspace::sync();

        foreach ($data['workspaces'] ?? [] as $ws => $config) {
            foreach ($config['packages'] ?? [] as $pkg) {
                $pkgName = is_array($pkg) ? ($pkg['name'] ?? '') : (string) $pkg;
                if ($pkgName === '') {
                    continue;
                }

                $path = Workspace::findPackagePath($pkgName);
                if ($path !== null && File::isDirectory(base_path($path))) {
                    $packagesToVerify[$pkgName] = $path;
                }
            }
        }

        if (empty($packagesToVerify)) {
            $this->warn('No local packages found in registered workspaces.');

            return self::SUCCESS;
        }

        $count = count($packagesToVerify);
        $this->info("Verifying {$count} package(s) across workspaces...");
        $this->newLine();

        try {
            $allResults = $this->verifier->checkAllPackages($packagesToVerify, $tier, $only, $fix, $isolated);
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
                $statusMap[$r->check] = match ($r->status) {
                    'passed' => '<fg=green>PASS</>',
                    'failed' => '<fg=red>FAIL</>',
                    'skipped' => '<fg=yellow>SKIP</>',
                    default => '-',
                };

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

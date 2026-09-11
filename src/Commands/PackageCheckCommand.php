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
        {--fix : Automatically fix code style issues with Pint}
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
        $fix = (bool) $this->option('fix');
        $isolated = (bool) $this->option('isolated');

        if ($isolated) {
            $this->warn('Notice: Isolated verification (--isolated) will be available in Phase 3.');
        }

        $rawOnly = (string) $this->option('only');
        $only = $rawOnly !== '' ? array_map('trim', explode(',', $rawOnly)) : [];

        if ($all) {
            return $this->handleAllPackages($only, $fix);
        }

        if ($rawName === '') {
            $this->error('Please specify a package name or use the [--all] flag to check all packages.');
            $this->line('  <comment>How to fix:</comment> Provide a package name or use --all:');
            $this->line('  <info>php artisan package:check vendor/package</info>');
            $this->line('  <info>php artisan package:check --all</info>');

            return self::FAILURE;
        }

        return $this->handleSinglePackage($rawName, $only, $fix);
    }

    /**
     * Handle quality check for a single package.
     *
     * @param  array<int, string>  $only
     */
    protected function handleSinglePackage(string $name, array $only, bool $fix): int
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
            $results = $this->verifier->checkAll($packagePath, $canonicalName, $only, $fix);
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
    protected function handleAllPackages(array $only, bool $fix): int
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
            $allResults = $this->verifier->checkAllPackages($packagesToVerify, $only, $fix);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $tableRows = [];
        $totalFailures = 0;

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

            $tableRows[] = [
                $pkgName,
                $statusMap['composer'] ?? '-',
                $statusMap['pint'] ?? '-',
                $statusMap['phpstan'] ?? '-',
                $statusMap['tests'] ?? '-',
                $pkgFailed ? '<fg=red>FAIL</>' : '<fg=green>PASS</>',
            ];
        }

        $this->table(['Package', 'Composer', 'Pint', 'PHPStan', 'Tests', 'Status'], $tableRows);
        $this->newLine();

        if ($totalFailures > 0) {
            $this->error("✖ Verification failed for {$totalFailures} package(s).");

            return self::FAILURE;
        }

        $this->info("✔ All {$count} package(s) passed verification.");

        return self::SUCCESS;
    }
}

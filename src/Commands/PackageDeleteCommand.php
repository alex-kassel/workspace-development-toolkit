<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class PackageDeleteCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:delete {name : Package name (vendor/package or short name for fixed-vendor workspace)} {--force : Delete without interactive confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Permanently delete a local package from disk';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rawName = (string) $this->argument('name');
        $force = (bool) $this->option('force');
        $normalizedInput = str_replace('\\', '/', trim($rawName));

        $canonicalName = Workspace::resolveCanonicalPackageName($normalizedInput);

        if (! str_contains($canonicalName, '/')) {
            $this->error("Invalid package name [{$rawName}]. Package must be in 'vendor/package' format or belong to a workspace with a fixed vendor.");
            $this->line('  <comment>How to fix:</comment> Specify the full package name, e.g.:');
            $this->line('  <info>php artisan package:delete my-vendor/my-package</info>');
            $this->line('  Or check existing packages with: <info>php artisan workspace:list</info>');

            return self::FAILURE;
        }

        [$rawVendor, $rawPackage] = explode('/', $canonicalName, 2);
        $vendor = strtolower(trim($rawVendor));
        $package = strtolower(trim($rawPackage));

        $segmentPattern = '/^[a-z0-9]([_.-]?[a-z0-9]+)*$/';
        if (! preg_match($segmentPattern, $vendor) || ! preg_match($segmentPattern, $package)) {
            $suggestedVendor = Str::slug($vendor);
            $suggestedPackage = Str::slug($package);
            $this->error("Invalid package name [{$rawName}]. Composer names may only contain lowercase letters, numbers, dashes, underscores, and dots.");
            $this->line('  <comment>How to fix:</comment> Did you mean:');
            $this->line("  <info>php artisan package:delete {$suggestedVendor}/{$suggestedPackage}".($force ? ' --force' : '').'</info>');

            return self::FAILURE;
        }

        $name = "{$vendor}/{$package}";

        if ($name !== $rawName) {
            $this->line("  <comment>Notice:</comment> Resolved package [{$rawName}] to Composer package [{$name}].");
        }

        $packagePath = Workspace::findPackagePath($name);

        if (! $packagePath) {
            $this->error("Package [{$name}] was not found in any registered workspace.");
            $this->line('  <comment>How to fix:</comment> Check existing packages using:');
            $this->line('  <info>php artisan workspace:list</info>');
            $this->line('  If the package is not local, remove it directly with Composer:');
            $this->line("  <info>composer remove {$name}</info>");

            return self::FAILURE;
        }

        $fullPath = base_path($packagePath);
        $realFullPath = realpath($fullPath);
        $realBasePath = realpath(base_path());

        $normalizedFullPath = $realFullPath ? strtolower(rtrim(str_replace('\\', '/', $realFullPath), '/')) : '';
        $normalizedBasePath = $realBasePath ? strtolower(rtrim(str_replace('\\', '/', $realBasePath), '/')) : '';

        if (! $realFullPath || ! $realBasePath || ! str_starts_with($normalizedFullPath, $normalizedBasePath.'/')) {
            $this->error("Security violation: Package path [{$fullPath}] resolves outside the application root.");

            return self::FAILURE;
        }

        if (! $force && ! $this->confirm("Are you sure you want to permanently delete [{$packagePath}] from disk?", false)) {
            $this->info('Deletion canceled.');

            return self::SUCCESS;
        }

        $composer = json_decode(File::get(base_path('composer.json')), true) ?: [];
        $isDev = isset($composer['require-dev'][$name]);
        $isRequire = isset($composer['require'][$name]);

        if ($isRequire || $isDev) {
            $this->info("Removing [{$name}] from Composer first...");
            $args = ['composer', 'remove', $name];
            if ($isDev) {
                $args[] = '--dev';
            }

            $result = Process::path(base_path())->timeout(180)->run($args);

            if (! $result->successful()) {
                $this->error("Failed to remove package [{$name}] from Composer.");
                $this->line("  <comment>Composer output:</comment>\n".trim($result->errorOutput()));
                $this->line('  <comment>How to fix:</comment> Resolve Composer issues or run removal manually:');
                $this->line("  <info>composer remove {$name}".($isDev ? ' --dev' : '').' -v</info>');

                return self::FAILURE;
            }
        }

        File::deleteDirectory($realFullPath);

        // If in a multi-vendor (nested) workspace, clean up parent vendor directory if left empty
        $vendorDir = dirname($realFullPath);
        $workspaceDir = dirname($vendorDir);
        if (File::isDirectory($vendorDir) && $vendorDir !== $workspaceDir && count(File::allFiles($vendorDir)) === 0 && count(File::directories($vendorDir)) === 0) {
            File::deleteDirectory($vendorDir);
        }

        Workspace::sync();

        $this->info("Package [{$name}] permanently deleted from [{$packagePath}].");

        $this->newLine();
        $this->line('  <comment>Hint:</comment> To see remaining packages across all workspaces:');
        $this->line('  <info>php artisan workspace:list</info>');

        return self::SUCCESS;
    }
}

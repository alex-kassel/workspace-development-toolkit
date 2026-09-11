<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

class PackageInstallCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:install {name : Package name (vendor/package or short name for fixed-vendor workspace)} {--dev : Install package into require-dev} {--remote : Allow installing non-local package from Composer remote repositories}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install a local workspace package into the application via Composer';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rawName = (string) $this->argument('name');
        $isDev = (bool) $this->option('dev');
        $allowRemote = (bool) $this->option('remote');
        $normalizedInput = str_replace('\\', '/', trim($rawName));

        $canonicalName = Workspace::resolveCanonicalPackageName($normalizedInput);

        if (! str_contains($canonicalName, '/')) {
            $this->error("Invalid package name [{$rawName}]. Package must be in 'vendor/package' format or belong to a workspace with a fixed vendor.");
            $this->line('  <comment>How to fix:</comment> Specify the full package name, e.g.:');
            $this->line('  <info>php artisan package:install my-vendor/my-package</info>');
            $this->line('  Or check registered workspaces: <info>php artisan workspace:list</info>');

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
            $this->line("  <info>php artisan package:install {$suggestedVendor}/{$suggestedPackage}".($isDev ? ' --dev' : '').'</info>');

            return self::FAILURE;
        }

        $name = "{$vendor}/{$package}";

        if ($name !== $rawName) {
            $this->line("  <comment>Notice:</comment> Resolved package [{$rawName}] to Composer package [{$name}].");
        }

        $packagePath = Workspace::findPackagePath($name);

        if (! $packagePath) {
            if (! $allowRemote) {
                $this->error("Package [{$name}] was not found in any registered workspace.");
                $this->line('  <comment>How to fix:</comment> Verify that the package exists in one of your workspaces:');
                $this->line('  <info>php artisan workspace:list</info>');
                $this->line('  Or create the package first:');
                $this->line("  <info>php artisan package:make {$name}</info>");
                $this->line('  If you intentionally wish to install a remote Composer package, re-run with --remote:');
                $this->line("  <info>php artisan package:install {$name}".($isDev ? ' --dev' : '').' --remote</info>');

                return self::FAILURE;
            }

            $this->warn("Notice: Package [{$name}] was not found locally. Installing from remote Composer repositories via [--remote].");
        }

        $packageConstraint = $packagePath ? "{$name}:@dev" : $name;
        $args = ['composer', 'require', $packageConstraint];

        if ($isDev) {
            $args[] = '--dev';
        }

        $this->info("Installing [{$name}] via Composer...");

        $result = Process::path(base_path())
            ->timeout(180)
            ->run($args);

        if (! $result->successful()) {
            $this->error("Failed to install package [{$name}] via Composer.");
            $this->line("  <comment>Composer output:</comment>\n".trim($result->errorOutput()));
            $this->line('  <comment>How to fix:</comment> If this is a local package, verify that it was created first:');
            $this->line("  <info>php artisan package:make {$name}</info>");
            $this->line('  Also check your registered workspaces: <info>php artisan workspace:list</info>');

            return self::FAILURE;
        }

        $targetSection = $isDev ? 'require-dev' : 'require';
        $this->info("Package [{$name}] installed successfully into [{$targetSection}].");

        $this->newLine();
        $this->line('  <comment>Hint:</comment> To uninstall this package from composer without deleting files:');
        $this->line("  <info>php artisan package:uninstall {$name}</info>");
        $this->line('  Or to permanently delete it:');
        $this->line("  <info>php artisan package:delete {$name}</info>");

        return self::SUCCESS;
    }
}

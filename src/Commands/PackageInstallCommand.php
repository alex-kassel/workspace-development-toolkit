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
    protected $signature = 'package:install {name : Package name (vendor/package or short name for fixed-vendor workspace)} {--dev : Install package into require-dev}';

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
        $vendor = Str::slug($rawVendor);
        $package = Str::slug($rawPackage);
        $name = "{$vendor}/{$package}";

        if ($name !== $rawName) {
            $this->line("  <comment>Notice:</comment> Resolved package [{$rawName}] to Composer package [{$name}].");
        }

        $packagePath = Workspace::findPackagePath($name);

        if (! $packagePath) {
            $this->warn("Notice: Package [{$name}] was not found in any local workspace. Composer will attempt to resolve it from remote repositories.");
        }

        $args = ['composer', 'require', $name];

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

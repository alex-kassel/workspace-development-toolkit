<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use Illuminate\Support\Str;

class WorkspaceAddCommand extends BaseWorkspaceCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:add {path? : The workspace directory path} {--vendor= : Optional fixed vendor name for flat single-word packages} {--default : Set as default workspace}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Add a local workspace to composer.json, .gitignore and workspace.json';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $path = $this->getRequiredWorkspacePath(mustExist: false);
        if ($path === null) {
            return self::FAILURE;
        }

        $rawPath = (string) $this->argument('path');
        $isDefault = (bool) $this->option('default');
        $rawVendor = (string) $this->option('vendor');

        $vendor = null;
        if ($rawVendor !== '') {
            $vendor = Str::slug($rawVendor);
            if ($vendor === '') {
                $this->error("Invalid vendor [{$rawVendor}]. Vendor may only contain alphanumeric characters.");
                $this->line('  <comment>How to fix:</comment> Provide a valid vendor slug, e.g.:');
                $this->line("  <info>php artisan workspace:add {$path} --vendor=my-vendor</info>");

                return self::FAILURE;
            }

            if ($vendor !== $rawVendor) {
                $this->line("  <comment>Notice:</comment> Converted vendor [{$rawVendor}] to normalized Composer format [{$vendor}].");
            }
        }

        try {
            if (array_key_exists($path, $this->workspace->all())) {
                $this->error("Workspace [{$path}] is already registered.");
                $this->line('  <comment>How to fix:</comment> View registered workspaces using:');
                $this->line('  <info>php artisan workspace:list</info>');

                return self::FAILURE;
            }

            $this->workspace->add($path, $vendor, $isDefault);
        } catch (WorkspaceException $e) {
            return $this->handleWorkspaceException($e);
        }

        $defaultMsg = $this->workspace->getDefault() === $path ? ' (set as default)' : '';

        $vendorMsg = $vendor ? " with fixed vendor [{$vendor}] (flat structure)" : ' (multi-vendor nested structure)';
        $this->info("Workspace [{$path}] added successfully{$defaultMsg}{$vendorMsg}.");

        $packages = $this->workspace->all()[$path]['packages'] ?? [];
        $packageCount = count($packages);
        if ($packageCount > 0) {
            $this->line("  <info>Discovered and registered {$packageCount} existing package(s) from disk.</info>");
        }

        $this->newLine();
        $this->line('  <comment>Hint:</comment> You can now create packages in this workspace:');
        if ($vendor) {
            $this->line("  <info>php artisan package:make my-package --workspace={$path}</info>");
            if ($this->workspace->getDefault() === $path) {
                $this->line('  Or simply (using default workspace): <info>php artisan package:make my-package</info>');
            }
        } else {
            $this->line("  <info>php artisan package:make my-vendor/my-package --workspace={$path}</info>");
            if ($this->workspace->getDefault() === $path) {
                $this->line('  Or simply (using default workspace): <info>php artisan package:make my-vendor/my-package</info>');
            }
        }

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class WorkspaceAddCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:add {path : The workspace directory path} {--vendor= : Optional fixed vendor name for flat single-word packages} {--default : Set as default workspace}';

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
        $rawPath = (string) $this->argument('path');
        $normalizedPath = trim(preg_replace('#[/\\\\]+#', '/', $rawPath) ?? '', '/');
        $isDefault = (bool) $this->option('default');
        $rawVendor = (string) $this->option('vendor');

        if ($normalizedPath === '') {
            $this->error('Workspace path cannot be empty.');
            $this->line('  <comment>How to fix:</comment> Provide a relative directory path for the workspace, e.g.:');
            $this->line('  <info>php artisan workspace:add packages</info>');

            return self::FAILURE;
        }

        $vendor = null;
        if ($rawVendor !== '') {
            $vendor = Str::slug($rawVendor);
            if ($vendor === '') {
                $this->error("Invalid vendor [{$rawVendor}]. Vendor may only contain alphanumeric characters.");
                $this->line('  <comment>How to fix:</comment> Provide a valid vendor slug, e.g.:');
                $this->line("  <info>php artisan workspace:add {$normalizedPath} --vendor=my-vendor</info>");

                return self::FAILURE;
            }

            if ($vendor !== $rawVendor) {
                $this->line("  <comment>Notice:</comment> Converted vendor [{$rawVendor}] to normalized Composer format [{$vendor}].");
            }
        }

        $path = $normalizedPath;

        try {
            Workspace::add($path, $vendor, $isDefault);
        } catch (WorkspaceException $e) {
            $this->error($e->getMessage());
            if ($e->getSolution()) {
                $this->line("  <comment>How to fix:</comment> {$e->getSolution()}");
            }

            return self::FAILURE;
        }

        $defaultMsg = Workspace::getDefault() === $path ? ' (set as default)' : '';
        $vendorMsg = $vendor ? " with fixed vendor [{$vendor}] (flat structure)" : ' (multi-vendor nested structure)';
        $this->info("Workspace [{$path}] added successfully{$defaultMsg}{$vendorMsg}.");

        $this->newLine();
        $this->line('  <comment>Hint:</comment> You can now create packages in this workspace:');
        if ($vendor) {
            $this->line("  <info>php artisan package:make my-package --workspace={$path}</info>");
            if (Workspace::getDefault() === $path) {
                $this->line('  Or simply (using default workspace): <info>php artisan package:make my-package</info>');
            }
        } else {
            $this->line("  <info>php artisan package:make my-vendor/my-package --workspace={$path}</info>");
            if (Workspace::getDefault() === $path) {
                $this->line('  Or simply (using default workspace): <info>php artisan package:make my-vendor/my-package</info>');
            }
        }

        return self::SUCCESS;
    }
}

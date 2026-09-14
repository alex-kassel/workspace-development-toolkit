<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use Laravel\Prompts\Prompt;

class PackageDeleteCommand extends BasePackageCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:delete {package? : Package name in vendor/package format (e.g. acme/my-pkg)} {--force : Delete without interactive confirmation}';

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
        $rawPackage = (string) $this->argument('package');
        $force = (bool) $this->option('force');

        $package = $this->resolveAndValidatePackage($rawPackage);
        if ($package === null) {
            return self::FAILURE;
        }

        $packagePath = null;
        try {
            $packagePath = $this->workspace->findPackagePath($package);
        } catch (WorkspaceException $e) {
            return $this->handleWorkspaceException($e);
        }

        if (! $packagePath) {
            $this->error("Package [{$package}] was not found in any registered workspace.");
            $this->line('  <comment>How to fix:</comment> View all registered packages across workspaces using:');
            $this->line('  <info>php artisan workspace:list</info>');
            $this->line('  If the package is a remote Composer dependency (not a local workspace package), uninstall it via:');
            $this->line("  <info>composer remove {$package}</info>");

            return self::FAILURE;
        }

        $isInteractive = $this->input->isInteractive() && @stream_isatty(STDIN);
        if (! $force && $isInteractive) {
            $usePrompt = class_exists(Prompt::class);
            $confirm = $usePrompt
                ? \Laravel\Prompts\confirm("Are you sure you want to permanently delete [{$packagePath}] from disk?", false)
                : $this->confirm("Are you sure you want to permanently delete [{$packagePath}] from disk?", false);

            if (! $confirm) {
                $this->info('Deletion canceled.');

                return self::SUCCESS;
            }
        }

        try {
            $this->workspace->deletePackage($package, force: $force);
        } catch (WorkspaceException $e) {
            return $this->handleWorkspaceException($e);
        }

        $this->info("Package [{$package}] permanently deleted from [{$packagePath}].");

        $this->newLine();
        $this->line('  <comment>Hint:</comment> To see remaining packages across all workspaces:');
        $this->line('  <info>php artisan workspace:list</info>');

        return self::SUCCESS;
    }
}

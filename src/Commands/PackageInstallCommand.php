<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\ComposerProcessException;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerDiagnosticService;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;

class PackageInstallCommand extends BasePackageCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:install {package? : Package name in vendor/package format (e.g. acme/my-pkg)} {--dev : Install package into require-dev} {--remote : Allow installing non-local package from Composer remote repositories}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install a local workspace package into the application via Composer';

    public function __construct(
        WorkspaceManager $workspace,
        ComposerManager $composer,
        protected readonly ComposerDiagnosticService $diagnostics,
    ) {
        parent::__construct($workspace, $composer);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rawPackage = (string) $this->argument('package');
        $isDev = (bool) $this->option('dev');
        $allowRemote = (bool) $this->option('remote');

        $package = $this->resolveAndValidatePackage($rawPackage);
        if ($package === null) {
            return self::FAILURE;
        }

        $packagePath = $this->workspace->findPackagePath($package);

        if (! $packagePath) {
            if (! $allowRemote) {
                $this->error("Package [{$package}] was not found in any registered workspace.");
                $this->line('  <comment>How to fix:</comment> Verify that the package exists in one of your workspaces:');
                $this->line('  <info>php artisan workspace:list</info>');
                $this->line('  Or create the package first:');
                $this->line("  <info>php artisan package:make {$package}</info>");
                $this->line('  If you intentionally wish to install a remote Composer package, re-run with --remote:');
                $this->line("  <info>php artisan package:install {$package}".($isDev ? ' --dev' : '').' --remote</info>');

                return self::FAILURE;
            }

            $this->warn("Notice: Package [{$package}] was not found locally. Installing from remote Composer repositories via [--remote].");
        }

        $packageConstraint = $packagePath ? "{$package}:@dev" : $package;
        $args = ['require', $packageConstraint];

        if ($isDev) {
            $args[] = '--dev';
        }

        $this->info("Installing [{$package}] via Composer...");

        try {
            $this->composer->runComposer($args);
        } catch (ComposerProcessException $e) {
            $diagnostic = $this->diagnostics->diagnoseInstallFailure($e->output, $package, $packagePath !== null);

            $this->error("Failed to install package [{$package}] via Composer.");
            $this->newLine();
            $this->warn("  [{$diagnostic->title}]");
            $this->line("  {$diagnostic->explanation}");

            if (! empty($diagnostic->actionableSteps)) {
                $this->newLine();
                $this->line('  <fg=yellow>How to fix:</>');
                foreach ($diagnostic->actionableSteps as $step) {
                    $this->line("  • {$step}");
                }
            }

            if ($diagnostic->rawOutput !== null && $diagnostic->rawOutput !== '') {
                $this->newLine();
                $this->line("  <comment>Composer output:</comment>\n  ".str_replace("\n", "\n  ", $diagnostic->rawOutput));
            }

            return self::FAILURE;
        }

        $targetSection = $isDev ? 'require-dev' : 'require';
        $this->info("Package [{$package}] installed successfully into [{$targetSection}].");

        $this->newLine();
        $this->line('  <comment>Hint:</comment> To uninstall this package from composer without deleting files:');
        $this->line("  <info>php artisan package:uninstall {$package}</info>");
        $this->line('  Or to permanently delete it:');
        $this->line("  <info>php artisan package:delete {$package}</info>");

        return self::SUCCESS;
    }
}

<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\ComposerProcessException;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;

class PackageUninstallCommand extends BasePackageCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:uninstall {package : Package name in vendor/package format (e.g. acme/my-pkg)} {--dev : Uninstall from require-dev}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Uninstall a package from the application via Composer';

    public function __construct(
        WorkspaceManager $workspace,
        ComposerManager $composer,
    ) {
        parent::__construct($workspace, $composer);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rawPackage = (string) $this->argument('package');
        $isDevOption = (bool) $this->option('dev');

        $package = $this->resolveAndValidatePackage($rawPackage);
        if ($package === null) {
            return self::FAILURE;
        }

        $requirementType = $this->composer->getRequirementType($package);
        $isRequire = $requirementType === 'require';
        $isRequireDev = $requirementType === 'require-dev';

        if (! $isRequire && ! $isRequireDev) {
            $this->error("Package [{$package}] is not installed in root composer.json.");
            $this->line('  <comment>How to fix:</comment> Verify installed packages with:');
            $this->line('  <info>composer show</info>');
            $this->line('  If you wanted to delete the local package files entirely, use:');
            $this->line("  <info>php artisan package:delete {$package}</info>");

            return self::FAILURE;
        }

        $isDev = $isDevOption || $isRequireDev;

        $args = ['remove', $package];
        if ($isDev) {
            $args[] = '--dev';
        }

        $this->info("Uninstalling [{$package}] via Composer...");

        try {
            $this->composer->runComposer($args);
        } catch (ComposerProcessException $e) {
            $this->error("Failed to uninstall package [{$package}] via Composer.");
            $this->line("  <comment>Composer output:</comment>\n".trim($e->output));
            $this->line('  <comment>How to fix:</comment> Check for dependencies blocking removal or run manually with verbose output:');
            $this->line("  <info>composer remove {$package}".($isDev ? ' --dev' : '').' -v</info>');

            return self::FAILURE;
        }

        $this->info("Package [{$package}] uninstalled successfully. Physical files in workspace remain intact.");

        $this->newLine();
        $this->line('  <comment>Hint:</comment> To re-install this package later:');
        $this->line("  <info>php artisan package:install {$package}".($isDev ? ' --dev' : '').'</info>');
        $this->line('  Or to permanently delete the physical files from disk:');
        $this->line("  <info>php artisan package:delete {$package}</info>");

        return self::SUCCESS;
    }
}

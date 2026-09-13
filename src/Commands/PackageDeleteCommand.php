<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\ComposerProcessException;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\FilesystemHelper;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\GitInspector;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\SkillInstaller;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;
use Illuminate\Support\Facades\File;

class PackageDeleteCommand extends BasePackageCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:delete {package : Package name in vendor/package format (e.g. acme/my-pkg)} {--force : Delete without interactive confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Permanently delete a local package from disk';

    public function __construct(
        WorkspaceManager $workspace,
        ComposerManager $composer,
        protected readonly GitInspector $gitInspector,
        protected readonly SkillInstaller $skillInstaller,
    ) {
        parent::__construct($workspace, $composer);
    }

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

        $packagePath = $this->workspace->findPackagePath($package);

        if (! $packagePath) {
            $this->error("Package [{$package}] was not found in any registered workspace.");
            $this->line('  <comment>How to fix:</comment> Check existing packages using:');
            $this->line('  <info>php artisan workspace:list</info>');
            $this->line('  If the package is not local, remove it directly with Composer:');
            $this->line("  <info>composer remove {$package}</info>");

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

        // F-01: Canonical root protection guard before any destructive action
        $targetCanonical = FilesystemHelper::canonicalPath($realFullPath);
        $protectedRoots = [FilesystemHelper::canonicalPath(base_path())];
        foreach (array_keys($this->workspace->all()) as $wsKey) {
            $protectedRoots[] = FilesystemHelper::canonicalPath(base_path($wsKey));
        }

        if (in_array($targetCanonical, $protectedRoots, true)) {
            $this->error("Security violation: Target directory [{$packagePath}] is a protected workspace or application root.");

            return self::FAILURE;
        }

        foreach ($protectedRoots as $protectedRoot) {
            if ($protectedRoot !== $targetCanonical && str_starts_with($protectedRoot.'/', $targetCanonical.'/')) {
                $this->error("Security violation: Target directory [{$packagePath}] contains a registered child workspace root.");

                return self::FAILURE;
            }
        }

        if (! $force && $this->gitInspector->hasGitRepository($realFullPath)) {
            if (! $this->gitInspector->isClean($realFullPath)) {
                $this->error("Cannot delete package [{$package}]: package working tree has uncommitted or untracked changes.");
                $this->line('  <comment>How to fix:</comment> Commit, stash, or discard changes before deleting, or use --force:');
                $this->line("  <info>php artisan package:delete {$package} --force</info>");

                return self::FAILURE;
            }

            if ($this->gitInspector->hasUnpushedCommits($realFullPath)) {
                $this->error("Cannot delete package [{$package}]: package contains local Git commits that have not been pushed to a remote repository.");
                $this->line('  <comment>How to fix:</comment> Push your commits to remote, or bypass check with --force:');
                $this->line("  <info>php artisan package:delete {$package} --force</info>");

                return self::FAILURE;
            }

            if ($this->gitInspector->hasStashes($realFullPath)) {
                $this->error("Cannot delete package [{$package}]: package has stashed changes.");
                $this->line('  <comment>How to fix:</comment> Drop or apply your stashes, or bypass check with --force:');
                $this->line("  <info>php artisan package:delete {$package} --force</info>");

                return self::FAILURE;
            }
        }

        if (! $force && ! $this->confirm("Are you sure you want to permanently delete [{$packagePath}] from disk?", false)) {
            $this->info('Deletion canceled.');

            return self::SUCCESS;
        }

        $requirementType = $this->composer->getRequirementType($package);
        $isDev = $requirementType === 'require-dev';
        $isRequire = $requirementType === 'require';

        if ($isRequire || $isDev) {
            $this->info("Removing [{$package}] from Composer first...");
            $args = ['remove', $package];
            if ($isDev) {
                $args[] = '--dev';
            }

            try {
                $this->composer->runComposer($args);
            } catch (ComposerProcessException $e) {
                $this->error("Failed to remove package [{$package}] from Composer.");
                $this->line("  <comment>Composer output:</comment>\n".trim($e->output));
                $this->line('  <comment>How to fix:</comment> Resolve Composer issues or run removal manually:');
                $this->line("  <info>composer remove {$package}".($isDev ? ' --dev' : '').' -v</info>');

                return self::FAILURE;
            }
        }

        // Clean up any installed agent skills of this package
        $skillsPath = $realFullPath.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'skills';
        if (File::isDirectory($skillsPath)) {
            $discovered = $this->skillInstaller->discoverSkillsInPath($skillsPath);
            foreach ($discovered as $slug => $path) {
                $this->skillInstaller->removeSkill($slug);
            }
        }

        $deleted = false;
        try {
            $deleted = $this->workspace->deleteDirectoryRecursively($realFullPath);
        } catch (\Throwable $e) {
            $this->error("Failed to delete package directory [{$packagePath}]: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! $deleted || File::isDirectory($realFullPath)) {
            $this->error("Failed to delete package directory [{$packagePath}].");

            return self::FAILURE;
        }

        // Find which workspace this package belongs to and remove from workspace manifest
        $matchedWorkspace = $this->workspace->findWorkspaceForPath($packagePath);
        if ($matchedWorkspace !== null) {
            $this->workspace->forgetPackage($matchedWorkspace, $package);
        }

        // If in a multi-vendor (nested) workspace, clean up parent vendor directory if left empty
        $vendorDir = dirname($realFullPath);

        $vendorCanonical = FilesystemHelper::canonicalPath($vendorDir);
        $isProtectedVendorDir = in_array($vendorCanonical, $protectedRoots, true);
        if (! $isProtectedVendorDir) {
            foreach ($protectedRoots as $protectedRoot) {
                if ($protectedRoot === $vendorCanonical || str_starts_with($protectedRoot.'/', $vendorCanonical.'/')) {
                    $isProtectedVendorDir = true;
                    break;
                }
            }
        }

        if (File::isDirectory($vendorDir) && ! $isProtectedVendorDir && $this->workspace->filesystem->isEmptyDirectory($vendorDir)) {
            $this->workspace->deleteDirectoryRecursively($vendorDir);
        }

        $this->workspace->sync();

        $this->info("Package [{$package}] permanently deleted from [{$packagePath}].");

        $this->newLine();
        $this->line('  <comment>Hint:</comment> To see remaining packages across all workspaces:');
        $this->line('  <info>php artisan workspace:list</info>');

        return self::SUCCESS;
    }
}

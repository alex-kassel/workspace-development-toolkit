<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\ComposerProcessException;
use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\GitInspector;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\SkillInstaller;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

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

        $validation = Workspace::validatePackageName($canonicalName);
        if (! $validation['isValid']) {
            $this->error($validation['error'] ?? "Invalid package name [{$rawName}].");
            if ($validation['suggestion'] !== null) {
                $this->line('  <comment>How to fix:</comment> Did you mean:');
                $this->line("  <info>php artisan package:delete {$validation['suggestion']}".($force ? ' --force' : '').'</info>');
            }

            return self::FAILURE;
        }

        $name = $validation['fullName'];

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

        // F-01: Canonical root protection guard before any destructive action
        $targetCanonical = $this->canonicalPath($realFullPath);
        $protectedRoots = [$this->canonicalPath(base_path())];
        foreach (array_keys(Workspace::all()) as $wsKey) {
            $protectedRoots[] = $this->canonicalPath(base_path($wsKey));
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

        $gitInspector = app(GitInspector::class);
        if (! $force && $gitInspector->hasGitRepository($realFullPath)) {
            if (! $gitInspector->isClean($realFullPath)) {
                $this->error("Cannot delete package [{$name}]: package working tree has uncommitted or untracked changes.");
                $this->line('  <comment>How to fix:</comment> Commit, stash, or discard changes before deleting, or use --force:');
                $this->line("  <info>php artisan package:delete {$name} --force</info>");

                return self::FAILURE;
            }

            if ($gitInspector->hasUnpushedCommits($realFullPath)) {
                $this->error("Cannot delete package [{$name}]: package has unpushed commits.");
                $this->line('  <comment>How to fix:</comment> Push your commits to remote, or bypass check with --force:');
                $this->line("  <info>php artisan package:delete {$name} --force</info>");

                return self::FAILURE;
            }

            if ($gitInspector->hasStashes($realFullPath)) {
                $this->error("Cannot delete package [{$name}]: package has stashed changes.");
                $this->line('  <comment>How to fix:</comment> Drop or apply your stashes, or bypass check with --force:');
                $this->line("  <info>php artisan package:delete {$name} --force</info>");

                return self::FAILURE;
            }
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
            $args = ['remove', $name];
            if ($isDev) {
                $args[] = '--dev';
            }

            try {
                Workspace::runComposer($args);
            } catch (ComposerProcessException $e) {
                $this->error("Failed to remove package [{$name}] from Composer.");
                $this->line("  <comment>Composer output:</comment>\n".trim($e->output));
                $this->line('  <comment>How to fix:</comment> Resolve Composer issues or run removal manually:');
                $this->line("  <info>composer remove {$name}".($isDev ? ' --dev' : '').' -v</info>');

                return self::FAILURE;
            }
        }

        // Clean up any installed agent skills of this package
        $skillsPath = $realFullPath.DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'skills';
        if (File::isDirectory($skillsPath)) {
            /** @var SkillInstaller $installer */
            $installer = app(SkillInstaller::class);
            $discovered = $installer->discoverSkillsInPath($skillsPath);
            foreach ($discovered as $slug => $path) {
                $installer->removeSkill($slug);
            }
        }

        $deleted = false;
        try {
            $deleted = Workspace::deleteDirectoryRecursively($realFullPath);
        } catch (\Throwable $e) {
            $this->error("Failed to delete package directory [{$packagePath}]: {$e->getMessage()}");

            return self::FAILURE;
        }

        if (! $deleted || File::isDirectory($realFullPath)) {
            $this->error("Failed to delete package directory [{$packagePath}].");

            return self::FAILURE;
        }

        // Find which workspace this package belongs to and remove from workspace manifest
        $matchedWorkspace = null;
        foreach (Workspace::all() as $ws => $config) {
            if ($packagePath === $ws || str_starts_with($packagePath, "{$ws}/")) {
                $matchedWorkspace = $ws;
                break;
            }
        }

        if ($matchedWorkspace !== null) {
            Workspace::forgetPackage($matchedWorkspace, $name);
            if (str_contains($name, '/')) {
                [, $shortName] = explode('/', $name, 2);
                Workspace::forgetPackage($matchedWorkspace, $shortName);
            }
            if ($rawName !== $name && $rawName !== '') {
                Workspace::forgetPackage($matchedWorkspace, $rawName);
            }
        }

        // If in a multi-vendor (nested) workspace, clean up parent vendor directory if left empty
        $vendorDir = dirname($realFullPath);

        $vendorCanonical = $this->canonicalPath($vendorDir);
        $isProtectedVendorDir = in_array($vendorCanonical, $protectedRoots, true);
        if (! $isProtectedVendorDir) {
            foreach ($protectedRoots as $protectedRoot) {
                if ($protectedRoot === $vendorCanonical || str_starts_with($protectedRoot.'/', $vendorCanonical.'/')) {
                    $isProtectedVendorDir = true;
                    break;
                }
            }
        }

        $isEmpty = true;
        if (File::isDirectory($vendorDir)) {
            try {
                $it = new \FilesystemIterator($vendorDir, \FilesystemIterator::KEY_AS_PATHNAME | \FilesystemIterator::CURRENT_AS_FILEINFO | \FilesystemIterator::SKIP_DOTS);
                $isEmpty = ! $it->valid();
            } catch (\Throwable) {
                $isEmpty = false;
            }
        }

        if (File::isDirectory($vendorDir) && ! $isProtectedVendorDir && $isEmpty) {
            Workspace::deleteDirectoryRecursively($vendorDir);
        }

        Workspace::sync();

        $this->info("Package [{$name}] permanently deleted from [{$packagePath}].");

        $this->newLine();
        $this->line('  <comment>Hint:</comment> To see remaining packages across all workspaces:');
        $this->line('  <info>php artisan workspace:list</info>');

        return self::SUCCESS;
    }

    /**
     * Compute canonical path resolving ., .., separators, symlinks, and case insensitivity.
     */
    protected function canonicalPath(string $path): string
    {
        $real = @realpath($path);
        if ($real !== false) {
            $path = $real;
        } else {
            $normalized = str_replace('\\', '/', $path);
            $segments = explode('/', $normalized);
            $resolved = [];
            foreach ($segments as $segment) {
                if ($segment === '' || $segment === '.') {
                    continue;
                }
                if ($segment === '..') {
                    array_pop($resolved);
                } else {
                    $resolved[] = $segment;
                }
            }
            $prefix = str_starts_with($normalized, '/') ? '/' : '';
            $path = $prefix.implode('/', $resolved);
        }

        $path = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }
}

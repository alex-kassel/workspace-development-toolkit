<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\AmbiguousPackageException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\ComposerProcessException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\DefaultWorkspaceNotConfiguredException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\InvalidJsonException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\InvalidWorkspacePathException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceNotFoundException;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use JsonException;

class WorkspaceManager
{
    public function __construct(
        public readonly ManifestRepository $manifest,
        public readonly PackageResolver $resolver,
        public readonly ComposerManager $composer,
        public readonly FilesystemHelper $filesystem,
    ) {}

    /**
     * Clear in-memory caches.
     */
    public function clearCache(): void
    {
        $this->manifest->clearCache();
        $this->resolver->clearCache();
    }

    /**
     * Get path to workspace.json.
     */
    public function workspaceJsonPath(): string
    {
        return $this->manifest->workspaceJsonPath();
    }

    /**
     * Load configuration from workspace.json.
     *
     * @return array{default: ?string, repository_template?: ?string, workspaces: array<string, array{vendor: ?string, packages: array<int, string|array{name: string, alias?: string, url?: string}>}>}
     *
     * @throws InvalidJsonException
     */
    public function load(): array
    {
        return $this->manifest->load();
    }

    /**
     * Save configuration to workspace.json.
     *
     * @param  array{default: ?string, repository_template?: ?string, workspaces: array<string, array{vendor: ?string, packages: array<int, string|array{name: string, alias?: string, url?: string}>}>}  $data
     */
    public function save(array $data): void
    {
        $this->manifest->save($data);
        $this->resolver->clearCache();
    }

    /**
     * Get all workspaces.
     *
     * @return array<string, array{vendor: ?string, packages: array<int, string|array{name: string, alias?: string, url?: string}>}>
     */
    public function all(): array
    {
        return $this->manifest->all();
    }

    /**
     * Get default workspace.
     */
    public function getDefault(): ?string
    {
        return $this->manifest->getDefault();
    }

    /**
     * Get required default workspace or throw.
     *
     * @throws DefaultWorkspaceNotConfiguredException
     */
    public function getRequiredDefault(): string
    {
        return $this->manifest->getRequiredDefault();
    }

    /**
     * Set default workspace.
     *
     * @throws WorkspaceNotFoundException
     */
    public function setDefault(string $workspace): bool
    {
        return $this->manifest->setDefault($workspace);
    }

    /**
     * Add a new workspace.
     *
     * @throws InvalidWorkspacePathException
     */
    public function add(string $path, ?string $vendor = null, bool $asDefault = false): bool
    {
        $cleanPath = $this->manifest->normalizeWorkspacePath($path);
        File::ensureDirectoryExists(base_path($cleanPath));
        $this->addToGitignore($cleanPath);

        $result = $this->manifest->add($cleanPath, $vendor, $asDefault);
        if ($result) {
            $this->resolver->clearCache();
            $this->composer->syncRepositories($this->manifest->all());
        }

        return $result;
    }

    /**
     * Add workspace directory to .gitignore.
     */
    public function addToGitignore(string $path): void
    {
        $gitignore = base_path('.gitignore');
        if (! File::exists($gitignore)) {
            return;
        }

        $entry = "/{$path}";
        $lines = preg_split('/\r\n|\r|\n/', File::get($gitignore)) ?: [];

        if (! in_array($entry, $lines, true) && ! in_array("{$entry}/", $lines, true)) {
            File::append($gitignore, "{$entry}\n");
        }
    }

    /**
     * Remove a workspace.
     *
     * @throws WorkspaceNotFoundException
     */
    public function remove(string $path): bool
    {
        $cleanPath = $this->manifest->normalizeWorkspacePath($path);
        $result = $this->manifest->remove($cleanPath);
        if ($result) {
            $this->removeFromGitignore($cleanPath);
            $this->resolver->clearCache();
            $this->composer->syncRepositories($this->manifest->all());
        }

        return $result;
    }

    /**
     * Get workspace vendor.
     */
    public function getWorkspaceVendor(string $workspace): ?string
    {
        return $this->manifest->getWorkspaceVendor($workspace);
    }

    /**
     * Get repository template.
     */
    public function getRepositoryTemplate(): string
    {
        return $this->manifest->getRepositoryTemplate();
    }

    /**
     * Set repository template.
     */
    public function setRepositoryTemplate(string $template): bool
    {
        return $this->manifest->setRepositoryTemplate($template);
    }

    /**
     * Normalize workspace path.
     *
     * @throws InvalidWorkspacePathException
     */
    public function normalizeWorkspacePath(string $path): string
    {
        return $this->manifest->normalizeWorkspacePath($path);
    }

    /**
     * Register or update package alias in workspace.json.
     */
    public function registerPackageAlias(string $workspace, string $packageName, string $alias): void
    {
        $this->manifest->registerPackageAlias($workspace, $packageName, $alias);
        $this->resolver->clearCache();
    }

    /**
     * Record package metadata in workspace.json.
     */
    public function recordPackage(string $workspace, string $packageName, ?string $alias = null, ?string $url = null): void
    {
        $this->manifest->recordPackage($workspace, $packageName, $alias, $url);
        $this->resolver->clearCache();
    }

    /**
     * Update active skills for a package in workspace.json.
     *
     * @param  array<int, string>  $skills
     */
    public function updatePackageSkills(string $workspace, string $packageName, array $skills): void
    {
        $this->manifest->updatePackageSkills($workspace, $packageName, $skills);
        $this->resolver->clearCache();
    }

    /**
     * Completely remove a package entry from workspace.json.
     */
    public function forgetPackage(string $workspace, string $packageName): bool
    {
        $result = $this->manifest->forgetPackage($workspace, $packageName);
        $this->resolver->clearCache();

        return $result;
    }

    /**
     * Scan workspace directory for packages.
     *
     * @return array<int, string|array{name: string, alias: string}>
     */
    public function scanPackages(string $workspace, ?string $vendor = null): array
    {
        return $this->resolver->scanPackages($workspace, $vendor);
    }

    /**
     * Find relative directory path for a package or alias.
     *
     * @throws AmbiguousPackageException
     */
    public function findPackagePath(string $packageName, ?string $workspace = null): ?string
    {
        return $this->resolver->findPackagePath($packageName, $workspace);
    }

    /**
     * Resolve canonical vendor/package name.
     *
     * @throws AmbiguousPackageException
     */
    public function resolveCanonicalPackageName(string $packageName, ?string $workspace = null): string
    {
        return $this->resolver->resolveCanonicalPackageName($packageName, $workspace);
    }

    /**
     * Determine if a package has a corrupted composer.json.
     */
    public function isPackageCorrupted(string $packageName, ?string $workspace = null): bool
    {
        return $this->resolver->isPackageCorrupted($packageName, $workspace);
    }

    /**
     * Find duplicate aliases across workspaces.
     *
     * @return array<int, string>
     */
    public function findDuplicateAliases(string $alias, ?string $excludePath = null): array
    {
        return $this->resolver->findDuplicateAliases($alias, $excludePath);
    }

    /**
     * Resolve package clone URL from template.
     */
    public function resolvePackageCloneUrl(string $packageName): string
    {
        $template = $this->getRepositoryTemplate();

        return str_replace('{package}', $packageName, $template);
    }

    /**
     * Normalize repository string (e.g. shorthand or custom URL).
     */
    public function normalizeRepositoryUrl(string $repo, bool $useSsh): string
    {
        return $this->resolver->normalizeRepositoryUrl($repo, $useSsh);
    }

    /**
     * Resolve git repository URL of the workspace toolkit itself.
     */
    public function resolveSelfRepositoryUrl(bool $useSsh): string
    {
        return $this->resolver->resolveSelfRepositoryUrl($useSsh);
    }

    /**
     * Format URL according to preferred protocol (SSH vs HTTPS).
     */
    public function formatUrlProtocol(string $url, bool $useSsh): string
    {
        return $this->resolver->formatUrlProtocol($url, $useSsh);
    }

    /**
     * Parse vendor and package names from repository URL or shorthand.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function parseRepoVendorAndPackage(string $url): array
    {
        return $this->resolver->parseRepoVendorAndPackage($url);
    }

    /**
     * Validate Composer package name syntax and resolve parts.
     *
     * @return array{
     *     vendor: string,
     *     package: string,
     *     fullName: string,
     *     isValid: bool,
     *     error: ?string,
     *     suggestion: ?string
     * }
     */
    public function validatePackageName(string $input, ?string $workspaceVendor = null): array
    {
        return $this->resolver->validatePackageName($input, $workspaceVendor);
    }

    /**
     * Remove workspace directory entry from .gitignore.
     */
    public function removeFromGitignore(string $path): void
    {
        $gitignore = base_path('.gitignore');
        if (! File::exists($gitignore)) {
            return;
        }

        $entry = "/{$path}";
        $lines = preg_split('/\r\n|\r|\n/', File::get($gitignore)) ?: [];
        $newLines = array_filter($lines, fn ($line) => trim($line) !== $entry && trim($line) !== "{$entry}/");

        if (count($newLines) !== count($lines)) {
            File::put($gitignore, implode("\n", $newLines)."\n", true);
        }
    }

    /**
     * Run a Composer command through ComposerManager.
     *
     * @param  array<int, string>  $args
     *
     * @throws ComposerProcessException
     */
    public function runComposer(array $args, ?int $timeout = null): ProcessResult
    {
        return $this->composer->runComposer($args, $timeout);
    }

    /**
     * Ensure Composer hooks.
     */
    public function ensureComposerHooks(): void
    {
        $this->composer->ensureComposerHooks();
    }

    /**
     * Ensure workspace standalone script.
     */
    public function ensureWorkspaceScript(): void
    {
        $this->composer->ensureWorkspaceScript();
    }

    /**
     * Update Composer lock and installed.json path references.
     */
    public function updateComposerPathReferences(string $canonicalName, string $oldRelPath, string $newRelPath): void
    {
        $this->composer->updateComposerPathReferences($canonicalName, $oldRelPath, $newRelPath);
    }

    /**
     * Update vendor symlink / junction.
     */
    public function updateVendorSymlink(string $canonicalName, string $targetFullPath): void
    {
        $vendorPackageDir = base_path("vendor/{$canonicalName}");
        $this->filesystem->updateSymlinkOrJunction($vendorPackageDir, $targetFullPath);
    }

    /**
     * Recursively delete directory.
     */
    public function deleteDirectoryRecursively(string $dir): bool
    {
        return $this->filesystem->deleteDirectoryRecursively($dir);
    }

    /**
     * Synchronize workspaces, packages, and composer.json repositories.
     *
     * @return array{default: ?string, repository_template?: ?string, workspaces: array<string, array{vendor: ?string, packages: array<int, string|array{name: string, alias?: string, url?: string}>}>}
     */
    public function sync(): array
    {
        $this->clearCache();
        $data = $this->load();

        foreach ($data['workspaces'] as $path => $config) {
            $data['workspaces'][$path]['packages'] = $this->scanPackages($path, $config['vendor'] ?? null);
        }

        $this->save($data);
        $this->composer->syncRepositories($data['workspaces']);
        $this->composer->ensureComposerHooks();
        $this->composer->ensureWorkspaceScript();

        return $data;
    }

    /**
     * Assign directory alias to a package in a flat workspace.
     *
     * @return array{old_path: string, new_path: string, canonical_name: string}
     *
     * @throws WorkspaceException
     */
    public function aliasPackage(string $packageName, string $alias): array
    {
        $context = $this->validateAndResolveAliasContext($packageName, $alias);

        $packagePath = $context['package_path'];
        $workspace = $context['workspace'];
        $baseShortName = $context['base_short_name'];
        $canonicalName = $context['canonical_name'];
        $targetRelativePath = $context['target_relative_path'];
        $targetFullPath = $context['target_full_path'];
        $oldFullPath = $context['old_full_path'];
        $currentDir = $context['current_dir'];

        $vendorLink = base_path("vendor/{$canonicalName}");
        $vendorLinkIsLinkOrJunction = $this->filesystem->isLinkOrJunction($vendorLink);
        $vendorLinkExisted = file_exists($vendorLink) || $vendorLinkIsLinkOrJunction;
        $vendorFileContent = (! $vendorLinkIsLinkOrJunction && is_file($vendorLink)) ? File::get($vendorLink) : null;
        $vendorLinkTarget = null;
        if ($vendorLinkIsLinkOrJunction) {
            $rawTarget = @readlink($vendorLink);
            if ($rawTarget === false) {
                // If readlink failed on Windows junction or symlink, try realpath
                $rawTarget = @realpath($vendorLink);
            }
            $vendorLinkTarget = ($rawTarget !== false) ? $rawTarget : $oldFullPath;
        }

        $trackedFiles = [
            base_path('composer.lock'),
            base_path('vendor/composer/installed.json'),
            base_path('vendor/composer/installed.php'),
            base_path('workspace.json'),
        ];
        $fileSnapshots = [];
        foreach ($trackedFiles as $file) {
            $fileSnapshots[$file] = File::exists($file) ? File::get($file) : null;
        }

        $rollback = function (\Throwable $e) use (
            $oldFullPath,
            $targetFullPath,
            $packagePath,
            $targetRelativePath,
            $vendorLink,
            $vendorLinkExisted,
            $vendorFileContent,
            $vendorLinkTarget,
            $fileSnapshots
        ): never {
            $renameBackSuccess = @rename($targetFullPath, $oldFullPath);
            if (! $renameBackSuccess && ! File::exists($oldFullPath)) {
                try {
                    $renameBackSuccess = File::move($targetFullPath, $oldFullPath);
                } catch (\Throwable) {
                    $renameBackSuccess = false;
                }
            }
            clearstatcache();
            $directoryRestored = $renameBackSuccess && File::isDirectory($oldFullPath) && ! File::isDirectory($targetFullPath);

            foreach ($fileSnapshots as $file => $content) {
                if ($content === null) {
                    if (File::exists($file)) {
                        @unlink($file);
                    }
                } else {
                    File::put($file, $content);
                }
            }

            $vendorRestoreError = null;
            if ($vendorLinkExisted) {
                if ($vendorFileContent !== null) {
                    try {
                        File::ensureDirectoryExists(dirname($vendorLink));
                        File::put($vendorLink, $vendorFileContent);
                    } catch (\Throwable $ve) {
                        $vendorRestoreError = $ve;
                    }
                } else {
                    try {
                        $restoreTarget = $vendorLinkTarget ?: $oldFullPath;
                        $this->filesystem->updateSymlinkOrJunction($vendorLink, $restoreTarget);
                    } catch (\Throwable $ve) {
                        $vendorRestoreError = $ve;
                    }
                }
            } else {
                if (file_exists($vendorLink) || $this->filesystem->isLinkOrJunction($vendorLink)) {
                    if (PHP_OS_FAMILY === 'Windows') {
                        @rmdir($vendorLink);
                    }
                    @unlink($vendorLink);
                }
            }

            $this->resolver->clearCache();

            if (! $directoryRestored) {
                throw new WorkspaceException(
                    "Failed to update references after renaming [{$targetRelativePath}]: {$e->getMessage()}. Rollback failed: directory could not be restored to [{$packagePath}].",
                    "Inspect directory [{$targetRelativePath}] and restore [{$packagePath}] manually.",
                    0,
                    $e
                );
            }

            if ($vendorRestoreError !== null) {
                throw new WorkspaceException(
                    "Failed to update references after renaming [{$targetRelativePath}]: {$e->getMessage()}. Rollback was incomplete: vendor link could not be restored to [{$vendorLink}]: {$vendorRestoreError->getMessage()}.",
                    "Inspect vendor link [{$vendorLink}] and restore it manually.",
                    0,
                    $e
                );
            }

            throw new WorkspaceException(
                "Failed to update references after renaming [{$targetRelativePath}]: {$e->getMessage()}",
                'The directory rename was rolled back.',
                0,
                $e
            );
        };

        $moved = false;
        if (strcasecmp($currentDir, $alias) !== 0) {
            $moveSuccess = @rename($oldFullPath, $targetFullPath);
            if (! $moveSuccess) {
                try {
                    $moveSuccess = File::move($oldFullPath, $targetFullPath);
                } catch (\Throwable $e) {
                    throw new WorkspaceException(
                        "Failed to rename directory [{$packagePath}] to [{$targetRelativePath}]: {$e->getMessage()}",
                        'Check directory permissions or close any programs holding files open in this directory.'
                    );
                }
            }

            if (! $moveSuccess || ! File::isDirectory($targetFullPath)) {
                throw new WorkspaceException(
                    "Failed to rename directory [{$packagePath}] to [{$targetRelativePath}].",
                    'Check directory permissions or close any programs holding files open in this directory.'
                );
            }

            $moved = true;

            try {
                $this->updateComposerPathReferences($canonicalName, $packagePath, $targetRelativePath);
                $this->updateVendorSymlink($canonicalName, $targetFullPath);
            } catch (\Throwable $e) {
                $rollback($e);
            }
        }

        try {
            $this->registerPackageAlias($workspace, $baseShortName, $alias);
        } catch (\Throwable $e) {
            if ($moved) {
                $rollback($e);
            }
            throw new WorkspaceException(
                "Failed to update workspace manifest: {$e->getMessage()}",
                'Choose a different alias or rename the conflicting package first.',
                0,
                $e
            );
        }

        return [
            'old_path' => $packagePath,
            'new_path' => $targetRelativePath,
            'canonical_name' => $canonicalName,
        ];
    }

    /**
     * Validate alias arguments and resolve paths for package aliasing.
     *
     * @return array{
     *     package_path: string,
     *     workspace: string,
     *     current_dir: string,
     *     canonical_name: string,
     *     base_short_name: string,
     *     target_relative_path: string,
     *     target_full_path: string,
     *     old_full_path: string
     * }
     *
     * @throws WorkspaceException
     */
    protected function validateAndResolveAliasContext(string $packageName, string $alias): array
    {
        $alias = trim($alias);
        if ($alias === '') {
            throw new WorkspaceException(
                'Alias cannot be empty.',
                'Specify a non-empty directory name alias, e.g. php artisan package:alias scraper-core Scraper.'
            );
        }

        if (! preg_match('/^[a-zA-Z0-9_.-]+$/', $alias)) {
            throw new WorkspaceException(
                "Invalid alias [{$alias}].",
                'Alias must contain only alphanumeric characters, dashes, underscores, and dots.'
            );
        }

        $packagePath = $this->findPackagePath($packageName);
        if ($packagePath === null) {
            throw new WorkspaceException(
                "Package [{$packageName}] was not found in any workspace.",
                'Verify the package exists or run php artisan workspace:list.'
            );
        }

        $matchedWorkspace = null;
        $workspaces = $this->all();
        $wsKeys = array_keys($workspaces);
        usort($wsKeys, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($wsKeys as $ws) {
            if ($packagePath === $ws || str_starts_with($packagePath, "{$ws}/")) {
                $matchedWorkspace = $ws;
                break;
            }
        }

        if ($matchedWorkspace === null) {
            throw new WorkspaceException(
                "Could not determine workspace for package [{$packageName}].",
                'Verify workspace configuration in workspace.json.'
            );
        }

        $workspace = $matchedWorkspace;
        $vendor = $this->getWorkspaceVendor($workspace);
        if ($vendor === null) {
            throw new WorkspaceException(
                'Aliases are only supported in flat (fixed-vendor) workspaces (e.g. app/Cores/{package}).',
                "Package [{$packageName}] is in nested workspace [{$workspace}], which enforces vendor/package structure."
            );
        }

        $currentDir = basename($packagePath);
        $composerPath = base_path("{$packagePath}/composer.json");
        $composerData = $this->readJsonFile($composerPath);
        $canonicalName = $composerData['name'] ?? "{$vendor}/{$currentDir}";
        $baseShortName = str_starts_with($canonicalName, "{$vendor}/")
            ? substr($canonicalName, strlen("{$vendor}/"))
            : $canonicalName;

        $targetRelativePath = "{$workspace}/{$alias}";
        $targetFullPath = base_path($targetRelativePath);

        if (strcasecmp($currentDir, $alias) !== 0 && File::exists($targetFullPath)) {
            throw new WorkspaceException(
                "Target directory [{$targetRelativePath}] already exists on disk.",
                'Choose a different alias or remove the existing directory.'
            );
        }

        return [
            'package_path' => $packagePath,
            'workspace' => $workspace,
            'current_dir' => $currentDir,
            'canonical_name' => $canonicalName,
            'base_short_name' => $baseShortName,
            'target_relative_path' => $targetRelativePath,
            'target_full_path' => $targetFullPath,
            'old_full_path' => base_path($packagePath),
        ];
    }

    /**
     * Read JSON file helper.
     *
     * @return array<string, mixed>
     */
    protected function readJsonFile(string $path): array
    {
        if (! File::exists($path)) {
            return [];
        }

        try {
            return json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR) ?: [];
        } catch (JsonException) {
            return [];
        }
    }
}

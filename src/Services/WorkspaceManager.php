<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\AmbiguousPackageException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\DefaultWorkspaceNotConfiguredException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\InvalidJsonException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\InvalidWorkspacePathException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceNotFoundException;
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
        $result = $this->manifest->remove($path);
        if ($result) {
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

        // Rename directory on disk if needed
        $oldFullPath = base_path($packagePath);
        $moved = false;

        if (strcasecmp($currentDir, $alias) !== 0) {
            $moveSuccess = @rename($oldFullPath, $targetFullPath);
            if (! $moveSuccess) {
                // Fallback to File::move
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
                // Rollback directory move if reference update fails
                @rename($targetFullPath, $oldFullPath);
                throw new WorkspaceException(
                    "Failed to update references after renaming [{$targetRelativePath}]: {$e->getMessage()}",
                    'The directory rename was rolled back.'
                );
            }
        }

        try {
            // Register alias in manifest
            $this->registerPackageAlias($workspace, $baseShortName, $alias);
        } catch (\Throwable $e) {
            if ($moved) {
                // Rollback directory move and references
                @rename($targetFullPath, $oldFullPath);
                $this->updateComposerPathReferences($canonicalName, $targetRelativePath, $packagePath);
                $this->updateVendorSymlink($canonicalName, $oldFullPath);
            }
            throw new WorkspaceException(
                "Failed to update workspace manifest: {$e->getMessage()}",
                'The directory rename was rolled back.'
            );
        }

        return [
            'old_path' => $packagePath,
            'new_path' => $targetRelativePath,
            'canonical_name' => $canonicalName,
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

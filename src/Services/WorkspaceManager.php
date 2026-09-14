<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\PackageAliasResult;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\AmbiguousPackageException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\ComposerProcessException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\DefaultWorkspaceNotConfiguredException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\InvalidJsonException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\InvalidWorkspacePathException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceNotFoundException;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use JsonException;

class WorkspaceManager
{
    public function __construct(
        public readonly ManifestRepository $manifest,
        public readonly PackageResolver $resolver,
        public readonly ComposerManager $composer,
        public readonly FilesystemHelper $filesystem,
        public readonly GitInspector $gitInspector,
        public readonly SkillInstaller $skillInstaller,
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
     * @return array{default: ?string, repository_template?: ?string, repository_url_template?: ?string, workspaces: array<string, array{vendor: ?string, packages: array<int, string|array{name: string, alias?: string, url?: string}>}>}
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
     * @param  array{default: ?string, repository_template?: ?string, repository_url_template?: ?string, workspaces: array<string, array{vendor: ?string, packages: array<int, string|array{name: string, alias?: string, url?: string}>}>}  $data
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
        try {
            File::ensureDirectoryExists(base_path($cleanPath));
        } catch (\Throwable $e) {
            throw new InvalidWorkspacePathException($cleanPath, "Could not create workspace directory: {$e->getMessage()}");
        }
        $this->addToGitignore($cleanPath);

        $result = $this->manifest->add($cleanPath, $vendor, $asDefault);
        if ($result) {
            $this->resolver->clearCache();
            $scanned = $this->scanPackages($cleanPath, $vendor);
            if (! empty($scanned)) {
                $data = $this->load();
                $data['workspaces'][$cleanPath]['packages'] = $scanned;
                $this->save($data);
                $this->syncLocalPackageManifestsForWorkspace($cleanPath);
            }
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
     * @throws WorkspaceException
     */
    public function remove(string $path, bool $force = false): bool
    {
        $cleanPath = $this->manifest->normalizeWorkspacePath($path);

        if (! $force) {
            $active = $this->getActivePackagesInWorkspace($cleanPath);
            if (! empty($active)) {
                $pkgList = implode(', ', array_keys($active));
                throw new WorkspaceException(
                    "Cannot remove workspace [{$cleanPath}]: contains active packages required by root composer.json ({$pkgList}).",
                    'Uninstall active packages first, or remove with --detach.'
                );
            }
        }

        $result = $this->manifest->remove($cleanPath);
        if ($result) {
            $this->removeFromGitignore($cleanPath);
            $this->resolver->clearCache();
            $this->composer->syncRepositories($this->manifest->all());
        }

        return $result;
    }

    /**
     * Detach all active packages from root composer.json and remove the workspace.
     * Physical package files remain intact on disk.
     *
     * @throws WorkspaceException
     */
    public function detach(string $path): bool
    {
        $cleanPath = $this->manifest->normalizeWorkspacePath($path);
        $active = $this->getActivePackagesInWorkspace($cleanPath);

        if (! empty($active)) {
            $this->composer->removeDependencies($active);
        }

        return $this->remove($cleanPath, force: true);
    }

    /**
     * Permanently purge a workspace: uninstalls active dependencies, removes skills,
     * unregisters workspace, and deletes its physical directory from disk.
     *
     * @throws WorkspaceException
     */
    public function purge(string $path, bool $force = false): bool
    {
        $cleanPath = $this->manifest->normalizeWorkspacePath($path);
        $fullPath = base_path($cleanPath);

        if (! File::isDirectory($fullPath)) {
            throw new WorkspaceException(
                "Cannot purge workspace [{$cleanPath}]: directory does not exist on disk.",
                'Verify workspace path or check registered workspaces with: php artisan workspace:list'
            );
        }

        if (! $force) {
            $dirtyMap = $this->gitInspector->getWorkspaceSafetyIssues($fullPath);
            if (! empty($dirtyMap)) {
                $lines = [];
                foreach ($dirtyMap as $pkg => $issues) {
                    $lines[] = "[{$pkg}]: ".implode(', ', $issues);
                }
                $details = implode("\n  • ", $lines);

                throw new WorkspaceException(
                    "Cannot purge workspace [{$cleanPath}]: packages have uncommitted changes or unpushed commits:\n  • {$details}",
                    'Commit, stash, or push changes before purging, or use --force.'
                );
            }
        }

        $active = $this->getActivePackagesInWorkspace($cleanPath);
        if (! empty($active)) {
            $this->composer->removeDependencies($active);
        }

        $this->skillInstaller->removeSkillsForWorkspace($fullPath);

        $removed = $this->remove($cleanPath, force: true);
        $this->deleteDirectoryRecursively($fullPath);

        return $removed;
    }

    /**
     * Flatten a workspace into a single-vendor (flat) structure.
     * Moves packages from depth 2 (workspace/vendor/package) to depth 1 (workspace/package).
     *
     * @return array{flattened: array<int, string>, foreign: array<int, string>}
     *
     * @throws WorkspaceException
     */
    public function flatten(string $workspace, string $vendor): array
    {
        $cleanPath = $this->manifest->normalizeWorkspacePath($workspace);
        $cleanVendor = Str::slug($vendor);
        if ($cleanVendor === '') {
            throw new WorkspaceException("Invalid vendor name [{$vendor}]. Vendor must be alphanumeric.");
        }

        $all = $this->all();
        if (! array_key_exists($cleanPath, $all)) {
            throw new WorkspaceNotFoundException($cleanPath, array_keys($all));
        }

        $fullPath = base_path($cleanPath);
        if (! File::isDirectory($fullPath)) {
            throw new WorkspaceException("Workspace directory [{$cleanPath}] does not exist on disk.");
        }

        $flattened = [];
        $foreign = [];

        // 1. Scan all existing depth-2 packages (workspace/*/*/composer.json)
        $depth2Files = File::glob("{$fullPath}/*/*/composer.json") ?: [];

        foreach ($depth2Files as $composerFile) {
            $pkgDir = dirname($composerFile);
            $parentVendorDir = dirname($pkgDir);
            $parentVendorName = basename($parentVendorDir);
            $pkgBaseName = basename($pkgDir);

            // Read composer.json
            try {
                $pkgComposer = json_decode(File::get($composerFile), true, 512, JSON_THROW_ON_ERROR);
                $canonicalName = $pkgComposer['name'] ?? "{$parentVendorName}/{$pkgBaseName}";
            } catch (\Throwable) {
                $canonicalName = "{$parentVendorName}/{$pkgBaseName}";
            }

            $isTargetVendor = str_starts_with($canonicalName, "{$cleanVendor}/") || $parentVendorName === $cleanVendor;

            // Target directory at depth 1
            $targetDirName = $isTargetVendor ? $pkgBaseName : "{$parentVendorName}-{$pkgBaseName}";
            $targetFullPath = "{$fullPath}/{$targetDirName}";

            if (File::exists($targetFullPath) && realpath($targetFullPath) !== realpath($pkgDir)) {
                throw new WorkspaceException(
                    "Cannot flatten package [{$canonicalName}]: target directory [{$cleanPath}/{$targetDirName}] already exists on disk.",
                    "Rename or remove [{$cleanPath}/{$targetDirName}] before flattening."
                );
            }

            // Move package directory to depth 1
            File::move($pkgDir, $targetFullPath);

            $oldRelPath = trim(str_replace(base_path(), '', $pkgDir), '/\\');
            $newRelPath = trim(str_replace(base_path(), '', $targetFullPath), '/\\');

            // Update composer path references and symlinks
            $this->updateComposerPathReferences($canonicalName, $oldRelPath, $newRelPath);
            $this->updateVendorSymlink($canonicalName, $targetFullPath);

            if ($isTargetVendor) {
                $flattened[] = $canonicalName;
            } else {
                $foreign[] = $canonicalName;
            }

            // If parent vendor dir is now empty, delete it
            if (File::isDirectory($parentVendorDir) && $this->filesystem->isEmptyDirectory($parentVendorDir)) {
                $this->deleteDirectoryRecursively($parentVendorDir);
            }
        }

        // 2. Set vendor in manifest
        $this->manifest->setWorkspaceVendor($cleanPath, $cleanVendor);

        // 3. Rescan packages under flat structure
        $scanned = $this->scanPackages($cleanPath, $cleanVendor);
        $data = $this->manifest->load();
        $data['workspaces'][$cleanPath]['packages'] = $scanned;
        $this->manifest->save($data);

        // 4. Update root composer.json path repository pattern to workspace/*
        $this->composer->syncRepositories($this->manifest->all());

        // 5. Save workspace-level workspace.json
        $this->saveWorkspaceManifest($cleanPath);

        // 6. Clear resolver cache
        $this->resolver->clearCache();

        return [
            'flattened' => $flattened,
            'foreign' => $foreign,
        ];
    }

    /**
     * Unflatten a workspace into a multi-vendor (nested) structure.
     * Moves packages from depth 1 (workspace/package) to depth 2 (workspace/vendor/package).
     *
     * @return array<int, string> List of unflattened packages
     *
     * @throws WorkspaceException
     */
    public function unflatten(string $workspace): array
    {
        $cleanPath = $this->manifest->normalizeWorkspacePath($workspace);

        $all = $this->all();
        if (! array_key_exists($cleanPath, $all)) {
            throw new WorkspaceNotFoundException($cleanPath, array_keys($all));
        }

        $currentVendor = $this->getWorkspaceVendor($cleanPath);
        $fullPath = base_path($cleanPath);
        if (! File::isDirectory($fullPath)) {
            throw new WorkspaceException("Workspace directory [{$cleanPath}] does not exist on disk.");
        }

        $unflattened = [];

        // 1. Scan all depth-1 packages (workspace/*/composer.json)
        $depth1Files = File::glob("{$fullPath}/*/composer.json") ?: [];

        foreach ($depth1Files as $composerFile) {
            $pkgDir = dirname($composerFile);
            $pkgBaseName = basename($pkgDir);

            // Read composer.json to determine true vendor
            $targetVendor = $currentVendor ?: 'acme';
            try {
                $pkgComposer = json_decode(File::get($composerFile), true, 512, JSON_THROW_ON_ERROR);
                if (! empty($pkgComposer['name']) && str_contains($pkgComposer['name'], '/')) {
                    $parts = explode('/', $pkgComposer['name']);
                    $targetVendor = $parts[0];
                    $canonicalName = $pkgComposer['name'];
                } else {
                    $canonicalName = "{$targetVendor}/{$pkgBaseName}";
                }
            } catch (\Throwable) {
                $canonicalName = "{$targetVendor}/{$pkgBaseName}";
            }

            // Target directory at depth 2
            $vendorDirPath = "{$fullPath}/{$targetVendor}";
            File::ensureDirectoryExists($vendorDirPath);

            $targetFullPath = "{$vendorDirPath}/{$pkgBaseName}";

            if (File::exists($targetFullPath) && realpath($targetFullPath) !== realpath($pkgDir)) {
                throw new WorkspaceException(
                    "Cannot unflatten package [{$canonicalName}]: target directory [{$cleanPath}/{$targetVendor}/{$pkgBaseName}] already exists on disk.",
                    'Rename or remove the conflicting directory before unflattening.'
                );
            }

            // Move package directory to depth 2
            File::move($pkgDir, $targetFullPath);

            $oldRelPath = trim(str_replace(base_path(), '', $pkgDir), '/\\');
            $newRelPath = trim(str_replace(base_path(), '', $targetFullPath), '/\\');

            // Update composer path references and symlinks
            $this->updateComposerPathReferences($canonicalName, $oldRelPath, $newRelPath);
            $this->updateVendorSymlink($canonicalName, $targetFullPath);

            $unflattened[] = $canonicalName;
        }

        // 2. Clear vendor in manifest
        $this->manifest->setWorkspaceVendor($cleanPath, null);

        // 3. Rescan packages under nested structure
        $scanned = $this->scanPackages($cleanPath, null);
        $data = $this->manifest->load();
        $data['workspaces'][$cleanPath]['packages'] = $scanned;
        $this->manifest->save($data);

        // 4. Update root composer.json path repository pattern to workspace/*/*
        $this->composer->syncRepositories($this->manifest->all());

        // 5. Save workspace-level workspace.json
        $this->saveWorkspaceManifest($cleanPath);

        // 6. Clear resolver cache
        $this->resolver->clearCache();

        return $unflattened;
    }

    /**
     * Set or clear fixed default vendor for a workspace and re-sync.
     *
     * @throws WorkspaceNotFoundException
     */
    public function setWorkspaceVendor(string $workspace, ?string $vendor): bool
    {
        $cleanPath = $this->manifest->normalizeWorkspacePath($workspace);
        $cleanVendor = $vendor !== null ? Str::slug($vendor) : null;
        if ($cleanVendor === '') {
            $cleanVendor = null;
        }

        $this->manifest->setWorkspaceVendor($cleanPath, $cleanVendor);

        $scanned = $this->scanPackages($cleanPath, $cleanVendor);
        $data = $this->manifest->load();
        $data['workspaces'][$cleanPath]['packages'] = $scanned;
        $this->manifest->save($data);

        $this->composer->syncRepositories($this->manifest->all());
        $this->saveWorkspaceManifest($cleanPath);
        $this->resolver->clearCache();

        return true;
    }

    /**
     * Save workspace-level workspace.json in the workspace directory.
     */
    public function saveWorkspaceManifest(string $workspace): void
    {
        $cleanPath = $this->manifest->normalizeWorkspacePath($workspace);
        $data = $this->manifest->load();
        $wsConfig = $data['workspaces'][$cleanPath] ?? null;
        if ($wsConfig === null) {
            return;
        }

        $fullPath = base_path($cleanPath);
        if (! File::isDirectory($fullPath)) {
            return;
        }

        $manifestPath = $fullPath.DIRECTORY_SEPARATOR.'workspace.json';
        $content = json_encode($wsConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        File::put($manifestPath, $content);
    }

    /**
     * Load workspace-level workspace.json if present.
     *
     * @return array{vendor: ?string, packages: array<int, string|array{name: string, alias?: string, url?: string, skills?: array<int, string>}>}|null
     */
    public function loadWorkspaceManifest(string $workspace): ?array
    {
        $cleanPath = $this->manifest->normalizeWorkspacePath($workspace);
        $fullPath = base_path($cleanPath);
        $manifestPath = $fullPath.DIRECTORY_SEPARATOR.'workspace.json';

        if (! File::exists($manifestPath)) {
            return null;
        }

        try {
            $json = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);
            if (is_array($json)) {
                return $json;
            }
        } catch (\Throwable) {
            // Ignore corrupted local manifest
        }

        return null;
    }

    /**
     * Detect subdirectories in a workspace that are not packages (no composer.json) or lack Git repositories.
     *
     * @return array<int, string> List of directory names
     */
    public function detectUnversionedDirectories(string $workspace): array
    {
        $cleanPath = $this->manifest->normalizeWorkspacePath($workspace);
        $fullPath = base_path($cleanPath);
        if (! File::isDirectory($fullPath)) {
            return [];
        }

        $dirs = File::directories($fullPath);
        $unversioned = [];

        foreach ($dirs as $dir) {
            $hasComposer = File::exists($dir.DIRECTORY_SEPARATOR.'composer.json');
            $hasGit = File::isDirectory($dir.DIRECTORY_SEPARATOR.'.git');

            if (! $hasComposer && ! $hasGit) {
                // Check if it's a vendor folder containing subpackages
                $subDirs = File::directories($dir);
                $hasSubPackage = false;
                foreach ($subDirs as $sub) {
                    if (File::exists($sub.DIRECTORY_SEPARATOR.'composer.json')) {
                        $hasSubPackage = true;
                        break;
                    }
                }

                if (! $hasSubPackage) {
                    $unversioned[] = basename($dir);
                }
            }
        }

        return $unversioned;
    }

    /**
     * Permanently delete a package: verifies safety, uninstalls dependencies from composer.json,
     * removes skills, removes files from disk, cleans up manifests and empty vendor folders.
     *
     * @return string The relative package path that was deleted.
     *
     * @throws WorkspaceException
     */
    public function deletePackage(string $packageName, bool $force = false): string
    {
        $packagePath = $this->findPackagePath($packageName);
        if (! $packagePath) {
            throw new WorkspaceException(
                "Package [{$packageName}] was not found in any registered workspace.",
                'View all registered packages across workspaces using: php artisan workspace:list'
            );
        }

        $fullPath = base_path($packagePath);
        $realFullPath = realpath($fullPath);
        $realBasePath = realpath(base_path());

        $normalizedFullPath = $realFullPath ? strtolower(rtrim(str_replace('\\', '/', $realFullPath), '/')) : '';
        $normalizedBasePath = $realBasePath ? strtolower(rtrim(str_replace('\\', '/', $realBasePath), '/')) : '';

        if (! $realFullPath || ! $realBasePath || ! str_starts_with($normalizedFullPath, $normalizedBasePath.'/')) {
            throw new WorkspaceException("Security violation: Package path [{$fullPath}] resolves outside the application root.");
        }

        $targetCanonical = FilesystemHelper::canonicalPath($realFullPath);
        $protectedRoots = [FilesystemHelper::canonicalPath(base_path())];
        foreach (array_keys($this->all()) as $wsKey) {
            $protectedRoots[] = FilesystemHelper::canonicalPath(base_path($wsKey));
        }

        if (in_array($targetCanonical, $protectedRoots, true)) {
            throw new WorkspaceException("Security violation: Target directory [{$packagePath}] is a protected workspace or application root.");
        }

        foreach ($protectedRoots as $protectedRoot) {
            if ($protectedRoot !== $targetCanonical && str_starts_with($protectedRoot.'/', $targetCanonical.'/')) {
                throw new WorkspaceException("Security violation: Target directory [{$packagePath}] contains a registered child workspace root.");
            }
        }

        if (! $force) {
            $issues = $this->gitInspector->getPackageSafetyIssues($realFullPath);
            if (! empty($issues)) {
                throw new WorkspaceException(
                    "Cannot delete package [{$packageName}]: {$issues[0]}.",
                    "Commit, stash, or discard changes before deleting, or use --force: php artisan package:delete {$packageName} --force"
                );
            }
        }

        $requirementType = $this->composer->getRequirementType($packageName);
        if ($requirementType !== null) {
            $this->composer->removeDependencies([$packageName => $requirementType]);
        }

        $this->skillInstaller->removeSkillsForPackage($realFullPath);

        $deleted = false;
        try {
            $deleted = $this->deleteDirectoryRecursively($realFullPath);
        } catch (\Throwable $e) {
            throw new WorkspaceException("Failed to delete package directory [{$packagePath}]: {$e->getMessage()}");
        }

        if (! $deleted || File::isDirectory($realFullPath)) {
            throw new WorkspaceException("Failed to delete package directory [{$packagePath}].");
        }

        $matchedWorkspace = $this->findWorkspaceForPath($packagePath);
        if ($matchedWorkspace !== null) {
            $this->forgetPackage($matchedWorkspace, $packageName);
        }

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

        if (File::isDirectory($vendorDir) && ! $isProtectedVendorDir && $this->filesystem->isEmptyDirectory($vendorDir)) {
            $this->deleteDirectoryRecursively($vendorDir);
        }

        $this->sync();

        return $packagePath;
    }

    /**
     * Find all packages in a given workspace that are currently required in root composer.json.
     *
     * @return array<string, string> Map of canonical package name => requirement type ('require'|'require-dev')
     */
    public function getActivePackagesInWorkspace(string $workspace): array
    {
        $cleanPath = $this->manifest->normalizeWorkspacePath($workspace);
        $active = [];

        $all = $this->all();
        $wsConfig = $all[$cleanPath] ?? null;
        if ($wsConfig === null) {
            return [];
        }

        $vendor = $wsConfig['vendor'] ?? null;

        // Collect from manifest
        $packages = $wsConfig['packages'] ?? [];
        foreach ($packages as $item) {
            $name = is_array($item) ? $item['name'] : (string) $item;
            if ($name === '') {
                continue;
            }
            $canonical = $this->resolveCanonicalPackageName($name, $cleanPath);
            $type = $this->composer->getRequirementType($canonical);
            if ($type !== null) {
                $active[$canonical] = $type;
            }
        }

        // Also check physical directories on disk
        $scanned = $this->scanPackages($cleanPath, $vendor);
        foreach ($scanned as $item) {
            $name = is_array($item) ? $item['name'] : (string) $item;
            if ($name === '') {
                continue;
            }
            $canonical = $this->resolveCanonicalPackageName($name, $cleanPath);
            $type = $this->composer->getRequirementType($canonical);
            if ($type !== null) {
                $active[$canonical] = $type;
            }
        }

        return $active;
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
        $this->syncLocalPackageManifestsForWorkspace($workspace);
    }

    /**
     * Record package metadata in workspace.json.
     */
    public function recordPackage(string $workspace, string $packageName, ?string $alias = null, ?string $url = null): void
    {
        $this->manifest->recordPackage($workspace, $packageName, $alias, $url);
        $this->resolver->clearCache();
        $this->syncLocalPackageManifestsForWorkspace($workspace);
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
        $this->syncLocalPackageManifestsForWorkspace($workspace);
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
     * Find the registered workspace path that contains the given package path.
     */
    public function findWorkspaceForPath(string $packagePath): ?string
    {
        $cleanPath = trim(str_replace('\\', '/', $packagePath), '/');
        $workspaces = $this->all();
        $wsKeys = array_keys($workspaces);
        usort($wsKeys, fn ($a, $b) => strlen($b) <=> strlen($a));

        foreach ($wsKeys as $ws) {
            $cleanWs = trim(str_replace('\\', '/', (string) $ws), '/');
            if ($cleanPath === $cleanWs || str_starts_with($cleanPath, "{$cleanWs}/")) {
                return (string) $ws;
            }
        }

        return null;
    }

    /**
     * Get all local packages mapped as [canonical_name => relative_path].
     *
     * @return array<string, string>
     */
    public function getAllLocalPackages(): array
    {
        return $this->resolver->getAllLocalPackages();
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
     *     vendorName: string,
     *     packageName: string,
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
     * @return array{default: ?string, repository_template?: ?string, repository_url_template?: ?string, workspaces: array<string, array{vendor: ?string, packages: array<int, string|array{name: string, alias?: string, url?: string}>}>}
     */
    public function sync(): array
    {
        $this->clearCache();
        $data = $this->load();

        if (empty($data['repository_url_template'])) {
            $configured = (string) config('workspace.repository_url_template', 'git@github.com:{package}.git');
            $data['repository_url_template'] = trim($configured) !== '' ? trim($configured) : 'git@github.com:{package}.git';
        }

        foreach ($data['workspaces'] as $path => $config) {
            $data['workspaces'][$path]['packages'] = $this->scanPackages($path, $config['vendor'] ?? null);
        }

        $this->save($data);
        $this->syncLocalPackageManifests();
        $this->composer->syncRepositories($data['workspaces']);
        $this->composer->ensureComposerHooks();
        $this->composer->ensureWorkspaceScript();
        $this->composer->ensureMinimumStability();

        return $data;
    }

    /**
     * Save local workspace.json inside a package directory and ensure it is gitignored.
     *
     * @param  array{name: string, alias?: ?string, url?: ?string, skills?: ?array<int, string>}  $metadata
     */
    public function saveLocalPackageManifest(string $packageRelativePath, array $metadata): void
    {
        $cleanPath = trim(str_replace(['\\', '//'], '/', $packageRelativePath), '/');
        $fullDir = base_path($cleanPath);
        if (! File::isDirectory($fullDir)) {
            return;
        }

        $manifestPath = $fullDir.DIRECTORY_SEPARATOR.'workspace.json';
        $payload = ['name' => $metadata['name']];

        if (! empty($metadata['alias'])) {
            $payload['alias'] = (string) $metadata['alias'];
        }
        if (! empty($metadata['url'])) {
            $payload['url'] = (string) $metadata['url'];
        }
        if (! empty($metadata['skills']) && is_array($metadata['skills'])) {
            $payload['skills'] = array_values(array_unique($metadata['skills']));
        }

        File::put($manifestPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        $this->ensureLocalPackageGitignore($cleanPath);
    }

    /**
     * Read local workspace.json from package directory if present.
     *
     * @return array{name?: string, alias?: string, url?: string, skills?: array<int, string>}|null
     */
    public function readLocalPackageManifest(string $packageRelativePath): ?array
    {
        $cleanPath = trim(str_replace(['\\', '//'], '/', $packageRelativePath), '/');
        $manifestPath = base_path($cleanPath.DIRECTORY_SEPARATOR.'workspace.json');
        if (! File::exists($manifestPath)) {
            return null;
        }

        try {
            $data = json_decode(File::get($manifestPath), true, 512, JSON_THROW_ON_ERROR);

            return is_array($data) ? $data : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Ensure workspace.json is listed in the package's local .gitignore file.
     */
    public function ensureLocalPackageGitignore(string $packageRelativePath): void
    {
        $cleanPath = trim(str_replace(['\\', '//'], '/', $packageRelativePath), '/');
        $fullDir = base_path($cleanPath);
        if (! File::isDirectory($fullDir)) {
            return;
        }

        $gitignorePath = $fullDir.DIRECTORY_SEPARATOR.'.gitignore';
        $entry = '/workspace.json';

        if (! File::exists($gitignorePath)) {
            File::put($gitignorePath, "{$entry}\n");

            return;
        }

        $content = File::get($gitignorePath);
        $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === 'workspace.json' || $trimmed === '/workspace.json') {
                return;
            }
        }

        File::append($gitignorePath, "{$entry}\n");
    }

    /**
     * Synchronize local workspace.json for all packages in a specific workspace.
     */
    public function syncLocalPackageManifestsForWorkspace(string $workspace): void
    {
        $cleanPath = $this->manifest->normalizeWorkspacePath($workspace);
        $all = $this->all();
        $wsConfig = $all[$cleanPath] ?? null;
        if (! $wsConfig) {
            return;
        }

        $packages = $wsConfig['packages'] ?? [];
        foreach ($packages as $item) {
            $name = is_array($item) ? $item['name'] : (string) $item;
            if ($name === '') {
                continue;
            }

            try {
                $packagePath = $this->findPackagePath($name, $cleanPath);
                if ($packagePath === null) {
                    continue;
                }

                $canonicalName = $this->resolveCanonicalPackageName($name, $cleanPath);
                $metadata = [
                    'name' => $canonicalName,
                    'alias' => is_array($item) ? ($item['alias'] ?? null) : null,
                    'url' => is_array($item) ? ($item['url'] ?? null) : null,
                    'skills' => is_array($item) ? ($item['skills'] ?? null) : null,
                ];

                $this->saveLocalPackageManifest($packagePath, $metadata);
            } catch (\Throwable) {
                // Continue with remaining packages
            }
        }

        $this->resolver->clearCache();
    }

    /**
     * Synchronize local workspace.json for all packages across all workspaces.
     */
    public function syncLocalPackageManifests(): void
    {
        foreach (array_keys($this->all()) as $ws) {
            $this->syncLocalPackageManifestsForWorkspace((string) $ws);
        }
    }

    /**
     * Assign directory alias to a package in a flat workspace.
     *
     * @throws WorkspaceException
     */
    public function aliasPackage(string $packageName, string $alias, bool $dumpAutoload = true): PackageAliasResult
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
                if (File::exists(base_path('vendor/autoload.php'))) {
                    try {
                        $this->composer->runComposer(['dump-autoload']);
                    } catch (\Throwable) {
                        // Best-effort autoloader refresh
                    }
                }
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

        if ($dumpAutoload) {
            try {
                $this->composer->runComposer(['dump-autoload']);
            } catch (\Throwable) {
                // Autoload dump error is non-fatal for alias operation
            }
        }

        return new PackageAliasResult(
            oldPath: $packagePath,
            newPath: $targetRelativePath,
            canonicalName: $canonicalName,
            alias: $alias,
            workspace: $workspace,
            wasInstalled: $vendorLinkExisted,
        );
    }

    /**
     * Validate an alias name syntax.
     *
     * @throws WorkspaceException
     */
    public function validateAliasName(string $alias): void
    {
        $trimmed = trim($alias);
        if ($trimmed === '') {
            throw new WorkspaceException(
                'Alias cannot be empty.',
                'Specify a non-empty directory name alias, e.g. php artisan package:alias scraper-core Scraper.'
            );
        }

        if (! preg_match('/^[a-zA-Z0-9_.-]+$/', $trimmed) || str_contains($trimmed, '/') || str_contains($trimmed, '\\') || $trimmed === '.' || $trimmed === '..') {
            throw new WorkspaceException(
                "Invalid alias [{$trimmed}].",
                'Alias must contain only alphanumeric characters, dashes, underscores, and dots, and cannot contain path separators or relative directory operators.'
            );
        }
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
        $this->validateAliasName($alias);
        $alias = trim($alias);

        $packagePath = $this->findPackagePath($packageName);
        if ($packagePath === null) {
            throw new WorkspaceException(
                "Package [{$packageName}] was not found in any workspace.",
                'Verify the package exists or run php artisan workspace:list.'
            );
        }

        $matchedWorkspace = $this->findWorkspaceForPath($packagePath);

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
                "Cannot use alias [{$alias}]: target directory [{$targetRelativePath}] already exists on disk.",
                'Choose a different alias name or remove the conflicting directory.'
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

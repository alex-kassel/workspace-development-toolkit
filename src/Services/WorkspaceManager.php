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
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use JsonException;

class WorkspaceManager
{
    /**
     * In-memory cache for workspace configuration.
     *
     * @var array{default: ?string, repository_template?: ?string, workspaces: array<string, array{vendor: ?string, packages: array<int, string|array{name: string, url: string}>}>}|null
     */
    protected ?array $cache = null;

    /**
     * Clear in-memory cache.
     */
    public function clearCache(): void
    {
        $this->cache = null;
    }

    /**
     * Get the path to workspace.json.
     */
    public function workspaceJsonPath(): string
    {
        return base_path('workspace.json');
    }

    /**
     * Get the path to composer.json.
     */
    public function composerJsonPath(): string
    {
        return base_path('composer.json');
    }

    /**
     * Load full workspace configuration.
     *
     * @return array{default: ?string, repository_template?: ?string, workspaces: array<string, array{vendor: ?string, packages: array<int, string|array{name: string, url: string}>}>}
     */
    public function load(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $path = $this->workspaceJsonPath();

        if (! File::exists($path)) {
            return $this->sync();
        }

        $data = $this->readJsonFile($path);

        if (! isset($data['workspaces']) || ! is_array($data['workspaces'])) {
            throw new InvalidJsonException($path, "Missing or invalid 'workspaces' object.");
        }

        $normalizedWorkspaces = [];
        foreach ($data['workspaces'] as $workspace => $config) {
            if (is_array($config) && isset($config['packages'])) {
                $normalizedWorkspaces[$workspace] = [
                    'vendor' => isset($config['vendor']) && is_string($config['vendor']) ? $config['vendor'] : null,
                    'packages' => is_array($config['packages']) ? $config['packages'] : [],
                ];
            } elseif (is_array($config)) {
                // Backward compatibility: list of packages
                $normalizedWorkspaces[$workspace] = [
                    'vendor' => null,
                    'packages' => $config,
                ];
            }
        }

        return $this->cache = [
            'default' => $data['default'] ?? null,
            'repository_template' => $data['repository_template'] ?? $this->defaultRepositoryTemplate(),
            'workspaces' => $normalizedWorkspaces,
        ];
    }

    /**
     * Get all workspaces and their configurations.
     *
     * @return array<string, array{vendor: ?string, packages: array<int, string>}>
     */
    public function all(): array
    {
        return $this->load()['workspaces'];
    }

    /**
     * Get configured vendor for a workspace.
     */
    public function getWorkspaceVendor(string $workspace): ?string
    {
        $workspace = trim(preg_replace('#[/\\\\]+#', '/', $workspace) ?? '', '/');
        $workspaces = $this->all();

        return $workspaces[$workspace]['vendor'] ?? null;
    }

    /**
     * Validate that workspace relative path is clean and does not escape base_path.
     *
     * @throws InvalidWorkspacePathException
     */
    public function validateWorkspacePath(string $path): string
    {
        $normalized = trim(preg_replace('#[/\\\\]+#', '/', $path) ?? '', '/');

        if ($normalized === '') {
            throw new InvalidWorkspacePathException($path, 'Workspace path cannot be empty');
        }

        // Prohibit absolute paths, drive letters, and parent traversal segments
        if (str_starts_with($path, '/') || str_starts_with($path, '\\') || preg_match('/^[a-zA-Z]:/', $path)) {
            throw new InvalidWorkspacePathException($path, 'Absolute paths are not allowed');
        }

        $segments = explode('/', $normalized);
        foreach ($segments as $segment) {
            if ($segment === '..' || $segment === '.') {
                throw new InvalidWorkspacePathException($path, 'Path traversal ("..") is not allowed');
            }
            if (! preg_match('/^[a-zA-Z0-9_.-]+$/', $segment)) {
                throw new InvalidWorkspacePathException($path, "Invalid path segment [{$segment}]");
            }
        }

        return $normalized;
    }

    /**
     * Find relative directory path for a given package name or alias.
     * Supports canonical name (vendor/package), short name, and custom alias.
     *
     * @throws AmbiguousPackageException
     */
    public function findPackagePath(string $packageName, ?string $workspace = null): ?string
    {
        $packageName = trim($packageName);
        $workspaces = $this->all();

        // Scope to single workspace if requested
        if ($workspace !== null) {
            $normalizedWs = trim(preg_replace('#[/\\\\]+#', '/', $workspace) ?? '', '/');
            if (isset($workspaces[$normalizedWs])) {
                $workspaces = [$normalizedWs => $workspaces[$normalizedWs]];
            }
        }

        $matches = [];

        foreach ($workspaces as $ws => $config) {
            $vendor = $config['vendor'] ?? null;
            $files = $vendor !== null
                ? (File::glob(base_path("{$ws}/*/composer.json")) ?: [])
                : (File::glob(base_path("{$ws}/*/*/composer.json")) ?: []);

            // Check configured packages/aliases in workspace config
            $aliasMap = [];
            foreach ($config['packages'] ?? [] as $pkgItem) {
                if (is_array($pkgItem) && isset($pkgItem['name'], $pkgItem['alias'])) {
                    $aliasMap[strtolower($pkgItem['alias'])] = $pkgItem['name'];
                }
            }

            foreach ($files as $file) {
                try {
                    $json = json_decode(File::get($file), true, 512, JSON_THROW_ON_ERROR);
                    $canonicalName = $json['name'] ?? '';
                    $dirName = basename(dirname($file));
                    $relPath = trim(str_replace([base_path(), '\\'], ['', '/'], dirname($file)), '/');

                    $isMatch = false;

                    // Match 1: Exact canonical name (e.g. "alex-kassel/scraper-core")
                    if (strcasecmp($canonicalName, $packageName) === 0) {
                        $isMatch = true;
                    }

                    // Match 2: Directory name matches alias or package name
                    if (! $isMatch && strcasecmp($dirName, $packageName) === 0) {
                        $isMatch = true;
                    }

                    // Match 3: If package has a fixed vendor and packageName matches short name
                    if (! $isMatch && $vendor !== null) {
                        $expectedShort = str_starts_with($canonicalName, "{$vendor}/")
                            ? substr($canonicalName, strlen("{$vendor}/"))
                            : $canonicalName;

                        if (strcasecmp($expectedShort, $packageName) === 0) {
                            $isMatch = true;
                        }
                    }

                    // Match 4: Check alias map in manifest
                    if (! $isMatch && isset($aliasMap[strtolower($packageName)])) {
                        $aliasedName = $aliasMap[strtolower($packageName)];
                        if (strcasecmp($canonicalName, $aliasedName) === 0 || strcasecmp($canonicalName, "{$vendor}/{$aliasedName}") === 0) {
                            $isMatch = true;
                        }
                    }

                    if ($isMatch) {
                        $matches[$relPath] = "{$relPath} ({$canonicalName})";
                    }
                } catch (JsonException $e) {
                    throw new InvalidJsonException($file, "Corrupted package manifest: {$e->getMessage()}", $e);
                }
            }
        }

        if (count($matches) > 1) {
            throw new AmbiguousPackageException($packageName, array_values($matches));
        }

        return count($matches) === 1 ? (string) array_key_first($matches) : null;
    }

    /**
     * Resolve full canonical vendor/package name.
     * Supports canonical name, short name, and custom alias.
     *
     * @throws AmbiguousPackageException
     */
    public function resolveCanonicalPackageName(string $packageName, ?string $workspace = null): string
    {
        $packageName = trim($packageName);

        // If explicitly in vendor/package format, verify whether it matches a local package path
        if (str_contains($packageName, '/')) {
            $path = $this->findPackagePath($packageName, $workspace);
            if ($path !== null && File::exists(base_path("{$path}/composer.json"))) {
                $data = json_decode(File::get(base_path("{$path}/composer.json")), true);
                if (! empty($data['name'])) {
                    return $data['name'];
                }
            }

            return $packageName;
        }

        // Try locating local package path by alias or short name
        $path = $this->findPackagePath($packageName, $workspace);
        if ($path !== null && File::exists(base_path("{$path}/composer.json"))) {
            $data = json_decode(File::get(base_path("{$path}/composer.json")), true);
            if (! empty($data['name'])) {
                return $data['name'];
            }
        }

        if ($workspace !== null) {
            $vendor = $this->getWorkspaceVendor($workspace);
            if ($vendor !== null) {
                return "{$vendor}/{$packageName}";
            }
        }

        // Check if default workspace has a fixed vendor
        $defaultWs = $this->getDefault();
        if ($defaultWs !== null) {
            $defaultVendor = $this->getWorkspaceVendor($defaultWs);
            if ($defaultVendor !== null) {
                return "{$defaultVendor}/{$packageName}";
            }
        }

        return $packageName;
    }

    /**
     * Get the default workspace path.
     */
    public function getDefault(): ?string
    {
        return $this->load()['default'];
    }

    /**
     * Get the default workspace path or throw exception if none configured.
     *
     * @throws DefaultWorkspaceNotConfiguredException
     */
    public function getRequiredDefault(): string
    {
        $default = $this->getDefault();

        if ($default === null || $default === '') {
            throw new DefaultWorkspaceNotConfiguredException;
        }

        return $default;
    }

    /**
     * Get the repository URL template (e.g. "git@github.com:{package}.git").
     */
    public function getRepositoryTemplate(): string
    {
        $data = $this->load();

        return $data['repository_template'] ?? $this->defaultRepositoryTemplate();
    }

    /**
     * Set the repository URL template.
     */
    public function setRepositoryTemplate(string $template): void
    {
        $data = $this->load();
        $data['repository_template'] = trim($template);
        $this->save($data);
    }

    /**
     * Get default repository template from configuration or fallback.
     */
    public function defaultRepositoryTemplate(): string
    {
        return config('workspace.repository_template', 'git@github.com:{package}.git');
    }

    /**
     * Resolve the Git clone URL for a given package name.
     */
    public function resolvePackageCloneUrl(string $packageName): string
    {
        $template = $this->getRepositoryTemplate();

        return str_replace('{package}', trim($packageName), $template);
    }

    /**
     * Assign a directory alias to a package in a flat (fixed-vendor) workspace.
     *
     * @return array{old_path: string, new_path: string, canonical_name: string}
     *
     * @throws WorkspaceException
     */
    public function aliasPackage(string $packageName, string $alias): array
    {
        $alias = trim($alias);
        if ($alias === '' || ! preg_match('/^[a-zA-Z0-9_.-]+$/', $alias)) {
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
        if (strcasecmp($currentDir, $alias) !== 0) {
            File::move($oldFullPath, $targetFullPath);

            $this->updateComposerPathReferences($canonicalName, $packagePath, $targetRelativePath);
            $this->updateVendorSymlink($canonicalName, $targetFullPath);
        }

        // Update workspace.json
        $data = $this->load();
        $wsPackages = $data['workspaces'][$workspace]['packages'] ?? [];
        $newPackages = [];

        foreach ($wsPackages as $item) {
            $existingName = is_array($item) ? ($item['name'] ?? '') : (string) $item;
            if ($existingName === $baseShortName || $existingName === $currentDir || $existingName === $canonicalName) {
                continue;
            }
            $newPackages[] = $item;
        }

        $newPackages[] = [
            'name' => $baseShortName,
            'alias' => $alias,
        ];

        // Sort by package name or alias
        usort($newPackages, function ($a, $b) {
            $nameA = is_array($a) ? ($a['alias'] ?? $a['name']) : $a;
            $nameB = is_array($b) ? ($b['alias'] ?? $b['name']) : $b;

            return strcasecmp($nameA, $nameB);
        });

        $data['workspaces'][$workspace]['packages'] = $newPackages;
        $this->save($data);

        return [
            'old_path' => $packagePath,
            'new_path' => $targetRelativePath,
            'canonical_name' => $canonicalName,
        ];
    }

    /**
     * Find any other packages matching a given alias/name across workspaces.
     *
     * @return array<int, string>
     */
    public function findDuplicateAliases(string $alias, ?string $excludePath = null): array
    {
        $duplicates = [];
        $workspaces = $this->all();

        foreach ($workspaces as $ws => $config) {
            $vendor = $config['vendor'] ?? null;
            $files = $vendor !== null
                ? (File::glob(base_path("{$ws}/*/composer.json")) ?: [])
                : (File::glob(base_path("{$ws}/*/*/composer.json")) ?: []);

            foreach ($files as $file) {
                $dirName = basename(dirname($file));
                $relPath = trim(str_replace([base_path(), '\\'], ['', '/'], dirname($file)), '/');

                if ($excludePath !== null && strcasecmp($relPath, $excludePath) === 0) {
                    continue;
                }

                $json = json_decode(File::get($file), true) ?: [];
                $canonicalName = $json['name'] ?? '';

                if (strcasecmp($dirName, $alias) === 0) {
                    $duplicates[] = "{$relPath} ({$canonicalName})";
                }
            }
        }

        return $duplicates;
    }

    /**
     * Set the default workspace.
     *
     * @throws WorkspaceNotFoundException
     */
    public function setDefault(string $path): bool
    {
        $path = trim(preg_replace('#[/\\\\]+#', '/', $path) ?? '', '/');
        $data = $this->load();

        if (! array_key_exists($path, $data['workspaces'])) {
            throw new WorkspaceNotFoundException($path, array_keys($data['workspaces']));
        }

        $data['default'] = $path;
        $this->save($data);

        return true;
    }

    /**
     * Add a workspace and register it in composer.json, .gitignore and workspace.json.
     */
    public function add(string $path, ?string $vendor = null, bool $isDefault = false): void
    {
        $path = $this->validateWorkspacePath($path);
        $vendor = $vendor ? trim($vendor) : null;

        $targetFullPath = base_path($path);
        File::ensureDirectoryExists($targetFullPath);

        $realTarget = realpath($targetFullPath);
        $realBase = realpath(base_path());

        if (! $realTarget || ! $realBase || ! str_starts_with(strtolower(rtrim(str_replace('\\', '/', $realTarget), '/')), strtolower(rtrim(str_replace('\\', '/', $realBase), '/')).'/')) {
            throw new InvalidWorkspacePathException($path, 'Path resolves outside the application root');
        }

        $this->addToGitignore($path);
        $this->registerInComposer($path, $vendor !== null);
        $this->ensureWorkspaceScript();
        $this->ensureComposerHooks();

        $data = $this->load();
        $isFirst = empty($data['workspaces']);

        $data['workspaces'][$path] = [
            'vendor' => $vendor,
            'packages' => $this->scanPackages($path, $vendor),
        ];

        if ($isFirst || $isDefault || empty($data['default'])) {
            $data['default'] = $path;
        }

        $this->save($data);
    }

    /**
     * Remove a workspace from composer.json and workspace.json (directory preserved).
     *
     * @throws WorkspaceNotFoundException
     */
    public function remove(string $path): bool
    {
        $path = trim(preg_replace('#[/\\\\]+#', '/', $path) ?? '', '/');
        $data = $this->load();

        if (! array_key_exists($path, $data['workspaces'])) {
            throw new WorkspaceNotFoundException($path, array_keys($data['workspaces']));
        }

        $this->removeFromComposer($path);

        unset($data['workspaces'][$path]);

        if ($data['default'] === $path) {
            $data['default'] = array_key_first($data['workspaces']) ?: null;
        }

        $this->save($data);

        return true;
    }

    /**
     * Scan physical packages and sync workspace.json.
     *
     * @return array{default: ?string, workspaces: array<string, array{vendor: ?string, packages: array<int, string>}>}
     */
    public function sync(): array
    {
        $composer = $this->readJsonFile($this->composerJsonPath());
        $registered = [];

        foreach ($composer['repositories'] ?? [] as $key => $repo) {
            if (($repo['type'] ?? '') === 'path' && isset($repo['url'])) {
                // Only consider repositories managed by this toolkit (workspace- prefix or already tracked)
                $isToolkitRepo = is_string($key) && str_starts_with($key, 'workspace-');
                if (! $isToolkitRepo && isset($repo['name']) && is_string($repo['name'])) {
                    $isToolkitRepo = str_starts_with($repo['name'], 'workspace-');
                }

                $trimmedUrl = trim($repo['url'], '/\\');
                $parts = explode('/', $trimmedUrl);
                // Strip wildcards
                $dir = implode('/', array_filter($parts, fn ($p) => $p !== '*'));

                if ($dir !== '' && $isToolkitRepo) {
                    $registered[$dir] = true;
                    $this->addToGitignore($dir);
                }
            }
        }

        $currentDefault = null;
        $currentTemplate = null;
        $existingVendors = [];

        if (File::exists($this->workspaceJsonPath())) {
            $existing = $this->readJsonFile($this->workspaceJsonPath());
            $currentDefault = $existing['default'] ?? null;
            $currentTemplate = $existing['repository_template'] ?? null;
            $rawWorkspaces = $existing['workspaces'] ?? [];

            foreach ($rawWorkspaces as $dir => $val) {
                $registered[$dir] = true;
                if (is_array($val) && isset($val['vendor'])) {
                    $existingVendors[$dir] = $val['vendor'];
                }
            }
        }

        $workspaces = [];
        foreach (array_keys($registered) as $dir) {
            $vendor = $existingVendors[$dir] ?? null;
            $workspaces[$dir] = [
                'vendor' => $vendor,
                'packages' => $this->scanPackages($dir, $vendor),
            ];
        }

        $default = $currentDefault && array_key_exists($currentDefault, $workspaces)
            ? $currentDefault
            : (array_key_first($workspaces) ?: null);

        $result = [
            'default' => $default,
            'repository_template' => $currentTemplate ?? $this->defaultRepositoryTemplate(),
            'workspaces' => $workspaces,
        ];

        $this->save($result);

        return $result;
    }

    /**
     * Scan a workspace directory for package names (preserving configured aliases).
     *
     * @return array<int, string|array{name: string, alias: string}>
     */
    public function scanPackages(string $workspace, ?string $vendor = null): array
    {
        $packages = [];
        $files = $vendor !== null
            ? (File::glob(base_path("{$workspace}/*/composer.json")) ?: [])
            : (File::glob(base_path("{$workspace}/*/*/composer.json")) ?: []);

        // Read existing alias map if available
        $existingAliases = [];
        if (File::exists($this->workspaceJsonPath())) {
            try {
                $currentData = json_decode(File::get($this->workspaceJsonPath()), true) ?: [];
                $configured = $currentData['workspaces'][$workspace]['packages'] ?? [];
                foreach ($configured as $item) {
                    if (is_array($item) && isset($item['name'], $item['alias'])) {
                        $existingAliases[$item['name']] = $item['alias'];
                        $existingAliases[$item['alias']] = $item['alias'];
                    }
                }
            } catch (\Throwable) {
                // Ignore corrupted json during scan
            }
        }

        foreach ($files as $file) {
            try {
                $json = json_decode(File::get($file), true, 512, JSON_THROW_ON_ERROR);
                $name = $json['name'] ?? null;
                $dirName = basename(dirname($file));

                if (! empty($name)) {
                    if ($vendor !== null) {
                        // In fixed vendor workspace, strip vendor/ prefix for packages list
                        $baseShort = str_starts_with($name, "{$vendor}/")
                            ? substr($name, strlen("{$vendor}/"))
                            : $name;

                        // Check if directory name is an alias or if alias exists in configuration
                        if (strcasecmp($dirName, $baseShort) !== 0) {
                            $packages[] = [
                                'name' => $baseShort,
                                'alias' => $dirName,
                            ];
                        } elseif (isset($existingAliases[$baseShort])) {
                            $packages[] = [
                                'name' => $baseShort,
                                'alias' => $existingAliases[$baseShort],
                            ];
                        } else {
                            $packages[] = $baseShort;
                        }
                    } else {
                        $packages[] = $name;
                    }
                }
            } catch (JsonException $e) {
                throw new InvalidJsonException($file, "Corrupted package manifest: {$e->getMessage()}", $e);
            }
        }

        usort($packages, function ($a, $b) {
            $nameA = is_array($a) ? ($a['alias'] ?? $a['name']) : $a;
            $nameB = is_array($b) ? ($b['alias'] ?? $b['name']) : $b;

            return strcasecmp($nameA, $nameB);
        });

        return array_values($packages);
    }

    /**
     * Save data to workspace.json.
     *
     * @param  array{default: ?string, repository_template?: ?string, workspaces: array<string, array{vendor: ?string, packages: array<int, string|array{name: string, url: string}>}>}  $data
     */
    public function save(array $data): void
    {
        ksort($data['workspaces']);

        $orderedData = [
            'default' => $data['default'] ?? null,
            'repository_template' => $data['repository_template'] ?? $this->defaultRepositoryTemplate(),
            'workspaces' => $data['workspaces'],
        ];

        $this->cache = $orderedData;
        File::put($this->workspaceJsonPath(), json_encode($orderedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    }

    /**
     * Register path repository in composer.json using Composer CLI.
     */
    protected function registerInComposer(string $path, bool $isFlat = false): void
    {
        $safePath = str_replace('/', '-', $path);
        $repoKey = "workspace-{$safePath}";
        $url = $isFlat ? "{$path}/*" : "{$path}/*/*";

        $composer = $this->readJsonFile($this->composerJsonPath());
        foreach ($composer['repositories'] ?? [] as $repo) {
            if (($repo['type'] ?? '') === 'path' && ($repo['url'] ?? '') === $url) {
                return;
            }
        }

        $result = Process::path(base_path())->run([
            'composer', 'config', "repositories.{$repoKey}", 'path', $url,
        ]);

        if (! $result->successful()) {
            throw new ComposerProcessException("composer config repositories.{$repoKey} path {$url}", $result->errorOutput());
        }
    }

    /**
     * Remove path repository from composer.json using Composer CLI.
     */
    protected function removeFromComposer(string $path): void
    {
        $safePath = str_replace('/', '-', $path);
        $repoKey = "workspace-{$safePath}";
        $flatUrl = "{$path}/*";
        $nestedUrl = "{$path}/*/*";

        $result = Process::path(base_path())->run([
            'composer', 'config', '--unset', "repositories.{$repoKey}",
        ]);

        if (! $result->successful() && ! str_contains(strtolower($result->errorOutput()), 'not found') && ! str_contains(strtolower($result->errorOutput()), 'does not exist')) {
            throw new ComposerProcessException("composer config --unset repositories.{$repoKey}", $result->errorOutput());
        }

        // Also try unsanitized key in case it was stored directly
        if ($safePath !== $path) {
            $fallbackResult = Process::path(base_path())->run([
                'composer', 'config', '--unset', "repositories.workspace-{$path}",
            ]);

            if (! $fallbackResult->successful() && ! str_contains(strtolower($fallbackResult->errorOutput()), 'not found') && ! str_contains(strtolower($fallbackResult->errorOutput()), 'does not exist')) {
                throw new ComposerProcessException("composer config --unset repositories.workspace-{$path}", $fallbackResult->errorOutput());
            }
        }

        $composer = $this->readJsonFile($this->composerJsonPath());
        if (! empty($composer['repositories'])) {
            $filtered = array_values(array_filter(
                $composer['repositories'],
                function ($repo, $key) use ($repoKey, $flatUrl, $nestedUrl) {
                    if (($repo['type'] ?? '') !== 'path') {
                        return true;
                    }
                    $url = $repo['url'] ?? '';
                    $name = $repo['name'] ?? (is_string($key) ? $key : '');
                    // Delete only if it matches our exact repo key or our exact workspace url
                    $isOurRepo = ($name === $repoKey || $url === $flatUrl || $url === $nestedUrl);

                    return ! $isOurRepo;
                },
                ARRAY_FILTER_USE_BOTH
            ));

            if (count($filtered) !== count($composer['repositories'])) {
                $composer['repositories'] = $filtered;
                File::put($this->composerJsonPath(), json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            }
        }
    }

    /**
     * Read and validate JSON from file.
     *
     * @return array<string, mixed>
     */
    protected function readJsonFile(string $path): array
    {
        if (! File::exists($path)) {
            throw new InvalidJsonException($path, 'Required file was not found on disk.');
        }

        try {
            $data = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new InvalidJsonException($path, "Syntax error: {$e->getMessage()}", $e);
        }

        if (! is_array($data)) {
            throw new InvalidJsonException($path, 'Expected JSON object or array, got '.gettype($data).'.');
        }

        return $data;
    }

    /**
     * Add workspace directory to .gitignore.
     */
    protected function addToGitignore(string $path): void
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
     * Ensure the standalone root `workspace` CLI script exists.
     */
    public function ensureWorkspaceScript(): void
    {
        $targetScript = base_path('workspace');
        if (File::exists($targetScript)) {
            return;
        }

        $stubPath = dirname(__DIR__, 2).'/stubs/workspace.stub';
        if (File::exists($stubPath)) {
            File::copy($stubPath, $targetScript);
            @chmod($targetScript, 0755);
        }
    }

    /**
     * Ensure pre-install-cmd and pre-update-cmd hooks are registered in composer.json.
     */
    public function ensureComposerHooks(): void
    {
        $composerPath = $this->composerJsonPath();
        if (! File::exists($composerPath)) {
            return;
        }

        $composer = $this->readJsonFile($composerPath);
        $scripts = $composer['scripts'] ?? [];
        $hookCommand = 'php workspace restore';
        $modified = false;

        foreach (['pre-install-cmd', 'pre-update-cmd'] as $hook) {
            if (! isset($scripts[$hook])) {
                $scripts[$hook] = [$hookCommand];
                $modified = true;
            } elseif (is_string($scripts[$hook])) {
                if ($scripts[$hook] !== $hookCommand) {
                    $scripts[$hook] = array_values(array_unique([$hookCommand, $scripts[$hook]]));
                    $modified = true;
                }
            } elseif (is_array($scripts[$hook])) {
                if (! in_array($hookCommand, $scripts[$hook], true)) {
                    array_unshift($scripts[$hook], $hookCommand);
                    $modified = true;
                }
            }
        }

        if ($modified) {
            $composer['scripts'] = $scripts;
            if (isset($composer['require-dev']) && is_array($composer['require-dev']) && empty($composer['require-dev'])) {
                $composer['require-dev'] = (object) [];
            }
            if (isset($composer['require']) && is_array($composer['require']) && empty($composer['require'])) {
                $composer['require'] = (object) [];
            }
            if (isset($composer['repositories']) && is_array($composer['repositories']) && empty($composer['repositories'])) {
                $composer['repositories'] = (object) [];
            }
            File::put($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        }
    }

    /**
     * Recursively delete a directory, unsetting read-only flags (essential for Windows .git folders).
     */
    public function deleteDirectoryRecursively(string $dir): bool
    {
        if (! File::isDirectory($dir)) {
            return true;
        }

        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );

            foreach ($items as $item) {
                $itemPath = $item->getRealPath();
                if ($itemPath === false) {
                    continue;
                }

                @chmod($itemPath, 0777);

                if ($item->isDir()) {
                    @rmdir($itemPath);
                } else {
                    @unlink($itemPath);
                }
            }

            @chmod($dir, 0777);
            @rmdir($dir);

            return ! File::isDirectory($dir);
        } catch (\Throwable) {
            return File::deleteDirectory($dir);
        }
    }

    /**
     * Update Composer lock and installed.json path references when a package directory moves.
     */
    public function updateComposerPathReferences(string $canonicalName, string $oldRelPath, string $newRelPath): void
    {
        $oldRelPath = str_replace('\\', '/', $oldRelPath);
        $newRelPath = str_replace('\\', '/', $newRelPath);

        // 1. Update composer.lock if present
        $lockFile = base_path('composer.lock');
        if (File::exists($lockFile)) {
            $lockContent = File::get($lockFile);
            $lockData = json_decode($lockContent, true);
            if (is_array($lockData)) {
                $changed = false;
                foreach (['packages', 'packages-dev'] as $section) {
                    if (! isset($lockData[$section]) || ! is_array($lockData[$section])) {
                        continue;
                    }
                    foreach ($lockData[$section] as &$pkg) {
                        if (($pkg['name'] ?? '') === $canonicalName && ($pkg['dist']['type'] ?? '') === 'path') {
                            $pkg['dist']['url'] = $newRelPath;
                            $changed = true;
                        }
                    }
                    unset($pkg);
                }
                if ($changed) {
                    File::put($lockFile, json_encode($lockData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
                }
            }
        }

        // 2. Update vendor/composer/installed.json if present
        $installedFile = base_path('vendor/composer/installed.json');
        if (File::exists($installedFile)) {
            $installedContent = File::get($installedFile);
            $installedData = json_decode($installedContent, true);
            if (is_array($installedData)) {
                $changed = false;
                $packages = &$installedData['packages'];
                if (is_array($packages)) {
                    foreach ($packages as &$pkg) {
                        if (($pkg['name'] ?? '') === $canonicalName && ($pkg['dist']['type'] ?? '') === 'path') {
                            $pkg['dist']['url'] = $newRelPath;
                            $changed = true;
                        }
                    }
                    unset($pkg);
                }
                if ($changed) {
                    File::put($installedFile, json_encode($installedData, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
                }
            }
        }
    }

    /**
     * Re-point or recreate symlink / junction in vendor directory.
     */
    public function updateVendorSymlink(string $canonicalName, string $targetFullPath): void
    {
        $vendorPackageDir = base_path("vendor/{$canonicalName}");
        $winVendor = str_replace('/', '\\', $vendorPackageDir);
        $winTarget = str_replace('/', '\\', $targetFullPath);

        if (PHP_OS_FAMILY === 'Windows') {
            if (is_link($vendorPackageDir) || file_exists($vendorPackageDir) || is_dir($vendorPackageDir)) {
                @rmdir($vendorPackageDir);
                Process::run("cmd /c mklink /J \"{$winVendor}\" \"{$winTarget}\"");
            }
        } else {
            if (is_link($vendorPackageDir) || file_exists($vendorPackageDir)) {
                @unlink($vendorPackageDir);
                @symlink($targetFullPath, $vendorPackageDir);
            }
        }
    }
}

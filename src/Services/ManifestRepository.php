<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\DefaultWorkspaceNotConfiguredException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\InvalidJsonException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\InvalidWorkspacePathException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceNotFoundException;
use AlexKassel\WorkspaceManifest\Exceptions\InvalidWorkspacePathException as ManifestPathException;
use AlexKassel\WorkspaceManifest\Exceptions\PackageConflictException;
use AlexKassel\WorkspaceManifest\WorkspaceManifest;
use Illuminate\Support\Facades\File;

class ManifestRepository
{
    /**
     * In-memory cache for workspace configuration.
     *
     * @var array{default: ?string, repository_template?: ?string, repository_url_template?: ?string, workspaces: array<string, array{vendor: ?string, packages: array<int, string|array{name: string, alias?: string, url?: string, skills?: array<int, string>}>}>}|null
     */
    protected ?array $cache = null;

    protected ?int $cacheMtime = null;

    public function __construct(
        protected ?WorkspaceManifest $workspaceManifest = null
    ) {}

    /**
     * Get underlying WorkspaceManifest instance.
     */
    public function manifest(): WorkspaceManifest
    {
        $path = $this->workspaceJsonPath();
        if ($this->workspaceManifest === null || $this->workspaceManifest->manifest()->path !== $path) {
            $this->workspaceManifest = app()->bound(WorkspaceManifest::class) && app(WorkspaceManifest::class)->manifest()->path === $path
                ? app(WorkspaceManifest::class)
                : WorkspaceManifest::open($path);
        }

        return $this->workspaceManifest;
    }

    /**
     * Get path to workspace.json.
     */
    public function workspaceJsonPath(): string
    {
        return base_path('workspace.json');
    }

    /**
     * Clear in-memory cache.
     */
    public function clearCache(): void
    {
        $this->cache = null;
        $this->cacheMtime = null;
        if ($this->workspaceManifest !== null) {
            $this->workspaceManifest->clearCache();
        }
    }

    /**
     * Load configuration from workspace.json.
     *
     * @return array{default: ?string, repository_template?: ?string, repository_url_template?: ?string, workspaces: array<string, array{vendor: ?string, packages: array<int, string|array{name: string, alias?: string, url?: string, skills?: array<int, string>}>}>}
     *
     * @throws InvalidJsonException
     */
    public function load(): array
    {
        $path = $this->workspaceJsonPath();
        $currentMtime = File::exists($path) ? File::lastModified($path) : null;

        if ($this->cache !== null && $this->cacheMtime !== null && $currentMtime === $this->cacheMtime) {
            return $this->cache;
        }

        if (! File::exists($path)) {
            $configuredTemplate = (string) config('workspace.repository_url_template', 'git@github.com:{package}.git');
            $defaultData = [
                'default' => null,
                'repository_url_template' => trim($configuredTemplate) !== '' ? trim($configuredTemplate) : 'git@github.com:{package}.git',
                'workspaces' => [],
            ];
            $this->save($defaultData);
            $this->cache = $defaultData;

            return $defaultData;
        }

        $content = File::get($path);

        try {
            $data = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($data)) {
                throw new \RuntimeException("Expected JSON object in {$path}");
            }
            $this->validateWorkspaceJson($data, $path);
        } catch (\Throwable) {
            return $this->heal();
        }

        /** @var array{default: ?string, repository_template?: ?string, repository_url_template?: ?string, workspaces: array<string, array{vendor: ?string, packages: array<int, string|array{name: string, alias?: string, url?: string, skills?: array<int, string>}>}>} $data */
        $this->cache = $data;
        $this->cacheMtime = $currentMtime;

        return $data;
    }

    /**
     * Self-heal and reconstruct workspace.json without creating any backup files.
     *
     * @return array{default: ?string, repository_template?: ?string, repository_url_template?: ?string, workspaces: array<string, array{vendor: ?string, packages: array<int, string|array{name: string, alias?: string, url?: string, skills?: array<int, string>}>}>}
     */
    public function heal(): array
    {
        $configuredTemplate = (string) config('workspace.repository_url_template', 'git@github.com:{package}.git');
        $defaultData = [
            'default' => null,
            'repository_url_template' => trim($configuredTemplate) !== '' ? trim($configuredTemplate) : 'git@github.com:{package}.git',
            'workspaces' => [],
        ];

        // Attempt to reconstruct workspaces from root composer.json path repositories
        $composerPath = base_path('composer.json');
        if (File::exists($composerPath)) {
            try {
                $composer = json_decode(File::get($composerPath), true, 512, JSON_THROW_ON_ERROR);
                if (is_array($composer) && ! empty($composer['repositories']) && is_array($composer['repositories'])) {
                    foreach ($composer['repositories'] as $repo) {
                        if (is_array($repo) && ($repo['type'] ?? '') === 'path' && ! empty($repo['url'])) {
                            $url = (string) $repo['url'];
                            $wsPath = null;
                            if (str_ends_with($url, '/*/*')) {
                                $wsPath = substr($url, 0, -4);
                            } elseif (str_ends_with($url, '/*')) {
                                $wsPath = substr($url, 0, -2);
                            }

                            if ($wsPath !== null && $wsPath !== '') {
                                $vendor = null;
                                $localManifestPath = base_path($wsPath.DIRECTORY_SEPARATOR.'workspace.json');
                                if (File::exists($localManifestPath)) {
                                    try {
                                        $localJson = json_decode(File::get($localManifestPath), true, 512, JSON_THROW_ON_ERROR);
                                        if (is_array($localJson) && ! empty($localJson['vendor']) && is_string($localJson['vendor'])) {
                                            $vendor = $localJson['vendor'];
                                        }
                                    } catch (\Throwable) {
                                        // Ignore corrupted local manifest
                                    }
                                }

                                $defaultData['workspaces'][$wsPath] = ['vendor' => $vendor, 'packages' => []];
                            }
                        }
                    }
                }
            } catch (\Throwable) {
                // Ignore errors reading composer.json during healing
            }
        }

        if (! empty($defaultData['workspaces'])) {
            $defaultData['default'] = array_key_first($defaultData['workspaces']);
        }

        $this->save($defaultData);
        $this->cache = $defaultData;

        return $defaultData;
    }

    /**
     * Save configuration to workspace.json.
     *
     * @param  array{default: ?string, repository_template?: ?string, repository_url_template?: ?string, workspaces: array<string, array{vendor: ?string, packages: array<int, string|array{name: string, alias?: string, url?: string, skills?: array<int, string>}>}>}  $data
     */
    public function save(array $data): void
    {
        $path = $this->workspaceJsonPath();
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n";
        File::put($path, $json, true);
        $this->cache = $data;
        $this->cacheMtime = File::exists($path) ? File::lastModified($path) : null;
        if ($this->workspaceManifest !== null) {
            $this->workspaceManifest->clearCache();
        }
    }

    /**
     * Get all workspaces.
     *
     * @return array<string, array{vendor: ?string, packages: array<int, string|array{name: string, alias?: string, url?: string, skills?: array<int, string>}>}>
     */
    public function all(): array
    {
        return $this->load()['workspaces'];
    }

    /**
     * Get default workspace name.
     */
    public function getDefault(): ?string
    {
        return $this->manifest()->getDefaultWorkspace();
    }

    /**
     * Get required default workspace name or fail with helpful exception.
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
     * Set default workspace.
     *
     * @throws WorkspaceNotFoundException
     */
    public function setDefault(string $workspace): bool
    {
        $workspace = $this->normalizeWorkspacePath($workspace);

        if (! $this->manifest()->hasWorkspace($workspace)) {
            throw new WorkspaceNotFoundException($workspace, array_keys($this->all()));
        }

        $this->manifest()->setDefaultWorkspace($workspace);
        $this->clearCache();

        return true;
    }

    /**
     * Add a new workspace.
     *
     * @throws InvalidWorkspacePathException
     */
    public function add(string $path, ?string $vendor = null, bool $asDefault = false): bool
    {
        $cleanPath = $this->normalizeWorkspacePath($path);
        $cleanVendor = $vendor !== null ? strtolower(trim($vendor)) : null;

        if ($this->manifest()->hasWorkspace($cleanPath)) {
            return false;
        }

        $this->manifest()->registerWorkspace($cleanPath, $cleanVendor, $asDefault);
        $this->clearCache();

        return true;
    }

    /**
     * Remove a workspace from workspace.json.
     *
     * @throws WorkspaceNotFoundException
     */
    public function remove(string $path): bool
    {
        $cleanPath = $this->normalizeWorkspacePath($path);

        if (! $this->manifest()->hasWorkspace($cleanPath)) {
            throw new WorkspaceNotFoundException($cleanPath, array_keys($this->all()));
        }

        $this->manifest()->removeWorkspace($cleanPath);
        $this->clearCache();

        return true;
    }

    /**
     * Get vendor for a given workspace.
     */
    public function getWorkspaceVendor(string $workspace): ?string
    {
        $cleanPath = $this->normalizeWorkspacePath($workspace);

        return $this->manifest()->getWorkspace($cleanPath)?->vendor;
    }

    /**
     * Set or clear vendor for a given workspace.
     *
     * @throws WorkspaceNotFoundException
     */
    public function setWorkspaceVendor(string $workspace, ?string $vendor): bool
    {
        $cleanPath = $this->normalizeWorkspacePath($workspace);
        $cleanVendor = $vendor !== null ? strtolower(trim($vendor)) : null;

        if (! $this->manifest()->hasWorkspace($cleanPath)) {
            throw new WorkspaceNotFoundException($cleanPath, array_keys($this->all()));
        }

        $this->manifest()->setWorkspaceVendor($cleanPath, $cleanVendor);
        $this->clearCache();

        return true;
    }

    /**
     * Get repository clone URL template.
     */
    public function getRepositoryTemplate(): string
    {
        $template = $this->manifest()->getRepositoryUrlTemplate();
        $configured = (string) config('workspace.repository_url_template');
        if (trim($configured) !== '' && ($template === WorkspaceManifest::DEFAULT_REPOSITORY_URL_TEMPLATE || ! File::exists($this->workspaceJsonPath()))) {
            return trim($configured);
        }

        return $template;
    }

    /**
     * Set repository clone URL template.
     */
    public function setRepositoryTemplate(string $template): bool
    {
        $this->manifest()->setRepositoryUrlTemplate(trim($template));
        $this->clearCache();

        return true;
    }

    /**
     * Register or update package alias in workspace.json.
     */
    public function registerPackageAlias(string $workspace, string $packageName, string $alias): void
    {
        $cleanWorkspace = $this->normalizeWorkspacePath($workspace);
        $data = $this->load();

        if (! isset($data['workspaces'][$cleanWorkspace])) {
            throw new WorkspaceNotFoundException($cleanWorkspace, array_keys($data['workspaces']));
        }

        try {
            $resolvedName = $this->resolveStoredPackageName($cleanWorkspace, $packageName);
            $this->manifest()->registerPackageAlias($cleanWorkspace, $resolvedName, $alias);
            $this->clearCache();
        } catch (PackageConflictException $e) {
            throw new WorkspaceException($e->getMessage(), 'Choose a different alias or rename the existing package first.');
        }
    }

    /**
     * Completely remove a package entry from workspace.json.
     *
     * @throws WorkspaceNotFoundException
     */
    public function forgetPackage(string $workspace, string $packageName): bool
    {
        $cleanWorkspace = $this->normalizeWorkspacePath($workspace);
        $data = $this->load();

        if (! isset($data['workspaces'][$cleanWorkspace])) {
            throw new WorkspaceNotFoundException($cleanWorkspace, array_keys($data['workspaces']));
        }

        $resolvedName = $this->resolveStoredPackageName($cleanWorkspace, $packageName);
        $removed = $this->manifest()->removePackage($resolvedName, $cleanWorkspace);
        if ($removed) {
            $this->clearCache();
        }

        return $removed;
    }

    /**
     * Record a package entry in workspace.json preserving custom URL, alias, etc.
     */
    public function recordPackage(string $workspace, string $packageName, ?string $alias = null, ?string $url = null): void
    {
        $cleanWorkspace = $this->normalizeWorkspacePath($workspace);
        $data = $this->load();

        if (! isset($data['workspaces'][$cleanWorkspace])) {
            throw new WorkspaceNotFoundException($cleanWorkspace, array_keys($data['workspaces']));
        }

        try {
            $resolvedName = $this->resolveStoredPackageName($cleanWorkspace, $packageName);
            $existing = $this->manifest()->getPackage($resolvedName, $cleanWorkspace);
            $skills = $existing !== null ? $existing->skills : [];

            $this->manifest()->addPackage($cleanWorkspace, $resolvedName, $alias, $url, $skills);
            $this->clearCache();
        } catch (PackageConflictException $e) {
            throw new WorkspaceException($e->getMessage(), 'Choose a different package name or alias first.');
        }
    }

    /**
     * Update active skills for a package in workspace.json.
     *
     * @param  array<int, string>  $skills
     */
    public function updatePackageSkills(string $workspace, string $packageName, array $skills): void
    {
        $cleanWorkspace = $this->normalizeWorkspacePath($workspace);
        $data = $this->load();

        if (! isset($data['workspaces'][$cleanWorkspace])) {
            throw new WorkspaceNotFoundException($cleanWorkspace, array_keys($data['workspaces']));
        }

        $resolvedName = $this->resolveStoredPackageName($cleanWorkspace, $packageName);
        $this->manifest()->updatePackageSkills($cleanWorkspace, $resolvedName, array_values(array_unique($skills)));
        $this->clearCache();
    }

    /**
     * Resolve the stored package entry name in workspace (strip vendor in fixed-vendor workspaces).
     */
    protected function resolveStoredPackageName(string $workspace, string $packageName): string
    {
        $vendor = $this->getWorkspaceVendor($workspace);
        if ($vendor !== null) {
            $prefix = strtolower($vendor).'/';
            if (str_starts_with(strtolower($packageName), $prefix)) {
                return substr($packageName, strlen($prefix));
            }
        }

        return $packageName;
    }

    /**
     * Normalize and validate workspace path.
     * Delegates to WorkspaceManifest::normalizeWorkspacePath.
     *
     * @throws InvalidWorkspacePathException
     */
    public function normalizeWorkspacePath(string $path): string
    {
        try {
            return WorkspaceManifest::normalizeWorkspacePath($path);
        } catch (ManifestPathException $e) {
            throw new InvalidWorkspacePathException($e->workspacePath, $e->getMessage());
        }
    }

    /**
     * Validate schema of workspace.json data.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws InvalidJsonException
     */
    protected function validateWorkspaceJson(array $data, string $path): void
    {
        if (! array_key_exists('workspaces', $data) || ! is_array($data['workspaces'])) {
            throw new InvalidJsonException($path, "Missing or invalid 'workspaces' array in {$path}");
        }

        if (array_key_exists('default', $data) && $data['default'] !== null && ! is_string($data['default'])) {
            throw new InvalidJsonException($path, "Invalid 'default' field in {$path}, must be string or null");
        }

        foreach ($data['workspaces'] as $wsPath => $config) {
            if (! is_string($wsPath) || ! is_array($config)) {
                throw new InvalidJsonException($path, "Invalid workspace entry [{$wsPath}] in {$path}");
            }
            if (array_key_exists('vendor', $config) && $config['vendor'] !== null && ! is_string($config['vendor'])) {
                throw new InvalidJsonException($path, "Invalid 'vendor' in workspace [{$wsPath}], must be string or null");
            }
            if (array_key_exists('packages', $config) && ! is_array($config['packages'])) {
                throw new InvalidJsonException($path, "Invalid 'packages' in workspace [{$wsPath}], must be array");
            }
        }
    }
}

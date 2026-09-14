<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\DefaultWorkspaceNotConfiguredException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\InvalidJsonException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\InvalidWorkspacePathException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceNotFoundException;
use Illuminate\Support\Facades\File;

class ManifestRepository
{
    /**
     * In-memory cache for workspace configuration.
     *
     * @var array{default: ?string, repository_template?: ?string, repository_url_template?: ?string, workspaces: array<string, array{vendor: ?string, packages: array<int, string|array{name: string, alias?: string, url?: string, skills?: array<int, string>}>}>}|null
     */
    protected ?array $cache = null;

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
        if ($this->cache !== null) {
            return $this->cache;
        }

        $path = $this->workspaceJsonPath();

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
                            if (str_ends_with($url, '/*/*')) {
                                $wsPath = substr($url, 0, -4);
                                $defaultData['workspaces'][$wsPath] = ['vendor' => null, 'packages' => []];
                            } elseif (str_ends_with($url, '/*')) {
                                $wsPath = substr($url, 0, -2);
                                $defaultData['workspaces'][$wsPath] = ['vendor' => null, 'packages' => []];
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
        return $this->load()['default'] ?? null;
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
        $data = $this->load();
        $workspace = $this->normalizeWorkspacePath($workspace);

        if (! array_key_exists($workspace, $data['workspaces'])) {
            throw new WorkspaceNotFoundException($workspace, array_keys($data['workspaces']));
        }

        $data['default'] = $workspace;
        $this->save($data);

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

        $data = $this->load();

        if (array_key_exists($cleanPath, $data['workspaces'])) {
            return false;
        }

        $data['workspaces'][$cleanPath] = [
            'vendor' => $cleanVendor,
            'packages' => [],
        ];

        ksort($data['workspaces']);

        if ($asDefault || $data['default'] === null) {
            $data['default'] = $cleanPath;
        }

        $this->save($data);

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
        $data = $this->load();

        if (! array_key_exists($cleanPath, $data['workspaces'])) {
            throw new WorkspaceNotFoundException($cleanPath, array_keys($data['workspaces']));
        }

        unset($data['workspaces'][$cleanPath]);

        if ($data['default'] === $cleanPath) {
            $data['default'] = array_key_first($data['workspaces']) ?? null;
        }

        $this->save($data);

        return true;
    }

    /**
     * Get vendor for a given workspace.
     */
    public function getWorkspaceVendor(string $workspace): ?string
    {
        $cleanPath = $this->normalizeWorkspacePath($workspace);
        $workspaces = $this->all();

        return $workspaces[$cleanPath]['vendor'] ?? null;
    }

    /**
     * Get repository clone URL template.
     */
    public function getRepositoryTemplate(): string
    {
        $data = $this->load();
        $configured = (string) config('workspace.repository_url_template', 'git@github.com:{package}.git');
        $default = trim($configured) !== '' ? trim($configured) : 'git@github.com:{package}.git';

        return $data['repository_url_template'] ?? $data['repository_template'] ?? $default;
    }

    /**
     * Set repository clone URL template.
     */
    public function setRepositoryTemplate(string $template): bool
    {
        $data = $this->load();
        $data['repository_url_template'] = trim($template);
        $this->save($data);

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

        $wsPackages = $data['workspaces'][$cleanWorkspace]['packages'];
        $newPackages = [];

        $existingSkills = null;
        $existingUrl = null;

        foreach ($wsPackages as $item) {
            $existingName = is_array($item) ? $item['name'] : (string) $item;
            $existingAlias = is_array($item) ? ($item['alias'] ?? null) : null;

            if ($existingName === $packageName) {
                continue;
            }

            if (strcasecmp($existingName, $alias) === 0) {
                throw new WorkspaceException(
                    "Cannot use alias [{$alias}]: it conflicts with the name of existing package [{$existingName}].",
                    "Choose a different alias or rename the existing package [{$alias}] first."
                );
            }

            if ($existingAlias !== null && strcasecmp($existingAlias, $alias) === 0) {
                throw new WorkspaceException(
                    "Cannot use alias [{$alias}]: it conflicts with the alias of existing package [{$existingName}].",
                    "Choose a different alias or rename the existing package [{$existingName}] first."
                );
            }
        }

        foreach ($wsPackages as $item) {
            $existingName = is_array($item) ? $item['name'] : (string) $item;
            if ($existingName === $packageName || $existingName === $alias) {
                if (is_array($item)) {
                    $existingSkills = $item['skills'] ?? null;
                    $existingUrl = $item['url'] ?? null;
                }

                continue;
            }
            $newPackages[] = $item;
        }

        $entry = [
            'name' => $packageName,
            'alias' => $alias,
        ];
        if ($existingUrl !== null) {
            $entry['url'] = $existingUrl;
        }
        if (! empty($existingSkills)) {
            $entry['skills'] = $existingSkills;
        }

        $newPackages[] = $entry;

        usort($newPackages, function ($a, $b) {
            $nameA = is_array($a) ? ($a['alias'] ?? $a['name']) : $a;
            $nameB = is_array($b) ? ($b['alias'] ?? $b['name']) : $b;

            return strcasecmp($nameA, $nameB);
        });

        $data['workspaces'][$cleanWorkspace]['packages'] = $newPackages;
        $this->save($data);
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

        $wsPackages = $data['workspaces'][$cleanWorkspace]['packages'];
        $vendor = $data['workspaces'][$cleanWorkspace]['vendor'] ?? null;
        $newPackages = [];
        $found = false;

        $targets = [strtolower($packageName)];
        if ($vendor !== null) {
            $prefix = strtolower($vendor).'/';
            if (str_starts_with(strtolower($packageName), $prefix)) {
                $targets[] = substr(strtolower($packageName), strlen($prefix));
            } else {
                $targets[] = strtolower("{$vendor}/{$packageName}");
            }
        } elseif (str_contains($packageName, '/')) {
            [, $shortName] = explode('/', $packageName, 2);
            $targets[] = strtolower($shortName);
        }

        foreach ($wsPackages as $item) {
            $existingName = is_array($item) ? $item['name'] : (string) $item;
            $existingAlias = is_array($item) ? ($item['alias'] ?? null) : null;

            $isMatch = in_array(strtolower($existingName), $targets, true)
                || ($existingAlias !== null && in_array(strtolower($existingAlias), $targets, true));

            if ($isMatch) {
                $found = true;

                continue;
            }

            $newPackages[] = $item;
        }

        if ($found) {
            $data['workspaces'][$cleanWorkspace]['packages'] = $newPackages;
            $this->save($data);
        }

        return $found;
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

        $wsPackages = $data['workspaces'][$cleanWorkspace]['packages'];
        $newPackages = [];

        // F-03: Pre-mutation validation for unified namespace
        foreach ($wsPackages as $item) {
            $existingName = is_array($item) ? $item['name'] : (string) $item;
            $existingAlias = is_array($item) ? ($item['alias'] ?? null) : null;

            if ($existingName !== $packageName) {
                if ($existingAlias !== null && strcasecmp($existingAlias, $packageName) === 0) {
                    throw new WorkspaceException(
                        "Cannot record package [{$packageName}]: it conflicts with the alias of existing package [{$existingName}].",
                        'Choose a different package name or update the existing package alias first.'
                    );
                }

                if ($alias !== null) {
                    if (strcasecmp($existingName, $alias) === 0) {
                        throw new WorkspaceException(
                            "Cannot use alias [{$alias}]: it conflicts with the name of existing package [{$existingName}].",
                            "Choose a different alias or rename the existing package [{$alias}] first."
                        );
                    }

                    if ($existingAlias !== null && strcasecmp($existingAlias, $alias) === 0) {
                        throw new WorkspaceException(
                            "Cannot use alias [{$alias}]: it conflicts with the alias of existing package [{$existingName}].",
                            'Choose a different alias or update the existing package alias first.'
                        );
                    }
                }
            }
        }

        $existingSkills = null;

        foreach ($wsPackages as $item) {
            $existingName = is_array($item) ? $item['name'] : (string) $item;
            $existingAlias = is_array($item) ? ($item['alias'] ?? null) : null;
            if ($existingName === $packageName || ($alias !== null && $existingAlias === $alias)) {
                if (is_array($item)) {
                    $existingSkills = $item['skills'] ?? null;
                }

                continue;
            }
            $newPackages[] = $item;
        }

        if ($alias !== null || $url !== null || ! empty($existingSkills)) {
            $entry = ['name' => $packageName];
            if ($alias !== null) {
                $entry['alias'] = $alias;
            }
            if ($url !== null) {
                $entry['url'] = $url;
            }
            if (! empty($existingSkills)) {
                $entry['skills'] = $existingSkills;
            }
            $newPackages[] = $entry;
        } else {
            $newPackages[] = $packageName;
        }

        usort($newPackages, function ($a, $b) {
            $nameA = is_array($a) ? ($a['alias'] ?? $a['name']) : $a;
            $nameB = is_array($b) ? ($b['alias'] ?? $b['name']) : $b;

            return strcasecmp($nameA, $nameB);
        });

        $data['workspaces'][$cleanWorkspace]['packages'] = $newPackages;
        $this->save($data);
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

        $wsPackages = $data['workspaces'][$cleanWorkspace]['packages'];
        $newPackages = [];
        $found = false;

        foreach ($wsPackages as $item) {
            $existingName = is_array($item) ? $item['name'] : (string) $item;
            if ($existingName === $packageName) {
                $found = true;
                $entry = is_array($item) ? $item : ['name' => $packageName];
                if (! empty($skills)) {
                    $entry['skills'] = array_values(array_unique($skills));
                } elseif (isset($entry['skills'])) {
                    unset($entry['skills']);
                }

                // If only name remains, flatten to string if preferred, or keep as array
                if (count($entry) === 1) {
                    $newPackages[] = $packageName;
                } else {
                    $newPackages[] = $entry;
                }
            } else {
                $newPackages[] = $item;
            }
        }

        if (! $found && ! empty($skills)) {
            $newPackages[] = [
                'name' => $packageName,
                'skills' => array_values(array_unique($skills)),
            ];
        }

        $data['workspaces'][$cleanWorkspace]['packages'] = $newPackages;
        $this->save($data);
    }

    /**
     * Normalize and validate workspace path.
     *
     * @throws InvalidWorkspacePathException
     */
    public function normalizeWorkspacePath(string $path): string
    {
        $trimmed = trim($path);

        if ($trimmed === '' || $trimmed === '.' || $trimmed === './') {
            throw new InvalidWorkspacePathException($path, 'Workspace path cannot be empty or root directory.');
        }

        $normalized = str_replace('\\', '/', $trimmed);

        if (str_starts_with($normalized, '/') || preg_match('/^[a-zA-Z]:[\\\\\/]/', $trimmed)) {
            throw new InvalidWorkspacePathException($path, 'Absolute paths are not allowed. Workspace must be a path relative to application root.');
        }

        $segments = explode('/', $normalized);
        foreach ($segments as $segment) {
            if ($segment === '..') {
                throw new InvalidWorkspacePathException($path, 'Path traversal ("..") is not allowed.');
            }
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if (! preg_match('/^[a-zA-Z0-9_\-\.]+$/', $segment)) {
                throw new InvalidWorkspacePathException($path, "Invalid path segment [{$segment}]. Only alphanumeric characters, dashes, underscores, and dots are allowed.");
            }
        }

        return trim(preg_replace('#/+#', '/', $normalized) ?? '', '/');
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

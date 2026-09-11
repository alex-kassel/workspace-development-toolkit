<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\ComposerProcessException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\DefaultWorkspaceNotConfiguredException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\InvalidJsonException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceNotFoundException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use JsonException;

class WorkspaceManager
{
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
     * @return array{default: ?string, workspaces: array<string, array{vendor: ?string, packages: array<int, string>}>}
     */
    public function load(): array
    {
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

        return [
            'default' => $data['default'] ?? null,
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
     * Find relative directory path for a given package name.
     * Supports both short name (if belonging to fixed vendor workspace) and canonical vendor/package name.
     */
    public function findPackagePath(string $packageName): ?string
    {
        $packageName = trim($packageName);
        $workspaces = $this->all();

        foreach ($workspaces as $workspace => $config) {
            $vendor = $config['vendor'] ?? null;

            // Determine if packageName matches directly or with fixed vendor prefix
            $targetCanonicalName = $packageName;
            if ($vendor !== null && ! str_contains($packageName, '/')) {
                $targetCanonicalName = "{$vendor}/{$packageName}";
            }

            $files = array_merge(
                File::glob(base_path("{$workspace}/*/*/composer.json")) ?: [],
                File::glob(base_path("{$workspace}/*/composer.json")) ?: []
            );

            foreach ($files as $file) {
                try {
                    $json = json_decode(File::get($file), true, 512, JSON_THROW_ON_ERROR);
                    $name = $json['name'] ?? '';
                    if ($name === $targetCanonicalName || $name === $packageName) {
                        return trim(str_replace([base_path(), '\\'], ['', '/'], dirname($file)), '/');
                    }
                } catch (JsonException $e) {
                    throw new InvalidJsonException($file, "Corrupted package manifest: {$e->getMessage()}", $e);
                }
            }
        }

        return null;
    }

    /**
     * Resolve full canonical vendor/package name.
     */
    public function resolveCanonicalPackageName(string $packageName, ?string $workspace = null): string
    {
        $packageName = trim($packageName);

        if (str_contains($packageName, '/')) {
            return $packageName;
        }

        if ($workspace !== null) {
            $vendor = $this->getWorkspaceVendor($workspace);
            if ($vendor !== null) {
                return "{$vendor}/{$packageName}";
            }
        }

        // Search in all registered workspaces
        foreach ($this->all() as $ws => $config) {
            $vendor = $config['vendor'] ?? null;
            if ($vendor !== null && in_array($packageName, $config['packages'], true)) {
                return "{$vendor}/{$packageName}";
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
        $path = trim(preg_replace('#[/\\\\]+#', '/', $path) ?? '', '/');
        $vendor = $vendor ? trim($vendor) : null;

        File::ensureDirectoryExists(base_path($path));

        $this->addToGitignore($path);
        $this->registerInComposer($path, $vendor !== null);

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

        foreach ($composer['repositories'] ?? [] as $repo) {
            if (($repo['type'] ?? '') === 'path' && isset($repo['url'])) {
                $trimmedUrl = trim($repo['url'], '/\\');
                $parts = explode('/', $trimmedUrl);
                // Strip wildcards
                $dir = implode('/', array_filter($parts, fn ($p) => $p !== '*'));
                if ($dir !== '') {
                    $registered[$dir] = true;
                    $this->addToGitignore($dir);
                }
            }
        }

        $currentDefault = null;
        $existingVendors = [];

        if (File::exists($this->workspaceJsonPath())) {
            $existing = $this->readJsonFile($this->workspaceJsonPath());
            $currentDefault = $existing['default'] ?? null;
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
            'workspaces' => $workspaces,
        ];

        $this->save($result);

        return $result;
    }

    /**
     * Scan a workspace directory for package names.
     *
     * @return array<int, string>
     */
    public function scanPackages(string $workspace, ?string $vendor = null): array
    {
        $packages = [];
        $files = array_merge(
            File::glob(base_path("{$workspace}/*/*/composer.json")) ?: [],
            File::glob(base_path("{$workspace}/*/composer.json")) ?: []
        );

        foreach ($files as $file) {
            try {
                $json = json_decode(File::get($file), true, 512, JSON_THROW_ON_ERROR);
                $name = $json['name'] ?? null;
                if (! empty($name)) {
                    if ($vendor !== null) {
                        // In fixed vendor workspace, strip vendor/ prefix for packages list
                        if (str_starts_with($name, "{$vendor}/")) {
                            $packages[] = substr($name, strlen("{$vendor}/"));
                        } else {
                            $packages[] = $name;
                        }
                    } else {
                        $packages[] = $name;
                    }
                }
            } catch (JsonException $e) {
                throw new InvalidJsonException($file, "Corrupted package manifest: {$e->getMessage()}", $e);
            }
        }

        sort($packages);

        return array_values(array_unique($packages));
    }

    /**
     * Save data to workspace.json.
     *
     * @param  array{default: ?string, workspaces: array<string, array{vendor: ?string, packages: array<int, string>}>}  $data
     */
    public function save(array $data): void
    {
        ksort($data['workspaces']);
        File::put($this->workspaceJsonPath(), json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
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

        Process::path(base_path())->run([
            'composer', 'config', '--unset', "repositories.{$repoKey}",
        ]);

        // Also try unsanitized key in case it was stored directly
        if ($safePath !== $path) {
            Process::path(base_path())->run([
                'composer', 'config', '--unset', "repositories.workspace-{$path}",
            ]);
        }

        $composer = $this->readJsonFile($this->composerJsonPath());
        if (! empty($composer['repositories'])) {
            $filtered = array_values(array_filter(
                $composer['repositories'],
                fn ($repo) => ! (($repo['type'] ?? '') === 'path' && (str_starts_with($repo['url'] ?? '', "{$path}/") || ($repo['name'] ?? '') === $repoKey))
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
}

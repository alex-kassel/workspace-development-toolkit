<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\ComposerProcessException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\InvalidJsonException;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;

class ComposerManager
{
    /**
     * Synchronize path repositories in root composer.json with registered workspaces.
     * Returns true if composer.json was modified, false if already up-to-date.
     *
     * @param  array<string, array{vendor: ?string, packages: array<int, string|array{name: string, alias?: string, url?: string}>}>  $workspaces
     */
    public function syncRepositories(array $workspaces): bool
    {
        $composerPath = base_path('composer.json');
        if (! File::exists($composerPath)) {
            return false;
        }

        $content = File::get($composerPath);
        try {
            $composer = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidJsonException(
                $composerPath,
                "Failed to parse composer.json: {$e->getMessage()}",
                $e
            );
        }

        if (! is_array($composer)) {
            throw new InvalidJsonException(
                $composerPath,
                'composer.json must be a valid JSON object'
            );
        }

        $existingRepos = $composer['repositories'] ?? [];

        // Determine if existing repositories was an associative dictionary (e.g. {"packagist.org": false})
        $isAssociative = is_array($existingRepos) && ! array_is_list($existingRepos);

        $newRepos = [];

        // Keep non-workspace repositories intact
        if (is_array($existingRepos)) {
            foreach ($existingRepos as $key => $repo) {
                // If repo is a path repo managed by workspace toolkit, skip it
                if (is_array($repo) && isset($repo['name']) && str_starts_with((string) $repo['name'], 'workspace-')) {
                    continue;
                }
                if ($isAssociative) {
                    $newRepos[$key] = $repo;
                } else {
                    $newRepos[] = $repo;
                }
            }
        }

        // Add repository definitions for all configured workspaces
        foreach ($workspaces as $wsPath => $config) {
            $vendor = $config['vendor'] ?? null;
            $repoName = 'workspace-'.str_replace(['/', '\\'], '-', $wsPath);
            $urlPattern = $vendor !== null ? "{$wsPath}/*" : "{$wsPath}/*/*";

            $definition = [
                'name' => $repoName,
                'type' => 'path',
                'url' => $urlPattern,
            ];

            if ($isAssociative) {
                $newRepos[$repoName] = $definition;
            } else {
                $newRepos[] = $definition;
            }
        }

        $targetRepositories = empty($newRepos) ? (object) [] : $newRepos;

        if ($this->areRepositoriesEquivalent($existingRepos, $newRepos)) {
            return false;
        }

        $composer['repositories'] = $targetRepositories;
        File::put($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", true);

        return true;
    }

    /**
     * Determine if existing and computed repositories are equivalent.
     */
    protected function areRepositoriesEquivalent(mixed $existing, mixed $new): bool
    {
        if (! is_array($existing) && ! is_array($new)) {
            return $existing === $new;
        }

        return json_encode($existing) === json_encode($new);
    }

    /**
     * Ensure root composer.json has pre-install and pre-update hooks for standalone workspace restore.
     */
    public function ensureComposerHooks(): void
    {
        $composerPath = base_path('composer.json');
        if (! File::exists($composerPath)) {
            return;
        }

        $content = File::get($composerPath);
        try {
            $composer = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidJsonException(
                $composerPath,
                "Failed to parse composer.json: {$e->getMessage()}",
                $e
            );
        }

        if (! is_array($composer)) {
            throw new InvalidJsonException(
                $composerPath,
                'composer.json must be a valid JSON object'
            );
        }

        $scripts = $composer['scripts'] ?? [];
        $modified = false;

        $targetHooks = [
            'pre-install-cmd' => 'php workspace restore',
            'pre-update-cmd' => 'php workspace restore',
        ];

        foreach ($targetHooks as $hookName => $targetHook) {
            $current = isset($scripts[$hookName])
                ? (is_array($scripts[$hookName]) ? $scripts[$hookName] : [$scripts[$hookName]])
                : [];

            if (! in_array($targetHook, $current, true)) {
                $current[] = $targetHook;
                $scripts[$hookName] = $current;
                $modified = true;
            }
        }

        if ($modified) {
            $composer['scripts'] = $scripts;
            File::put($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", true);
        }
    }

    /**
     * Ensure the standalone `workspace` CLI script exists in the root directory.
     */
    public function ensureWorkspaceScript(): void
    {
        $targetPath = base_path('workspace');
        $stubPath = __DIR__.'/../../stubs/workspace.stub';

        if (! File::exists($stubPath)) {
            return;
        }

        $stubContent = File::get($stubPath);

        if (! File::exists($targetPath) || File::get($targetPath) !== $stubContent) {
            File::put($targetPath, $stubContent);
            @chmod($targetPath, 0755);
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
                            if (isset($pkg['install-path'])) {
                                $pkg['install-path'] = '../../'.$newRelPath;
                            }
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

        // 3. Update vendor/composer/installed.php if present
        $installedPhpFile = base_path('vendor/composer/installed.php');
        if (File::exists($installedPhpFile)) {
            $installedPhpContent = File::get($installedPhpFile);
            if (str_contains($installedPhpContent, $oldRelPath)) {
                $installedPhpContent = str_replace($oldRelPath, $newRelPath, $installedPhpContent);
                File::put($installedPhpFile, $installedPhpContent);
            }
        }
    }

    /**
     * Run Composer command.
     *
     * @param  array<int, string>  $args
     *
     * @throws ComposerProcessException
     */
    public function runComposer(array $args, ?int $timeout = null): ProcessResult
    {
        $timeout ??= (int) config('workspace.process_timeout', 300);
        $command = array_merge(['composer'], $args);
        $result = Process::timeout($timeout)->path(base_path())->run($command);

        if (! $result->successful()) {
            throw new ComposerProcessException(
                implode(' ', $command),
                $result->exitCode() ?? 1,
                $result->errorOutput() ?: $result->output()
            );
        }

        return $result;
    }

    /**
     * Ensure root composer.json has minimum-stability set to dev and prefer-stable set to true.
     * This is required for Composer to resolve local workspace packages and their inter-dependencies.
     */
    public function ensureMinimumStability(): bool
    {
        $composerPath = base_path('composer.json');
        if (! File::exists($composerPath)) {
            return false;
        }

        $content = File::get($composerPath);
        try {
            $composer = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new InvalidJsonException(
                $composerPath,
                "Failed to parse composer.json: {$e->getMessage()}",
                $e
            );
        }

        if (! is_array($composer)) {
            throw new InvalidJsonException(
                $composerPath,
                'composer.json must be a valid JSON object'
            );
        }

        $modified = false;

        if (($composer['minimum-stability'] ?? null) !== 'dev') {
            $composer['minimum-stability'] = 'dev';
            $modified = true;
        }

        if (($composer['prefer-stable'] ?? null) !== true) {
            $composer['prefer-stable'] = true;
            $modified = true;
        }

        if ($modified) {
            File::put($composerPath, json_encode($composer, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", true);
        }

        return $modified;
    }

    /**
     * Get requirement section ('require' or 'require-dev') for a package in root composer.json, or null if not required.
     */
    public function getRequirementType(string $package): ?string
    {
        $composerPath = base_path('composer.json');
        if (! File::exists($composerPath)) {
            return null;
        }

        try {
            $composer = json_decode(File::get($composerPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($composer)) {
            return null;
        }

        if (isset($composer['require-dev'][$package])) {
            return 'require-dev';
        }

        if (isset($composer['require'][$package])) {
            return 'require';
        }

        return null;
    }

    /**
     * Determine if a package is required in root composer.json (either require or require-dev).
     */
    public function isInstalled(string $package): bool
    {
        return $this->getRequirementType($package) !== null;
    }

    /**
     * Remove multiple dependencies from root composer.json in batch.
     *
     * @param  array<string, string>|array<int, string>  $packages  List of package names or map of [package => 'require'|'require-dev']
     *
     * @throws ComposerProcessException
     */
    public function removeDependencies(array $packages): void
    {
        $requirePkgs = [];
        $devPkgs = [];

        foreach ($packages as $key => $value) {
            $pkg = is_string($key) && ($value === 'require' || $value === 'require-dev') ? $key : (string) $value;
            $type = ($value === 'require' || $value === 'require-dev') ? $value : $this->getRequirementType($pkg);

            if ($type === 'require') {
                $requirePkgs[] = $pkg;
            } elseif ($type === 'require-dev') {
                $devPkgs[] = $pkg;
            }
        }

        if (! empty($requirePkgs)) {
            $this->runComposer(array_merge(['remove'], array_values(array_unique($requirePkgs))));
        }

        if (! empty($devPkgs)) {
            $this->runComposer(array_merge(['remove'], array_values(array_unique($devPkgs)), ['--dev']));
        }
    }
}

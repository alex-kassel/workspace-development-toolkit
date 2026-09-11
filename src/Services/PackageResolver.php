<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\AmbiguousPackageException;
use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\InvalidJsonException;
use Illuminate\Support\Facades\File;
use JsonException;
use Throwable;

class PackageResolver
{
    /**
     * In-memory cache for package path index across workspaces.
     *
     * @var array<string, array<string, array{relPath: string, canonicalName: string, shortName: string, dirName: string, vendor: ?string, workspace: string, alias: ?string}>>|null
     */
    protected ?array $packagesPathCache = null;

    public function __construct(
        protected ManifestRepository $manifest
    ) {}

    /**
     * Clear in-memory package path index cache.
     */
    public function clearCache(): void
    {
        $this->packagesPathCache = null;
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

        // Read existing alias map if available from manifest
        $existingAliases = [];
        try {
            $currentData = $this->manifest->load();
            $configured = $currentData['workspaces'][$workspace]['packages'] ?? [];
            foreach ($configured as $item) {
                if (is_array($item) && isset($item['name'], $item['alias'])) {
                    $existingAliases[$item['name']] = $item['alias'];
                    $existingAliases[$item['alias']] = $item['alias'];
                }
            }
        } catch (Throwable) {
            // Ignore errors during scan
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
     * Find relative directory path for a given package name or alias.
     * Supports canonical name (vendor/package), short name, and custom alias.
     * Uses in-memory index to avoid redundant disk scanning.
     *
     * @throws AmbiguousPackageException
     */
    public function findPackagePath(string $packageName, ?string $workspace = null): ?string
    {
        $packageName = trim($packageName);
        $workspaces = $this->manifest->all();

        // Scope to single workspace if requested
        if ($workspace !== null) {
            $normalizedWs = $this->manifest->normalizeWorkspacePath($workspace);
            if (isset($workspaces[$normalizedWs])) {
                $workspaces = [$normalizedWs => $workspaces[$normalizedWs]];
            }
        }

        $allPackages = $this->getPackageIndex();
        $matches = [];

        foreach ($workspaces as $ws => $config) {
            $wsPackages = $allPackages[$ws] ?? [];
            $vendor = $config['vendor'] ?? null;

            $aliasMap = [];
            foreach ($config['packages'] ?? [] as $pkgItem) {
                if (is_array($pkgItem) && isset($pkgItem['name'], $pkgItem['alias'])) {
                    $aliasMap[strtolower($pkgItem['alias'])] = $pkgItem['name'];
                }
            }

            foreach ($wsPackages as $pkg) {
                $canonicalName = $pkg['canonicalName'];
                $dirName = $pkg['dirName'];
                $relPath = $pkg['relPath'];
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

        // Find package path in workspaces
        $path = $this->findPackagePath($packageName, $workspace);
        if ($path !== null) {
            $allPackages = $this->getPackageIndex();
            foreach ($allPackages as $wsPackages) {
                if (isset($wsPackages[$path])) {
                    return $wsPackages[$path]['canonicalName'];
                }
            }
        }

        if (str_contains($packageName, '/')) {
            return $packageName;
        }

        // Check if requested workspace or default workspace has a fixed vendor
        $ws = $workspace ?: $this->manifest->getDefault();
        if ($ws !== null) {
            $vendor = $this->manifest->getWorkspaceVendor($ws);
            if ($vendor !== null) {
                return "{$vendor}/{$packageName}";
            }
        }

        return $packageName;
    }

    /**
     * Find any other packages matching a given alias/name across workspaces.
     *
     * @return array<int, string>
     */
    public function findDuplicateAliases(string $alias, ?string $excludePath = null): array
    {
        $duplicates = [];
        $allPackages = $this->getPackageIndex();

        $normalizedExclude = $excludePath !== null
            ? trim(str_replace(['\\', '//'], '/', $excludePath), '/')
            : null;

        foreach ($allPackages as $ws => $packages) {
            foreach ($packages as $pkg) {
                $relPath = $pkg['relPath'];
                if ($normalizedExclude !== null && strcasecmp($relPath, $normalizedExclude) === 0) {
                    continue;
                }

                $dirName = $pkg['dirName'];
                $canonicalName = $pkg['canonicalName'];
                $shortName = $pkg['shortName'];

                if (strcasecmp($dirName, $alias) === 0 || strcasecmp($shortName, $alias) === 0 || strcasecmp($canonicalName, $alias) === 0) {
                    $duplicates[] = "{$relPath} ({$canonicalName})";
                }
            }
        }

        return array_values(array_unique($duplicates));
    }

    /**
     * Build or return cached in-memory index of all packages across all workspaces.
     *
     * @return array<string, array<string, array{relPath: string, canonicalName: string, shortName: string, dirName: string, vendor: ?string, workspace: string, alias: ?string}>>
     */
    protected function getPackageIndex(): array
    {
        if ($this->packagesPathCache !== null) {
            return $this->packagesPathCache;
        }

        $index = [];
        $workspaces = $this->manifest->all();

        foreach ($workspaces as $ws => $config) {
            $vendor = $config['vendor'] ?? null;
            $files = $vendor !== null
                ? (File::glob(base_path("{$ws}/*/composer.json")) ?: [])
                : (File::glob(base_path("{$ws}/*/*/composer.json")) ?: []);

            $index[$ws] = [];

            foreach ($files as $file) {
                try {
                    $json = json_decode(File::get($file), true, 512, JSON_THROW_ON_ERROR);
                    $canonicalName = $json['name'] ?? '';
                    $dirName = basename(dirname($file));
                    $relPath = trim(str_replace([base_path(), '\\'], ['', '/'], dirname($file)), '/');
                    $shortName = str_starts_with($canonicalName, "{$vendor}/")
                        ? substr($canonicalName, strlen("{$vendor}/"))
                        : (str_contains($canonicalName, '/') ? explode('/', $canonicalName, 2)[1] : $canonicalName);

                    $index[$ws][$relPath] = [
                        'relPath' => $relPath,
                        'canonicalName' => $canonicalName,
                        'shortName' => $shortName,
                        'dirName' => $dirName,
                        'vendor' => $vendor,
                        'workspace' => $ws,
                        'alias' => strcasecmp($dirName, $shortName) !== 0 ? $dirName : null,
                    ];
                } catch (JsonException $e) {
                    throw new InvalidJsonException($file, "Corrupted package manifest: {$e->getMessage()}", $e);
                }
            }
        }

        $this->packagesPathCache = $index;

        return $this->packagesPathCache;
    }
}

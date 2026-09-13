<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\AmbiguousPackageException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
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

        // Read existing configured packages map from manifest to preserve URLs, offline packages, etc.
        $configuredPackages = [];
        try {
            $currentData = $this->manifest->load();
            $configured = $currentData['workspaces'][$workspace]['packages'] ?? [];
            foreach ($configured as $item) {
                $pkgName = is_array($item) ? $item['name'] : (string) $item;
                if ($pkgName !== '') {
                    $configuredPackages[$pkgName] = $item;
                    if (is_array($item) && isset($item['alias'])) {
                        $configuredPackages[$item['alias']] = $item;
                    }
                }
            }
        } catch (Throwable) {
            // Ignore errors during scan
        }

        $discoveredNames = [];

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

                        $existing = $configuredPackages[$baseShort] ?? $configuredPackages[$dirName] ?? null;
                        $existingAlias = is_array($existing) ? ($existing['alias'] ?? null) : null;
                        $existingUrl = is_array($existing) ? ($existing['url'] ?? null) : null;
                        $existingSkills = is_array($existing) ? ($existing['skills'] ?? null) : null;

                        $discoveredNames[$baseShort] = true;
                        if ($existingAlias !== null) {
                            $discoveredNames[$existingAlias] = true;
                        }

                        $effectiveAlias = (strcasecmp($dirName, $baseShort) !== 0) ? $dirName : $existingAlias;

                        if ($effectiveAlias !== null || $existingUrl !== null || ! empty($existingSkills)) {
                            $entry = ['name' => $baseShort];
                            if ($effectiveAlias !== null) {
                                $entry['alias'] = $effectiveAlias;
                            }
                            if ($existingUrl !== null) {
                                $entry['url'] = $existingUrl;
                            }
                            if (! empty($existingSkills)) {
                                $entry['skills'] = $existingSkills;
                            }
                            $packages[] = $entry;
                        } else {
                            $packages[] = $baseShort;
                        }
                    } else {
                        $existing = $configuredPackages[$name] ?? null;
                        $existingUrl = is_array($existing) ? ($existing['url'] ?? null) : null;
                        $existingSkills = is_array($existing) ? ($existing['skills'] ?? null) : null;

                        $discoveredNames[$name] = true;

                        if ($existingUrl !== null || ! empty($existingSkills)) {
                            $entry = ['name' => $name];
                            if ($existingUrl !== null) {
                                $entry['url'] = $existingUrl;
                            }
                            if (! empty($existingSkills)) {
                                $entry['skills'] = $existingSkills;
                            }
                            $packages[] = $entry;
                        } else {
                            $packages[] = $name;
                        }
                    }
                }
            } catch (JsonException $e) {
                Log::warning("WDT: Skipping corrupted composer.json [{$file}]: {$e->getMessage()}");

                continue; // Skip this file, proceed with remaining packages
            }
        }

        // Retain previously configured packages that may temporarily be offline/missing from disk
        foreach ($configuredPackages as $key => $item) {
            $name = is_array($item) ? $item['name'] : (string) $item;
            if ($name === '' || isset($discoveredNames[$name]) || ($key !== $name && isset($discoveredNames[$key]))) {
                continue;
            }
            $packages[] = $item;
            $discoveredNames[$name] = true;
            if (is_array($item) && isset($item['alias'])) {
                $discoveredNames[$item['alias']] = true;
            }
        }

        usort($packages, function ($a, $b) {
            $nameA = is_array($a) ? ($a['alias'] ?? $a['name']) : $a;
            $nameB = is_array($b) ? ($b['alias'] ?? $b['name']) : $b;

            return strcasecmp($nameA, $nameB);
        });

        return $packages;
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
            foreach ($config['packages'] as $pkgItem) {
                if (is_array($pkgItem) && isset($pkgItem['alias'])) {
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
     * Determine if a package directory has a corrupted composer.json manifest.
     */
    public function isPackageCorrupted(string $packageName, ?string $workspace = null): bool
    {
        $path = $this->findPackagePath($packageName, $workspace);
        if ($path === null) {
            return false;
        }

        $allPackages = $this->getPackageIndex();
        foreach ($allPackages as $wsPackages) {
            if (isset($wsPackages[$path])) {
                return ! empty($wsPackages[$path]['corrupted']);
            }
        }

        return false;
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
     * @return array<string, array<string, array{relPath: string, canonicalName: string, shortName: string, dirName: string, vendor: ?string, workspace: string, alias: ?string, corrupted?: bool}>>
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
                    Log::warning(
                        "WDT: Skipping corrupted composer.json [{$file}]: {$e->getMessage()}"
                    );

                    $dirName = basename(dirname($file));
                    $relPath = trim(str_replace([base_path(), '\\'], ['', '/'], dirname($file)), '/');
                    $vendorPrefix = $vendor ?? (dirname(dirname($file)) !== base_path($ws) ? basename(dirname(dirname($file))) : null);
                    $canonicalName = $vendorPrefix !== null ? "{$vendorPrefix}/{$dirName}" : $dirName;
                    $shortName = $dirName;

                    $index[$ws][$relPath] = [
                        'relPath' => $relPath,
                        'canonicalName' => $canonicalName,
                        'shortName' => $shortName,
                        'dirName' => $dirName,
                        'vendor' => $vendor,
                        'workspace' => $ws,
                        'alias' => null,
                        'corrupted' => true,
                    ];

                    continue;
                }
            }
        }

        $this->packagesPathCache = $index;

        return $this->packagesPathCache;
    }

    /**
     * Format URL according to preferred protocol (SSH vs HTTPS).
     */
    public function formatUrlProtocol(string $url, bool $useSsh): string
    {
        if ($useSsh) {
            // If https://github.com/vendor/package.git -> git@github.com:vendor/package.git
            if (preg_match('#^https?://github\.com/([^/]+)/([^/]+?)(?:\.git)?$#i', $url, $matches)) {
                return "git@github.com:{$matches[1]}/{$matches[2]}.git";
            }
        } else {
            // If git@github.com:vendor/package.git -> https://github.com/vendor/package.git
            if (preg_match('#^git@github\.com:([^/]+)/([^/]+?)(?:\.git)?$#i', $url, $matches)) {
                return "https://github.com/{$matches[1]}/{$matches[2]}.git";
            }
        }

        return $url;
    }

    /**
     * Parse vendor and package names from repository URL or shorthand.
     *
     * @return array{0: ?string, 1: ?string}
     */
    public function parseRepoVendorAndPackage(string $url): array
    {
        // git@github.com:vendor/package.git or https://github.com/vendor/package.git
        if (preg_match('#[:/]([a-zA-Z0-9_.-]+)/([a-zA-Z0-9_.-]+?)(?:\.git)?$#', $url, $matches)) {
            return [$matches[1], $matches[2]];
        }

        return [null, null];
    }

    /**
     * Normalize repository string (e.g. "vendor/package" shorthand -> GitHub URL).
     */
    public function normalizeRepositoryUrl(string $repo, bool $useSsh): string
    {
        $repo = trim($repo);

        // Standard Git or SSH URL
        if (str_starts_with($repo, 'git@') || str_starts_with($repo, 'http://') || str_starts_with($repo, 'https://') || str_starts_with($repo, 'ssh://')) {
            return $this->formatUrlProtocol($repo, $useSsh);
        }

        // Shorthand vendor/package: resolve via repository_template
        if (preg_match('#^[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+$#', $repo)) {
            $template = $this->manifest->getRepositoryTemplate();
            $url = str_replace('{package}', $repo, $template);

            return $this->formatUrlProtocol($url, $useSsh);
        }

        return $repo;
    }

    /**
     * Resolve the git repository URL of the workspace toolkit itself.
     */
    public function resolveSelfRepositoryUrl(bool $useSsh): string
    {
        $packageDir = dirname(__DIR__, 2);

        // 1. Try reading from git config inside package directory
        if (File::isDirectory("{$packageDir}/.git")) {
            $gitRemote = Process::path($packageDir)->run(['git', 'config', '--get', 'remote.origin.url']);
            if ($gitRemote->successful() && trim($gitRemote->output()) !== '') {
                $url = trim($gitRemote->output());

                return $this->formatUrlProtocol($url, $useSsh);
            }
        }

        // 2. Try composer installed.json in root
        $installedJsonPath = base_path('vendor/composer/installed.json');
        if (File::exists($installedJsonPath)) {
            $installed = json_decode(File::get($installedJsonPath), true);
            $packages = $installed['packages'] ?? $installed;
            foreach ($packages as $pkg) {
                if (($pkg['name'] ?? '') === 'alex-kassel/workspace-development-toolkit') {
                    $sourceUrl = $pkg['source']['url'] ?? null;
                    if ($sourceUrl) {
                        return $this->formatUrlProtocol($sourceUrl, $useSsh);
                    }
                }
            }
        }

        // 3. Fallback to package's own composer.json name on GitHub
        $composerJsonPath = "{$packageDir}/composer.json";
        $pkgName = 'alex-kassel/workspace-development-toolkit';
        if (File::exists($composerJsonPath)) {
            $data = json_decode(File::get($composerJsonPath), true);
            $pkgName = $data['name'] ?? $pkgName;
        }

        return $useSsh
            ? "git@github.com:{$pkgName}.git"
            : "https://github.com/{$pkgName}.git";
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
        $normalized = str_replace('\\', '/', trim($input));
        $segmentPattern = '/^[a-z0-9]([_.-]?[a-z0-9]+)*$/';

        if (str_contains($normalized, '/')) {
            [$rawVendor, $rawPackage] = explode('/', $normalized, 2);
            $rawVendor = trim($rawVendor);
            $rawPackage = trim($rawPackage);

            $vendor = strtolower($rawVendor);
            $package = strtolower($rawPackage);

            $validVendor = (bool) preg_match($segmentPattern, $rawVendor);
            $validPackage = (bool) preg_match($segmentPattern, $rawPackage);

            if (! $validVendor || ! $validPackage) {
                $suggestedVendor = Str::slug($vendor);
                $suggestedPackage = Str::slug($package);

                return [
                    'vendorName' => $vendor,
                    'packageName' => $package,
                    'vendor' => $vendor,
                    'package' => $package,
                    'fullName' => "{$vendor}/{$package}",
                    'isValid' => false,
                    'error' => "Invalid package name [{$input}]. Composer vendor and package names must contain only lowercase letters, numbers, dashes, underscores, and dots.",
                    'suggestion' => "{$suggestedVendor}/{$suggestedPackage}",
                ];
            }

            return [
                'vendorName' => $vendor,
                'packageName' => $package,
                'vendor' => $vendor,
                'package' => $package,
                'fullName' => "{$vendor}/{$package}",
                'isValid' => true,
                'error' => null,
                'suggestion' => null,
            ];
        }

        // Single segment input
        $rawPackage = trim($normalized);
        $package = strtolower($rawPackage);
        $validPackage = (bool) preg_match($segmentPattern, $rawPackage);

        if ($workspaceVendor !== null) {
            $rawWsVendor = trim($workspaceVendor);
            $vendor = strtolower($rawWsVendor);
            $validWsVendor = (bool) preg_match($segmentPattern, $rawWsVendor);

            if (! $validPackage || ! $validWsVendor) {
                $suggested = Str::slug($package);

                return [
                    'vendorName' => $vendor,
                    'packageName' => $package,
                    'vendor' => $vendor,
                    'package' => $package,
                    'fullName' => "{$vendor}/{$package}",
                    'isValid' => false,
                    'error' => "Invalid package name [{$input}]. Composer package names must contain only lowercase letters, numbers, dashes, underscores, and dots.",
                    'suggestion' => $suggested,
                ];
            }

            return [
                'vendorName' => $vendor,
                'packageName' => $package,
                'vendor' => $vendor,
                'package' => $package,
                'fullName' => "{$vendor}/{$package}",
                'isValid' => true,
                'error' => null,
                'suggestion' => null,
            ];
        }

        if (! $validPackage) {
            $suggested = Str::slug($package);

            return [
                'vendorName' => '',
                'packageName' => $package,
                'vendor' => '',
                'package' => $package,
                'fullName' => $package,
                'isValid' => false,
                'error' => "Invalid package name [{$input}]. Composer package names must contain only lowercase letters, numbers, dashes, underscores, and dots.",
                'suggestion' => "my-vendor/{$suggested}",
            ];
        }

        return [
            'vendorName' => '',
            'packageName' => $package,
            'vendor' => '',
            'package' => $package,
            'fullName' => $package,
            'isValid' => false,
            'error' => "Package name [{$input}] requires a vendor prefix in 'vendor/package' format.",
            'suggestion' => "my-vendor/{$package}",
        ];
    }
}

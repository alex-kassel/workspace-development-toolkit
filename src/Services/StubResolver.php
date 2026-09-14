<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use AlexKassel\WorkspaceDevelopmentToolkit\DTOs\StubResolutionResult;
use FilesystemIterator;
use Illuminate\Support\Facades\File;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

class StubResolver
{
    /**
     * Standard canonical relative mappings for root stubs.
     *
     * @var array<string, string>
     */
    protected const STANDARD_MAPPINGS = [
        'composer.json.stub' => 'composer.json',
        'LICENSE.stub' => 'LICENSE',
        'README.md.stub' => 'README.md',
        'CHANGELOG.md.stub' => 'CHANGELOG.md',
        'gitattributes.stub' => '.gitattributes',
        '.gitattributes.stub' => '.gitattributes',
        'gitignore.stub' => '.gitignore',
        '.gitignore.stub' => '.gitignore',
        'phpunit.xml.stub' => 'phpunit.xml',
        'phpstan.neon.stub' => 'phpstan.neon',
        'TestCase.php.stub' => 'tests/TestCase.php',
        'bootstrap.php.stub' => 'tests/bootstrap.php',
        'ExampleTest.php.stub' => 'tests/Unit/ExampleTest.php',
        'ServiceProvider.php.stub' => 'src/{{ providerClass }}.php',
        'config.php.stub' => 'config/{{ package }}.php',
        'gitkeep.stub' => 'tests/Unit/.gitkeep',
        'run-tests.yml.stub' => '.github/workflows/run-tests.yml',
    ];

    public function __construct(
        protected ManifestRepository $manifest,
    ) {}

    /**
     * Resolve the complete cascading map of stubs for a given workspace and optional archetype.
     *
     * Priority (lowest to highest):
     * 1. Bundled default stubs (packages/.../stubs/package/)
     * 2. Bundled archetype stubs (packages/.../stubs/archetypes/{archetype}/)
     * 3. Host global stubs (stubs/workspace/default/ or stubs/workspace/)
     * 4. Host archetype stubs (stubs/workspace/archetypes/{archetype}/)
     * 5. Workspace-specific stubs ({workspace}/.stubs/ or configured custom stubs_path)
     */
    public function resolve(string $workspace, ?string $archetype = null): StubResolutionResult
    {
        $fileMap = [];
        $extraReplacements = [];
        $excludedFiles = [];
        $appliedTiers = [];

        $tiers = $this->collectTiers($workspace, $archetype);

        foreach ($tiers as $tier) {
            $dir = $tier['path'];
            $tierName = $tier['name'];

            if (! File::isDirectory($dir)) {
                continue;
            }

            $appliedTiers[] = $tierName;

            // 1. Process stubs.json manifest if present in this tier
            $manifestPath = $dir.DIRECTORY_SEPARATOR.'stubs.json';
            $manifestMappings = [];

            if (File::exists($manifestPath)) {
                $manifestContent = (string) File::get($manifestPath);
                $manifestData = json_decode($manifestContent, true);

                if (is_array($manifestData)) {
                    if (! empty($manifestData['exclude_default_files']) && is_array($manifestData['exclude_default_files'])) {
                        foreach ($manifestData['exclude_default_files'] as $excluded) {
                            if (is_string($excluded)) {
                                $cleanExcluded = trim(str_replace('\\', '/', $excluded), '/');
                                $excludedFiles[] = $cleanExcluded;
                                unset($fileMap[$cleanExcluded]);
                            }
                        }
                    }

                    if (! empty($manifestData['file_mappings']) && is_array($manifestData['file_mappings'])) {
                        foreach ($manifestData['file_mappings'] as $stubPath => $targetPath) {
                            if (is_string($stubPath) && is_string($targetPath)) {
                                $manifestMappings[trim(str_replace('\\', '/', $stubPath), '/')] = trim(str_replace('\\', '/', $targetPath), '/');
                            }
                        }
                    }

                    if (! empty($manifestData['extra_replacements']) && is_array($manifestData['extra_replacements'])) {
                        foreach ($manifestData['extra_replacements'] as $token => $value) {
                            if (is_string($token) && (is_string($value) || is_numeric($value))) {
                                $extraReplacements[$token] = (string) $value;
                            }
                        }
                    }
                }
            }

            // 2. Scan and overlay all stub files in this directory
            $scannedFiles = $this->scanDirectory($dir);

            foreach ($scannedFiles as $relPath => $fullPath) {
                // Ignore the manifest file itself
                if ($relPath === 'stubs.json') {
                    continue;
                }

                // Handle .ignore file markers (e.g. LICENSE.ignore, run-tests.yml.ignore)
                if (str_ends_with($relPath, '.ignore')) {
                    $targetToIgnore = substr($relPath, 0, -7);
                    $excludedFiles[] = $targetToIgnore;
                    unset($fileMap[$targetToIgnore]);

                    continue;
                }

                // Determine target relative path
                $targetRelPath = $manifestMappings[$relPath]
                    ?? self::STANDARD_MAPPINGS[$relPath]
                    ?? $this->deriveTargetRelPath($relPath);

                // If this file has been excluded by an upper tier manifest, skip it
                if (in_array($targetRelPath, $excludedFiles, true)) {
                    continue;
                }

                $fileMap[$targetRelPath] = $fullPath;
            }
        }

        // Final prune of any files marked excluded
        foreach (array_unique($excludedFiles) as $excluded) {
            unset($fileMap[$excluded]);
        }

        return new StubResolutionResult(
            fileMap: $fileMap,
            extraReplacements: $extraReplacements,
            excludedFiles: array_values(array_unique($excludedFiles)),
            archetype: $archetype,
            appliedTiers: $appliedTiers,
        );
    }

    /**
     * Scan a directory recursively and return [relativePath => fullPath].
     *
     * @return array<string, string>
     */
    public function scanDirectory(string $dir): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        /** @var SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $fullPath = $file->getPathname();
                $relPath = trim(str_replace('\\', '/', substr($fullPath, strlen($dir))), '/');
                $files[$relPath] = $fullPath;
            }
        }

        return $files;
    }

    /**
     * Get all available archetypes across bundled defaults and host application.
     *
     * @return array<string, array{name: string, description: string, path: string, source: string}>
     */
    public function getAvailableArchetypes(): array
    {
        $archetypes = [
            'library' => [
                'name' => 'library',
                'description' => 'Standard Composer package with ServiceProvider, PHPUnit test suite, and PHPStan',
                'path' => $this->bundledStubsPath('package'),
                'source' => 'bundled',
            ],
            'pest' => [
                'name' => 'pest',
                'description' => 'Package with Pest PHP test setup and Arch testing support',
                'path' => $this->bundledArchetypesPath('pest'),
                'source' => 'bundled',
            ],
            'ddd-module' => [
                'name' => 'ddd-module',
                'description' => 'Domain-driven module (Domain, Actions, DTOs, Contracts directories)',
                'path' => $this->bundledArchetypesPath('ddd-module'),
                'source' => 'bundled',
            ],
            'minimal' => [
                'name' => 'minimal',
                'description' => 'Minimal package with composer.json and ServiceProvider only',
                'path' => $this->bundledArchetypesPath('minimal'),
                'source' => 'bundled',
            ],
        ];

        // Discover custom host archetypes from stubs/workspace/archetypes/*
        $hostArchetypesDir = base_path('stubs/workspace/archetypes');
        if (File::isDirectory($hostArchetypesDir)) {
            $directories = File::directories($hostArchetypesDir);
            foreach ($directories as $dir) {
                $slug = basename($dir);
                $desc = "Custom host archetype: {$slug}";
                $manifest = $dir.DIRECTORY_SEPARATOR.'stubs.json';
                if (File::exists($manifest)) {
                    $json = json_decode((string) File::get($manifest), true);
                    if (is_array($json) && ! empty($json['description'])) {
                        $desc = (string) $json['description'];
                    }
                }

                $archetypes[$slug] = [
                    'name' => $slug,
                    'description' => $desc,
                    'path' => $dir,
                    'source' => 'host',
                ];
            }
        }

        return $archetypes;
    }

    /**
     * Derive target relative path from stub relative path.
     * E.g. 'src/Domain/Entity.php.stub' -> 'src/Domain/Entity.php'
     * E.g. 'routes/api.php' -> 'routes/api.php'
     */
    protected function deriveTargetRelPath(string $stubRelPath): string
    {
        if (str_ends_with($stubRelPath, '.stub')) {
            return substr($stubRelPath, 0, -5);
        }

        return $stubRelPath;
    }

    /**
     * Collect candidate directories in priority order (lowest to highest).
     *
     * @return array<int, array{name: string, path: string}>
     */
    protected function collectTiers(string $workspace, ?string $archetype): array
    {
        $tiers = [];

        // Tier 1: Bundled default stubs
        $tiers[] = [
            'name' => 'bundled:default',
            'path' => $this->bundledStubsPath('package'),
        ];

        // Tier 2: Bundled archetype stubs
        if ($archetype !== null && $archetype !== '' && $archetype !== 'library') {
            $bundledArchPath = $this->bundledArchetypesPath($archetype);
            if (File::isDirectory($bundledArchPath)) {
                $tiers[] = [
                    'name' => "bundled:archetype:{$archetype}",
                    'path' => $bundledArchPath,
                ];
            }
        }

        // Tier 3: Host global stubs (stubs/workspace/default/ or stubs/workspace/)
        $hostDefaultDir = base_path('stubs/workspace/default');
        if (File::isDirectory($hostDefaultDir)) {
            $tiers[] = [
                'name' => 'host:default',
                'path' => $hostDefaultDir,
            ];
        } else {
            $hostWorkspaceDir = base_path('stubs/workspace');
            if (File::isDirectory($hostWorkspaceDir)) {
                $tiers[] = [
                    'name' => 'host:workspace',
                    'path' => $hostWorkspaceDir,
                ];
            }
        }

        // Tier 4: Host archetype stubs
        if ($archetype !== null && $archetype !== '') {
            $hostArchPath = base_path("stubs/workspace/archetypes/{$archetype}");
            if (File::isDirectory($hostArchPath)) {
                $tiers[] = [
                    'name' => "host:archetype:{$archetype}",
                    'path' => $hostArchPath,
                ];
            }
        }

        // Tier 5: Workspace-specific stubs
        // 5a. Auto-discovered in {workspace}/.stubs
        $cleanWorkspace = trim(str_replace('\\', '/', $workspace), '/');
        $workspaceStubsDir = base_path("{$cleanWorkspace}/.stubs");
        if (File::isDirectory($workspaceStubsDir)) {
            $tiers[] = [
                'name' => "workspace:{$cleanWorkspace}:stubs",
                'path' => $workspaceStubsDir,
            ];
        }

        // 5b. Configured in workspace.json
        $manifestWorkspaces = $this->manifest->all();
        $wsConfig = $manifestWorkspaces[$cleanWorkspace] ?? null;
        if (is_array($wsConfig) && ! empty($wsConfig['stubs_path'])) {
            $configuredPath = base_path((string) $wsConfig['stubs_path']);
            if (File::isDirectory($configuredPath)) {
                $tiers[] = [
                    'name' => "workspace:configured:{$cleanWorkspace}",
                    'path' => $configuredPath,
                ];
            }
        }

        // 5c. Host folder for workspace name: stubs/workspace/{cleanWorkspace}
        $hostPerWorkspaceDir = base_path("stubs/workspace/{$cleanWorkspace}");
        if (File::isDirectory($hostPerWorkspaceDir) && $hostPerWorkspaceDir !== $hostDefaultDir) {
            $tiers[] = [
                'name' => "host:workspace_override:{$cleanWorkspace}",
                'path' => $hostPerWorkspaceDir,
            ];
        }

        return $tiers;
    }

    /**
     * Path to bundled stubs directory.
     */
    protected function bundledStubsPath(string $subpath = ''): string
    {
        $base = dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'stubs';

        return $subpath !== '' ? $base.DIRECTORY_SEPARATOR.$subpath : $base;
    }

    /**
     * Path to bundled archetype directory.
     */
    protected function bundledArchetypesPath(string $archetype): string
    {
        return dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'stubs'.DIRECTORY_SEPARATOR.'archetypes'.DIRECTORY_SEPARATOR.$archetype;
    }
}

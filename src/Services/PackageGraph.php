<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Services;

use Illuminate\Support\Facades\File;

class PackageGraph
{
    /**
     * @var array<string, array<string>>|null
     */
    protected ?array $dependencies = null;

    /**
     * @var array<string, array<string>>|null
     */
    protected ?array $dependents = null;

    /**
     * @var array<string, string>|null
     */
    protected ?array $pathToPackage = null;

    /**
     * @var array<string, string>|null
     */
    protected ?array $packageToPath = null;

    public function __construct(
        protected PackageResolver $resolver,
        protected ManifestRepository $manifest
    ) {}

    /**
     * Clear graph cache.
     */
    public function clearCache(): void
    {
        $this->dependencies = null;
        $this->dependents = null;
        $this->pathToPackage = null;
        $this->packageToPath = null;
    }

    /**
     * Build the dependency graph across all registered workspaces.
     */
    public function buildGraph(): void
    {
        if ($this->dependencies !== null) {
            return;
        }

        $this->dependencies = [];
        $this->dependents = [];
        $this->pathToPackage = [];
        $this->packageToPath = [];

        $allWorkspaces = $this->manifest->all();
        $workspacePackages = [];

        // 1. Discover all packages in workspace
        foreach ($allWorkspaces as $wsPath => $config) {
            $vendor = $config['vendor'] ?? null;
            $files = $vendor !== null
                ? (File::glob(base_path("{$wsPath}/*/composer.json")) ?: [])
                : (File::glob(base_path("{$wsPath}/*/*/composer.json")) ?: []);

            foreach ($files as $file) {
                try {
                    $json = json_decode(File::get($file), true, 512, JSON_THROW_ON_ERROR);
                    $name = $json['name'] ?? null;
                    if ($name) {
                        $dir = dirname($file);
                        $relPath = str_replace('\\', '/', ltrim(str_replace(base_path(), '', $dir), '/\\'));
                        $workspacePackages[$name] = [
                            'path' => $relPath,
                            'composer' => $json,
                        ];
                        $this->packageToPath[$name] = $relPath;
                        $this->pathToPackage[$relPath] = $name;
                        $this->dependencies[$name] = [];
                        $this->dependents[$name] = [];
                    }
                } catch (\Throwable) {
                    // Ignore unparseable composer.json files during graph scan
                }
            }
        }

        // 2. Build dependency links
        foreach ($workspacePackages as $name => $data) {
            $composer = $data['composer'];
            $reqs = array_merge(
                array_keys($composer['require'] ?? []),
                array_keys($composer['require-dev'] ?? [])
            );

            foreach ($reqs as $req) {
                $reqStr = (string) $req;
                if (isset($workspacePackages[$reqStr]) && $reqStr !== $name) {
                    $this->dependencies[$name][] = $reqStr;
                    $this->dependents[$reqStr][] = $name;
                }
            }
        }

        foreach ($this->dependencies as $name => $deps) {
            $this->dependencies[$name] = array_values(array_unique($deps));
        }

        foreach ($this->dependents as $name => $deps) {
            $this->dependents[$name] = array_values(array_unique($deps));
        }
    }

    /**
     * Get direct internal dependencies of a package.
     *
     * @return array<int, string>
     */
    public function getDependencies(string $packageName): array
    {
        $this->buildGraph();
        $canonical = $this->resolver->resolveCanonicalPackageName($packageName);

        return $this->dependencies[$canonical] ?? [];
    }

    /**
     * Get workspace packages that depend on the given package.
     * When $recursive is true, returns all direct and transitive dependents in topological order.
     *
     * @return array<int, string>
     */
    public function getDependents(string $packageName, bool $recursive = true): array
    {
        $this->buildGraph();
        $canonical = $this->resolver->resolveCanonicalPackageName($packageName);

        if (! $recursive) {
            return $this->dependents[$canonical] ?? [];
        }

        $visited = [];
        $queue = $this->dependents[$canonical] ?? [];

        while (! empty($queue)) {
            $current = array_shift($queue);
            if (! in_array($current, $visited, true)) {
                $visited[] = $current;
                foreach (($this->dependents[$current] ?? []) as $downstream) {
                    if (! in_array($downstream, $visited, true)) {
                        $queue[] = $downstream;
                    }
                }
            }
        }

        return $visited;
    }

    /**
     * Detect any cyclic dependencies between workspace packages.
     *
     * @return array<int, array<int, string>>
     */
    public function detectCycles(): array
    {
        $this->buildGraph();
        $cycles = [];
        $visited = [];
        $recursionStack = [];

        foreach (array_keys($this->dependencies ?? []) as $node) {
            $path = [];
            $this->findCyclesDfs($node, $visited, $recursionStack, $path, $cycles);
        }

        return $cycles;
    }

    /**
     * @param  array<string, bool>  $visited
     * @param  array<string, bool>  $recursionStack
     * @param  array<int, string>  $path
     * @param  array<int, array<int, string>>  $cycles
     */
    protected function findCyclesDfs(
        string $node,
        array &$visited,
        array &$recursionStack,
        array &$path,
        array &$cycles
    ): void {
        $visited[$node] = true;
        $recursionStack[$node] = true;
        $path[] = $node;

        foreach (($this->dependencies[$node] ?? []) as $neighbor) {
            if (! isset($visited[$neighbor])) {
                $this->findCyclesDfs($neighbor, $visited, $recursionStack, $path, $cycles);
            } elseif (! empty($recursionStack[$neighbor])) {
                $cycleStartIndex = array_search($neighbor, $path, true);
                if ($cycleStartIndex !== false) {
                    $cycles[] = array_slice($path, $cycleStartIndex);
                }
            }
        }

        $recursionStack[$node] = false;
        array_pop($path);
    }

    /**
     * Render ASCII tree visualization of package dependencies and dependents.
     */
    public function renderTree(string $packageName): string
    {
        $this->buildGraph();
        $canonical = $this->resolver->resolveCanonicalPackageName($packageName);

        $dependencies = $this->getDependencies($canonical);
        $dependents = $this->getDependents($canonical, recursive: false);
        $allDependents = $this->getDependents($canonical, recursive: true);

        $lines = [];
        $lines[] = "<info>{$canonical}</info>";

        $lines[] = '  ├── <comment>Dependencies (Requires):</comment>';
        if (empty($dependencies)) {
            $lines[] = '  │   └── <fg=gray>(none within workspace)</>';
        } else {
            foreach ($dependencies as $idx => $dep) {
                $prefix = ($idx === count($dependencies) - 1) ? '└── ' : '├── ';
                $lines[] = "  │   {$prefix}{$dep}";
            }
        }

        $lines[] = '  └── <comment>Dependents (Used By):</comment>';
        if (empty($dependents)) {
            $lines[] = '      └── <fg=gray>(no other workspace packages depend on this)</>';
        } else {
            foreach ($dependents as $idx => $dep) {
                $isLast = ($idx === count($dependents) - 1);
                $prefix = $isLast ? '└── ' : '├── ';
                $lines[] = "      {$prefix}{$dep}";
            }
            if (count($allDependents) > count($dependents)) {
                $transitive = array_diff($allDependents, $dependents);
                $lines[] = '      <fg=gray>Transitive:</> '.implode(', ', $transitive);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * Render Mermaid graph of all workspace dependencies.
     */
    public function renderMermaid(): string
    {
        $this->buildGraph();
        $lines = ['graph TD'];

        foreach ($this->dependencies ?? [] as $source => $targets) {
            $srcId = preg_replace('/[^a-zA-Z0-9_]/', '_', $source);
            foreach ($targets as $target) {
                $tgtId = preg_replace('/[^a-zA-Z0-9_]/', '_', $target);
                $lines[] = "    {$srcId}[\"{$source}\"] --> {$tgtId}[\"{$target}\"]";
            }
        }

        return implode("\n", $lines);
    }
}

<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\PackageGraph;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;
use Illuminate\Support\Facades\File;

class PackageDepsCommand extends BasePackageCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'package:deps
        {package : Package name in vendor/package format (e.g. acme/my-pkg)}
        {--mermaid : Output dependency graph in Mermaid diagram format}
        {--json : Output machine-readable JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Inspect internal workspace dependencies and dependents of a package';

    public function __construct(
        WorkspaceManager $workspace,
        ComposerManager $composer,
        protected ?PackageGraph $graph = null,
    ) {
        parent::__construct($workspace, $composer);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $graph = $this->graph ?? app(PackageGraph::class);
        $graph->clearCache();

        $rawPackage = (string) $this->argument('package');
        $packagePath = $this->workspace->findPackagePath($rawPackage);

        if ($packagePath === null || ! File::isDirectory(base_path($packagePath))) {
            $this->error("Package [{$rawPackage}] not found.");
            $this->line('  <comment>How to fix:</comment> View registered packages using:');
            $this->line('  <info>php artisan workspace:list</info>');

            return self::FAILURE;
        }

        $package = $this->workspace->resolveCanonicalPackageName($rawPackage);

        if ((bool) $this->option('mermaid')) {
            $this->line($graph->renderMermaid());

            return self::SUCCESS;
        }

        if ((bool) $this->option('json')) {
            $data = [
                'package' => $package,
                'path' => $packagePath,
                'dependencies' => $graph->getDependencies($package),
                'direct_dependents' => $graph->getDependents($package, recursive: false),
                'all_dependents' => $graph->getDependents($package, recursive: true),
                'cycles' => $graph->detectCycles(),
            ];
            $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Dependency Graph for [{$package}]:");
        $this->newLine();
        foreach (explode("\n", $graph->renderTree($package)) as $treeLine) {
            $this->line($treeLine);
        }
        $this->newLine();

        $cycles = $graph->detectCycles();
        if (! empty($cycles)) {
            $this->warn('⚠ Cyclic dependency detected in workspace:');
            foreach ($cycles as $cycle) {
                $this->line('  • '.implode(' -> ', $cycle));
            }
        }

        return self::SUCCESS;
    }
}

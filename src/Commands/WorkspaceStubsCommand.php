<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\StubResolver;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;
use Illuminate\Support\Facades\File;

class WorkspaceStubsCommand extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:stubs
        {--workspace= : Target workspace to publish stubs for (publishes to {workspace}/.stubs/)}
        {--archetype= : Base archetype to publish (library, pest, ddd-module, minimal)}
        {--force : Overwrite existing stubs if already present}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Publish customizable package scaffolding stubs to host or workspace';

    public function __construct(
        WorkspaceManager $workspace,
        ComposerManager $composer,
        protected readonly StubResolver $stubResolver,
    ) {
        parent::__construct($workspace, $composer);
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rawWorkspace = (string) $this->option('workspace');
        $rawArchetype = (string) $this->option('archetype');
        $force = (bool) $this->option('force');

        $archetype = trim($rawArchetype) !== '' ? trim($rawArchetype) : 'library';
        $archetypes = $this->stubResolver->getAvailableArchetypes();

        if (! array_key_exists($archetype, $archetypes)) {
            $available = implode(', ', array_keys($archetypes));
            $this->error("Unknown archetype [{$archetype}]. Available archetypes: [{$available}].");

            return self::FAILURE;
        }

        // Determine destination directory
        if (trim($rawWorkspace) !== '') {
            $cleanWorkspace = trim(str_replace('\\', '/', $rawWorkspace), '/');
            $destDir = base_path("{$cleanWorkspace}/.stubs");
            $targetLabel = "workspace [{$cleanWorkspace}] ({$destDir})";
        } else {
            $destDir = base_path('stubs/workspace/default');
            $targetLabel = "host default ({$destDir})";
        }

        if (File::isDirectory($destDir) && ! $force) {
            $this->warn("Stubs directory already exists at [{$destDir}].");
            $this->line('  <comment>How to fix:</comment> Use [--force] to overwrite existing stubs:');
            $this->line('  <info>php artisan workspace:stubs'.(trim($rawWorkspace) !== '' ? " --workspace={$rawWorkspace}" : '').' --force</info>');

            return self::FAILURE;
        }

        File::ensureDirectoryExists($destDir);

        // Resolve source stubs
        $stubResolution = $this->stubResolver->resolve(
            workspace: trim($rawWorkspace) !== '' ? trim($rawWorkspace) : 'default',
            archetype: $archetype
        );

        $copiedCount = 0;
        foreach ($stubResolution->fileMap as $targetRelPath => $sourceFullPath) {
            // Retain original filename
            $destFile = $destDir.DIRECTORY_SEPARATOR.basename($sourceFullPath);
            File::copy($sourceFullPath, $destFile);
            $copiedCount++;
        }

        // Generate sample stubs.json manifest in destination
        $sampleManifest = [
            'description' => "Custom stubs for {$targetLabel}",
            'exclude_default_files' => [],
            'file_mappings' => [],
            'extra_replacements' => [],
        ];

        $manifestFile = $destDir.DIRECTORY_SEPARATOR.'stubs.json';
        if (! File::exists($manifestFile) || $force) {
            File::put($manifestFile, json_encode($sampleManifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
        }

        $this->info("✔ Successfully published {$copiedCount} stub file(s) for {$targetLabel}.");
        $this->line('  <comment>Hint:</comment> You can now customize these stubs or edit stubs.json to exclude files.');

        return self::SUCCESS;
    }
}

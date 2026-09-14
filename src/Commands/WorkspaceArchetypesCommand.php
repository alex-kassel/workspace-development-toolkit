<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Services\ComposerManager;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\StubResolver;
use AlexKassel\WorkspaceDevelopmentToolkit\Services\WorkspaceManager;

class WorkspaceArchetypesCommand extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:archetypes
        {--json : Output archetypes list as JSON}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List all available package scaffolding archetypes (bundled and host-custom)';

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
        $archetypes = $this->stubResolver->getAvailableArchetypes();

        if ($this->option('json')) {
            foreach (explode("\n", (string) json_encode(array_values($archetypes), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)) as $line) {
                $this->line($line);
            }

            return self::SUCCESS;
        }

        $this->info('Available Package Scaffolding Archetypes:');
        $this->newLine();

        $rows = [];
        foreach ($archetypes as $slug => $arch) {
            $sourceBadge = $arch['source'] === 'bundled'
                ? '<fg=cyan>bundled</>'
                : '<fg=green>host custom</>';

            $rows[] = [
                "<info>{$slug}</info>",
                $sourceBadge,
                $arch['description'],
            ];
        }

        $this->table(['Archetype', 'Source', 'Description'], $rows);

        $this->newLine();
        $this->line('  <comment>Usage example:</comment> php artisan package:make my-pkg --type=pest');

        return self::SUCCESS;
    }
}

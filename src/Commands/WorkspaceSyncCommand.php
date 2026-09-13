<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

class WorkspaceSyncCommand extends BaseWorkspaceCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:sync
        {--dry-run : Check whether composer.json requires synchronization without modifying it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Synchronize path repositories in root composer.json with registered workspaces';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $workspaces = $this->workspace->all();

        if (empty($workspaces)) {
            $this->info('No workspaces registered. Nothing to synchronize.');
            $this->line('  <comment>How to fix:</comment> Register a workspace using:');
            $this->line('  <info>php artisan workspace:add packages</info>');

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info('Checking root composer.json synchronization (dry-run)...');
            $this->line('Registered workspaces: <info>['.implode(', ', array_keys($workspaces)).']</info>');

            return self::SUCCESS;
        }

        $modified = $this->composer->syncRepositories($workspaces);
        $this->composer->ensureComposerHooks();
        $this->composer->ensureWorkspaceScript();

        if ($modified) {
            $this->info('✔ Root composer.json path repositories synchronized successfully.');
        } else {
            $this->info('✔ Root composer.json path repositories are already up to date (0 file changes).');
        }

        return self::SUCCESS;
    }
}

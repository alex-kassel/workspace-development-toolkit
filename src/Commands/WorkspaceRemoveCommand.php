<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Facades\Workspace;
use Illuminate\Console\Command;

class WorkspaceRemoveCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'workspace:remove {path : The workspace directory path}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Remove a workspace from composer.json and workspace.json (directory is preserved)';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $rawPath = (string) $this->argument('path');
        $path = trim(preg_replace('#[/\\\\]+#', '/', $rawPath) ?? '', '/');

        if ($path === '') {
            $this->error('Workspace path cannot be empty.');
            $this->line('  <comment>How to fix:</comment> Provide the name of a registered workspace, e.g.:');
            $this->line('  <info>php artisan workspace:remove packages</info>');

            return self::FAILURE;
        }

        $workspaces = Workspace::all();
        if (! array_key_exists($path, $workspaces)) {
            $available = array_keys($workspaces);
            $availableStr = empty($available) ? 'none' : implode(', ', $available);

            $this->error("Workspace [{$path}] is not registered. Available workspaces: [{$availableStr}].");
            $this->line('  <comment>How to fix:</comment> Check registered workspaces with:');
            $this->line('  <info>php artisan workspace:list</info>');

            return self::FAILURE;
        }

        Workspace::remove($path);

        $this->info("Workspace [{$path}] removed from configuration (composer.json and workspace.json).");

        $this->newLine();
        $this->line('  <comment>Hint:</comment> Workspace removal only unregisters the repository.');
        $this->line("  1. The physical directory [{$path}/] was preserved. To completely delete it, remove it manually from disk.");
        $this->line("  2. If you no longer need git ignore rules for it, remove [/{$path}] from your .gitignore file.");

        return self::SUCCESS;
    }
}

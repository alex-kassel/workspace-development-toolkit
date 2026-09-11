<?php

declare(strict_types=1);

namespace AlexKassel\WorkspaceDevelopmentToolkit\Commands;

use AlexKassel\WorkspaceDevelopmentToolkit\Exceptions\WorkspaceException;
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

        try {
            Workspace::remove($path);
        } catch (WorkspaceException $e) {
            $this->error($e->getMessage());
            if ($e->getSolution()) {
                $this->line("  <comment>How to fix:</comment> {$e->getSolution()}");
            }

            return self::FAILURE;
        }

        $this->info("Workspace [{$path}] removed from configuration (composer.json and workspace.json).");

        $this->newLine();
        $this->line('  <comment>Hint:</comment> Workspace removal only unregisters the repository.');
        $this->line("  1. The physical directory [{$path}/] was preserved.");
        $this->line("     <fg=yellow;options=bold>CAUTION:</> Before deleting it manually, verify that git working trees inside [{$path}/] are clean and all commits have been pushed!");
        $this->line("  2. The ignore rule [/{$path}] has been cleanly removed from your .gitignore file.");

        return self::SUCCESS;
    }
}
